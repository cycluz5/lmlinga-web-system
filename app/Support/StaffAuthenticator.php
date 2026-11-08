<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Staff login against database staff tables, with demo/config fallback for UI-phase tests.
 */
final class StaffAuthenticator
{
    /**
     * @return array{user: User|null, via: 'database'|'demo'|null, must_change_password: bool}
     */
    public static function attempt(string $identity, string $password): array
    {
        self::clearIdentityBeforeLogin();

        $identityKey = self::normalizeIdentity($identity);
        if ($identityKey === '' || $password === '') {
            return ['user' => null, 'via' => null, 'must_change_password' => false];
        }

        $failure = ['user' => null, 'via' => null, 'must_change_password' => false];

        if (UserManagementErdMode::isActive()) {
            $erdResult = self::attemptStaffTable($identityKey, $password);
            if ($erdResult['via'] !== null) {
                return $erdResult;
            }

            return $failure;
        }

        if (Schema::hasTable('users')) {
            $legacyResult = self::attemptStaffTable($identityKey, $password);
            if ($legacyResult['via'] !== null) {
                return $legacyResult;
            }
            if ($legacyResult['identity_matched']) {
                return $failure;
            }
        }

        $demo = DemoStaffLogin::attempt($identity, $password);
        if ($demo === null) {
            return $failure;
        }

        DemoStaffLogin::establishSession($demo);

        return [
            'user' => null,
            'via' => 'demo',
            'must_change_password' => false,
        ];
    }

    /**
     * @return array{
     *     user: User|null,
     *     via: 'database'|null,
     *     must_change_password: bool,
     *     identity_matched: bool
     * }
     */
    private static function attemptStaffTable(string $identityKey, string $password): array
    {
        $failure = [
            'user' => null,
            'via' => null,
            'must_change_password' => false,
            'identity_matched' => false,
        ];

        $user = User::query()
            ->where(function ($query) use ($identityKey): void {
                $query->whereRaw('LOWER(email) = ?', [$identityKey])
                    ->orWhereRaw('LOWER(username) = ?', [$identityKey]);
            })
            ->first();

        if ($user === null) {
            return $failure;
        }

        if (! $user->isActive() || ! Hash::check($password, (string) $user->password)) {
            return [
                ...$failure,
                'identity_matched' => true,
            ];
        }

        Auth::login($user);
        $user->syncUiRoleSession();
        session([
            DemoStaffLogin::SESSION_DISPLAY_NAME => $user->composeDisplayName(),
            DemoStaffLogin::SESSION_EMAIL => (string) $user->email,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        return [
            'user' => $user,
            'via' => 'database',
            'must_change_password' => (bool) $user->must_change_password,
            'identity_matched' => true,
        ];
    }

    public static function logout(): void
    {
        Auth::logout();
        DemoStaffLogin::clearSession();
    }

    private static function clearIdentityBeforeLogin(): void
    {
        Auth::logout();
        DemoStaffLogin::clearSession();
    }

    private static function normalizeIdentity(string $value): string
    {
        return strtolower(trim($value));
    }
}
