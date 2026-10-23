<?php

/**
 * Pre-framework checks. Must not depend on vendor/ or CI services.
 */

function bootstrap_normalize_origin(string $origin): string
{
    return rtrim(trim($origin, " \t\"'/"), '/');
}

function bootstrap_allowed_origins(): array
{
    $raw = '';
    if (! empty($_ENV['CORS_ALLOWED_ORIGINS'])) {
        $raw = (string) $_ENV['CORS_ALLOWED_ORIGINS'];
    } elseif (getenv('CORS_ALLOWED_ORIGINS') !== false) {
        $raw = (string) getenv('CORS_ALLOWED_ORIGINS');
    } elseif (! empty($_ENV['FRONT_END_URL'])) {
        $raw = (string) $_ENV['FRONT_END_URL'];
    }

    $origins = [
        'https://business-ops.myt-emr.com',
        'https://accounting-front.myt-emr.com',
        'https://zuat-accounting-front.myt-emr.com',
        'https://corporate-portal.myt-emr.com',
        'https://diagnostic-front.myt-emr.com',
        'https://zuat-diagnostic-front.myt-emr.com',
        'https://diagnostic-oakridge.myt-emr.com',
        'https://zuat-diagnostic-oakridge.myt-emr.com',
        'https://diagnostic-consolacion.myt-emr.com',
        'https://zuat-diagnostic-consolacion.myt-emr.com',
        'https://diagnostic-mactan.myt-emr.com',
        'https://zuat-diagnostic-mactan.myt-emr.com',
        'https://diagnostic-mantlewood.myt-emr.com',
        'https://zuat-diagnostic-mantlewood.myt-emr.com',
        'https://diagnostic-mobile.myt-emr.com',
        'https://zuat-diagnostic-mobile.myt-emr.com',
        'https://emr-mobile.myt-emr.com',
        'https://zuat-emr-mobile.myt-emr.com',
        'https://emr-yakap.myt-emr.com',
        'https://zuat-emr-yakap.myt-emr.com',
        'https://emr-yakap-mantlewood.myt-emr.com',
        'https://zuat-emr-yakap-mantlewood.myt-emr.com',
        'https://emr-yakap-consolacion.myt-emr.com',
        'https://zuat-emr-yakap-consolacion.myt-emr.com',
        'https://emr-yakap-oakridge.myt-emr.com',
        'https://zuat-emr-yakap-oakridge.myt-emr.com',
        'https://emr-yakap-mactan.myt-emr.com',
        'https://zuat-emr-yakap-mactan.myt-emr.com',
        'https://registration-consolacion.myt-emr.com',
        'https://zuat-registration-consolacion.myt-emr.com',
        'https://registration-mactan.myt-emr.com',
        'https://zuat-registration-mactan.myt-emr.com',
        'https://registration-mantlewood.myt-emr.com',
        'https://zuat-registration-mantlewood.myt-emr.com',
        'https://registration-oakridge.myt-emr.com',
        'https://zuat-registration-oakridge.myt-emr.com',
        'https://registration-front.myt-emr.com',
        'https://zuat-registration-front.myt-emr.com',
        'http://192.168.0.122:3000/',
        'http://192.168.0.114:3000/',
    ];
    foreach (explode(',', $raw) as $origin) {
        $normalized = bootstrap_normalize_origin($origin);
        if ($normalized !== '') {
            $origins[] = $normalized;
        }
    }

    return array_values(array_unique($origins));
}

function bootstrap_attach_options_cors(): void
{
    $origin = '';
    if (! empty($_SERVER['HTTP_ORIGIN'])) {
        $origin = bootstrap_normalize_origin((string) $_SERVER['HTTP_ORIGIN']);
    }
    if ($origin === '' || ! in_array($origin, bootstrap_allowed_origins(), true)) {
        return;
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, PATCH, DELETE');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, api-key, API-KEY, Origin-API-Key, latitude, longitude, X-Cron-Secret');
    header('Access-Control-Max-Age: 86400');
}

/** Read only the named setting when DotEnv/framework services are not available yet. */
function bootstrap_environment_value(string $key, string $root = '', $default = null)
{
    foreach ([$_ENV, $_SERVER] as $values) {
        if (array_key_exists($key, $values)) {
            return $values[$key];
        }
    }
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }
    $env_file = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . '.env';
    if ($root !== '' && is_readable($env_file)) {
        $source = @file_get_contents($env_file);
        if (is_string($source) && preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*?)\s*$/m', $source, $matches)) {
            $value = preg_replace('/\s+#.*$/', '', $matches[1]);
            return trim($value, " \t\r\n\"'");
        }
    }

    return $default;
}

