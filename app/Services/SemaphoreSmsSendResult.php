<?php

namespace App\Services;

/**
 * Application-owned Semaphore SMS send outcome.
 * Never carries api_key or OTP plaintext.
 */
final readonly class SemaphoreSmsSendResult
{
    public const CATEGORY_QUEUED = 'queued';

    public const CATEGORY_CONFIGURATION = 'configuration';

    public const CATEGORY_HTTP = 'http';

    public const CATEGORY_PROVIDER = 'provider';

    public const CATEGORY_MALFORMED = 'malformed';

    public const CATEGORY_NETWORK = 'network';

    public const CATEGORY_TIMEOUT = 'timeout';

    private function __construct(
        public bool $queued,
        public ?string $messageId,
        public string $failureCategory,
        public ?int $httpStatus,
    ) {}

    public static function queued(string $messageId, ?int $httpStatus = 200): self
    {
        return new self(true, $messageId, self::CATEGORY_QUEUED, $httpStatus);
    }

    public static function failed(string $category, ?int $httpStatus = null): self
    {
        return new self(false, null, $category, $httpStatus);
    }
}
