<?php

$enabledRaw = env('LMLINGA_AT_REST_ENABLED');
if ($enabledRaw === null || $enabledRaw === '') {
    $atRestEnabled = true;
} elseif (is_bool($enabledRaw)) {
    $atRestEnabled = $enabledRaw;
} else {
    $normalized = strtolower(trim((string) $enabledRaw));
    $atRestEnabled = ! in_array($normalized, ['false', '0', 'off', 'no'], true);
}

return [

    /*
    | Opaque ids in URLs: household / member / resident / request ids are shown as keyed codes
    | and raw ids in those positions are rejected (404). Set OPAQUE_URLS=false only to roll back.
    */
    'opaque_urls' => (bool) env('OPAQUE_URLS', true),

    /*
    | Health-worker account checks (admin User Management, create and edit):
    | - staff_email_dns_check: reject emails whose domain has no mail server (MX/A lookup).
    | - staff_email_mailbox_probe: for the domains below, also ask the provider's mail server whether the exact
    |   mailbox exists (SMTP RCPT TO, no email is sent). An unanswerable check accepts the address.
    | - staff_mobile_dummy_check: reject placeholder mobile numbers (09000000000, 09123456789, ...). The Philippine
    |   mobile format itself is always required.
    | Each can be switched off with its LMLINGA_STAFF_* environment variable.
    */
    'staff_email_dns_check' => (bool) env('LMLINGA_STAFF_EMAIL_DNS_CHECK', true),
    'staff_email_mailbox_probe' => (bool) env('LMLINGA_STAFF_EMAIL_MAILBOX_PROBE', true),
    'staff_email_probe_domains' => ['gmail.com', 'googlemail.com'],
    'staff_mobile_dummy_check' => (bool) env('LMLINGA_STAFF_MOBILE_DUMMY_CHECK', true),

    /*
     | Health-worker email ownership verification. Admin-created staff accounts stay unusable until the worker
     | enters a 6-digit code emailed to the account address (fixed 5-minute expiry,
     | StaffEmailVerificationService::EXPIRY_MINUTES). Accounts that existed before this feature are not
     | affected. enabled=false restores immediate use of newly created accounts (rollback switch).
     */
    'resident_registration_otp' => [
        // When off, /chatbot/register creates the account immediately (no email code).
        'enabled' => (bool) env('LMLINGA_RESIDENT_REGISTRATION_OTP', true),
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
        'max_sends_per_hour' => 5,
        'pending_session_minutes' => 30,
        // Named rate limiters (AppServiceProvider), per IP + pending email.
        'send_per_10_minutes' => (int) env('LMLINGA_RESIDENT_OTP_SEND_PER_10_MINUTES', 5),
        'verify_per_10_minutes' => (int) env('LMLINGA_RESIDENT_OTP_VERIFY_PER_10_MINUTES', 10),
    ],

    'staff_email_verification' => [
        'enabled' => (bool) env('LMLINGA_STAFF_EMAIL_VERIFICATION', true),
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
        'max_sends_per_hour' => 5,
        'pending_session_minutes' => 30,
        'reverify_on_email_change' => (bool) env('LMLINGA_STAFF_REVERIFY_ON_EMAIL_CHANGE', true),
        // Named rate limiters (AppServiceProvider), per IP + pending account.
        'send_per_10_minutes' => (int) env('LMLINGA_STAFF_EMAIL_OTP_SEND_PER_10_MINUTES', 5),
        'verify_per_10_minutes' => (int) env('LMLINGA_STAFF_EMAIL_OTP_VERIFY_PER_10_MINUTES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | LMLinga at-rest field encryption (AES-256-GCM)
    |--------------------------------------------------------------------------
    |
    | Dedicated clinical-field crypto. Never reuse APP_KEY or Laravel Crypt.
    | LMLINGA_AT_REST_ENABLED defaults to true. false is an explicit emergency
    | write-disable only; missing/invalid keys must never silently store plaintext.
    |
    */

    'at_rest' => [
        'enabled' => $atRestEnabled,
        'cipher' => 'aes-256-gcm',
        'version' => 'v2',
        'nonce_bytes' => 12,
        'tag_bytes' => 16,
        'key' => env('LMLINGA_AT_REST_KEY'),
        'key_id' => env('LMLINGA_AT_REST_KEY_ID', 'k1'),
        'previous_keys' => env('LMLINGA_AT_REST_PREVIOUS_KEYS', ''),
        'display_unavailable' => '[Decryption unavailable]',
    ],

];
