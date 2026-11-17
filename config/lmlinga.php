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
    | Refinement item 1: reject health-worker emails whose domain has no mail server (MX/A lookup) when an
    | account is created or its email changed. Set LMLINGA_STAFF_EMAIL_DNS_CHECK=false only for offline demos.
    */
    'staff_email_dns_check' => (bool) env('LMLINGA_STAFF_EMAIL_DNS_CHECK', true),

    /*
    | For the domains below, also ask the provider's mail server whether the exact mailbox exists (SMTP RCPT TO,
    | no email is sent). An unanswerable check (port 25 blocked, timeout) accepts the address; the emailed code
    | still has to be entered before first use. Set LMLINGA_STAFF_EMAIL_MAILBOX_PROBE=false to switch it off.
    */
    /*
    | Reject placeholder mobile numbers (09000000000, 09123456789, ...) on health-worker accounts. The Philippine
    | mobile format itself is always required. Set LMLINGA_STAFF_MOBILE_DUMMY_CHECK=false to switch the placeholder test off.
    */
    'staff_mobile_dummy_check' => (bool) env('LMLINGA_STAFF_MOBILE_DUMMY_CHECK', true),

    'staff_email_mailbox_probe' => (bool) env('LMLINGA_STAFF_EMAIL_MAILBOX_PROBE', true),
    'staff_email_probe_domains' => ['gmail.com', 'googlemail.com'],

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
    | treated as inside the barangay (fail closed). Batch 5 (SM-03): new and
    | changed Spot Mapping / household coordinates are rejected on the server
    | when outside this boundary (App\Rules\WithinBarangayBoundary).
    | LMLINGA_GEOFENCE_ENFORCE=false is the rollback switch.
    |
    */

    'geofence' => [
        'boundary_path' => env('LMLINGA_BOUNDARY_PATH', base_path('resources/geo/la-medalla-boundary.geojson')),
        'enforce' => (bool) env('LMLINGA_GEOFENCE_ENFORCE', true),
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

    /*
     | Batch 1 — health-worker email ownership verification. Admin-created staff accounts stay unusable until
     | the worker enters a 6-digit code emailed to the account address (fixed 5-minute expiry,
     | StaffEmailVerificationService::EXPIRY_MINUTES). Accounts that existed before this feature are not
     | affected. enabled=false restores immediate use of newly created accounts (rollback switch).
     */
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

    'resident_email_verification' => [
        'enabled' => (bool) env('LMLINGA_RESIDENT_EMAIL_VERIFICATION', true),
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
        'max_resends' => 3,
        'pending_registration_minutes' => 30,
        // Batch 6 two-step registration: minutes allowed for step 2 (name + zone) after the email is verified.
        'details_minutes' => 30,
        'emails_per_hour_per_address' => 5,
        // Named rate limiters (AppServiceProvider), per resident account or IP.
        'send_per_10_minutes' => (int) env('LMLINGA_EMAIL_OTP_SEND_PER_10_MINUTES', 5),
        'verify_per_10_minutes' => (int) env('LMLINGA_EMAIL_OTP_VERIFY_PER_10_MINUTES', 10),
    ],

    /*
     | Batch 6 — the resident chatbot uses the Bikol-Iriga terms a Linguist approved (Language Management) as a
     | glossary in its prompt. Only approved, non-retired terms; no change while none are approved.
     | LMLINGA_CHATBOT_APPROVED_TERMS=false turns it off (rollback).
     */
    'chatbot' => [
        'approved_terms_in_prompt' => (bool) env('LMLINGA_CHATBOT_APPROVED_TERMS', true),
        'approved_terms_max' => 80,

        // Admin saves publish a term directly (no Linguist review). LMLINGA_TERMS_REQUIRE_REVIEW=true restores
        // the review step: saves become drafts that a Linguist must approve.
        'terms_require_review' => (bool) env('LMLINGA_TERMS_REQUIRE_REVIEW', false),
    ],

    /*
     | SR-07 chatbot dataset uploads (Admin) reviewed by a Linguist. Batch 6: approved datasets are used by
     | `chatbot:compare` Approach B (context-based, approved datasets only). The resident chatbot's corpus
     | (resources/health_docs) is unchanged; indexing approved datasets into it needs a separate approval.
     */
    'chatbot_datasets' => [
        'max_kilobytes' => 100,
        'min_characters' => 200,
        'max_line_length' => 5000,
        'uploads_per_hour' => (int) env('LMLINGA_DATASET_UPLOADS_PER_HOUR', 20),

        // Admin saves publish directly (no Linguist review). LMLINGA_DATASETS_REQUIRE_REVIEW=true restores the
        // review step: saves become drafts that a Linguist must approve.
        'require_review' => (bool) env('LMLINGA_DATASETS_REQUIRE_REVIEW', false),

        // Where published datasets are written for the chatbot: <path>/<language>/<category>/<source>.txt.
        // Outside resources/ so deploys never overwrite it; the chatbot reads this folder as well as resources/health_docs.
        'admin_docs_path' => storage_path('app/health_docs_admin'),
    ],

];
