<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Barangay-wide Deworming monitoring summary structure (Health Records → Child Care).
 *
 * UI-PHASE ONLY: {@see monitoringRows()}, {@see summaryCards()} return Figma
 * screenshot preview/demo display values so the page can be visually compared.
 * These are NOT authoritative production aggregates, are NOT persisted, and must
 * NOT be treated as seed data or business-rule confirmation.
 *
 * Individual Deworming record / Add Record pages resolve resident workflow children
 * from DemoCatalog when possible, otherwise from deterministic Deworming UI-phase
 * supplemental profiles for monitoring-row keys (same identity fields already used
 * in project fixtures). History is derived from monitoring July/January dates with
 * descriptive SE Status / Remarks (no dash placeholders).
 *
 * Status percentages are literal Figma display strings (e.g. "84%").
 * Do NOT derive percentages from round counts until an approved formula exists.
 */
final class HealthRecordsDeworming
{
    public const EMPTY_CELL = '';

    public const SCHOOL_NOT_YET = 'Not yet school-aged';

    public const REMARKS_NONE = 'No concerns reported';

    /**
     * UI-phase summary card display values from the Deworming Figma frame.
     *
     * @return array{
     *     first_round: string,
     *     second_round: string,
     *     received_1_dose_pct: string,
     *     received_2_dose_pct: string
     * }
     */
    public static function summaryCards(): array
    {
        return [
            // Figma preview/demo values only — not production aggregates.
            'first_round' => '60',
            'second_round' => '0',
            'received_1_dose_pct' => '0%', // literal Figma display — do not derive
            'received_2_dose_pct' => '84%', // literal Figma display — do not derive
        ];
    }

    /**
     * Project-supported Deworming round values (same domain as Non-Resident UI-phase).
     *
     * @return list<string>
     */
    public static function roundOptions(): array
    {
        return ['1', '2'];
    }

    /**
     * Project-supported SE Status labels (Household NHTS terminology).
     *
     * @return list<string>
     */
    public static function seStatusOptions(): array
    {
        return ['NHTS', 'Non-NHTS'];
    }

