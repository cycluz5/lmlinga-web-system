<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Barangay-wide Child Care summary rows aggregated from the household demo catalog
 * (same source as Household Profiling member Child Care modules).
 */
final class HealthRecordsChildCare
{
    /**
     * Program scope: members aged 0–59 months (inclusive) are included in Child Care summary.
     */
    public const MAX_AGE_MONTHS = 59;

    public const EMPTY_RECORD = 'No record';

    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach (DemoCatalog::households() as $household) {
            $householdNo = (string) ($household['householdNo'] ?? '');
            $zone = (string) ($household['zone'] ?? '');

            foreach ($household['memberList'] ?? [] as $member) {
                if (! is_array($member)) {
                    continue;
                }

                $ageMonths = self::ageInMonths($member);
                if ($ageMonths === null || $ageMonths > self::MAX_AGE_MONTHS) {
                    continue;
                }

                $memberId = (string) ($member['id'] ?? '');
                if ($memberId === '') {
                    continue;
                }

                $sex = trim((string) ($member['sex'] ?? ''));
                $birthStatus = data_get($member, 'birth_history.status');
                $healthStatus = data_get($member, 'nutrition.status');

                $rows[] = [
                    'household_no' => $householdNo,
                    'member_id' => $memberId,
                    'full_name' => self::displayName($member),
                    'zone' => $zone,
                    'sex' => $sex,
                    'sex_normalized' => strtolower($sex),
                    'age_months' => $ageMonths,
                    'age_label' => self::formatAgeMonths($ageMonths),
                    'birth_status' => filled($birthStatus) ? (string) $birthStatus : self::EMPTY_RECORD,
                    'health_status' => filled($healthStatus) ? (string) $healthStatus : self::EMPTY_RECORD,
                    'view_url' => route('household-profiling.members.show', [
                        'householdNo' => $householdNo,
                        'memberId' => $memberId,
                    ]),
                ];
            }
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => strcasecmp((string) $a['full_name'], (string) $b['full_name'])
        );

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{total: int, female: int, male: int}
     */
    public static function summaryCounts(array $rows): array
    {
        $female = 0;
        $male = 0;

        foreach ($rows as $row) {
            $sex = (string) ($row['sex_normalized'] ?? '');
            if ($sex === 'female') {
                $female++;
            } elseif ($sex === 'male') {
                $male++;
            }
        }

        return [
            'total' => count($rows),
            'female' => $female,
            'male' => $male,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    public static function zonesFromRows(array $rows): array
    {
        $zones = [];

        foreach ($rows as $row) {
            $zone = trim((string) ($row['zone'] ?? ''));
            if ($zone !== '') {
                $zones[$zone] = true;
            }
        }

        $list = array_keys($zones);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    /**
     * @return array<string, string>
     */
    public static function ageFilterOptions(): array
    {
        return [
            'all' => 'Age',
            '0-5' => '0–5 months',
            '6-11' => '6–11 months',
            '12-23' => '12–23 months',
            '24-59' => '24–59 months',
        ];
    }

    public static function matchesAgeBand(int $ageMonths, string $band): bool
    {
        return match ($band) {
            '0-5' => $ageMonths >= 0 && $ageMonths <= 5,
            '6-11' => $ageMonths >= 6 && $ageMonths <= 11,
            '12-23' => $ageMonths >= 12 && $ageMonths <= 23,
            '24-59' => $ageMonths >= 24 && $ageMonths <= 59,
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $member
     */
    public static function ageInMonths(array $member): ?int
    {
        $birthday = $member['birthday'] ?? null;
        if (is_string($birthday) && $birthday !== '') {
            try {
                $born = Carbon::parse($birthday)->startOfDay();
                $now = Carbon::now()->startOfDay();
                if ($born->greaterThan($now)) {
                    return 0;
                }

                return (int) $born->diffInMonths($now);
            } catch (\Throwable) {
                // fall through to numeric age
            }
        }

        if (isset($member['age']) && is_numeric($member['age'])) {
            return max(0, (int) $member['age']) * 12;
        }

        return null;
    }

    public static function formatAgeMonths(int $months): string
    {
        $months = max(0, $months);
        $label = $months === 1 ? 'Month' : 'Months';

        return $months.' '.$label;
    }

    /**
     * @param  array<string, mixed>  $member
     */
    public static function displayName(array $member): string
    {
        $name = trim((string) ($member['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $parts = array_filter([
            $member['first_name'] ?? null,
            $member['middle_name'] ?? null,
            $member['last_name'] ?? null,
        ], static fn ($part) => filled($part));

        return $parts !== [] ? implode(' ', $parts) : 'Unknown';
    }

    public static function isChildCarePopulation(array $member): bool
    {
        $months = self::ageInMonths($member);

        return $months !== null && $months <= self::MAX_AGE_MONTHS;
    }
}
