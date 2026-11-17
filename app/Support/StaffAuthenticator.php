<?php

namespace App\Support;

use App\Models\User;
use App\Services\StaffEmailVerificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Staff login against database staff tables, with demo/config fallback for UI-phase tests.
 */
final class StaffAuthenticator
{
    /**
     * pending_verification is the account when the password was correct but its email is not verified yet
     * (Batch 1). No session is established for such an account; the caller starts email verification.
     *
     * @return array{user: User|null, via: 'database'|'demo'|null, must_change_password: bool, pending_verification: User|null}
     */
    public static function attempt(string $identity, string $password): array
    {
        self::clearIdentityBeforeLogin();

        $failure = ['user' => null, 'via' => null, 'must_change_password' => false, 'pending_verification' => null];

        $identityKey = self::normalizeIdentity($identity);
        if ($identityKey === '' || $password === '') {
            return $failure;
        }

        if (UserManagementErdMode::isActive()) {
            $erdResult = self::attemptStaffTable($identityKey, $password);
            if ($erdResult['via'] !== null) {
                return $erdResult;
            }

            return [...$failure, 'pending_verification' => $erdResult['pending_verification']];
        }

        if (Schema::hasTable('users')) {
            $legacyResult = self::attemptStaffTable($identityKey, $password);
            if ($legacyResult['via'] !== null) {
                return $legacyResult;
            }
            if ($legacyResult['identity_matched']) {
                return [...$failure, 'pending_verification' => $legacyResult['pending_verification']];
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
            'pending_verification' => null,
        ];
    }

    /**
     * @return array{
     *     user: User|null,
     *     via: 'database'|null,
     *     must_change_password: bool,
     *     identity_matched: bool,
     *     pending_verification: User|null
     * }
     */
    private static function attemptStaffTable(string $identityKey, string $password): array
    {
        $failure = [
            'user' => null,
            'via' => null,
            'must_change_password' => false,
            'identity_matched' => false,
            'pending_verification' => null,
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

        // Batch 1: correct password but email ownership not yet proven — never establish a session.
        if (app(StaffEmailVerificationService::class)->isPending($user)) {
            return [
                ...$failure,
                'identity_matched' => true,
                'pending_verification' => $user,
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
            'pending_verification' => null,
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
