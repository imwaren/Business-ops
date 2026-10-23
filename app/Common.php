<?php

/**
 * The goal of this file is to allow developers a location
 * where they can overwrite core procedural functions and
 * replace them with their own. This file is loaded during
 * the bootstrap process and is called during the framework's
 * execution.
 *
 * This can be looked at as a `master helper` file that is
 * loaded early on, and may also contain additional functions
 * that you'd like to use throughout your entire application
 *
 * @see: https://codeigniter4.github.io/CodeIgniter4/
 */

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Send email to one or more recipients (To/CC) in a single message.
 */
function send_email_to_many($subject, $message, array $to = [], array $cc = [])
{
    $to = function_exists('normalize_email_list') ? normalize_email_list($to) : array_values(array_filter($to));
    $cc = function_exists('normalize_email_list') ? normalize_email_list($cc) : array_values(array_filter($cc));

    if (empty($to) && empty($cc)) {
        return false;
    }

    if (empty($to) && !empty($cc)) {
        $to = [array_shift($cc)];
    }

    try {
        $mail = new PHPMailer(true);
        $hasRecipient = false;

        foreach ($to as $address) {
            try {
                $mail->addAddress($address);
                $hasRecipient = true;
            } catch (PHPMailerException $e) {
                if (str_contains($e->getMessage(), 'Invalid address')) {
                    log_message('error', 'Invalid email skipped: ' . $address);
                    continue;
                }
                throw $e;
            }
        }

        foreach ($cc as $address) {
            try {
                $mail->addCC($address);
                $hasRecipient = true;
            } catch (PHPMailerException $e) {
                if (str_contains($e->getMessage(), 'Invalid address')) {
                    log_message('error', 'Invalid email skipped: ' . $address);
                    continue;
                }
                throw $e;
            }
        }

        if (!$hasRecipient) {
            return false;
        }

        $mail->isSMTP();
        $mail->Host       = 'smtp.office365.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = OUTLOOK_EMAIL;
        $mail->Password   = OUTLOOK_EMAIL_PASSWORD;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(OUTLOOK_EMAIL, 'CLINICA PRIME - BUSINESS OPERATIONS');
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $message;
        $mail->send();

        return true;
    } catch (PHPMailerException $e) {
        log_message('error', 'PHPMailer Exception: ' . $e->getMessage());
        return false;
    } catch (\Throwable $e) {
        log_message('error', 'Unexpected Email Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send email to a single recipient.
 */
function send_email($subject, $message, $to)
{
    return send_email_to_many($subject, $message, [$to]);
}

/**
 * Normalize and deduplicate email addresses.
 */
function normalize_email_list(array $emails): array
{
    $normalized = [];
    foreach ($emails as $email) {
        $email = trim((string) $email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $normalized[strtolower($email)] = $email;
        }
    }

    return array_values($normalized);
}

/**
 * Return primary emails when present, otherwise fallback emails.
 */
function resolve_with_fallback(array $primaryEmails, array $fallbackEmails): array
{
    $primary = normalize_email_list($primaryEmails);
    if (!empty($primary)) {
        return $primary;
    }

    return normalize_email_list($fallbackEmails);
}

/**
 * Split emails into To (first) and CC (rest) for a combined send.
 */
function split_to_and_cc(array $emails): array
{
    $emails = normalize_email_list($emails);
    if (empty($emails)) {
        return [[], []];
    }

    $to = [array_shift($emails)];

    return [$to, $emails];
}

/**
 * Resolve immediate_head email for a user id.
 */
function resolve_immediate_head_email($userModel, $userId): ?string
{
    $user = $userModel->select('immediate_head', ['id' => $userId, 'is_deleted' => 0], 1);
    if (!$user || !$user->immediate_head) {
        return null;
    }

    $head = $userModel->select('email', ['id' => $user->immediate_head, 'is_deleted' => 0], 1);

    return ($head && $head->email) ? $head->email : null;
}

/**
 * Resolve a single user's email by id.
 */
function resolve_user_email_by_id($userModel, $userId): ?string
{
    if (!$userId) {
        return null;
    }

    $user = $userModel->select('email', ['id' => $userId, 'is_deleted' => 0], 1);

    return ($user && $user->email) ? $user->email : null;
}

/**
 * Resolve emails for all active users with the given role ids.
 */
function resolve_emails_by_role($userModel, array $roleIds): array
{
    if (empty($roleIds)) {
        return [];
    }

    $users = $userModel->get_details(null, $roleIds) ?: [];
    $emails = [];

    foreach ($users as $user) {
        if (!empty($user['email'])) {
            $emails[] = $user['email'];
        }
    }

    return normalize_email_list($emails);
}

/**
 * Resolve request.added_by email.
 */
function resolve_request_added_by_email($requestModel, $userModel, $requestId): ?string
{
    $request = $requestModel->select('added_by', ['id' => $requestId, 'is_deleted' => 0], 1);
    if (!$request || !$request->added_by) {
        return null;
    }

    return resolve_user_email_by_id($userModel, $request->added_by);
}

/**
 * Fallback chain: immediate_head then user's own email.
 */
function resolve_user_fallback_emails($userModel, $userId): array
{
    $emails = [];
    $headEmail = resolve_immediate_head_email($userModel, $userId);
    if ($headEmail) {
        $emails[] = $headEmail;
    }

    $userEmail = resolve_user_email_by_id($userModel, $userId);
    if ($userEmail) {
        $emails[] = $userEmail;
    }

    return normalize_email_list($emails);
}

/**
 * Prepare a notification for post-response delivery; email failures never fail the API.
 */
function dispatch_email_notification($subject, $message, array $primaryEmails, array $fallbackEmails): bool
{
    try {
        $recipients = resolve_with_fallback($primaryEmails, $fallbackEmails);
        if (empty($recipients)) {
            log_message('error', 'Background notification skipped: no valid email recipients.');
            return true;
        }

        [$to, $cc] = split_to_and_cc($recipients);
        _dispatch_notification_email_background([
            'subject' => $subject,
            'message' => $message,
            'to' => $to,
            'cc' => $cc,
        ]);
    } catch (\Throwable $error) {
        log_message('error', 'Background notification preparation/scheduling failed: ' . get_class($error));
    }

    return true;
}

/** Register one shutdown callback and capture only prepared email data. */
function _dispatch_notification_email_background(array $notification): void
{
    static $notifications = [];
    static $registered = false;

    $notifications[] = $notification;
    if ($registered) {
        return;
    }

    register_shutdown_function(static function () use (&$notifications): void {
        $batch = $notifications;
        $notifications = [];

        // Read the final status after the framework has sent the HTTP response.
        if (http_response_code() >= 400) {
            foreach ($batch as $index => $notification) {
                log_message('error', 'Background notification skipped: failed HTTP response; message=' . ($index + 1));
            }
            return;
        }

        try {
            ignore_user_abort(true);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (PHP_SAPI !== 'cli') {
                while (ob_get_level() > 0) {
                    if (!@ob_end_flush()) {
                        break;
                    }
                }
                flush();
            }
        } catch (\Throwable $error) {
            log_message('error', 'Background notification response completion failed: ' . get_class($error));
            return;
        }

        foreach ($batch as $index => $notification) {
            try {
                if (!send_email_to_many($notification['subject'], $notification['message'], $notification['to'], $notification['cc'])) {
                    log_message('error', 'Background notification SMTP failed; message=' . ($index + 1));
                }
            } catch (\Throwable $error) {
                log_message('error', 'Background notification delivery exception; message=' . ($index + 1) . ' error=' . get_class($error));
            }
        }
    });
    $registered = true;
}

/**
 * Upload to VM4
 */
function upload_to_vm4($file, $path)
{
    $endpoint = VM4_ENDPOINT . 'upload.php';

    // ----------------------------------------------------
    // 1) QUICK ENDPOINT CHECK (HEAD request)
    // ----------------------------------------------------
    $check = curl_init();
    curl_setopt_array($check, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    curl_exec($check);
    $httpCode = curl_getinfo($check, CURLINFO_HTTP_CODE);
    $error    = curl_errno($check);
    curl_close($check);

    if ($error || $httpCode < 200 || $httpCode >= 500) {
        // log endpoint unreachable
        log_message('error', "VM4 endpoint unreachable or down: HTTP {$httpCode}, cURL Error: {$error}");

        return [
            'success' => false,
            'error'   => 'Endpoint not reachable or down (HTTP ' . $httpCode . ')',
        ];
    }

    // ----------------------------------------------------
    // 2) PERFORM THE FILE UPLOAD (your original code)
    // ----------------------------------------------------
    $curl = curl_init();

    $postData = [
        'file' => new \CURLFile(
            $file->getTempName(),
            $file->getClientMimeType(),
            $file->getName()
        ),
        'path' => $path,
    ];

    curl_setopt_array($curl, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . VM4_SECRET_TOKEN,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response    = curl_exec($curl);
    $uploadError = curl_error($curl);
    curl_close($curl);

    if ($uploadError) {
        // KEEP YOUR LOG MESSAGE
        log_message('error', 'CURL upload error: ' . $uploadError);

        return [
            'success' => false,
            'error'   => $uploadError,
        ];
    }

    return json_decode($response, true);
}

/***
 * Variable Dumping
 */
function dump($value = null)
{
    echo '<pre>';
    var_dump($value);
    echo '</pre>';
    die();
}

/**
 * Hash a password with Argon2id when available, otherwise bcrypt.
 */
function app_password_hash($password): string
{
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

    return password_hash((string) $password, $algo);
}

/**
 * Skip api_response inserts for list/read methods named get or get_*.
 */
function should_skip_api_response_logging(?string $method): bool
{
    $method = (string) $method;

    return $method === 'get' || strncmp($method, 'get_', 4) === 0;
}

/**
 * Cron secret from X-Cron-Secret header or ?cron_secret= query (Hestia wget).
 */
function cron_secret_is_valid(string $expected, string $header, string $query): bool
{
    if ($expected === '') {
        return false;
    }
    if ($header !== '' && hash_equals($expected, $header)) {
        return true;
    }

    return $query !== '' && hash_equals($expected, $query);
}

/**
 * Strip quotes/trailing slash from an Origin or allow-list entry.
 */
function cors_normalize_origin(?string $origin): string
{
    $origin = trim((string) $origin);
    $origin = trim($origin, "\"'");

    return rtrim($origin, '/');
}

/**
 * @return list<string>
 */
function cors_allowed_origins(): array
{
    $raw = '';
    if (function_exists('myt_env')) {
        $raw = (string) myt_env('CORS_ALLOWED_ORIGINS', '');
    } elseif (! empty($_ENV['CORS_ALLOWED_ORIGINS'])) {
        $raw = (string) $_ENV['CORS_ALLOWED_ORIGINS'];
    }
    $raw = cors_normalize_origin($raw);
    if ($raw === '' && defined('FRONT_END_URL')) {
        $raw = cors_normalize_origin((string) FRONT_END_URL);
    }

    $origins = [];
    foreach (explode(',', $raw) as $origin) {
        $normalized = cors_normalize_origin($origin);
        if ($normalized !== '') {
            $origins[] = $normalized;
        }
    }

    return $origins;
}

function cors_origin_is_allowed(?string $origin): bool
{
    $origin = cors_normalize_origin($origin);

    return $origin !== '' && in_array($origin, cors_allowed_origins(), true);
}

function cors_apply_to_response($request, $response): void
{
    if (! is_object($request) || ! is_object($response) || ! method_exists($request, 'getHeaderLine')) {
        return;
    }

    $origin = cors_normalize_origin((string) $request->getHeaderLine('Origin'));
    if (! cors_origin_is_allowed($origin)) {
        return;
    }

    $response->setHeader('Access-Control-Allow-Origin', $origin);
    $response->setHeader('Vary', 'Origin');
    $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, PUT, PATCH, DELETE');
    $response->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, api-key, API-KEY, Origin-API-Key, latitude, longitude, X-Cron-Secret');
    $response->setHeader('Access-Control-Max-Age', '86400');
}

/**
 * Redact secrets from request payloads before logging.
 */
function redact_sensitive($data)
{
    $keys = [
        'password', 'new_password', 'old_password', 'token', 'signature',
        'api_key', 'API-KEY', 'cron_secret', 'X-Cron-Secret',
    ];

    if (is_object($data)) {
        $data = (array) $data;
    }
    if (! is_array($data)) {
        return $data;
    }

    foreach ($data as $key => $value) {
        $normalized = strtolower((string) $key);
        if (in_array($normalized, $keys, true) || str_contains($normalized, 'password') || str_contains($normalized, 'token')) {
            $data[$key] = '[REDACTED]';
            continue;
        }
        if (is_array($value) || is_object($value)) {
            $data[$key] = redact_sensitive($value);
        }
    }

    return $data;
}

/**
 * Safe LIMIT clause. Caps page size to prevent unbounded dumps.
 */
function sql_limit_clause($page = null, $data_size = null): string
{
    if ($page === null && $data_size === null) {
        return '';
    }

    $size = (int) $data_size;
    if ($size <= 0) {
        $size = 20;
    }
    $size = min($size, 100);
    $pageNo = max(1, (int) $page);
    $offset = ($pageNo - 1) * $size;

    return ' LIMIT ' . $offset . ', ' . $size;
}

/**
 * Default list pagination. Accepts page_size as an alias for data_size.
 *
 * @param array<string, mixed> $payload
 * @return array{0:int,1:int}
 */
function list_page_params(array $payload, $default_page = 1, $default_size = 20): array
{
    $page = $payload['page'] ?? null;
    $data_size = $payload['data_size'] ?? $payload['page_size'] ?? null;
    if ($page === '' || $page === false) {
        $page = null;
    }
    if ($data_size === '' || $data_size === false) {
        $data_size = null;
    }
    if ($page === null && $data_size === null) {
        return [(int) $default_page, (int) $default_size];
    }

    return [
        max(1, (int) ($page ?: $default_page)),
        max(1, (int) ($data_size ?: $default_size)),
    ];
}

/**
 * Allow-list a printer / filesystem token. Rejects traversal.
 */
function sanitize_printer_name($name): ?string
{
    $name = trim((string) $name);
    if ($name === '' || ! preg_match('/^[A-Za-z0-9 _-]{1,64}$/', $name)) {
        return null;
    }

    return $name;
}

/**
 * Allow-list a SQL identifier (column or table fragment).
 */
function sanitize_sql_identifier($name): ?string
{
    $name = trim((string) $name);
    if ($name === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
        return null;
    }

    return $name;
}

/**
 * Allow-list ORDER BY expressions like "last_name ASC, first_name DESC".
 */
function sanitize_order_by($order): ?string
{
    $order = trim((string) $order);
    if ($order === '') {
        return null;
    }
    if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\s+(ASC|DESC))?(,\s*[A-Za-z_][A-Za-z0-9_]*(\s+(ASC|DESC))?)*$/i', $order)) {
        return null;
    }

    return $order;
}

/**
 * Strip password/token fields from a user row or list.
 */
function strip_sensitive_user_fields($rows)
{
    $remove = ['password', 'token', 'token_expiry'];
    $isList = is_array($rows) && $rows !== [] && array_keys($rows) === range(0, count($rows) - 1);

    if (! $isList) {
        if (is_object($rows)) {
            foreach ($remove as $field) {
                unset($rows->{$field});
            }
            return $rows;
        }
        if (is_array($rows)) {
            foreach ($remove as $field) {
                unset($rows[$field]);
            }
        }
        return $rows;
    }

    foreach ($rows as $i => $row) {
        $rows[$i] = strip_sensitive_user_fields($row);
    }

    return $rows;
}

/**
 * Build an HTML block that shows an approver's message/reason inside a status email.
 * Returns an empty string when there is nothing to show, so callers can append it safely.
 */
function build_status_reason_html(string $label, $reason): string
{
    $reason = trim((string) $reason);
    if ($reason === '') {
        return '';
    }

    return '<p><strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ':</strong><br>'
        . nl2br(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')) . '</p>';
}

/**
 * Role id => label used in "approved/disapproved by ..." email wording.
 */
function status_actor_role_label($roleId): string
{
    $labels = [
        4  => 'Admin',
        19 => 'Sales Account Executive',
        20 => 'Sales Manager',
        21 => 'Sales Director',
        26 => 'Finance Director',
    ];

    return $labels[(int) $roleId] ?? 'approver';
}

/**
 * Compare an incoming API key against configured keys.
 */
function api_key_is_valid(?string $provided): bool
{
    $candidates = array_filter(array_map('trim', explode(',', (string) myt_env('API_KEYS', API_KEY))));
    if ($provided === null || $provided === '') {
        return false;
    }
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && hash_equals($candidate, $provided)) {
            return true;
        }
    }

    return false;
}

if (! function_exists('myt_env')) {
    /**
     * Compatibility with deployed Constants.php versions without the Core reader.
     */
    function myt_env(string $key, $default = '')
    {
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        $value = getenv($key);

        return ($value === false || $value === '') ? $default : $value;
    }
}

/**
 * Compare an incoming API key against configured keys.
 */
function csr_api_key_is_valid(?string $provided): bool
{
    $candidates = array_filter(array_map('trim', explode(',', (string) myt_env('CSR_API_KEYS', CSR_API_KEY))));
    if ($provided === null || $provided === '') {
        return false;
    }
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && hash_equals($candidate, $provided)) {
            return true;
        }
    }

    return false;
}

/**
 * Dispatch Email Background
 */
function _dispatch_email_background($booking, $branch_id, $booking_detail_id)
{
    $booking_id = $booking->id;
    $email      = $this->request->getVar('email') ?: $booking->patient_email;
    $self       = $this;

    register_shutdown_function(function () use ($booking_id, $branch_id, $email, $booking_detail_id, $self) { // ✅ add $self

        fastcgi_finish_request();

        log_message('info', 'SHUTDOWN FIRED dispatch');

        $branches = BranchRegistry::all();
        $config   = $branches[$branch_id] ?? null;
        if (empty($config)) {
            return;
        }

        $db      = db_connect($config['db']);
        $booking = (new \App\Models\Booking($db))->get_by_id($booking_id);
        if (empty($booking)) {
            return;
        }

        $booking->patient_email = $email;

        $booking_details = $branch_id == BRANCH_MOBILE
            ? new \App\Models\Booking_details_mobile($db)
            : new \App\Models\Booking_details($db);

        $self->_send_email($booking, $branch_id, $booking_details, $booking_detail_id, $db);
    });
}
