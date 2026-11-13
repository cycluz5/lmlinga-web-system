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

    /*
    |--------------------------------------------------------------------------
    | Resident household linking
    |--------------------------------------------------------------------------
    |
    | staff_verified (default): a matched request waits for an Admin to verify the
    | resident's identity and mobile number; only then is an SMS OTP sent, and only
    | to that staff-verified number. Email OTP is not offered for linking because
    | resident account emails are never verified. legacy: the original automatic
    | flow (OTP to the requester-supplied number). Use legacy only as an emergency
    | rollback — it re-opens unverified household linking.
    |
    */

    'household_linking' => [
        'mode' => env('LMLINGA_HOUSEHOLD_LINKING_MODE', 'staff_verified'),
        'max_requests_per_account_per_day' => (int) env('LMLINGA_LINKING_MAX_REQUESTS_PER_ACCOUNT', 3),
        'max_requests_per_household_per_day' => (int) env('LMLINGA_LINKING_MAX_REQUESTS_PER_HOUSEHOLD', 5),
        // Named rate limiters (AppServiceProvider). Each limiter has its own key, so limits never
        // share a counter. Resident limits are per resident account (IP only before login).
        'submit_per_hour' => (int) env('LMLINGA_LINKING_SUBMIT_PER_HOUR', 10),
        'sms_send_per_10_minutes' => (int) env('LMLINGA_LINKING_SMS_SEND_PER_10_MINUTES', 5),
        'register_per_hour_per_ip' => (int) env('LMLINGA_REGISTER_PER_HOUR_PER_IP', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resident email verification (panel refinement SR-03)
    |--------------------------------------------------------------------------
    |
    | A resident account is created only after a 6-digit code emailed to the
    | address is entered (5-minute expiry, same as the household OTP). Existing
    | accounts keep working; they verify once before submitting a new household
    | record request. enabled=false restores immediate registration (rollback).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Barangay boundary (panel refinement SR-11, step SM-01)
    |--------------------------------------------------------------------------
    |
    | Official Barangay La Medalla boundary as a GeoJSON Feature (Polygon or
    | MultiPolygon, [longitude, latitude], properties.source required). Only
    | authoritative data may be placed here — see resources/geo/README.md.
    | Until a valid file exists, Spot Mapping shows a warning and no point is
    | treated as inside the barangay (fail closed). Coordinate enforcement on
    | save is a later step (SM-03) and is not active.
    |
    */

    'geofence' => [
        'boundary_path' => env('LMLINGA_BOUNDARY_PATH', base_path('resources/geo/la-medalla-boundary.geojson')),
    ],

    /*
     | SR-10 Demo Location Mode (Phase A foundation). OFF by default; enable only on the demonstration
     | deployment. Demo points come from a reviewed file of verified coordinates (none exists yet) and are
     | used only when the official boundary (above) is valid and contains them. Admin-only, per session,
     | at most 2 hours; simulated coordinates are never saved.
     */
    'demo_location' => [
        'enabled' => (bool) env('LMLINGA_DEMO_LOCATION_ENABLED', false),
        'points_path' => env('LMLINGA_DEMO_LOCATION_POINTS_PATH', base_path('resources/geo/la-medalla-demo-points.json')),
        'ttl_minutes' => min(120, max(1, (int) env('LMLINGA_DEMO_LOCATION_TTL_MINUTES', 120))),
    ],

    'resident_email_verification' => [
        'enabled' => (bool) env('LMLINGA_RESIDENT_EMAIL_VERIFICATION', true),
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
        'max_resends' => 3,
        'pending_registration_minutes' => 30,
        'emails_per_hour_per_address' => 5,
        // Named rate limiters (AppServiceProvider), per resident account or IP.
        'send_per_10_minutes' => (int) env('LMLINGA_EMAIL_OTP_SEND_PER_10_MINUTES', 5),
        'verify_per_10_minutes' => (int) env('LMLINGA_EMAIL_OTP_VERIFY_PER_10_MINUTES', 10),
    ],

    /*
     | SR-07 chatbot dataset uploads (Admin) reviewed by a Linguist. Approved datasets are NOT used by
     | the chatbot yet: indexing/activation needs a separate approval.
     */
    'chatbot_datasets' => [
        'max_kilobytes' => 100,
        'min_characters' => 200,
        'max_line_length' => 5000,
        'uploads_per_hour' => (int) env('LMLINGA_DATASET_UPLOADS_PER_HOUR', 20),
    ],

];
