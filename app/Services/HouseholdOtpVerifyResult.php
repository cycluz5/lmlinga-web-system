<?php

namespace App\Services;

final class HouseholdOtpVerifyResult
{
    public const SAFE_INVALID_MESSAGE = 'The verification code is incorrect.';

    public const SAFE_FAILURE_MESSAGE = 'Verification could not be completed. Please try again.';

    public function __construct(
        public readonly bool $ok,
        public readonly string $message = '',
        public readonly string $reason = '',
        public readonly int $lockSecondsRemaining = 0,
        public readonly int $attemptsRemaining = 0,
    ) {}

    public static function success(): self
    {
        return new self(true);
    }

    public static function invalid(): self
    {
        return new self(false, self::SAFE_INVALID_MESSAGE, 'invalid');
    }

    public static function invalidWithAttemptsRemaining(int $remaining): self
    {
        // Attempt counts are tracked server-side only; residents see a fixed safe message.
        return self::invalid();
    }

    public static function locked(int $secondsRemaining): self
    {
        $secondsRemaining = max(0, $secondsRemaining);
        $minutes = intdiv($secondsRemaining, 60);
        $seconds = $secondsRemaining % 60;
        $label = sprintf('%02d:%02d', $minutes, $seconds);

        return new self(
            false,
            'You have reached the maximum number of attempts. Please try again in '.$label.'.',
            'locked',
            $secondsRemaining,
        );
    }

    public static function failed(string $reason): self
    {
        return new self(false, self::SAFE_FAILURE_MESSAGE, $reason);
    }
}
