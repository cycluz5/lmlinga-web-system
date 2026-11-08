<?php

namespace App\Support;

/**
 * UI-phase staff login against config/env demo accounts only.
 * Not production authentication. No database users.
 * Credentials are never loaded from a local PHP credential fixture file.
 */
final class DemoStaffLogin
{
    public const SESSION_DISPLAY_NAME = 'lml.demo_staff_name';

    public const SESSION_EMAIL = 'lml.demo_staff_email';

    /** Set only by a successful staff login — not by ?role= session seeding. */
    public const SESSION_LOGIN_ESTABLISHED = 'lml.staff_login_established';

    /**
     * @return list<array{
     *     email: string,
     *     password: string,
     *     shell_role: string,
     *     display_name: string,
     *     identities: list<string>
     * }>
     */
    public static function accounts(): array
    {
        $raw = config('demo.staff_accounts', []);
        if (! is_array($raw)) {
            return [];
        }

        $accounts = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $email = trim((string) ($row['email'] ?? ''));
            $password = (string) ($row['password'] ?? '');
            $shellRole = trim((string) ($row['shell_role'] ?? ''));
            $displayName = trim((string) ($row['display_name'] ?? ''));

            if ($email === '' || $password === '' || $shellRole === '') {
                continue;
            }

            $identities = $row['identities'] ?? [$email];
            if (! is_array($identities)) {
                $identities = [$email];
            }

            $normalizedIdentities = [];
            foreach ($identities as $identity) {
                $value = trim((string) $identity);
                if ($value !== '') {
                    $normalizedIdentities[] = $value;
                }
            }

            if ($normalizedIdentities === []) {
                $normalizedIdentities[] = $email;
            }

            $accounts[] = [
                'email' => $email,
                'password' => $password,
                'shell_role' => $shellRole,
                'display_name' => $displayName !== '' ? $displayName : $email,
                'identities' => array_values(array_unique($normalizedIdentities)),
            ];
        }

        return $accounts;
    }

    /**
     * @return array{
     *     email: string,
     *     password: string,
     *     shell_role: string,
     *     display_name: string,
     *     identities: list<string>
     * }|null
     */
    public static function attempt(string $identity, string $password): ?array
    {
        $identityKey = self::normalizeIdentity($identity);
        if ($identityKey === '' || $password === '') {
            return null;
        }

        foreach (self::accounts() as $account) {
            $matchesIdentity = false;
            foreach ($account['identities'] as $candidate) {
                if (self::normalizeIdentity((string) $candidate) === $identityKey) {
                    $matchesIdentity = true;
                    break;
                }
            }

            if (! $matchesIdentity) {
                continue;
            }

            if (! hash_equals((string) $account['password'], $password)) {
                return null;
            }

            return $account;
        }

        return null;
    }

    public static function establishSession(array $account): void
    {
        UiRole::set((string) $account['shell_role']);
        session([
            self::SESSION_DISPLAY_NAME => (string) $account['display_name'],
            self::SESSION_EMAIL => (string) $account['email'],
            self::SESSION_LOGIN_ESTABLISHED => true,
        ]);
    }

    public static function clearSession(): void
    {
        session()->forget([
            UiRole::SESSION_KEY,
            self::SESSION_DISPLAY_NAME,
            self::SESSION_EMAIL,
            self::SESSION_LOGIN_ESTABLISHED,
        ]);
    }

    private static function normalizeIdentity(string $value): string
    {
        return strtolower(trim($value));
    }
}
