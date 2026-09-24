<?php

namespace App\Support;

/**
 * Demo Risk Assessment catalog helpers.
 *
 * Fixture catalog is read-only; history section edits are session-backed overlays
 * keyed by household + member + assessment id (never creates new assessments).
 */
final class DemoRiskAssessment
{
    public const SESSION_KEY = 'lml.demo.risk_assessments.overlays.v1';

    public const SECTION_RED_FLAGS = 'red-flags';

    public const SECTION_PAST_MEDICAL = 'past-medical';

    public const SECTION_FAMILY_HISTORY = 'family-history';

    public const SECTION_LIFESTYLE = 'lifestyle';

    public const SECTION_PHYSICAL = 'physical';

    /**
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    public static function catalog(): array
    {
        /** @var array<string, array<string, list<array<string, mixed>>>> $catalog */
        $catalog = require resource_path('demo/risk-assessments.php');

        return $catalog;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forMember(string $householdNo, string $memberId): array
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);
        $catalog = self::catalog();
        $rows = $catalog[$hh][$mb] ?? [];

        return array_map(
            static fn (array $row): array => self::applyOverlay($hh, $mb, $row),
            $rows
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $householdNo, string $memberId, string $assessmentId): ?array
    {
        $id = strtoupper(trim($assessmentId));

        foreach (self::forMember($householdNo, $memberId) as $row) {
            if (strtoupper((string) ($row['id'] ?? '')) === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether the assessment exists in the fixture catalog for this member
     * (ignores overlays — used to refuse create-on-update).
     */
    public static function existsInCatalog(string $householdNo, string $memberId, string $assessmentId): bool
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);
        $id = strtoupper(trim($assessmentId));
        $catalog = self::catalog();

        foreach ($catalog[$hh][$mb] ?? [] as $row) {
            if (strtoupper((string) ($row['id'] ?? '')) === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update one section of an existing historical assessment.
     * Returns the updated assessment, or null when the record is not owned /
     * does not exist for this household+member (never creates).
     *
     * @param  array<string, mixed>  $sectionPayload
     * @return array<string, mixed>|null
     */
    public static function updateSection(
        string $householdNo,
        string $memberId,
        string $assessmentId,
        string $section,
        array $sectionPayload
    ): ?array {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);
        $id = strtoupper(trim($assessmentId));
        $sectionKey = self::normalizeSection($section);

        if ($sectionKey === null || ! self::existsInCatalog($hh, $mb, $id)) {
            return null;
        }

        $current = self::find($hh, $mb, $id);
        if ($current === null) {
            return null;
        }

        $patch = self::sectionPayloadToPatch($sectionKey, $sectionPayload);
        if ($patch === null) {
            return null;
        }

        $overlays = self::overlays();
        $existingOverlay = $overlays[$hh][$mb][$id] ?? [];
        if (! is_array($existingOverlay)) {
            $existingOverlay = [];
        }

        $overlays[$hh][$mb][$id] = array_merge($existingOverlay, $patch);
        session([self::SESSION_KEY => $overlays]);

        return self::find($hh, $mb, $id);
    }

    /**
     * @return array<string, array{slug: string, label: string, icon: string}>
     */
    public static function historySections(): array
    {
        return [
            self::SECTION_RED_FLAGS => [
                'slug' => self::SECTION_RED_FLAGS,
                'label' => 'Red Flag Assessment',
                'icon' => 'bi-clipboard2-check',
            ],
            self::SECTION_PAST_MEDICAL => [
                'slug' => self::SECTION_PAST_MEDICAL,
                'label' => 'Past Medical History',
                'icon' => 'bi-heart-pulse',
            ],
            self::SECTION_FAMILY_HISTORY => [
                'slug' => self::SECTION_FAMILY_HISTORY,
                'label' => 'Family History',
                'icon' => 'bi-people',
            ],
            self::SECTION_LIFESTYLE => [
                'slug' => self::SECTION_LIFESTYLE,
                'label' => 'Lifestyle & Risk Factor',
                'icon' => 'bi-heart',
            ],
            self::SECTION_PHYSICAL => [
                'slug' => self::SECTION_PHYSICAL,
                'label' => 'Physical Measurement and Clinical Screening',
                'icon' => 'bi-eyedropper',
            ],
        ];
    }

    public static function normalizeSection(?string $section): ?string
    {
        $slug = strtolower(trim((string) $section));

        return array_key_exists($slug, self::historySections()) ? $slug : null;
    }

    /**
     * Filter history rows by Date Conducted (conducted_at, Y-m-d).
     *
     * Presets: all / '' (no restriction), this_month, last_3_months, this_year, custom.
     * Custom requires both $from and $to; incomplete or From > To leaves rows unfiltered.
     * Boundaries are inclusive. Uses Carbon::today() so tests may freeze time.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function filterByDate(array $rows, ?string $date, ?string $from = null, ?string $to = null): array
    {
        $filter = is_string($date) ? trim($date) : '';
        if ($filter === '' || $filter === 'all') {
            return $rows;
        }

        $today = \Carbon\Carbon::today();

        if ($filter === 'this_month') {
            return self::filterByInclusiveConductedRange(
                $rows,
                $today->copy()->startOfMonth()->toDateString(),
                $today->copy()->endOfMonth()->toDateString()
            );
        }

        if ($filter === 'last_3_months') {
            return self::filterByInclusiveConductedRange(
                $rows,
                $today->copy()->subMonthsNoOverflow(3)->toDateString(),
                $today->toDateString()
            );
        }

        if ($filter === 'this_year') {
            return self::filterByInclusiveConductedRange(
                $rows,
                $today->copy()->startOfYear()->toDateString(),
                $today->copy()->endOfYear()->toDateString()
            );
        }

        if ($filter === 'custom') {
            $fromDate = is_string($from) ? trim($from) : '';
            $toDate = is_string($to) ? trim($to) : '';

            if ($fromDate === '' || $toDate === '' || $fromDate > $toDate) {
                return $rows;
            }

            return self::filterByInclusiveConductedRange($rows, $fromDate, $toDate);
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function filterByInclusiveConductedRange(array $rows, string $from, string $to): array
    {
        return array_values(array_filter(
            $rows,
            static function (array $row) use ($from, $to): bool {
                $conducted = (string) ($row['conducted_at'] ?? '');

                return $conducted !== '' && $conducted >= $from && $conducted <= $to;
            }
        ));
    }

    /**
     * Field option catalogs for wizard / view rendering.
     *
     * @return array<string, mixed>
     */
    public static function fieldDefinitions(): array
    {
        return [
            'red_flags' => [
                'left' => [
                    'chest_pain' => 'Chest Pain',
                    'difficulty_breathing' => 'Difficulty of Breathing',
                    'loss_of_consciousness' => 'Loss of Consciousness',
                    'slurred_speech' => 'Slurred Speech',
                    'facial_asymmetry' => 'Facial Asymmetry',
                    'disoriented' => 'Disoriented as to time, place and person',
                    'chest_retractions' => 'Chest Retractions',
                ],
                'right' => [
                    'seizure' => 'Seizure or Convulsion',
                    'self_harm' => 'Act of Self-Harm / Suicide',
                    'agitated' => 'Agitated and/or aggressive Behavior',
                    'eye_injury' => 'Eye Injury/Foreign Body on the eye',
                    'severe_injuries' => 'Severe Injuries',
                    'weakness_numbness' => 'Weakness/Numbness on arm or left in one side of the body',
                    'none' => 'None of the listed conditions were experienced',
                ],
            ],
            'past_medical' => [
                'left' => [
                    'hypertension' => 'Hypertension',
                    'heart_diseases' => 'Heart Diseases',
                    'diabetes' => 'Diabetes',
                    'cancer' => 'Cancer',
                    'copd' => 'COPD',
                    'asthma' => 'Asthma',
                ],
                'right' => [
                    'mental_neuro_substance' => 'Mental, Neurological, and Substance-Abuse Disorder',
                    'vision_problems' => 'Vision Problems',
                    'previous_surgical' => 'Previous Surgical History',
                    'thyroid' => 'Thyroid Disorder',
                    'kidney' => 'Kidney Disorder',
                    'allergies' => 'Allergies',
                ],
                'none' => 'None of the listed conditions were experienced',
            ],
            'family_history' => [
                'left' => [
                    'hypertension' => 'Hypertension',
                    'stroke' => 'Stroke',
                    // Capstone Figma label (Isch. Heart Disease). Typed brief "Dia Heart Disease" treated as OCR/typo.
                    'isch_heart_disease' => 'Isch. Heart Disease',
                    'diabetes_mellitus' => 'Diabetes Mellitus',
                    'asthma' => 'Asthma',
                    'cancer' => 'Cancer',
                ],
                'right' => [
                    'kidney_disease' => 'Kidney Disease',
                    'premature_heart_vascular' => 'Premature Heart/Vascular Disease (1st-Degree Relative)',
                    'family_tb' => 'Family Member with TB (Past 5 Years)',
                    'mental_neuro_substance' => 'Mental, Neurological, or Substance Use Disorder',
                    'copd' => 'COPD (Chronic Obstructive Pulmonary Disease)',
                    'none' => 'None of the listed conditions were experienced',
                ],
            ],
            'tobacco' => [
                'never' => 'Never',
                'current' => 'Current User',
                'stopped_lt_1y' => 'Stopped < 1 year',
            ],
            'alcohol' => [
                'never' => 'Never',
                'light' => 'Light (Occasional)',
                'excessive' => 'Excessive',
            ],
            'dietary' => [
                'yes' => 'Yes',
                'no' => 'No',
            ],
            'physical_activity' => [
                'yes' => 'Yes',
                'no' => 'No',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedKeysForGroup(string $group): array
    {
        $fields = self::fieldDefinitions();

        return match ($group) {
            'red_flags' => array_keys(array_merge(
                $fields['red_flags']['left'],
                $fields['red_flags']['right']
            )),
            'past_medical' => array_merge(
                array_keys($fields['past_medical']['left']),
                array_keys($fields['past_medical']['right']),
                ['none']
            ),
            'family_history' => array_keys(array_merge(
                $fields['family_history']['left'],
                $fields['family_history']['right']
            )),
            'tobacco' => array_keys($fields['tobacco']),
            'alcohol' => array_keys($fields['alcohol']),
            'dietary' => array_keys($fields['dietary']),
            'physical_activity' => array_keys($fields['physical_activity']),
            default => [],
        };
    }

    /**
     * Enforce "None" mutual exclusivity for checkbox groups.
     *
     * @param  list<string>  $selected
     * @return list<string>
     */
    public static function applyNoneExclusive(array $selected, string $noneKey = 'none'): array
    {
        $clean = array_values(array_unique(array_filter(
            array_map(static fn (mixed $v): string => trim((string) $v), $selected),
            static fn (string $v): bool => $v !== ''
        )));

        if (in_array($noneKey, $clean, true)) {
            return [$noneKey];
        }

        return $clean;
    }

    public static function dietaryQuestion(): string
    {
        return 'Eat high salt food (processed/fast food such as instant noodles, burgers, fries, dried fish, grilled/fried (e.g. isaw, barbecue, liver, chicken skin, high sugar food and drinks (eg. chocolates, cakes, pastries, soft drinks) weekly)?';
    }

    public static function physicalActivityQuestion(): string
    {
        return 'Do at least 2.5 hours a week of moderate-intensity physical activity';
    }

    public static function formatConductedDate(string $isoDate): string
    {
        try {
            $formatted = DisplayDate::format($isoDate);

            return $formatted !== '' ? $formatted : $isoDate;
        } catch (\Throwable) {
            return $isoDate;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function applyOverlay(string $householdNo, string $memberId, array $row): array
    {
        $id = strtoupper((string) ($row['id'] ?? ''));
        if ($id === '') {
            return $row;
        }

        $overlay = self::overlays()[$householdNo][$memberId][$id] ?? null;
        if (! is_array($overlay) || $overlay === []) {
            return $row;
        }

        return array_merge($row, $overlay, ['id' => $id]);
    }

    /**
     * @return array<string, array<string, array<string, array<string, mixed>>>>
     */
    private static function overlays(): array
    {
        $overlays = session(self::SESSION_KEY, []);

        return is_array($overlays) ? $overlays : [];
    }

    /**
     * Map a validated section payload to assessment attribute patch.
     * Public for DB-12 persistence service reuse.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public static function sectionPayloadToPatch(string $section, array $payload): ?array
    {
        return match ($section) {
            self::SECTION_RED_FLAGS => [
                'red_flags' => self::applyNoneExclusive(
                    self::filterAllowedList($payload['red_flags'] ?? [], 'red_flags')
                ),
            ],
            self::SECTION_PAST_MEDICAL => [
                'past_medical' => self::applyNoneExclusive(
                    self::filterAllowedList($payload['past_medical'] ?? [], 'past_medical')
                ),
            ],
            self::SECTION_FAMILY_HISTORY => [
                'family_history' => self::applyNoneExclusive(
                    self::filterAllowedList($payload['family_history'] ?? [], 'family_history')
                ),
            ],
            self::SECTION_LIFESTYLE => [
                'tobacco' => self::nullableAllowedScalar($payload['tobacco'] ?? null, 'tobacco') ?? '',
                'alcohol' => self::nullableAllowedScalar($payload['alcohol'] ?? null, 'alcohol') ?? '',
                'dietary' => self::nullableAllowedScalar($payload['dietary'] ?? null, 'dietary') ?? '',
                'physical_activity' => self::nullableAllowedScalar(
                    $payload['physical_activity'] ?? null,
                    'physical_activity'
                ) ?? '',
            ],
            self::SECTION_PHYSICAL => self::physicalPayloadToPatch($payload),
            default => null,
        };
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private static function filterAllowedList(mixed $value, string $group): array
    {
        $items = is_array($value) ? $value : [];
        $allowed = self::allowedKeysForGroup($group);

        return array_values(array_filter(
            array_map(static fn (mixed $v): string => trim((string) $v), $items),
            static fn (string $v): bool => $v !== '' && in_array($v, $allowed, true)
        ));
    }

    private static function nullableAllowedScalar(mixed $value, string $group): ?string
    {
        $scalar = is_string($value) || is_numeric($value) ? trim((string) $value) : '';
        if ($scalar === '') {
            return null;
        }

        return in_array($scalar, self::allowedKeysForGroup($group), true) ? $scalar : null;
    }

    private static function nullableString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function physicalPayloadToPatch(array $payload): array
    {
        $systolic = self::nullableString($payload['systolic'] ?? null);
        $diastolic = self::nullableString($payload['diastolic'] ?? null);
        $heightCm = self::nullableString($payload['height_cm'] ?? null);
        $weightKg = self::nullableString($payload['weight_kg'] ?? null);

        // Authoritative derived values — ignore any browser-supplied bmi / bp_status.
        $bmi = RiskAssessmentClinicalValues::calculateBmi($heightCm, $weightKg);
        $bpStatus = RiskAssessmentClinicalValues::calculateBpStatus($systolic, $diastolic);

        $patch = [
            'height_cm' => $heightCm,
            'weight_kg' => $weightKg,
            'bmi' => $bmi ?? '',
            'waist_cm' => self::nullableString($payload['waist_cm'] ?? null),
            'systolic' => $systolic,
            'diastolic' => $diastolic,
            'bp_status' => $bpStatus ?? '',
            'visual_no_screening' => ! empty($payload['visual_no_screening']),
            'visual_blurred' => ! empty($payload['visual_blurred']),
            'visual_blurred_note' => self::nullableString($payload['visual_blurred_note'] ?? null),
        ];

        // Presentation sync only — not a clinical BP algorithm beyond status labels.
        if ($systolic !== '' && $diastolic !== '') {
            $patch['bp_reading'] = $systolic.'/'.$diastolic;
        }

        return $patch;
    }
}
