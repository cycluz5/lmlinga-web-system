<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative ERD risk_assessment contract helpers.
 */
final class RiskAssessmentErdMode
{
    public const UNSUPPORTED_SECTION_MESSAGE = 'This information is not recorded in the current Risk Assessment database configuration.';

    /** @var array<string, string> */
    private const TOBACCO_KEY_TO_LABEL = [
        'never' => 'Never',
        'current' => 'Current User',
        'stopped_lt_1y' => 'Stopped < 1 year',
    ];

    /** @var array<string, string> */
    private const ALCOHOL_KEY_TO_LABEL = [
        'never' => 'Never',
        'light' => 'Light (Occasional)',
        'excessive' => 'Excessive',
    ];

    /** @var array<string, string> */
    private const PHYSICAL_ACTIVITY_KEY_TO_LABEL = [
        'yes' => 'Yes',
        'no' => 'No',
    ];

    /** @var array<string, string> */
    private const DIETARY_KEY_TO_LABEL = [
        'yes' => 'Yes',
        'no' => 'No',
    ];

    private static ?bool $active = null;

    private static ?bool $requiresUserId = null;

    private static ?bool $bloodPressureStatusGenerated = null;

    public static function isActive(): bool
    {
        if (self::$active === null) {
            self::$active = ! Schema::hasTable('risk_assessments')
                && Schema::hasTable('risk_assessment')
                && Schema::hasColumn('risk_assessment', 'risk_assessment_id')
                && Schema::hasColumn('risk_assessment', 'resident_id')
                && Schema::hasColumn('risk_assessment', 'user_id');
        }

        return self::$active;
    }

    public static function schemaCompatibleForWrites(): bool
    {
        if (! self::isActive()) {
            return false;
        }

        foreach ([
            'resident_id',
            'user_id',
            'tobacco_vape_usage',
            'alcohol_intake',
            'dietary_habits',
            'physical_activity',
            'height_cm',
            'weight_kg',
            'waist_circum_cm',
            'systolic_blood_pressure',
            'diastolic_blood_pressure',
        ] as $column) {
            if (! Schema::hasColumn('risk_assessment', $column)) {
                return false;
            }
        }

        return true;
    }

    public static function usesSingleSelectDietary(): bool
    {
        return true;
    }

    public static function wizardStepCount(): int
    {
        return 5;
    }

    public static function recordedDateLabel(): string
    {
        return self::isActive() ? 'Recorded Date' : 'Date Conducted';
    }

    public static function sectionSupported(string $section): bool
    {
        return DemoRiskAssessment::normalizeSection($section) !== null;
    }

    /**
     * @return array<string, array{slug: string, label: string, icon: string}>
     */
    public static function visibleHistorySections(): array
    {
        return DemoRiskAssessment::historySections();
    }

    public static function hasHistoryChildTables(): bool
    {
        return Schema::hasTable('red_flags_assessment')
            && Schema::hasTable('past_medical_history')
            && Schema::hasTable('family_history');
    }

    public static function childTableForGroup(string $group): ?string
    {
        return match ($group) {
            'red_flags' => 'red_flags_assessment',
            'past_medical' => 'past_medical_history',
            'family_history' => 'family_history',
            default => null,
        };
    }

