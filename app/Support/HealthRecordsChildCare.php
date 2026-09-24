<?php

namespace App\Support;

use App\Models\Resident;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Barangay-wide Child Care summary rows from persisted residents (0–18 years).
 *
 * Under-5 helpers ({@see MAX_AGE_MONTHS}, {@see isChildCarePopulation}) remain for
 * Vitamin A / Deworming / Operation Timbang / school-immunization gates.
 */
final class HealthRecordsChildCare
{
    /**
     * Under-5 program gate used by related Child Care services.
     */
    public const MAX_AGE_MONTHS = 59;

    /**
     * Health Records → Child Care listing scope: through 18 years of age (inclusive).
     */
    public const MAX_AGE_YEARS = 18;

    public const EMPTY_RECORD = 'No record';

    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        if (! Schema::hasTable('residents') || ! Schema::hasTable('households')) {
            return [];
        }

        $with = ['household'];
        if (ChildBirthHistoryService::legacyBirthHistoryTableAvailable()) {
            $with[] = 'childBirthHistory';
        }
        if (ChildBirthHistoryService::nutritionPersistenceAvailable()) {
            $with[] = 'childNutrition';
        }

        $residents = Resident::query()
            ->with($with)
            ->whereHas('household')
            ->get();

        $rows = [];

