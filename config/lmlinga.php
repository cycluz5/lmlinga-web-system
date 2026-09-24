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