function bootstrap_error_details_enabled(string $root = ''): bool
{
    return filter_var(bootstrap_environment_value('ERROR_RESPONSE_DETAILS', $root, false), FILTER_VALIDATE_BOOLEAN);
}

/** Format messages without query text, configured credentials, or bound database values. */
function bootstrap_redact_error(string $message, string $root = ''): string
{
    $values = array_merge(getenv() ?: [], $_SERVER, $_ENV, get_defined_constants());
    $env_file = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . '.env';
    if ($root !== '' && is_readable($env_file)) {
        $source = @file_get_contents($env_file);
        if (is_string($source)) {
            preg_match_all('/^\s*([A-Za-z_][A-Za-z0-9_.-]*)\s*=\s*(.*?)\s*$/m', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if (!array_key_exists($match[1], $values)) {
                    $values[$match[1]] = trim($match[2], " \t\r\n\"'");
                }
            }
        }
    }
    $secrets = [];
    foreach ($values as $key => $value) {
        if (preg_match('/password|passwd|secret|token|api[_-]?keys?|encryption[_.]?key|authorization|credential|username|(?:database|db|mysql)[_.-].*user/i', (string) $key)
            && is_scalar($value)) {
            foreach (array_merge([(string) $value], explode(',', (string) $value)) as $secret) {
                $secret = trim($secret);
                if (strlen($secret) >= 4) {
                    $secrets[] = $secret;
                }
            }
        }
    }
    usort($secrets, static fn($left, $right) => strlen($right) <=> strlen($left));
    $message = str_replace(array_unique($secrets), '[REDACTED]', $message);
    $message = preg_replace_callback('/\b(password|passwd|token|api[_-]?key|authorization|secret)\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|\S+)/i',
        static fn($matches) => $matches[1] . '=[REDACTED]', $message);
    $message = preg_replace('/\bBearer\s+\S+/i', 'Bearer [REDACTED]', $message);
    $message = preg_replace('/(Access denied for user\s+)\'[^\r\n]*\'(\s*\(using password[:=])/i', '$1\'[REDACTED]\'$2', $message);
    $message = preg_replace('~(\w+://)[^\s/@:]+:[^\s/@]+@~', '$1[REDACTED]@', $message);
    $message = preg_replace('/(Duplicate entry\s+)\'[^\r\n]*\'(\s+for key\b)/i', '$1\'[REDACTED]\'$2', $message);
    $message = preg_replace('/((?:Incorrect|Invalid|Truncated)\s+[^\r\n]*?value\s+)\'[^\r\n]*\'(\s+for column\b)/i', '$1\'[REDACTED]\'$2', $message);
    $message = preg_replace('/(near\s+)\'[^\r\n]*\'/i', '$1\'[REDACTED]\'', $message);
    $message = preg_replace('/(?:\bSQL(?:\s+Query)?\s*:|\bQuery\s*:|\bSELECT\s+.+?\s+FROM\b|\bINSERT\s+INTO\b|\bUPDATE\s+\S+\s+SET\b|\bDELETE\s+FROM\b|\b(?:ALTER|CREATE|DROP)\s+TABLE\b)[\s\S]*/i', '[SQL omitted]', $message);
    if ($root !== '') {
        $message = str_replace([rtrim($root, '/\\') . '/', rtrim($root, '/\\') . '\\'], '', $message);
    }

    return $message;
}

function bootstrap_error_payload(?Throwable $exception, string $reason, string $root = ''): array
{
    $file = null;
    if ($exception !== null) {
        $path = str_replace('\\', '/', $exception->getFile());
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $file = $root !== '' && str_starts_with($path, $prefix)
            ? substr($path, strlen($prefix)) : '[external]/' . basename($path);
    }

    return [
        'code' => 500,
        'message' => 'something went wrong',
        'error' => [
            'type' => $exception !== null ? get_class($exception) : 'BootstrapError',
            'message' => bootstrap_redact_error($exception !== null ? $exception->getMessage() : $reason, $root),
            'file' => $file,
            'line' => $exception !== null ? $exception->getLine() : null,
        ],
    ];
}