        foreach ($residents as $resident) {
            $member = HouseholdProfilingPresenter::memberFromModel($resident);
            $ageMonths = self::ageInMonths($member);
            $ageYears = self::ageInYears($member);
            if ($ageYears === null || $ageYears > self::MAX_AGE_YEARS) {
                continue;
            }

            $household = $resident->household;
            if ($household === null) {
                continue;
            }

            $householdNo = (string) ($household->household_no ?? '');
            $memberId = ResidentMemberIdentity::memberIdFor($resident);
            $sex = trim((string) ($member['sex'] ?? ''));
            $birthdayIso = self::isoBirthday($member['birthday'] ?? null);
            $birthStatus = trim((string) ($member['birth_history']['status'] ?? ''));

            $rows[] = [
                'household_no' => $householdNo,
                'member_id' => $memberId,
                'full_name' => self::displayName($member),
                'zone' => self::zoneLabelForHousehold($household),
                'sex' => $sex !== '' ? $sex : self::EMPTY_RECORD,
                'sex_normalized' => strtolower($sex),
                'age_months' => $ageMonths ?? ($ageYears * 12),
                'age_years' => $ageYears,
                'age_label' => self::formatAgeLabel($ageMonths, $ageYears),
                'birthday' => $birthdayIso !== ''
                    ? (DisplayDate::format($birthdayIso) ?: self::EMPTY_RECORD)
                    : self::EMPTY_RECORD,
                'birthday_iso' => $birthdayIso,
                'birth_status' => $birthStatus !== '' ? $birthStatus : self::EMPTY_RECORD,
                'health_status' => self::EMPTY_RECORD,
                'view_url' => $householdNo !== '' && $memberId !== ''
                    ? route('household-profiling.members.show', [
                        'householdNo' => $householdNo,
                        'memberId' => $memberId,
                    ])
                    : '#',
            ];
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
     * Custom age bands for Child Care listing (0–18 years).
     * Keeps under-5 month bands and adds school-age year bands.
     *
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
            '5-9' => '5–9 years',
            '10-14' => '10–14 years',
            '15-18' => '15–18 years',
            'custom' => 'Custom',
        ];
    }

    /**
     * Zone/age/sex-filtered Child Care rows — the Child Care Report
     * Builder's data source. $ageBand follows {@see ageFilterOptions()};
     * when it's 'custom', $ageMin/$ageMax (years, inclusive) apply instead.
     *
     * @return list<array<string, mixed>>
     */
    public static function exportRows(
        ?string $zone = null,
        string $ageBand = 'all',
        ?int $ageMin = null,
        ?int $ageMax = null,
        ?string $sex = null,
    ): array {
        return array_values(array_filter(
            self::rows(),
            static function (array $row) use ($zone, $ageBand, $ageMin, $ageMax, $sex): bool {
                if ($zone !== null && trim((string) ($row['zone'] ?? '')) !== $zone) {
                    return false;
                }

                if ($sex !== null && (string) ($row['sex_normalized'] ?? '') !== $sex) {
                    return false;
                }

                if ($ageBand === 'custom') {
                    if (! self::matchesAgeYears((int) ($row['age_years'] ?? 0), $ageMin, $ageMax)) {
                        return false;
                    }
                } elseif ($ageBand !== 'all' && $ageBand !== '') {
                    if (! self::matchesAgeBand((int) ($row['age_months'] ?? 0), $ageBand)) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * "All Ages" / "5–9 years" / "Custom 3–7 yrs" — the active age filter,
     * in the same words as {@see ageFilterOptions()}. Used for the report's
     * summary scope label.
     */
    public static function ageFilterLabel(string $ageBand, ?int $ageMin = null, ?int $ageMax = null): string
    {
        if ($ageBand === 'custom') {
            if ($ageMin !== null && $ageMax !== null) {
                return 'Custom '.$ageMin.'–'.$ageMax.' yrs';
            }
            if ($ageMin !== null) {
                return 'Custom '.$ageMin.'+ yrs';
            }
            if ($ageMax !== null) {
                return 'Custom up to '.$ageMax.' yrs';
            }

            return 'Custom';
        }

        if ($ageBand === '' || $ageBand === 'all') {
            return 'All Ages';
        }

        return self::ageFilterOptions()[$ageBand] ?? $ageBand;
    }

    public static function sexFilterLabel(?string $sex): string
    {
        return match ($sex) {
            'female' => 'Female',
            'male' => 'Male',
            default => 'All Sexes',
        };
    }

    /**
     * "All Ages · All Sexes" / "5–9 years · Female" — depends on which of
     * the age/sex filters are active. Used for the Child Care report's
     * summary scope label (zone is already reflected by the per-zone
     * report sections, so it isn't repeated here).
     */
    public static function scopeLabel(string $ageBand, ?string $sex, ?int $ageMin = null, ?int $ageMax = null): string
    {
        return self::ageFilterLabel($ageBand, $ageMin, $ageMax).' · '.self::sexFilterLabel($sex);
    }

    public static function matchesAgeBand(int $ageMonths, string $band): bool
    {
        return match ($band) {
            '0-5' => $ageMonths >= 0 && $ageMonths <= 5,
            '6-11' => $ageMonths >= 6 && $ageMonths <= 11,
            '12-23' => $ageMonths >= 12 && $ageMonths <= 23,
            '24-59' => $ageMonths >= 24 && $ageMonths <= 59,
            '5-9' => $ageMonths >= 60 && $ageMonths <= 119,
            '10-14' => $ageMonths >= 120 && $ageMonths <= 179,
            '15-18' => $ageMonths >= 180 && $ageMonths <= (self::MAX_AGE_YEARS * 12) + 11,
            '0-11m' => $ageMonths >= 0 && $ageMonths <= 11,
            '1-4' => $ageMonths >= 12 && $ageMonths <= 59,
            default => true,
        };
    }

    /**
     * Free-type age filter (years inclusive). Null bound means unbounded.
     */
    public static function matchesAgeYears(int $ageYears, ?int $minYears, ?int $maxYears): bool
    {
        if ($minYears !== null && $ageYears < $minYears) {
            return false;
        }

        if ($maxYears !== null && $ageYears > $maxYears) {
            return false;
        }

        return true;
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

    /**
     * @param  array<string, mixed>  $member
     */
    public static function ageInYears(array $member): ?int
    {
        $birthday = $member['birthday'] ?? null;
        if (is_string($birthday) && $birthday !== '') {
            $years = HealthRecordsClinicalListing::ageInYearsFromBirthday($birthday);
            if ($years !== null) {
                return max(0, $years);
            }
        }

        if (isset($member['age']) && is_numeric($member['age'])) {
            return max(0, (int) $member['age']);
        }

        $months = self::ageInMonths($member);

        return $months === null ? null : intdiv(max(0, $months), 12);
    }

    public static function formatAgeMonths(int $months): string
    {
        $months = max(0, $months);
        $label = $months === 1 ? 'Month' : 'Months';

        return $months.' '.$label;
    }

    public static function formatAgeLabel(?int $ageMonths, ?int $ageYears): string
    {
        if ($ageMonths !== null && $ageMonths < 12) {
            return self::formatAgeMonths($ageMonths);
        }

        if ($ageYears !== null) {
            $years = max(0, $ageYears);
            $label = $years === 1 ? 'Year' : 'Years';

            return $years.' '.$label;
        }

        if ($ageMonths !== null) {
            return self::formatAgeMonths($ageMonths);
        }

        return self::EMPTY_RECORD;
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

    /**
     * Under-5 Child Care service population (Vitamin A / Deworming / OT gates).
     *
     * @param  array<string, mixed>  $member
     */
    public static function isChildCarePopulation(array $member): bool
    {
        $months = self::ageInMonths($member);

        return $months !== null && $months <= self::MAX_AGE_MONTHS;
    }

    /**
     * Health Records listing population (0–18 years inclusive).
     *
     * @param  array<string, mixed>  $member
     */
    public static function isChildCareListingPopulation(array $member): bool
    {
        $years = self::ageInYears($member);

        return $years !== null && $years <= self::MAX_AGE_YEARS;
    }

    private static function isoBirthday(mixed $birthday): string
    {
        if ($birthday instanceof \DateTimeInterface) {
            return $birthday->format('Y-m-d');
        }

        $raw = trim((string) $birthday);
        if ($raw === '') {
            return '';
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return $raw;
        }
    }

    private static function zoneLabelForHousehold(\App\Models\Household $household): string
    {
        $column = HouseholdZoneResolver::locationColumn();
        if ($column === null) {
            return '';
        }

        return HouseholdZoneResolver::displayLabelFromStoredValue((string) ($household->{$column} ?? ''));
    }
}
