<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Health Records → Maternal → Non-Resident / unregistered clients (UI-phase).
 *
 * Listing = fixture maternal candidates who are female AND whose normalized
 * full name does NOT exist in Household Profiling DemoCatalog member names.
 *
 * Male candidates exist in the fixture so tests can prove server-side exclusion.
 * They are never returned by rows(). Catalog sex values are not rewritten.
 *
 * Session-created clients from the Add Non-Resident Maternal form are merged
 * into candidateRecords(). Sex is always Female; BMI is always derived.
 */
final class HealthRecordsNonResidentMaternal
{
    public const SESSION_CREATED_KEY = 'lml.demo.hr_nr_maternal.created.v1';

    /**
     * Eligible female non-resident rows only.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        $residentNames = self::residentFullNameIndex();
        $rows = [];

        foreach (self::candidateRecords() as $record) {
            if (! HealthRecordsMaternal::isFemaleSex((string) ($record['sex'] ?? ''))) {
                continue;
            }

            $normalized = self::normalizeFullName((string) ($record['full_name'] ?? ''));
            if ($normalized === '' || isset($residentNames[$normalized])) {
                continue;
            }

            $rows[] = $record;
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => strcasecmp((string) $a['full_name'], (string) $b['full_name'])
        );

        return $rows;
    }

    /**
     * Unfiltered fixture (includes males and name-colliding residents).
     *
     * @return list<array<string, mixed>>
     */
    public static function candidateRecords(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = require resource_path('demo/non-resident-maternal.php');

        $fixture = array_map(
            static fn (array $row): array => self::normalizeRecord($row),
            $rows
        );

        return array_merge(self::sessionCreated(), $fixture);
    }

    /**
     * Household member civil-status domain values (do not invent new statuses).
     *
     * @return list<string>
     */
    public static function statusOptions(): array
    {
        return ['Single', 'Married', 'Widowed', 'Separated'];
    }

    /**
     * BMI from kg + cm, rounded to 1 decimal (same convention as DemoMaternalCare).
     */
    public static function calculateBmi(mixed $weightKg, mixed $heightCm): ?float
    {
        if (! is_numeric($weightKg) || ! is_numeric($heightCm)) {
            return null;
        }

        $weight = (float) $weightKg;
        $height = (float) $heightCm;
        if ($weight <= 0 || $height <= 0) {
            return null;
        }

        $heightMeters = $height / 100;
        if ($heightMeters <= 0) {
            return null;
        }

        return round($weight / ($heightMeters * $heightMeters), 1);
    }

