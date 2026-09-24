<?php

namespace App\Support;

use App\Models\ResidentAccount;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

/**
 * Temporary lockout after consecutive unsuccessful authentications.
 *
 * Worker and Resident counters are independent namespaces.
 * Identifiers are normalized; passwords are never stored.
 *
 * After 5 consecutive failures the identifier is locked for 15 minutes
 * from the fifth failure. A successful login before the limit clears
 * the counter. Correct credentials while locked are still rejected.
 */
final class LoginAttemptLockout
{
    public const MAX_ATTEMPTS = 5;

    /** Temporary lock duration after the fifth consecutive failure. */
    public const LOCK_SECONDS = 900;

    public const NAMESPACE_WORKER = 'worker';

    public const NAMESPACE_RESIDENT = 'resident';

    public const MESSAGE = 'Too many unsuccessful login attempts. Please try again in 15 minutes.';

    public const GENERIC_FAILURE = 'Invalid email or password. Please try again.';

    public static function isLocked(string $namespace, string $identity): bool
    {
        return RateLimiter::tooManyAttempts(self::lockKey($namespace, $identity), 1);
    }

    public static function recordFailure(string $namespace, string $identity): void
    {
        $attemptsKey = self::attemptsKey($namespace, $identity);
        RateLimiter::hit($attemptsKey, self::LOCK_SECONDS);

        if (RateLimiter::attempts($attemptsKey) >= self::MAX_ATTEMPTS) {
            RateLimiter::hit(self::lockKey($namespace, $identity), self::LOCK_SECONDS);
        }
    }

    public static function reset(string $namespace, string $identity): void
    {
        RateLimiter::clear(self::attemptsKey($namespace, $identity));
        RateLimiter::clear(self::lockKey($namespace, $identity));
    }

    public static function attemptsKey(string $namespace, string $identity): string
    {
        return 'login-lockout:'.$namespace.':attempts:'.self::canonicalIdentity($namespace, $identity);
    }

    public static function lockKey(string $namespace, string $identity): string
    {
        return 'login-lockout:'.$namespace.':lock:'.self::canonicalIdentity($namespace, $identity);
    }

    public static function normalizeIdentity(string $identity): string
    {
        return strtolower(trim($identity));
    }

    private static function canonicalIdentity(string $namespace, string $identity): string
    {
        $normalized = self::normalizeIdentity($identity);
        if ($normalized === '') {
            return 'ident:';
        }

        if ($namespace === self::NAMESPACE_WORKER) {
            $userId = self::existingWorkerKey($normalized);
            if ($userId !== null) {
                return 'id:'.$userId;
            }
        }

        if ($namespace === self::NAMESPACE_RESIDENT) {
            $accountId = self::existingResidentKey($normalized);
            if ($accountId !== null) {
                return 'id:'.$accountId;
            }
        }

        return 'ident:'.$normalized;
    }

    private static function existingWorkerKey(string $normalized): ?string
    {
        $table = (new User)->getTable();
        if (! Schema::hasTable($table)) {
            return null;
        }

        $user = User::query()
            ->where(function ($query) use ($normalized): void {
                $query->whereRaw('LOWER(email) = ?', [$normalized])
                    ->orWhereRaw('LOWER(username) = ?', [$normalized]);
            })
            ->first();

        return $user !== null ? (string) $user->getKey() : null;
    }

    private static function existingResidentKey(string $normalized): ?string
    {
        if (! Schema::hasTable((new ResidentAccount)->getTable())) {
            return null;
        }

        $account = ResidentAccount::query()
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->first();

        return $account !== null ? (string) $account->getKey() : null;
    }
}
