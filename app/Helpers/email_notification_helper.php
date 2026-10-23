<?php

/**
 * Email notification recipient resolution helpers.
 * Implementations live in app/Common.php; this file is a no-op if already loaded.
 */

if (!defined('ROLE_SALES_AE')) {
    define('ROLE_SALES_AE', 19);
    define('ROLE_SALES_MANAGER', 20);
    define('ROLE_SALES_DIRECTOR', 21);
    define('ROLE_BUSINESS_OPS', 22);
    define('ROLE_FINANCE_MANAGER', 23);
    define('ROLE_FINANCE_DIRECTOR', 26);
    define('ROLE_BDO', 32);
    define('ROLE_IT', 17);
    define('ENDORSEMENT_CORPORATE_EMAIL', 'endorsement.corporate@clinicaprime.com.ph');
}

if (! function_exists('normalize_email_list')) {
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
}

if (! function_exists('resolve_with_fallback')) {
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
}

if (! function_exists('split_to_and_cc')) {
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
}

if (! function_exists('resolve_immediate_head_email')) {
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
}

if (! function_exists('resolve_user_email_by_id')) {
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
}

if (! function_exists('resolve_emails_by_role')) {
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
}

if (! function_exists('resolve_request_added_by_email')) {
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
}

if (! function_exists('resolve_user_fallback_emails')) {
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
}

if (! function_exists('dispatch_email_notification')) {
    /**
     * Dispatch notification using primary recipients with fallback.
     */
    function dispatch_email_notification($subject, $message, array $primaryEmails, array $fallbackEmails): bool
    {
        $recipients = resolve_with_fallback($primaryEmails, $fallbackEmails);
        if (empty($recipients)) {
            return false;
        }

        [$to, $cc] = split_to_and_cc($recipients);

        return send_email_to_many($subject, $message, $to, $cc);
    }
}
