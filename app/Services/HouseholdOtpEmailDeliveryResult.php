<?php

namespace App\Services;

/**
 * Outcome of local OTP issue + Laravel Mail delivery for a household record request.
 * Never carries OTP plaintext or mail credentials.
 */
final readonly class HouseholdOtpEmailDeliveryResult
{
    public const SAFE_FAILURE_MESSAGE = 'We could not send the verification code right now. Please try again later.';

    private function __construct(
        public bool $sent,
        public bool $alreadySent,
        public string $failureCategory,
    ) {}

    public static function sent(): self
    {
        return new self(true, false, 'sent');
    }

    public static function alreadySent(): self
    {
        return new self(true, true, 'already_sent');
    }

    public static function failed(string $category): self
    {
        return new self(false, false, $category);
    }
}