    /**
     * UI request key → live child-table column.
     *
     * @return array<string, string>
     */
    public static function uiKeyToColumnForGroup(string $group): array
    {
        return match ($group) {
            'red_flags' => [
                'chest_pain' => 'chest_pain',
                'difficulty_breathing' => 'diff_breathing',
                'loss_of_consciousness' => 'loss_consciousness',
                'slurred_speech' => 'slurred_speech',
                'facial_asymmetry' => 'facial_assym',
                'disoriented' => 'disorientation',
                'chest_retractions' => 'chest_retract',
                'seizure' => 'seizure',
                'self_harm' => 'self_harm',
                'agitated' => 'is_agitated',
                'eye_injury' => 'eye_injury',
                'weakness_numbness' => 'weakness_body',
                'none' => 'no_red_flags',
            ],
            'past_medical' => [
                'hypertension' => 'hypertension',
                'heart_diseases' => 'heart_diseases',
                'diabetes' => 'diabetes',
                'cancer' => 'cancer',
                'copd' => 'copd',
                'asthma' => 'asthma',
                'mental_neuro_substance' => 'mental_disorders',
                'vision_problems' => 'vision_problems',
                'previous_surgical' => 'surgical_history',
                'thyroid' => 'thyroid_disorders',
                'allergies' => 'allergies',
                'none' => 'none_past_medical',
            ],
            'family_history' => [
                'hypertension' => 'hypertension',
                'stroke' => 'stroke',
                'isch_heart_disease' => 'heart_disease',
                'diabetes_mellitus' => 'diabetes_mellitus',
                'asthma' => 'asthma',
                'cancer' => 'cancer',
                'kidney_disease' => 'kidney_disease',
                'premature_heart_vascular' => 'first_degree_cardio',
                'family_tb' => 'tb',
                'mental_neuro_substance' => 'mental_problem',
                'copd' => 'copd',
                'none' => 'none_family_history',
            ],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function erdAllowedUiKeysForGroup(string $group): array
    {
        return array_keys(self::uiKeyToColumnForGroup($group));
    }

    public static function isErdUiKeySupported(string $group, string $key): bool
    {
        return array_key_exists($key, self::uiKeyToColumnForGroup($group));
    }

    /**
     * Figma/request keys with no live column (fail-closed; never persist elsewhere).
     *
     * @return list<string>
     */
    public static function unsupportedUiKeysForGroup(string $group): array
    {
        return array_values(array_diff(
            DemoRiskAssessment::allowedKeysForGroup($group),
            self::erdAllowedUiKeysForGroup($group)
        ));
    }

    public static function tobaccoKeyToLabel(string $key): ?string
    {
        return self::TOBACCO_KEY_TO_LABEL[$key] ?? null;
    }

    public static function tobaccoLabelToKey(string $label): ?string
    {
        return self::reverseLookup(self::TOBACCO_KEY_TO_LABEL, $label);
    }

    public static function alcoholKeyToLabel(string $key): ?string
    {
        return self::ALCOHOL_KEY_TO_LABEL[$key] ?? null;
    }

    public static function alcoholLabelToKey(string $label): ?string
    {
        return self::reverseLookup(self::ALCOHOL_KEY_TO_LABEL, $label);
    }

    public static function physicalActivityKeyToLabel(string $key): ?string
    {
        return self::PHYSICAL_ACTIVITY_KEY_TO_LABEL[$key] ?? null;
    }

    public static function physicalActivityLabelToKey(string $label): ?string
    {
        return self::reverseLookup(self::PHYSICAL_ACTIVITY_KEY_TO_LABEL, $label);
    }

    public static function dietaryKeyToLabel(string $key): ?string
    {
        return self::DIETARY_KEY_TO_LABEL[$key] ?? null;
    }

    public static function dietaryLabelToKey(string $label): ?string
    {
        return self::reverseLookup(self::DIETARY_KEY_TO_LABEL, $label);
    }

    public static function requiresUserId(): bool
    {
        if (self::$requiresUserId === null) {
            self::$requiresUserId = self::detectRequiresUserId();
        }

        return self::$requiresUserId;
    }

    public static function isBloodPressureStatusGenerated(): bool
    {
        if (self::$bloodPressureStatusGenerated === null) {
            self::$bloodPressureStatusGenerated = self::detectBloodPressureStatusGenerated();
        }

        return self::$bloodPressureStatusGenerated;
    }

    /**
     * The app computes and stores blood_pressure_status once the column is a
     * plain (encrypted) column rather than a MySQL generated column.
     */
    public static function writesBloodPressureStatus(): bool
    {
        return self::isActive()
            && Schema::hasColumn('risk_assessment', 'blood_pressure_status')
            && ! self::isBloodPressureStatusGenerated();
    }

    /**
     * Same rules and labels as the former MySQL generated column.
     */
    public static function bloodPressureStatusLabel(mixed $systolic, mixed $diastolic): ?string
    {
        return match (RiskAssessmentClinicalValues::calculateBpStatus($systolic, $diastolic)) {
            RiskAssessmentClinicalValues::BP_NORMAL => 'Normal',
            RiskAssessmentClinicalValues::BP_ELEVATED => 'Elevated',
            RiskAssessmentClinicalValues::BP_STAGE_1 => 'Hypertension Stage 1',
            RiskAssessmentClinicalValues::BP_STAGE_2 => 'Hypertension Stage 2',
            RiskAssessmentClinicalValues::BP_SEVERE => 'Hypertensive Crisis',
            default => null,
        };
    }

    /**
     * Allowed labels per lifestyle column. Hard-coded because the columns are
     * TEXT ciphertext at rest, so the MySQL enum can no longer be read back.
     *
     * @return list<string>
     */
    public static function allowedValues(string $column): array
    {
        return array_values(match ($column) {
            'tobacco_vape_usage' => self::TOBACCO_KEY_TO_LABEL,
            'alcohol_intake' => self::ALCOHOL_KEY_TO_LABEL,
            'physical_activity' => self::PHYSICAL_ACTIVITY_KEY_TO_LABEL,
            'dietary_habits' => self::DIETARY_KEY_TO_LABEL,
            default => [],
        });
    }

    public static function usesStrictEnumDomain(string $column): bool
    {
        return self::allowedValues($column) !== [];
    }

    public static function isValidEnumValue(string $column, string $value): bool
    {
        $allowed = self::allowedValues($column);

        return $allowed === [] || in_array($value, $allowed, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function isWritablePayloadValid(array $payload): bool
    {
        if (self::requiresUserId()) {
            if (! isset($payload['user_id']) || (int) $payload['user_id'] <= 0) {
                return false;
            }
        }

        if (self::isBloodPressureStatusGenerated() && array_key_exists('blood_pressure_status', $payload)) {
            return false;
        }

        foreach ([
            'tobacco_vape_usage',
            'alcohol_intake',
            'physical_activity',
            'dietary_habits',
        ] as $column) {
            if (! isset($payload[$column])) {
                continue;
            }

            $value = (string) $payload[$column];
            if ($value === '') {
                continue;
            }

            if (! self::isValidEnumValue($column, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $payload): array
    {
        if (isset($payload['tobacco_vape_usage'])) {
            $payload['tobacco_vape_usage'] = self::normalizeTobaccoUsage((string) $payload['tobacco_vape_usage']);
        }

        if (isset($payload['alcohol_intake'])) {
            $payload['alcohol_intake'] = self::normalizeAlcoholIntake((string) $payload['alcohol_intake']);
        }

        if (isset($payload['physical_activity'])) {
            $payload['physical_activity'] = self::normalizePhysicalActivity((string) $payload['physical_activity']);
        }

        if (isset($payload['dietary_habits'])) {
            $payload['dietary_habits'] = self::normalizeDietaryHabits((string) $payload['dietary_habits']);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function filterWritablePayload(array $payload): array
    {
        unset(
            $payload['risk_assessment_id'],
            $payload['created_at'],
        );

        if (! self::writesBloodPressureStatus()) {
            unset($payload['blood_pressure_status']);
        }

        return $payload;
    }

    public static function normalizeTobaccoUsage(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }

        if (($key = self::tobaccoLabelToKey($trimmed)) !== null) {
            return self::tobaccoKeyToLabel($key) ?? $trimmed;
        }

        if (($label = self::tobaccoKeyToLabel($trimmed)) !== null) {
            return $label;
        }

        $allowed = self::allowedValues('tobacco_vape_usage');
        if ($allowed !== [] && in_array($trimmed, $allowed, true)) {
            return $trimmed;
        }

        $lower = strtolower($trimmed);

        return match (true) {
            in_array($lower, ['never', 'none'], true) => 'Never',
            in_array($lower, ['current', 'current user', 'current smoker'], true) => 'Current User',
            str_contains($lower, 'stopped') => 'Stopped < 1 year',
            default => $trimmed,
        };
    }

    public static function normalizeAlcoholIntake(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }

        if (($key = self::alcoholLabelToKey($trimmed)) !== null) {
            return self::alcoholKeyToLabel($key) ?? $trimmed;
        }

        if (($label = self::alcoholKeyToLabel($trimmed)) !== null) {
            return $label;
        }

        $allowed = self::allowedValues('alcohol_intake');
        if ($allowed !== [] && in_array($trimmed, $allowed, true)) {
            return $trimmed;
        }

        $lower = strtolower($trimmed);

        return match (true) {
            in_array($lower, ['never', 'none'], true) => 'Never',
            in_array($lower, ['moderate', 'light', 'light (occasional)', 'occasional'], true) => 'Light (Occasional)',
            in_array($lower, ['excessive', 'heavy'], true) => 'Excessive',
            default => $trimmed,
        };
    }

    public static function normalizePhysicalActivity(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }

        if (($key = self::physicalActivityLabelToKey($trimmed)) !== null) {
            return self::physicalActivityKeyToLabel($key) ?? $trimmed;
        }

        if (($label = self::physicalActivityKeyToLabel($trimmed)) !== null) {
            return $label;
        }

        $allowed = self::allowedValues('physical_activity');
        if ($allowed !== [] && in_array($trimmed, $allowed, true)) {
            return $trimmed;
        }

        $lower = strtolower($trimmed);

        return match ($lower) {
            'yes' => 'Yes',
            'no' => 'No',
            default => $trimmed,
        };
    }

    public static function normalizeDietaryHabits(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }

        if (($key = self::dietaryLabelToKey($trimmed)) !== null) {
            return self::dietaryKeyToLabel($key) ?? $trimmed;
        }

        if (($label = self::dietaryKeyToLabel($trimmed)) !== null) {
            return $label;
        }

        $allowed = self::allowedValues('dietary_habits');
        if ($allowed !== [] && in_array($trimmed, $allowed, true)) {
            return $trimmed;
        }

        $lower = strtolower($trimmed);

        return match ($lower) {
            'yes' => 'Yes',
            'no' => 'No',
            default => $trimmed,
        };
    }

    public static function resetCachedState(): void
    {
        self::$active = null;
        self::$requiresUserId = null;
        self::$bloodPressureStatusGenerated = null;
    }

    /**
     * @param  array<string, string>  $map
     */
    private static function reverseLookup(array $map, string $label): ?string
    {
        foreach ($map as $key => $mappedLabel) {
            if ($mappedLabel === $label) {
                return $key;
            }
        }

        return null;
    }

    private static function detectRequiresUserId(): bool
    {
        if (! Schema::hasTable('risk_assessment') || ! Schema::hasColumn('risk_assessment', 'user_id')) {
            return false;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $row = DB::selectOne(
                'SELECT IS_NULLABLE, COLUMN_DEFAULT
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?',
                ['risk_assessment', 'user_id']
            );

            return $row !== null
                && strtoupper((string) ($row->IS_NULLABLE ?? '')) === 'NO'
                && ($row->COLUMN_DEFAULT === null);
        }

        if ($driver === 'sqlite') {
            foreach (DB::select("PRAGMA table_info('risk_assessment')") as $column) {
                if (($column->name ?? '') === 'user_id') {
                    return (int) ($column->notnull ?? 0) === 1;
                }
            }
        }

        return false;
    }

    private static function detectBloodPressureStatusGenerated(): bool
    {
        if (! Schema::hasTable('risk_assessment') || ! Schema::hasColumn('risk_assessment', 'blood_pressure_status')) {
            return false;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $row = DB::selectOne(
                'SELECT EXTRA FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?',
                ['risk_assessment', 'blood_pressure_status']
            );

            return $row !== null
                && str_contains(strtoupper((string) ($row->EXTRA ?? '')), 'GENERATED');
        }

        if ($driver === 'sqlite') {
            try {
                foreach (DB::select("PRAGMA table_xinfo('risk_assessment')") as $column) {
                    if (($column->name ?? '') === 'blood_pressure_status' && (int) ($column->hidden ?? 0) !== 0) {
                        return true;
                    }
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }
}
