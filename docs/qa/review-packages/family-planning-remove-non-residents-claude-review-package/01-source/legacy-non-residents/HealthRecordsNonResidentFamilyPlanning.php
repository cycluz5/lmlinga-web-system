<?php

namespace App\Support;

/**
 * Health Records → Family Planning → Non-Resident / unregistered clients (UI-phase).
 *
 * Fixture catalog only — not persisted and not mapped from Household Profiling.
 */
final class HealthRecordsNonResidentFamilyPlanning
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function clients(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = require resource_path('demo/non-resident-family-planning.php');

        return array_map(
            static fn (array $row): array => self::normalizeClient($row),
            $rows
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findClient(string $clientKey): ?array
    {
        $key = self::normalizeKey($clientKey);

        foreach (self::clients() as $client) {
            if (($client['key'] ?? '') === $key) {
                return $client;
            }
        }

        return null;
    }

    /**
     * Latest visit for a client (most recent by visited_at).
     *
     * @return array<string, mixed>|null
     */
    public static function latestVisit(array $client): ?array
    {
        $visits = is_array($client['visits'] ?? null) ? $client['visits'] : [];

        return $visits === [] ? null : $visits[0];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findVisit(string $clientKey, string $visitId): ?array
    {
        $client = self::findClient($clientKey);
        if ($client === null) {
            return null;
        }

        $id = strtoupper(trim($visitId));
        foreach ($client['visits'] as $visit) {
            if (strtoupper((string) ($visit['id'] ?? '')) === $id) {
                return $visit;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function barangays(?array $clients = null): array
    {
        $clients ??= self::clients();
        $map = [];

        foreach ($clients as $client) {
            $label = trim((string) ($client['barangay'] ?? ''));
            if ($label !== '') {
                $map[$label] = true;
            }
        }

        $list = array_keys($map);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    /**
     * @return list<string>
     */
    public static function years(?array $clients = null): array
    {
        $clients ??= self::clients();
        $years = [];

        foreach ($clients as $client) {
            $year = trim((string) ($client['year'] ?? ''));
            if ($year !== '') {
                $years[$year] = true;
            }
        }

        $list = array_map('strval', array_keys($years));
        rsort($list, SORT_NUMERIC);

        return $list;
    }

    /**
     * @return list<string>
     */
    public static function civilStatusOptions(): array
    {
        return ['Single', 'Married', 'Widowed', 'Separated'];
    }

    /**
     * @return list<string>
     */
    public static function sexOptions(): array
    {
        return ['Female', 'Male'];
    }

    /**
     * @return list<string>
     */
    public static function methodOptions(): array
    {
        return [
            'Pills',
            'Condom',
            'Injectable',
            'IUD',
            'Implant',
            'BTL',
        ];
    }

    /**
     * Reuse household FP commodity labels for UI selects.
     *
     * @return list<string>
     */
    public static function commodityOptions(): array
    {
        return DemoFamilyPlanning::commodityOptions();
    }

    public static function fullName(array $client): string
    {
        $parts = array_filter([
            trim((string) ($client['first_name'] ?? '')),
            trim((string) ($client['middle_name'] ?? '')),
            trim((string) ($client['last_name'] ?? '')),
        ], static fn (string $part): bool => $part !== '');

        return $parts === [] ? '—' : implode(' ', $parts);
    }

    public static function displayName(array $client): string
    {
        return strtoupper(self::fullName($client));
    }

    public static function ageFromBirthday(string $birthday): ?int
    {
        try {
            return (int) \Carbon\Carbon::parse($birthday)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function formatBirthdayLong(string $isoDate): string
    {
        try {
            return \Carbon\Carbon::parse($isoDate)->format('F j, Y');
        } catch (\Throwable) {
            return $isoDate;
        }
    }

    public static function formatVisitDateShort(string $isoDate): string
    {
        try {
            return \Carbon\Carbon::parse($isoDate)->format('m/d/Y');
        } catch (\Throwable) {
            return $isoDate;
        }
    }

    public static function formatAddressLine(array $client): string
    {
        $zone = trim((string) ($client['address_zone'] ?? ''));
        $barangay = trim((string) ($client['barangay'] ?? ''));
        $municipality = trim((string) ($client['municipality'] ?? ''));

        $parts = array_filter([$zone, $barangay, $municipality], static fn (string $p): bool => $p !== '');

        return $parts === [] ? '—' : implode(', ', $parts);
    }

    /**
     * Flatten commodities across visits for the client details table.
     *
     * @return list<array{commodity: string, quantity: int|string, date_given: string}>
     */
    public static function commoditiesLedger(array $client): array
    {
        $ledger = [];
        $visits = is_array($client['visits'] ?? null) ? $client['visits'] : [];

        foreach ($visits as $visit) {
            $visitDate = (string) ($visit['visited_at'] ?? '');
            $defaultGiven = $visitDate !== '' ? self::formatVisitDateShort($visitDate) : '—';
            $commodities = is_array($visit['commodities'] ?? null) ? $visit['commodities'] : [];

            foreach ($commodities as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $givenAt = (string) ($item['given_at'] ?? $visitDate);
                $ledger[] = [
                    'commodity' => $name,
                    'quantity' => $item['quantity'] ?? '—',
                    'date_given' => $givenAt !== ''
                        ? self::formatVisitDateShort($givenAt)
                        : $defaultGiven,
                ];
            }
        }

        return $ledger;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function normalizeClient(array $row): array
    {
        $visits = is_array($row['visits'] ?? null) ? $row['visits'] : [];
        $normalizedVisits = [];

        foreach ($visits as $visit) {
            if (! is_array($visit)) {
                continue;
            }
            $commodities = is_array($visit['commodities'] ?? null) ? $visit['commodities'] : [];
            $normalizedCommodities = [];

            foreach ($commodities as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $normalizedCommodities[] = [
                    'name' => trim((string) ($item['name'] ?? '')),
                    'quantity' => $item['quantity'] ?? '',
                    'given_at' => isset($item['given_at']) ? (string) $item['given_at'] : null,
                ];
            }

            $normalizedVisits[] = [
                'id' => strtoupper(trim((string) ($visit['id'] ?? ''))),
                'visited_at' => (string) ($visit['visited_at'] ?? ''),
                'remarks' => (string) ($visit['remarks'] ?? ''),
                'commodities' => $normalizedCommodities,
            ];
        }

        usort(
            $normalizedVisits,
            static fn (array $a, array $b): int => strcmp((string) ($b['visited_at'] ?? ''), (string) ($a['visited_at'] ?? ''))
        );

        $birthday = (string) ($row['birthday'] ?? '');
        $age = $birthday !== '' ? self::ageFromBirthday($birthday) : null;

        return [
            'key' => self::normalizeKey((string) ($row['key'] ?? '')),
            'first_name' => (string) ($row['first_name'] ?? ''),
            'middle_name' => (string) ($row['middle_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'birthday' => $birthday,
            'sex' => (string) ($row['sex'] ?? ''),
            'civil_status' => (string) ($row['civil_status'] ?? ''),
            'address_zone' => (string) ($row['address_zone'] ?? ''),
            'barangay' => (string) ($row['barangay'] ?? ''),
            'municipality' => (string) ($row['municipality'] ?? ''),
            'method' => (string) ($row['method'] ?? ''),
            'start_date' => (string) ($row['start_date'] ?? ''),
            'last_visit' => (string) ($row['last_visit'] ?? ''),
            'year' => (string) ($row['year'] ?? ''),
            'age' => $age,
            'full_name' => self::fullName($row),
            'visits' => $normalizedVisits,
        ];
    }

    private static function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }
}