    /**
     * Naegele-style EDD: LMP + 280 days (same offset as DemoMaternalCare::estimateEdd).
     */
    public static function estimateEddFromLmp(?string $lmp): ?string
    {
        $trimmed = trim((string) $lmp);
        if ($trimmed === '') {
            return null;
        }

        try {
            return Carbon::parse($trimmed)->addDays(280)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Persist a non-resident maternal client. Sex and BMI are server-derived.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function createFromRegistration(array $validated): array
    {
        unset($validated['sex'], $validated['gender'], $validated['bmi']);

        $first = trim((string) ($validated['first_name'] ?? ''));
        $middle = trim((string) ($validated['middle_name'] ?? ''));
        $last = trim((string) ($validated['last_name'] ?? ''));
        $fullName = trim(implode(' ', array_filter([$first, $middle, $last], static fn (string $p): bool => $p !== '')));

        $birthday = (string) ($validated['birthday'] ?? '');
        $ageYears = null;
        try {
            $ageYears = $birthday !== '' ? Carbon::parse($birthday)->age : null;
        } catch (\Throwable) {
            $ageYears = null;
        }

        $lmp = (string) ($validated['lmp'] ?? '');
        $edd = trim((string) ($validated['edd'] ?? ''));
        if ($edd === '') {
            $edd = self::estimateEddFromLmp($lmp) ?? '';
        }

        $gravida = (int) ($validated['gravida'] ?? 0);
        $parity = (int) ($validated['parity'] ?? 0);
        $weight = $validated['weight'] ?? null;
        $height = $validated['height'] ?? null;
        $bmi = self::calculateBmi($weight, $height);

        $year = '';
        try {
            $year = $lmp !== '' ? Carbon::parse($lmp)->format('Y') : (string) now()->year;
        } catch (\Throwable) {
            $year = (string) now()->year;
        }

        $record = self::normalizeRecord([
            'key' => 'nr-mc-'.Str::lower(Str::ulid()),
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'full_name' => $fullName,
            'sex' => 'Female',
            'age' => $ageYears,
            'birthday' => $birthday,
            'status' => (string) ($validated['status'] ?? ''),
            'complete_address' => trim((string) ($validated['complete_address'] ?? '')),
            'barangay' => '',
            'year' => $year,
            'lmp' => self::formatListingDate($lmp),
            'gravida_parity' => $gravida.'-'.$parity,
            'edd' => self::formatListingDate($edd),
            'weight' => is_numeric($weight) ? (float) $weight : null,
            'height' => is_numeric($height) ? (float) $height : null,
            'bmi' => $bmi,
            'blood_pressure' => trim((string) ($validated['blood_pressure'] ?? '')),
            'delivery_type' => 'VD',
            'trimester' => '2nd',
            'prenatal_visits' => '1',
            'is_high_risk' => false,
            'is_due_prenatal' => true,
            'is_delivered' => false,
            'is_incomplete_prenatal' => true,
            'population' => 'non-resident',
        ]);

        $created = self::sessionCreated();
        array_unshift($created, $record);
        session([self::SESSION_CREATED_KEY => $created]);

        return $record;
    }

    /**
     * Eligible non-resident female client only. Males, name-colliding residents,
     * and unknown keys are not returned.
     *
     * @return array<string, mixed>|null
     */
    public static function findEligible(string $key): ?array
    {
        $normalized = strtolower(trim($key));
        if ($normalized === '') {
            return null;
        }

        foreach (self::rows() as $row) {
            if ((string) ($row['key'] ?? '') === $normalized) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Single current-pregnancy summary from stored listing fields. No invented history.
     *
     * @param  array<string, mixed>  $client
     * @return array<string, mixed>|null
     */
    public static function pregnancySummary(array $client): ?array
    {
        $lmp = trim((string) ($client['lmp'] ?? ''));
        $gp = trim((string) ($client['gravida_parity'] ?? ''));
        $edd = trim((string) ($client['edd'] ?? ''));
        if ($lmp === '' && $gp === '') {
            return null;
        }

        $gravida = null;
        $parity = null;
        if (preg_match('/^(\d+)\s*[-–]\s*(\d+)$/', $gp, $matches) === 1) {
            $gravida = (int) $matches[1];
            $parity = (int) $matches[2];
        }

        $delivered = (bool) ($client['is_delivered'] ?? false);

        return [
            'number' => 1,
            'gravida' => $gravida,
            'parity' => $parity,
            'gp_label' => $gravida !== null && $parity !== null ? 'G'.$gravida.' P'.$parity : $gp,
            'lmp' => $lmp,
            'lmp_label' => self::formatDisplayDate($lmp),
            'edd' => $edd,
            'edd_label' => self::formatDisplayDate($edd),
            'delivery_type' => trim((string) ($client['delivery_type'] ?? '')),
            'status_label' => $delivered ? 'Delivered' : 'Active pregnancy',
        ];
    }

    public static function formatDisplayDate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        try {
            if (preg_match('/^\d{2}-\d{2}-\d{2}$/', $trimmed) === 1) {
                return Carbon::createFromFormat('m-d-y', $trimmed)->format('F j, Y');
            }

            return Carbon::parse($trimmed)->format('F j, Y');
        } catch (\Throwable) {
            return $trimmed;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function sessionCreated(): array
    {
        $rows = session(self::SESSION_CREATED_KEY, []);
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(
            $rows,
            static fn ($row): bool => is_array($row)
        ));
    }

    private static function formatListingDate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        try {
            return Carbon::parse($trimmed)->format('m-d-y');
        } catch (\Throwable) {
            return $trimmed;
        }
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return list<string>
     */
    public static function barangays(?array $rows = null): array
    {
        $rows ??= self::rows();
        $map = [];

        foreach ($rows as $row) {
            $barangay = trim((string) ($row['barangay'] ?? ''));
            if ($barangay !== '') {
                $map[$barangay] = true;
            }
        }

        $list = array_keys($map);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return list<string>
     */
    public static function years(?array $rows = null): array
    {
        $rows ??= self::rows();
        $years = [];

        foreach ($rows as $row) {
            $year = trim((string) ($row['year'] ?? ''));
            if ($year !== '') {
                $years[$year] = true;
            }
        }

        $list = array_map('strval', array_keys($years));
        rsort($list, SORT_NUMERIC);

        return $list;
    }

    public static function normalizeFullName(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name)) ?? '';

        return mb_strtolower($collapsed);
    }

    /**
     * @return array<string, true>
     */
    public static function residentFullNameIndex(): array
    {
        $index = [];

        foreach (DemoCatalog::households() as $household) {
            foreach ($household['memberList'] ?? [] as $member) {
                if (! is_array($member)) {
                    continue;
                }

                $normalized = self::normalizeFullName(HealthRecordsMaternal::displayName($member));
                if ($normalized !== '') {
                    $index[$normalized] = true;
                }
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function normalizeRecord(array $row): array
    {
        $fullName = trim((string) ($row['full_name'] ?? ''));
        if ($fullName === '') {
            $fullName = trim(implode(' ', array_filter([
                (string) ($row['first_name'] ?? ''),
                (string) ($row['middle_name'] ?? ''),
                (string) ($row['last_name'] ?? ''),
            ], static fn (string $part): bool => trim($part) !== '')));
        }

        $birthday = trim((string) ($row['birthday'] ?? ''));
        $ageYears = isset($row['age']) && is_numeric($row['age'])
            ? (int) $row['age']
            : null;
        if ($birthday !== '') {
            try {
                $ageYears = Carbon::parse($birthday)->age;
            } catch (\Throwable) {
                // Keep fixture age when birthday is unparseable.
            }
        }

        return [
            'key' => strtolower(trim((string) ($row['key'] ?? ''))),
            'full_name' => $fullName,
            'first_name' => (string) ($row['first_name'] ?? ''),
            'middle_name' => (string) ($row['middle_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'sex' => (string) ($row['sex'] ?? ''),
            'sex_normalized' => strtolower(trim((string) ($row['sex'] ?? ''))),
            'age_years' => $ageYears,
            'age_group' => HealthRecordsMaternal::ageGroupLetter($ageYears),
            'birthday' => $birthday,
            'status' => (string) ($row['status'] ?? ''),
            'complete_address' => (string) ($row['complete_address'] ?? ''),
            'barangay' => (string) ($row['barangay'] ?? ''),
            'year' => (string) ($row['year'] ?? '2026'),
            'lmp' => self::completeValue((string) ($row['lmp'] ?? ''), '06-15-25'),
            'gravida_parity' => self::completeValue((string) ($row['gravida_parity'] ?? ''), '1-0'),
            'edd' => self::completeValue((string) ($row['edd'] ?? ''), '03-22-26'),
            'weight' => $row['weight'] ?? null,
            'height' => $row['height'] ?? null,
            'bmi' => $row['bmi'] ?? null,
            'blood_pressure' => (string) ($row['blood_pressure'] ?? ''),
            'delivery_type' => self::completeValue((string) ($row['delivery_type'] ?? ''), 'VD'),
            'trimester' => self::completeValue((string) ($row['trimester'] ?? ''), '2nd'),
            'prenatal_visits' => self::completeValue((string) ($row['prenatal_visits'] ?? ''), '2'),
            'is_high_risk' => (bool) ($row['is_high_risk'] ?? false),
            'is_due_prenatal' => (bool) ($row['is_due_prenatal'] ?? false),
            'is_delivered' => (bool) ($row['is_delivered'] ?? false),
            'is_incomplete_prenatal' => (bool) ($row['is_incomplete_prenatal'] ?? false),
            'population' => 'non-resident',
        ];
    }

    private static function completeValue(string $value, string $fallback): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === HealthRecordsMaternal::EMPTY || $trimmed === '-') {
            return $fallback;
        }

        return $trimmed;
    }
}
