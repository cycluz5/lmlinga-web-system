<?php

namespace App\Services;

use App\Models\RecordRequestOtp;

/**
 * In-memory result of OTP issuance. Plaintext is never persisted.
 */
final readonly class IssuedHouseholdRecordOtp
{
    public function __construct(
        public RecordRequestOtp $otp,
        public ?string $plaintext,
        public bool $reused,
    ) {}
}