    /**
     * UI-phase child monitoring rows with Figma preview/demo display values.
     *
     * Every monitoring row resolves a resident Deworming workflow destination via
     * {@see findChild()} (DemoCatalog first, then supplemental UI-phase profiles).
     *
     * @return list<array<string, mixed>>
     */
    public static function monitoringRows(): array
    {
        $rows = self::baseMonitoringRows();

        foreach ($rows as &$row) {
            $child = self::findChild((string) $row['key']);
            if ($child === null) {
                $row['view_url'] = null;
                $row['create_url'] = null;

                continue;
            }

            $row['view_url'] = $child['view_url'];
            $row['create_url'] = $child['create_url'];
        }
        unset($row);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function baseMonitoringRows(): array
    {
        $zones = self::zones();
        $zoneFallback = $zones[0] ?? 'Zone 1';

        // Figma preview/demo rows — each key has a resident Deworming workflow profile.
        return [
            self::row('kristine-b-reyes', 'Kristine B. Reyes', '3 yrs old', 'July 1, 2026', 'January 20, 2026', [
                'zone' => $zones[0] ?? $zoneFallback,
                'sex' => 'female',
                'status' => '2-doses',
            ]),
            self::row('jacob-a-magistrado', 'Jacob A. Magistrado', '5 yrs old', 'July 1, 2026', 'January 20, 2026', [
                'zone' => $zones[1] ?? $zoneFallback,
                'sex' => 'male',
                'status' => '2-doses',
            ]),
            self::row('haziel-h-santos', 'Haziel H. Santos', '4 yrs old', 'July 1, 2026', 'January 20, 2026', [
                'zone' => $zones[2] ?? $zoneFallback,
                'sex' => 'female',
                'status' => '2-doses',
            ]),
            self::row('andrei-b-malaya', 'Andrei B. Malaya', '3 yrs old', 'July 1, 2026', 'January 20, 2026', [
                'zone' => $zones[0] ?? $zoneFallback,
                'sex' => 'male',
                'status' => '2-doses',
            ]),
            self::row('crisley-f-fernando', 'Crisley F. Fernando', '3 yrs old', 'July 1, 2026', 'January 20, 2026', [
                'zone' => $zones[1] ?? $zoneFallback,
                'sex' => 'female',
                'status' => '2-doses',
            ]),
            self::row('gabriel-allan-s-chua', 'Gabriel Allan S. Chua', '4 yrs old', 'July 1, 2026', 'January 20, 2026', [
                'zone' => $zones[2] ?? $zoneFallback,
                'sex' => 'male',
                'status' => '2-doses',
            ]),
        ];
    }

    /**
     * Resolve a resident Deworming child profile for the individual/add workflow.
     * DemoCatalog household members take precedence; otherwise supplemental UI-phase
     * profiles cover remaining monitoring-row keys (Andrei / Crisley / Gabriel).
     *
     * @return array<string, mixed>|null
     */
    public static function findChild(string $childKey): ?array
    {
        $childKey = strtolower(trim($childKey));
        if ($childKey === '' || preg_match('/^[a-z0-9\-]+$/', $childKey) !== 1) {
            return null;
        }

        foreach (DemoCatalog::households() as $household) {
            if (! is_array($household)) {
                continue;
            }

            $householdNo = (string) ($household['householdNo'] ?? '');
            foreach ($household['memberList'] ?? [] as $member) {
                if (! is_array($member)) {
                    continue;
                }

                if (! HealthRecordsChildCare::isChildCarePopulation($member)) {
                    continue;
                }

                $fullName = HealthRecordsChildCare::displayName($member);
                if (self::slugifyName($fullName) !== $childKey) {
                    continue;
                }

                return self::buildChildProfileFromHousehold($childKey, $household, $member, $householdNo);
            }
        }

        return self::findSupplementalChild($childKey);
    }

    /**
     * Deworming history for a monitoring child key.
     * Built from the July / January monitoring display dates with descriptive
     * SE Status and Remarks (never dash placeholders).
     *
     * @return list<array<string, mixed>>
     */
    public static function recordsFor(string $childKey): array
    {
        $childKey = strtolower(trim($childKey));
        foreach (self::baseMonitoringRows() as $row) {
            if ((string) ($row['key'] ?? '') !== $childKey) {
                continue;
            }

            return self::recordsFromMonitoringRow($row);
        }

        return [];
    }

    /**
     * Status filter options for the Deworming UI-phase toolbar.
     *
     * @return array<string, string>
     */
    public static function statusFilterOptions(): array
    {
        return [
            'all' => 'Status',
            '1-dose' => 'Received 1 dose/year',
            '2-doses' => 'Received 2 dose/year',
            'none' => 'No dose recorded',
        ];
    }

    /**
     * Zones available for the Deworming filter (household demo catalog).
     *
     * @return list<string>
     */
    public static function zones(): array
    {
        $zones = [];

        foreach (DemoCatalog::households() as $household) {
            $zone = trim((string) ($household['zone'] ?? ''));
            if ($zone !== '') {
                $zones[$zone] = true;
            }
        }

        $list = array_keys($zones);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    /**
     * @param  array{zone?: string, sex?: string, status?: string}  $meta
     * @return array<string, mixed>
     */
    private static function row(
        string $key,
        string $fullName,
        string $ageLabel,
        string $julyRound,
        string $januaryRound,
        array $meta = []
    ): array {
        return [
            'key' => $key,
            'full_name' => $fullName,
            'age_label' => $ageLabel,
            'july_round' => $julyRound,
            'january_round' => $januaryRound,
            'zone' => (string) ($meta['zone'] ?? ''),
            'sex' => (string) ($meta['sex'] ?? ''),
            'status' => (string) ($meta['status'] ?? 'all'),
            'view_url' => null,
            'create_url' => null,
        ];
    }

    /**
     * Deterministic UI-phase profiles for monitoring keys not in DemoCatalog.
     * Identity fields match existing project fixture data (same names/DOB/mothers).
     *
     * @return array<string, mixed>|null
     */
    private static function findSupplementalChild(string $childKey): ?array
    {
        $profiles = self::supplementalProfiles();
        if (! isset($profiles[$childKey]) || ! is_array($profiles[$childKey])) {
            return null;
        }

        return self::buildChildProfileFromSupplemental($childKey, $profiles[$childKey]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function supplementalProfiles(): array
    {
        return [
            'andrei-b-malaya' => [
                'full_name' => 'Andrei B. Malaya',
                'sex' => 'Male',
                'birthday' => '2026-04-12',
                'mother_name' => 'Liza B. Malaya',
                'address_line' => 'Zone 3, Brgy. San Jose, Iriga City',
                'school_grade_label' => self::SCHOOL_NOT_YET,
            ],
            'crisley-f-fernando' => [
                'full_name' => 'Crisley F. Fernando',
                'sex' => 'Female',
                'birthday' => '2025-08-01',
                'mother_name' => 'Ana F. Fernando',
                'address_line' => 'Poblacion, Brgy. Del Rosario, Naga City',
                'school_grade_label' => self::SCHOOL_NOT_YET,
            ],
            'gabriel-allan-s-chua' => [
                'full_name' => 'Gabriel Allan S. Chua',
                'sex' => 'Male',
                'birthday' => '2024-06-15',
                'mother_name' => 'Michelle S. Chua',
                'address_line' => 'Zone 1, Brgy. Mabulo, Naga City',
                'school_grade_label' => self::SCHOOL_NOT_YET,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $household
     * @param  array<string, mixed>  $member
     * @return array<string, mixed>
     */
    private static function buildChildProfileFromHousehold(
        string $childKey,
        array $household,
        array $member,
        string $householdNo
    ): array {
        $birthday = trim((string) ($member['birthday'] ?? ''));
        $ageMonths = HealthRecordsChildCare::ageInMonths($member);
        $sex = trim((string) ($member['sex'] ?? ''));
        $education = trim((string) ($member['education'] ?? ''));
        $schoolGrade = self::schoolGradeLabel($education, $ageMonths);

        $address = trim((string) ($household['address'] ?? ''));
        if ($address === '') {
            $parts = array_filter([
                trim((string) ($household['street'] ?? '')),
                trim((string) ($household['zone'] ?? '')),
            ], static fn (string $part): bool => $part !== '');
            $address = $parts !== [] ? implode(', ', $parts) : 'Address not recorded in household profile';
        }

        $mother = self::motherNameFromHousehold($household, (string) ($member['id'] ?? ''));

        return self::withWorkflowUrls([
            'key' => $childKey,
            'household_no' => $householdNo,
            'member_id' => (string) ($member['id'] ?? ''),
            'full_name' => HealthRecordsChildCare::displayName($member),
            'sex' => $sex !== '' ? $sex : 'Sex not recorded',
            'birthday' => $birthday,
            'birthday_label' => self::formatDisplayDate($birthday),
            'age_months' => $ageMonths,
            'age_label' => $ageMonths === null
                ? 'Age not recorded'
                : HealthRecordsChildCare::formatAgeMonths($ageMonths),
            'mother_name' => $mother,
            'address_line' => $address,
            'school_grade_label' => $schoolGrade,
        ]);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private static function buildChildProfileFromSupplemental(string $childKey, array $profile): array
    {
        $birthday = trim((string) ($profile['birthday'] ?? ''));
        $ageMonths = null;
        if ($birthday !== '') {
            try {
                $ageMonths = (int) Carbon::parse($birthday)->startOfDay()->diffInMonths(Carbon::now()->startOfDay());
            } catch (\Throwable) {
                $ageMonths = null;
            }
        }

        return self::withWorkflowUrls([
            'key' => $childKey,
            'household_no' => '',
            'member_id' => '',
            'full_name' => (string) ($profile['full_name'] ?? 'Unknown'),
            'sex' => filled($profile['sex'] ?? null) ? (string) $profile['sex'] : 'Sex not recorded',
            'birthday' => $birthday,
            'birthday_label' => self::formatDisplayDate($birthday),
            'age_months' => $ageMonths,
            'age_label' => $ageMonths === null
                ? 'Age not recorded'
                : HealthRecordsChildCare::formatAgeMonths($ageMonths),
            'mother_name' => filled($profile['mother_name'] ?? null)
                ? (string) $profile['mother_name']
                : 'Mother not recorded',
            'address_line' => filled($profile['address_line'] ?? null)
                ? (string) $profile['address_line']
                : 'Address not recorded',
            'school_grade_label' => filled($profile['school_grade_label'] ?? null)
                ? (string) $profile['school_grade_label']
                : self::SCHOOL_NOT_YET,
        ]);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private static function withWorkflowUrls(array $profile): array
    {
        $childKey = (string) $profile['key'];

        $profile['view_url'] = route('health-records.child-care.deworming.show', [
            'childKey' => $childKey,
        ]);
        $profile['create_url'] = route('health-records.child-care.deworming.create', [
            'childKey' => $childKey,
        ]);
        $profile['summary_url'] = route('health-records.child-care.deworming');

        return $profile;
    }

    private static function schoolGradeLabel(string $education, ?int $ageMonths): string
    {
        if ($education !== '' && strcasecmp($education, 'N/A') !== 0) {
            return $education;
        }

        if ($ageMonths === null || $ageMonths < 36) {
            return self::SCHOOL_NOT_YET;
        }

        return self::SCHOOL_NOT_YET;
    }

    /**
     * @param  array<string, mixed>  $household
     */
    private static function motherNameFromHousehold(array $household, string $excludeMemberId): string
    {
        foreach ($household['memberList'] ?? [] as $member) {
            if (! is_array($member)) {
                continue;
            }

            if ((string) ($member['id'] ?? '') === $excludeMemberId) {
                continue;
            }

            $sex = strtolower(trim((string) ($member['sex'] ?? '')));
            if ($sex !== 'female') {
                continue;
            }

            $relation = strtolower(trim((string) ($member['relationship'] ?? $member['relation'] ?? '')));
            if (! in_array($relation, ['wife', 'spouse', 'mother', 'head'], true)) {
                continue;
            }

            $name = HealthRecordsChildCare::displayName($member);

            return $name !== '' ? $name : 'Mother not recorded in household profile';
        }

        return 'Mother not recorded in household profile';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private static function recordsFromMonitoringRow(array $row): array
    {
        $records = [];
        $key = (string) ($row['key'] ?? 'child');
        $seOptions = self::seStatusOptions();

        $july = self::parseMonitoringDate((string) ($row['july_round'] ?? ''));
        if ($july !== null) {
            $records[] = [
                'id' => 'DW-'.strtoupper($key).'-R1',
                'year' => $july['year'],
                'round' => '1',
                'se_status' => $seOptions[1] ?? 'Non-NHTS',
                'date_given' => $july['iso'],
                'date_given_label' => $july['label'],
                'remarks' => self::REMARKS_NONE,
            ];
        }

        $january = self::parseMonitoringDate((string) ($row['january_round'] ?? ''));
        if ($january !== null) {
            $records[] = [
                'id' => 'DW-'.strtoupper($key).'-R2',
                'year' => $january['year'],
                'round' => '2',
                'se_status' => $seOptions[0] ?? 'NHTS',
                'date_given' => $january['iso'],
                'date_given_label' => $january['label'],
                'remarks' => self::REMARKS_NONE,
            ];
        }

        usort(
            $records,
            static function (array $a, array $b): int {
                $yearCmp = strcmp((string) $b['year'], (string) $a['year']);
                if ($yearCmp !== 0) {
                    return $yearCmp;
                }

                return strcmp((string) $b['round'], (string) $a['round']);
            }
        );

        return $records;
    }

    /**
     * @return array{iso: string, year: string, label: string}|null
     */
    private static function parseMonitoringDate(string $label): ?array
    {
        $label = trim($label);
        if ($label === '' || strcasecmp($label, 'No record') === 0) {
            return null;
        }

        try {
            $date = Carbon::parse($label)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return [
            'iso' => $date->toDateString(),
            'year' => $date->format('Y'),
            'label' => $date->format('F j, Y'),
        ];
    }

    private static function formatDisplayDate(string $isoDate): string
    {
        $isoDate = trim($isoDate);
        if ($isoDate === '') {
            return 'Date of birth not recorded';
        }

        try {
            return Carbon::parse($isoDate)->format('F j, Y');
        } catch (\Throwable) {
            return $isoDate;
        }
    }

    private static function slugifyName(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }
}
