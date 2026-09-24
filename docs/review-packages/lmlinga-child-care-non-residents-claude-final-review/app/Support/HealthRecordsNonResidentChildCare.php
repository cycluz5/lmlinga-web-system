<?php

namespace App\Support;

/**
 * Health Records → Child Care → Non-Resident / unregistered children (UI-phase).
 *
 * Listing = fixture child-care candidates whose normalized full name does NOT
 * exist in Household Profiling DemoCatalog member names.
 *
 * Not persisted. Birthday year drives the Year filter.
 */
final class HealthRecordsNonResidentChildCare
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        $residentNames = self::residentFullNameIndex();
        $rows = [];

        foreach (self::candidateRecords() as $record) {
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
     * @return array<string, mixed>|null
     */
    public static function find(string $childKey): ?array
    {
        $key = self::normalizeKey($childKey);

        foreach (self::rows() as $row) {
            if (($row['key'] ?? '') === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findMeasurement(string $childKey, string $measurementId): ?array
    {
        $child = self::find($childKey);
        if ($child === null) {
            return null;
        }

        $id = strtoupper(trim($measurementId));
        foreach ($child['measurements'] ?? [] as $measurement) {
            if (strtoupper((string) ($measurement['id'] ?? '')) === $id) {
                return $measurement;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function nutritionStatusOptions(): array
    {
        return ['Normal', 'Needs Monitoring', 'Below Normal', 'Above Normal'];
    }

    /**
     * @param  array<string, mixed>|null  $child
     * @return list<array{label: string, available: bool, url: string|null}>
     */
    public static function childCareRecordItems(?array $child = null): array
    {
        $childKey = is_array($child) ? (string) ($child['key'] ?? '') : '';
        $immunizationUrl = $childKey !== ''
            ? route('health-records.child-care.non-residents.immunization', ['childKey' => $childKey])
            : null;
        $schoolBasedUrl = $childKey !== ''
            ? route('health-records.child-care.non-residents.school-based-immunization', ['childKey' => $childKey])
            : null;
        $childNutritionUrl = $childKey !== ''
            ? route('health-records.child-care.non-residents.child-nutrition', ['childKey' => $childKey])
            : null;
        $dewormingUrl = $childKey !== ''
            ? route('health-records.child-care.non-residents.deworming', ['childKey' => $childKey])
            : null;

        return [
            ['label' => 'Child Immunization', 'available' => $immunizationUrl !== null, 'url' => $immunizationUrl],
            ['label' => 'School Based Immunization', 'available' => $schoolBasedUrl !== null, 'url' => $schoolBasedUrl],
            ['label' => 'Child Nutrition', 'available' => $childNutritionUrl !== null, 'url' => $childNutritionUrl],
            ['label' => 'Deworming', 'available' => $dewormingUrl !== null, 'url' => $dewormingUrl],
        ];
    }

    /**
     * @return list<string>
     */
    public static function dewormingRoundOptions(): array
    {
        return ['1', '2'];
    }

    /**
     * Project-supported SE Status labels (Household NHTS terminology).
     *
     * @return list<string>
     */
    public static function dewormingSeStatusOptions(): array
    {
        return ['NHTS', 'Non-NHTS'];
    }

    /**
     * @param  list<array<string, mixed>>  $measurements
     * @return array<string, mixed>|null
     */
    public static function latestMeasurement(array $measurements): ?array
    {
        return $measurements[0] ?? null;
    }

    /**
     * Earliest Operation Timbang / Nutritional Status record by date.
     * Does not invent a measurement when none exist.
     *
     * @param  list<array<string, mixed>>  $measurements
     * @return array<string, mixed>|null
     */
    public static function firstMeasurement(array $measurements): ?array
    {
        $first = null;
        $firstDate = null;

        foreach ($measurements as $measurement) {
            $date = (string) ($measurement['date'] ?? '');
            if ($date === '') {
                continue;
            }

            if ($firstDate === null || strcmp($date, $firstDate) < 0) {
                $firstDate = $date;
                $first = $measurement;
            }
        }

        return $first;
    }

    /**
     * @param  list<array<string, mixed>>  $measurements
     * @return array{infant: list<array<string, mixed>>, child: list<array<string, mixed>>}
     */
    public static function groupMeasurements(array $measurements): array
    {
        $infant = [];
        $child = [];

        foreach ($measurements as $measurement) {
            $ageMonths = $measurement['age_months'] ?? null;
            if (is_int($ageMonths) && $ageMonths <= 12) {
                $infant[] = $measurement;
            } else {
                $child[] = $measurement;
            }
        }

        return ['infant' => $infant, 'child' => $child];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function filterRows(array $rows, string $search, string $barangay, string $year): array
    {
        $query = self::normalizeFullName($search);
        $barangay = trim($barangay);
        $year = trim($year);

        return array_values(array_filter(
            $rows,
            static function (array $row) use ($query, $barangay, $year): bool {
                $name = self::normalizeFullName((string) ($row['full_name'] ?? ''));
                $matchesSearch = $query === '' || str_contains($name, $query);
                $matchesBarangay = $barangay === '' || $barangay === 'all' || ($row['barangay'] ?? '') === $barangay;
                $matchesYear = $year === '' || $year === 'all' || (string) ($row['year'] ?? '') === $year;

                return $matchesSearch && $matchesBarangay && $matchesYear;
            }
        ));
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
            $label = trim((string) ($row['barangay'] ?? ''));
            if ($label !== '') {
                $map[$label] = true;
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
    public static function gradeLevelOptions(): array
    {
        return ['N/A', 'Kinder', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'];
    }

    public static function composeFullName(string $firstName, string $middleName, string $lastName): string
    {
        $parts = array_filter(
            [trim($firstName), trim($middleName), trim($lastName)],
            static fn (string $part): bool => $part !== ''
        );

        return $parts === [] ? '' : implode(' ', $parts);
    }

    public static function normalizeFullName(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name)) ?? '';

        return mb_strtolower($collapsed);
    }

    public static function isResidentFullName(string $fullName): bool
    {
        $normalized = self::normalizeFullName($fullName);

        return $normalized !== '' && isset(self::residentFullNameIndex()[$normalized]);
    }

    /**
     * @return array{full_name: string, household_no: string, member_id: string, view_url: string}|null
     */
    public static function findResidentByFullName(string $fullName): ?array
    {
        $normalized = self::normalizeFullName($fullName);
        if ($normalized === '') {
            return null;
        }

        return self::residentFullNameIndex()[$normalized] ?? null;
    }

    /**
     * @return list<array{normalized: string, full_name: string, view_url: string}>
     */
    public static function residentLookupPayload(): array
    {
        $payload = [];

        foreach (self::residentFullNameIndex() as $normalized => $resident) {
            $payload[] = [
                'normalized' => $normalized,
                'full_name' => $resident['full_name'],
                'view_url' => $resident['view_url'],
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, array{full_name: string, household_no: string, member_id: string, view_url: string}>
     */
    public static function residentFullNameIndex(): array
    {
        $index = [];

        foreach (DemoCatalog::households() as $household) {
            $householdNo = (string) ($household['householdNo'] ?? '');

            foreach ($household['memberList'] ?? [] as $member) {
                if (! is_array($member)) {
                    continue;
                }

                $fullName = HealthRecordsChildCare::displayName($member);
                $normalized = self::normalizeFullName($fullName);
                if ($normalized === '') {
                    continue;
                }

                $memberId = (string) ($member['id'] ?? '');
                $index[$normalized] = [
                    'full_name' => $fullName,
                    'household_no' => $householdNo,
                    'member_id' => $memberId,
                    'view_url' => $householdNo !== '' && $memberId !== ''
                        ? route('household-profiling.members.show', [
                            'householdNo' => $householdNo,
                            'memberId' => $memberId,
                        ])
                        : '',
                ];
            }
        }

        return $index;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function candidateRecords(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = require resource_path('demo/non-resident-child-care.php');

        return array_map(
            static fn (array $row): array => self::normalizeRecord($row),
            $rows
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function normalizeRecord(array $row): array
    {
        $fullName = self::composeFullName(
            (string) ($row['first_name'] ?? ''),
            (string) ($row['middle_name'] ?? ''),
            (string) ($row['last_name'] ?? '')
        );

        $birthday = (string) ($row['birthday'] ?? '');
        $ageMonths = HealthRecordsChildCare::ageInMonths(['birthday' => $birthday]);
        $year = '';
        if ($birthday !== '' && preg_match('/^(\d{4})/', $birthday, $match) === 1) {
            $year = $match[1];
        }

        $key = self::normalizeKey((string) ($row['key'] ?? $fullName));
        $motherName = trim((string) ($row['mother_name'] ?? ''));
        if ($motherName === '') {
            $motherName = self::composeFullName(
                (string) ($row['mother_first_name'] ?? ''),
                (string) ($row['mother_middle_name'] ?? ''),
                (string) ($row['mother_last_name'] ?? '')
            );
        }

        $addressLine = self::formatAddressLine(
            (string) ($row['address_zone'] ?? ''),
            (string) ($row['barangay'] ?? ''),
            (string) ($row['municipality'] ?? '')
        );

        $measurements = self::normalizeMeasurements(
            is_array($row['measurements'] ?? null) ? $row['measurements'] : [],
            $birthday
        );
        $dewormingRecords = self::normalizeDewormingRecords(
            is_array($row['deworming_records'] ?? null) ? $row['deworming_records'] : []
        );

        return [
            'key' => $key,
            'first_name' => (string) ($row['first_name'] ?? ''),
            'middle_name' => (string) ($row['middle_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'mother_name' => $motherName !== '' ? $motherName : '—',
            'mother_first_name' => (string) ($row['mother_first_name'] ?? ''),
            'mother_middle_name' => (string) ($row['mother_middle_name'] ?? ''),
            'mother_last_name' => (string) ($row['mother_last_name'] ?? ''),
            'birthday' => $birthday,
            'birthday_label' => self::formatBirthday($birthday),
            'sex' => (string) ($row['sex'] ?? ''),
            'address_zone' => (string) ($row['address_zone'] ?? ''),
            'barangay' => (string) ($row['barangay'] ?? ''),
            'municipality' => (string) ($row['municipality'] ?? ''),
            'address_line' => $addressLine,
            'school_name' => (string) ($row['school_name'] ?? ''),
            'grade_level' => (string) ($row['grade_level'] ?? ''),
            'school_grade_label' => self::formatSchoolGrade(
                (string) ($row['school_name'] ?? ''),
                (string) ($row['grade_level'] ?? '')
            ),
            'health_status' => filled($row['health_status'] ?? null)
                ? (string) $row['health_status']
                : HealthRecordsChildCare::EMPTY_RECORD,
            'full_name' => $fullName,
            'age_months' => $ageMonths,
            'age_label' => $ageMonths === null ? '—' : HealthRecordsChildCare::formatAgeMonths($ageMonths),
            'year' => $year,
            'measurements' => $measurements,
            'latest_measurement' => self::latestMeasurement($measurements),
            'first_measurement' => self::firstMeasurement($measurements),
            'view_url' => route('health-records.child-care.non-residents.show', [
                'childKey' => $key,
            ]),
            'edit_url' => route('health-records.child-care.non-residents.edit', [
                'childKey' => $key,
            ]),
            'nutrition_url' => route('health-records.child-care.non-residents.nutrition', [
                'childKey' => $key,
            ]),
            'deworming_records' => $dewormingRecords,
            'deworming_url' => route('health-records.child-care.non-residents.deworming', [
                'childKey' => $key,
            ]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function normalizeDewormingRecords(array $rows): array
    {
        $normalized = [];
        $allowedStatus = array_fill_keys(self::dewormingSeStatusOptions(), true);
        $allowedRounds = array_fill_keys(self::dewormingRoundOptions(), true);

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = strtoupper(trim((string) ($row['id'] ?? '')));
            if ($id === '') {
                continue;
            }

            $year = trim((string) ($row['year'] ?? ''));
            $round = trim((string) ($row['round'] ?? ''));
            $status = trim((string) ($row['se_status'] ?? ''));
            $dateGiven = trim((string) ($row['date_given'] ?? ''));
            $remarks = trim((string) ($row['remarks'] ?? ''));

            $normalized[] = [
                'id' => $id,
                'year' => preg_match('/^\d{4}$/', $year) === 1 ? $year : '',
                'round' => isset($allowedRounds[$round]) ? $round : '',
                'se_status' => isset($allowedStatus[$status]) ? $status : '',
                'date_given' => $dateGiven,
                'date_given_label' => self::formatBirthday($dateGiven),
                'remarks' => $remarks,
            ];
        }

        usort(
            $normalized,
            static function (array $a, array $b): int {
                $yearCmp = strcmp((string) $b['year'], (string) $a['year']);
                if ($yearCmp !== 0) {
                    return $yearCmp;
                }

                return strcmp((string) $b['round'], (string) $a['round']);
            }
        );

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function normalizeMeasurements(array $rows, string $birthday): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = strtoupper(trim((string) ($row['id'] ?? '')));
            $date = (string) ($row['date'] ?? '');
            if ($id === '' || $date === '') {
                continue;
            }

            $ageMonths = self::ageMonthsOnDate($birthday, $date);

            $normalized[] = [
                'id' => $id,
                'date' => $date,
                'date_label' => self::formatBirthday($date),
                'age_months' => $ageMonths,
                'age_label' => $ageMonths === null ? '—' : HealthRecordsChildCare::formatAgeMonths($ageMonths),
                'weight_kg' => self::nullableNumber($row['weight_kg'] ?? null),
                'height_cm' => self::nullableNumber($row['height_cm'] ?? null),
                'muac_cm' => self::nullableNumber($row['muac_cm'] ?? null),
                'weight_for_age' => trim((string) ($row['weight_for_age'] ?? '')),
                'height_for_age' => trim((string) ($row['height_for_age'] ?? '')),
                'status' => trim((string) ($row['status'] ?? '')),
                'remarks' => trim((string) ($row['remarks'] ?? '')),
                'progress' => '—',
                'weight_progress' => '—',
                'height_progress' => '—',
            ];
        }

        usort(
            $normalized,
            static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date'])
        );

        $chronological = array_reverse($normalized);
        $previous = null;
        $withProgress = [];

        foreach ($chronological as $measurement) {
            $weightDelta = $previous === null
                ? null
                : self::deltaLabel($previous['weight_kg'] ?? null, $measurement['weight_kg'] ?? null, 'kg');
            $heightDelta = $previous === null
                ? null
                : self::deltaLabel($previous['height_cm'] ?? null, $measurement['height_cm'] ?? null, 'cm');

            $measurement['weight_progress'] = $weightDelta ?? '—';
            $measurement['height_progress'] = $heightDelta ?? '—';
            $measurement['progress'] = self::formatProgress($previous, $measurement);
            $withProgress[] = $measurement;
            $previous = $measurement;
        }

        return array_reverse($withProgress);
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>  $current
     */
    private static function formatProgress(?array $previous, array $current): string
    {
        if ($previous === null) {
            return '—';
        }

        $parts = [];
        $weightDelta = self::deltaLabel($previous['weight_kg'] ?? null, $current['weight_kg'] ?? null, 'kg');
        $heightDelta = self::deltaLabel($previous['height_cm'] ?? null, $current['height_cm'] ?? null, 'cm');

        if ($weightDelta !== null) {
            $parts[] = $weightDelta;
        }
        if ($heightDelta !== null) {
            $parts[] = $heightDelta;
        }

        return $parts === [] ? '—' : implode(' / ', $parts);
    }

    private static function deltaLabel(mixed $from, mixed $to, string $unit): ?string
    {
        if (! is_numeric($from) || ! is_numeric($to)) {
            return null;
        }

        $delta = round((float) $to - (float) $from, 1);
        $sign = $delta > 0 ? '+' : '';

        return $sign.number_format($delta, 1).' '.$unit;
    }

    private static function nullableNumber(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function formatAddressLine(string $zone, string $barangay, string $municipality): string
    {
        $parts = array_filter(
            [trim($zone), trim($barangay), trim($municipality)],
            static fn (string $part): bool => $part !== ''
        );

        return $parts === [] ? '—' : implode(', ', $parts);
    }

    private static function formatSchoolGrade(string $school, string $grade): string
    {
        $school = trim($school);
        $grade = trim($grade);
        if ($school === '' && ($grade === '' || strcasecmp($grade, 'N/A') === 0)) {
            return 'Not Recorded';
        }
        if ($school === '') {
            return $grade;
        }
        if ($grade === '' || strcasecmp($grade, 'N/A') === 0) {
            return $school;
        }

        return $school.' ('.$grade.')';
    }

    private static function formatBirthday(string $isoDate): string
    {
        if ($isoDate === '') {
            return '—';
        }

        try {
            return \Carbon\Carbon::parse($isoDate)->format('F j, Y');
        } catch (\Throwable) {
            return $isoDate;
        }
    }

    private static function ageMonthsOnDate(string $birthday, string $onDate): ?int
    {
        if ($birthday === '' || $onDate === '') {
            return null;
        }

        try {
            $born = \Carbon\Carbon::parse($birthday)->startOfDay();
            $at = \Carbon\Carbon::parse($onDate)->startOfDay();
            if ($born->greaterThan($at)) {
                return 0;
            }

            return (int) $born->diffInMonths($at);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }
}
