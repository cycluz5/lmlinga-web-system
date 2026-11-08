<?php

namespace App\Services;

/**
 * Outcome of local OTP issue + Semaphore delivery for a household record request.
 * Never carries OTP plaintext, api_key, or raw provider payloads.
 */
final readonly class HouseholdOtpSmsDeliveryResult
{
    public const SAFE_FAILURE_MESSAGE = 'We could not send the verification code right now. Please try again later.';

    private function __construct(
        public bool $sent,
        public bool $alreadySent,
        public ?string $providerMessageId,
        public string $failureCategory,
    ) {}

    public static function sent(?string $providerMessageId): self
    {
        return new self(true, false, $providerMessageId, SemaphoreSmsSendResult::CATEGORY_QUEUED);
    }

    public static function alreadySent(): self
    {
        return new self(true, true, null, SemaphoreSmsSendResult::CATEGORY_QUEUED);
    }

    public static function failed(string $category): self
    {
        return new self(false, false, null, $category);
    }

    public function residentMessage(): string
    {
        if ($this->sent) {
            return '';
        }

        return self::SAFE_FAILURE_MESSAGE;
    }
}