function bootstrap_log_path(string $root, ?DateTimeImmutable $logged_at = null): string
{
    $logged_at = ($logged_at ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('Asia/Manila'));

    return rtrim($root, '/\\') . '/writable/logs/log-' . $logged_at->format('Y-m-d') . '.log';
}

function bootstrap_write_error(string $root, string $message, ?DateTimeImmutable $logged_at = null): void
{
    static $writing = false;
    $message = bootstrap_redact_error($message, $root);
    if ($writing) {
        error_log($message);
        return;
    }
    $writing = true;
    try {
        if (function_exists('log_message')) {
            $timezone = date_default_timezone_get();
            try {
                date_default_timezone_set('Asia/Manila');
                if (log_message('error', $message) === true) {
                    return;
                }
            } catch (Throwable $logging_error) {
                // Keep the original failure and use the framework-independent daily writer.
            } finally {
                date_default_timezone_set($timezone);
            }
        }
        $path = bootstrap_log_path($root, $logged_at);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $logged_at = ($logged_at ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('Asia/Manila'));
        $new_file = !is_file($path);
        $written = @file_put_contents($path, 'ERROR - ' . $logged_at->format('Y-m-d H:i:s') . ' --> ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written !== false) {
            if ($new_file) {
                @chmod($path, 0644);
            }
            return;
        }
    } catch (Throwable $logging_error) {
        // Logging failure must not replace the original response.
    } finally {
        $writing = false;
    }
    error_log($message);
}

function bootstrap_fail(string $code, string $message, string $root = '', ?Throwable $exception = null): void
{
    $payload = bootstrap_error_payload($exception, $message, $root);
    $log_message = $code . ': ' . $payload['error']['type'] . ': ' . $payload['error']['message'];
    if ($payload['error']['file'] !== null) {
        $log_message .= ' in ' . $payload['error']['file'] . ' on line ' . $payload['error']['line'];
    }
    if ($root !== '') {
        bootstrap_write_error($root, $log_message);
    } else {
        error_log($log_message);
    }

    $isOptions = isset($_SERVER['REQUEST_METHOD'])
        && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'OPTIONS';
    if ($isOptions) {
        bootstrap_attach_options_cors();
        http_response_code(204);
        exit;
    }

    if (! headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        bootstrap_attach_options_cors();
    }

    echo json_encode(bootstrap_error_details_enabled($root) ? $payload : [
        'error'   => 'bootstrap_failed',
        'code'    => $code,
        'message' => $message,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit(1);
}

function bootstrap_fail_cli(string $code, string $message, string $root = ''): void
{
    if ($root !== '') {
        bootstrap_write_error($root, $code . ': ' . $message);
    } else {
        error_log($code . ': ' . $message);
    }

    fwrite(STDERR, $code . ': ' . $message . PHP_EOL);
    exit(1);
}

function bootstrap_ensure_php(string $min, $fail): void
{
    if (version_compare(PHP_VERSION, $min, '<')) {
        $fail(
            'php_version',
            'PHP ' . $min . ' or newer is required (CI 4.7). Current: ' . PHP_VERSION
        );
    }
}

function bootstrap_ensure_vendor(string $root, $fail): array
{
    $autoload = $root . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    $boot     = $root . 'vendor' . DIRECTORY_SEPARATOR . 'codeigniter4'
        . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'system'
        . DIRECTORY_SEPARATOR . 'Boot.php';

    if (! is_file($autoload) || ! is_file($boot)) {
        $fail(
            'vendor_missing',
            'Bootstrap failed: run composer install --no-dev in the document root.'
        );
    }

    return ['autoload' => $autoload, 'boot' => $boot];
}

function bootstrap_ensure_writable(string $root, $fail): void
{
    $writable = $root . 'writable';
    foreach (['logs', 'session', 'cache'] as $dir) {
        $path = $writable . DIRECTORY_SEPARATOR . $dir;
        if (! is_dir($path)) {
            @mkdir($path, 0775, true);
        }
    }

    if (! is_dir($writable) || ! is_writable($writable)) {
        $fail(
            'writable',
            'writable/ is missing or not writable. mkdir -p writable/logs writable/session writable/cache && chmod -R 775 writable'
        );
    }
}

function bootstrap_safe_root(string $appDirectory): string
{
    $candidate = rtrim($appDirectory, '\\/ ') . '/..';
    $resolved  = realpath($candidate);
    if ($resolved === false) {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR;
    }

    return $resolved . DIRECTORY_SEPARATOR;
}
