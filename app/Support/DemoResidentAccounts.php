<?php

namespace App\Support;

/**
 * Session-backed demo store for resident account CRUD (UI preview).
 * Seeded from resources/demo/resident-accounts.php. Distinct from Household Requests.
 */
final class DemoResidentAccounts
{
    public const SESSION_KEY = 'lml.demo.resident_accounts.v2';

    /** @var list<string> */
    public const ALLOWED_ZONES = ['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function seed(): array
    {
        /** @var list<array<string, mixed>> $catalog */
        $catalog = require resource_path('demo/resident-accounts.php');

        return array_values($catalog);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        if (! session()->has(self::SESSION_KEY)) {
            session([self::SESSION_KEY => self::seed()]);
        }

        /** @var list<array<string, mixed>> $accounts */
        $accounts = session(self::SESSION_KEY, []);

        return array_values($accounts);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        return collect(self::all())->firstWhere('id', $id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public static function update(string $id, array $attributes): ?array
    {
        $accounts = self::all();
        $index = collect($accounts)->search(static fn (array $row): bool => ($row['id'] ?? '') === $id);

        if ($index === false) {
            return null;
        }

        $current = $accounts[$index];
        $first = trim((string) ($attributes['first_name'] ?? $current['first_name'] ?? ''));
        $middle = trim((string) ($attributes['middle_name'] ?? $current['middle_name'] ?? ''));
        $last = trim((string) ($attributes['last_name'] ?? $current['last_name'] ?? ''));
        $zone = trim((string) ($attributes['zone'] ?? $current['zone'] ?? ''));
        $email = trim((string) ($attributes['email'] ?? $current['email'] ?? ''));

        $fullName = trim(implode(' ', array_filter([$first, $middle, $last], static fn (string $part): bool => $part !== '')));

        $accounts[$index] = [
            'id' => $id,
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'name' => $fullName !== '' ? $fullName : (string) ($current['name'] ?? ''),
            'zone' => $zone,
            'email' => $email,
            // Preserve seed contact for future use; not edited in Residents CRUD UI.
            'contact_number' => (string) ($current['contact_number'] ?? ''),
        ];

        session([self::SESSION_KEY => array_values($accounts)]);

        return $accounts[$index];
    }

    public static function delete(string $id): bool
    {
        $accounts = self::all();
        $filtered = array_values(array_filter(
            $accounts,
            static fn (array $row): bool => ($row['id'] ?? '') !== $id
        ));

        if (count($filtered) === count($accounts)) {
            return false;
        }

        session([self::SESSION_KEY => $filtered]);

        return true;
    }
}
