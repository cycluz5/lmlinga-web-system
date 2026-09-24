<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Persistent SMS OTP verification attempt / lockout tracking.
 * Separate from Email so channel locks never collide.
 * Resend must not clear this.
 */
final class HouseholdOtpAttemptGuard
{
    public const CHANNEL_SMS = 'sms';

    public const MAX_ATTEMPTS = 5;

    public const LOCK_SECONDS = 120;

    public function isLocked(int $accountId, int $requestId, string $channel = self::CHANNEL_SMS): bool
    {
        return $this->remainingLockSeconds($accountId, $requestId, $channel) > 0;
    }

    public function remainingLockSeconds(int $accountId, int $requestId, string $channel = self::CHANNEL_SMS): int
    {
        $until = Cache::get($this->lockKey($accountId, $requestId, $channel));

        if (! is_numeric($until)) {
            return 0;
        }

        $remaining = (int) $until - time();

        return $remaining > 0 ? $remaining : 0;
    }

    public function failureCount(int $accountId, int $requestId, string $channel = self::CHANNEL_SMS): int
    {
        return max(0, (int) Cache::get($this->attemptsKey($accountId, $requestId, $channel), 0));
    }

    /**
     * @return array{locked: bool, failures: int, remaining_attempts: int, lock_seconds: int}
     */
    public function recordFailure(int $accountId, int $requestId, string $channel = self::CHANNEL_SMS): array
    {
        $channel = $this->normalizeChannel($channel);

        if ($this->isLocked($accountId, $requestId, $channel)) {
            return [
                'locked' => true,
                'failures' => $this->failureCount($accountId, $requestId, $channel),
                'remaining_attempts' => 0,
                'lock_seconds' => $this->remainingLockSeconds($accountId, $requestId, $channel),
            ];
        }

        $failures = $this->failureCount($accountId, $requestId, $channel) + 1;
        Cache::put($this->attemptsKey($accountId, $requestId, $channel), $failures, now()->addDay());

        if ($failures >= self::MAX_ATTEMPTS) {
            $until = time() + self::LOCK_SECONDS;
            Cache::put($this->lockKey($accountId, $requestId, $channel), $until, self::LOCK_SECONDS);

            return [
                'locked' => true,
                'failures' => $failures,
                'remaining_attempts' => 0,
                'lock_seconds' => self::LOCK_SECONDS,
            ];
        }

        return [
            'locked' => false,
            'failures' => $failures,
            'remaining_attempts' => self::MAX_ATTEMPTS - $failures,
            'lock_seconds' => 0,
        ];
    }

    public function clear(int $accountId, int $requestId, string $channel = self::CHANNEL_SMS): void
    {
        $channel = $this->normalizeChannel($channel);
        Cache::forget($this->attemptsKey($accountId, $requestId, $channel));
        Cache::forget($this->lockKey($accountId, $requestId, $channel));
    }

    private function normalizeChannel(string $channel): string
    {
        return strtolower(trim($channel)) === self::CHANNEL_SMS
            ? self::CHANNEL_SMS
            : self::CHANNEL_SMS;
    }

    private function attemptsKey(int $accountId, int $requestId, string $channel): string
    {
        $channel = $this->normalizeChannel($channel);

        return "household.otp.verify.attempts.{$channel}.{$accountId}.{$requestId}";
    }

    private function lockKey(int $accountId, int $requestId, string $channel): string
    {
        $channel = $this->normalizeChannel($channel);

        return "household.otp.verify.locked_until.{$channel}.{$accountId}.{$requestId}";
    }
}
