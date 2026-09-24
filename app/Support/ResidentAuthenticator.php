<?php

namespace App\Support;

use App\Models\ResidentAccount;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Resident chatbot login against resident_accounts.
 * Does not use the staff web guard. Passwords are never logged.
 */
final class ResidentAuthenticator
{
    public const SESSION_ACCOUNT_ID = 'lml.resident_account_id';

    public const SESSION_EMAIL = 'lml.resident_email';

    public const SESSION_LOGIN_ESTABLISHED = 'lml.resident_login_established';

    /**
     * @return array{account: ResidentAccount|null, via: 'database'|null}
     */
    public static function attempt(string $identity, string $password): array
    {
        $failure = ['account' => null, 'via' => null];
        $identityKey = LoginAttemptLockout::normalizeIdentity($identity);

        if ($identityKey === '' || $password === '') {
            return $failure;
        }

        $table = (new ResidentAccount)->getTable();
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'email')) {
            return $failure;
        }

        $account = ResidentAccount::query()
            ->whereRaw('LOWER(email) = ?', [$identityKey])
            ->first();

        if ($account === null || ! Hash::check($password, (string) $account->password)) {
            return $failure;
        }

        self::establishSession($account);

        return [
            'account' => $account,
            'via' => 'database',
        ];
    }

    public static function establishSession(ResidentAccount $account): void
    {
        session([
            self::SESSION_ACCOUNT_ID => $account->getKey(),
            self::SESSION_EMAIL => (string) $account->email,
            self::SESSION_LOGIN_ESTABLISHED => true,
        ]);
    }

    public static function clearSession(): void
    {
        session()->forget([
            self::SESSION_ACCOUNT_ID,
            self::SESSION_EMAIL,
            self::SESSION_LOGIN_ESTABLISHED,
        ]);
    }
}
