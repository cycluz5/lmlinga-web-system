<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Persistent Email OTP verification attempt / lockout tracking.
 * Uses Cache (file/redis/database) keyed by account + request so the lock
 * survives refresh, new tabs, and logout/login. Resend must not clear this.
 */
final class HouseholdEmailOtpAttemptGuard
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_SECONDS = 120;

    public function isLocked(int $accountId, int $requestId): bool
    {
        return $this->remainingLockSeconds($accountId, $requestId) > 0;
    }

    public function remainingLockSeconds(int $accountId, int $requestId): int
    {
        $until = Cache::get($this->lockKey($accountId, $requestId));

        if (! is_numeric($until)) {
            return 0;
        }

        $remaining = (int) $until - time();

        return $remaining > 0 ? $remaining : 0;
    }

    public function failureCount(int $accountId, int $requestId): int
    {
        return max(0, (int) Cache::get($this->attemptsKey($accountId, $requestId), 0));
    }

    public function attemptsRemainingBeforeLock(int $accountId, int $requestId): int
    {
        return max(0, self::MAX_ATTEMPTS - $this->failureCount($accountId, $requestId));
    }

    /**
     * Record one failed verification. When count reaches MAX_ATTEMPTS, start the lock window.
     *
     * @return array{locked: bool, failures: int, remaining_attempts: int, lock_seconds: int}
     */
    public function recordFailure(int $accountId, int $requestId): array
    {
        if ($this->isLocked($accountId, $requestId)) {
            return [
                'locked' => true,
                'failures' => $this->failureCount($accountId, $requestId),
                'remaining_attempts' => 0,
                'lock_seconds' => $this->remainingLockSeconds($accountId, $requestId),
            ];
        }

        $failures = $this->failureCount($accountId, $requestId) + 1;
        Cache::put($this->attemptsKey($accountId, $requestId), $failures, now()->addDay());

        if ($failures >= self::MAX_ATTEMPTS) {
            $until = time() + self::LOCK_SECONDS;
            Cache::put($this->lockKey($accountId, $requestId), $until, self::LOCK_SECONDS);

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

    public function clear(int $accountId, int $requestId): void
    {
        Cache::forget($this->attemptsKey($accountId, $requestId));
        Cache::forget($this->lockKey($accountId, $requestId));
    }

    private function attemptsKey(int $accountId, int $requestId): string
    {
        return "household.email_otp.verify.attempts.{$accountId}.{$requestId}";
    }

    private function lockKey(int $accountId, int $requestId): string
    {
        return "household.email_otp.verify.locked_until.{$accountId}.{$requestId}";
    }
}
