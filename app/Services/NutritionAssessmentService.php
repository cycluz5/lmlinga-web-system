<?php

namespace App\Services;

use App\Models\Resident;
use App\Models\TimbangRecord;
use App\Support\GrowthReferenceLoader;
use App\Support\HealthRecordsChildCare;
use App\Support\HealthRecordsRiskAssessment;
use App\Support\RiskAssessmentClinicalValues;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Single authoritative implementation for age-adaptive Nutritional Status
 * assessment. Age is always computed at the measurement date (never "today")
 * so historical records stay correct as a resident grows older.
 *
 * Server-side only. Controllers must not contain classification logic;
 * Blade/JS may only mirror this for UX (enable/disable, live preview) and
 * must never be trusted as the source of truth.
 */
final class NutritionAssessmentService
{
    public const BAND_INFANT = '0-5m';

    public const BAND_CHILD = '6-59m';

    public const BAND_ADOLESCENT = '5-19y';

    public const BAND_ADULT = 'adult';

    /**
     * Completed age in months at the measurement date. Null when the
     * birthday or measurement date is missing/unparseable, or the resident
     * was not yet born on the measurement date.
     */
    public function ageInMonthsAtMeasurement(mixed $birthday, mixed $measurementDate): ?int
    {
        $born = $this->toCarbonDate($birthday);
        $on = $this->toCarbonDate($measurementDate);
        if ($born === null || $on === null || $born->greaterThan($on)) {
            return null;
        }

        return (int) $born->diffInMonths($on);
    }

    /**
     * Completed age in years at the measurement date.
     */
    public function ageInYearsAtMeasurement(mixed $birthday, mixed $measurementDate): ?int
    {
        $born = $this->toCarbonDate($birthday);
        $on = $this->toCarbonDate($measurementDate);
        if ($born === null || $on === null || $born->greaterThan($on)) {
            return null;
        }

        return (int) $born->diffInYears($on);
    }

    /**
     * Which age-adaptive form/classification band applies at the measurement
     * date. Null when age cannot be determined.
     */
    public function ageBand(mixed $birthday, mixed $measurementDate): ?string
    {
        $ageMonths = $this->ageInMonthsAtMeasurement($birthday, $measurementDate);
        if ($ageMonths === null) {
            return null;
        }

        if ($ageMonths < 6) {
            return self::BAND_INFANT;
        }
        if ($ageMonths < 60) {
            return self::BAND_CHILD;
        }

        $ageYears = $this->ageInYearsAtMeasurement($birthday, $measurementDate);

        return $ageYears !== null && $ageYears >= HealthRecordsRiskAssessment::MIN_AGE_YEARS
            ? self::BAND_ADULT
            : self::BAND_ADOLESCENT;
    }

    public function muacApplicable(string $band): bool
    {
        return $band === self::BAND_CHILD;
    }

    public function bmiApplicable(string $band): bool
    {
        return $band === self::BAND_ADOLESCENT || $band === self::BAND_ADULT;
    }

    public function weightForAgeApplicable(string $band): bool
    {
        return $band === self::BAND_INFANT || $band === self::BAND_CHILD;
    }

    /**
     * Weight-for-Length/Height shares Weight-for-Age's 0-59 month band —
     * the same WHO population the paper-ERD schema was designed around.
     */
    public function weightForHeightApplicable(string $band): bool
    {
        return $this->weightForAgeApplicable($band);
    }

    /**
     * BMI = weight_kg / (height_m ^ 2), rounded to 1 decimal. Reuses the
     * project's one BMI formula (RiskAssessmentClinicalValues::calculateBmi).
     */
    public function calculateBmi(mixed $weightKg, mixed $heightCm): ?float
    {
        $value = RiskAssessmentClinicalValues::calculateBmi($heightCm, $weightKg);

        return $value === null ? null : (float) $value;
    }

    /**
     * Adult (19y+) BMI classification — delegates to the one shared standard
     * also used by HealthRecordsRiskAssessment, so the two features never
     * disagree.
     */
    public function classifyAdultBmi(float $bmi): string
    {
        return RiskAssessmentClinicalValues::classifyAdultBmi($bmi);
    }

    /**
     * BMI-for-Age (5-19y). Numeric BMI is always computable, but the project
     * has no approved BMI-for-Age threshold reference yet, so classification
     * is intentionally withheld rather than borrowing adult cutoffs.
     *
     * @return array{value: float|null, status: string|null}
     */
    public function classifyBmiForAge(?float $bmi): array
    {
        if ($bmi === null) {
            return ['value' => null, 'status' => null];
        }

        return ['value' => $bmi, 'status' => TimbangRecord::REFERENCE_UNAVAILABLE];
    }

    /**
     * Weight-for-Age (0-59 completed months). Direct lookup against the
     * National Nutrition Council (DOH) Child Growth Standards reference
     * (resources/growth-references/nnc_growth_standards.json — official
     * absolute cutoffs per age-in-months/sex, no interpolation needed).
     * Null when sex, weight, or the reference row is unavailable — never
     * guessed.
     */
    public function classifyWeightForAge(mixed $sex, int $ageMonths, mixed $weightKg): ?string
    {
        $sexKey = GrowthReferenceLoader::nncSexKey($sex);
        if ($sexKey === null || ! is_numeric($weightKg) || $ageMonths < 0) {
            return null;
        }

        $row = GrowthReferenceLoader::nncWeightForAgeRow($sexKey, $ageMonths);
        if ($row === null) {
            return null;
        }

        [$severe, , , $normalFrom, $normalTo] = $row;
        $weight = (float) $weightKg;

        return match (true) {
            $weight < $severe => 'Severely Underweight',
            $weight < $normalFrom => 'Underweight',
            $weight <= $normalTo => 'Normal',
            default => 'Overweight',
        };
    }

    /**
     * Height/Length-for-Age (0-59 completed months). Direct lookup against
     * the same NNC reference used for Weight-for-Age. Null when sex,
     * height, or the reference row is unavailable — never guessed.
     */
    public function classifyHeightForAge(mixed $sex, int $ageMonths, mixed $heightCm): ?string
    {
        $sexKey = GrowthReferenceLoader::nncSexKey($sex);
        if ($sexKey === null || ! is_numeric($heightCm) || $ageMonths < 0) {
            return null;
        }

        $row = GrowthReferenceLoader::nncHeightForAgeRow($sexKey, $ageMonths);
        if ($row === null) {
            return null;
        }

        [$severeStunted, , , $normalFrom, $normalTo] = $row;
        $height = (float) $heightCm;

        return match (true) {
            $height < $severeStunted => 'Severely Stunted',
            $height < $normalFrom => 'Stunted',
            $height <= $normalTo => 'Normal',
            default => 'Tall',
        };
    }

    /**
     * Weight-for-Length/Height (0-59 completed months). Direct lookup
     * against the WHO SD-position reference
     * (resources/growth-references/who_weight_for_length_height_zscore.csv),
     * rounded to the nearest 0.5cm grid row. WHO tabulates this indicator
     * two ways — "Weight-for-length" (recumbent, used under 24 months) and
     * "Weight-for-height" (standing, 24 months and up) — the resident's age
     * at measurement picks which table applies, since this project does not
     * separately record which position was used to take the measurement.
     * Standard WHO cut points: <-3SD Severely Wasted, -3 to <-2SD Wasted,
     * -2 to +1SD Normal, >+1 to +2SD Possible Risk of Overweight, >+2SD
     * Overweight. Null when sex, height, weight, or the reference row is
     * unavailable — never guessed.
     */
    public function classifyWeightForHeight(mixed $sex, int $ageMonths, mixed $heightCm, mixed $weightKg): ?string
    {
        $sexLabel = GrowthReferenceLoader::sexLabel($sex);
        if ($sexLabel === null || ! is_numeric($heightCm) || ! is_numeric($weightKg)) {
            return null;
        }

        $indicator = $ageMonths < 24 ? 'Weight-for-length' : 'Weight-for-height';
        $row = GrowthReferenceLoader::whoWeightForLengthOrHeightRow($sexLabel, $indicator, (float) $heightCm);
        if ($row === null) {
            return null;
        }

        [$neg3, $neg2, , , $pos1, $pos2] = $row;
        $weight = (float) $weightKg;

        return match (true) {
            $weight < $neg3 => 'Severely Wasted',
            $weight < $neg2 => 'Wasted',
            $weight <= $pos1 => 'Normal',
            $weight <= $pos2 => 'Possible Risk of Overweight',
            default => 'Overweight',
        };
    }

    /**
     * MUAC (6-59 completed months only). Absolute-cm thresholds from
     * who_status_thresholds.csv: <11.5 SAM, 11.5-<12.5 MAM, >=12.5 Normal.
     * Returns 'N/A' outside the applicable band (even when a value was
     * somehow supplied), null when applicable but not measured this visit.
     */
    public function classifyMuac(int $ageMonths, mixed $muacCm): ?string
    {
        if ($ageMonths < 6 || $ageMonths >= 60) {
            return TimbangRecord::MUAC_STATUS[3]; // 'N/A'
        }

        if (! is_numeric($muacCm)) {
            return null;
        }

        $value = (float) $muacCm;
        foreach (GrowthReferenceLoader::muacAbsoluteThresholds() as $row) {
            $min = (float) ($row['Min_Value'] ?? 0);
            $max = (float) ($row['Max_Value'] ?? 0);
            if ($value >= $min && $value < $max) {
                return (string) ($row['Status'] ?? '');
            }
        }

        return null;
    }

    /**
     * "Worst applicable result wins": each indicator's concrete status is
     * mapped to a severity tier; unmapped/sentinel (N/A, pending-reference,
     * not measured) values are excluded rather than guessed. Null when no
     * indicator produced a concrete result.
     *
     * @param  array{weight_for_age?: string|null, height_for_age?: string|null, weight_for_height?: string|null, muac_status?: string|null, bmi_status?: string|null}  $indicators
     */
    public function determineOverallStatus(array $indicators): ?string
    {
        $severityMaps = [
            'weight_for_age' => [
                'Normal' => 0,
                'Overweight' => 1,
                'Underweight' => 2,
                'Severely Underweight' => 3,
            ],
            'height_for_age' => [
                'Normal' => 0,
                'Tall' => 0,
                'Stunted' => 2,
                'Severely Stunted' => 3,
            ],
            'weight_for_height' => [
                'Normal' => 0,
                'Possible Risk of Overweight' => 1,
                'Wasted' => 2,
                'Overweight' => 2,
                'Severely Wasted' => 3,
            ],
            'muac_status' => [
                'Normal' => 0,
                'Moderate Acute Malnutrition (MAM)' => 2,
                'Severe Acute Malnutrition (SAM)' => 3,
            ],
            'bmi_status' => [
                'Normal' => 0,
                'Overweight' => 1,
                'Underweight' => 2,
                'Obese' => 2,
            ],
        ];

        $worstTier = null;
        foreach ($indicators as $key => $status) {
            if ($status === null) {
                continue;
            }

            $map = $severityMaps[$key] ?? null;
            if ($map === null || ! array_key_exists($status, $map)) {
                continue;
            }

            $tier = $map[$status];
            if ($worstTier === null || $tier > $worstTier) {
                $worstTier = $tier;
            }
        }

        if ($worstTier === null) {
            return null;
        }

        return TimbangRecord::OVERALL_STATUS[$worstTier];
    }

    /**
     * Full server-side assessment for one raw measurement. Recomputes
     * everything from the resident's birthday/sex and the measurement date —
     * never trusts a client-supplied classification. Throws when MUAC is
     * supplied outside its applicable 6-59 month band (disabled HTML inputs
     * are not sufficient protection on their own).
     *
     * @param  array{measurement_date: string, weight_kg?: mixed, height_cm?: mixed, muac_cm?: mixed}  $measurement
     * @return array{
     *     age_months: int|null,
     *     age_years: int|null,
     *     age_band: string|null,
     *     age_label: string,
     *     sex_label: string|null,
     *     muac_applicable: bool,
     *     bmi_applicable: bool,
     *     weight_for_age_applicable: bool,
     *     weight_for_height_applicable: bool,
     *     weight_for_age: string|null,
     *     height_for_age: string|null,
     *     weight_for_height: string|null,
     *     muac_status: string|null,
     *     bmi_value: float|null,
     *     bmi_status: string|null,
     *     overall_nutritional_status: string|null
     * }
     */
    public function assess(Resident $resident, array $measurement): array
    {
        $measurementDate = (string) $measurement['measurement_date'];
        $ageMonths = $this->ageInMonthsAtMeasurement($resident->birthday, $measurementDate);
        $ageYears = $this->ageInYearsAtMeasurement($resident->birthday, $measurementDate);
        $band = $this->ageBand($resident->birthday, $measurementDate);
        $sexLabel = GrowthReferenceLoader::sexLabel($resident->sex);

        $muacCm = $measurement['muac_cm'] ?? null;
        $muacApplicable = $band !== null && $this->muacApplicable($band);

        if (! $muacApplicable && $muacCm !== null && $muacCm !== '') {
            throw ValidationException::withMessages([
                'muac_cm' => 'MUAC only applies to residents aged 6–59 completed months at the measurement date.',
            ]);
        }

        $weightForAge = null;
        $heightForAge = null;
        $weightForHeight = null;
        if ($band !== null && $this->weightForAgeApplicable($band)) {
            $weightForAge = $this->classifyWeightForAge($resident->sex, $ageMonths, $measurement['weight_kg'] ?? null);
            $heightForAge = $this->classifyHeightForAge($resident->sex, $ageMonths, $measurement['height_cm'] ?? null);
            $weightForHeight = $this->classifyWeightForHeight($resident->sex, $ageMonths, $measurement['height_cm'] ?? null, $measurement['weight_kg'] ?? null);
        }

        $muacStatus = null;
        if ($ageMonths !== null) {
            $muacStatus = $this->classifyMuac($ageMonths, $muacCm);
        }

        $bmiValue = null;
        $bmiStatus = null;
        if ($band !== null && $this->bmiApplicable($band)) {
            $bmiValue = $this->calculateBmi($measurement['weight_kg'] ?? null, $measurement['height_cm'] ?? null);
            if ($bmiValue !== null) {
                $bmiStatus = $band === self::BAND_ADULT
                    ? $this->classifyAdultBmi($bmiValue)
                    : TimbangRecord::REFERENCE_UNAVAILABLE;
            }
        }

        $overall = $this->determineOverallStatus([
            'weight_for_age' => $weightForAge,
            'height_for_age' => $heightForAge,
            'weight_for_height' => $weightForHeight,
            'muac_status' => $muacApplicable ? $muacStatus : null,
            'bmi_status' => $band === self::BAND_ADULT ? $bmiStatus : null,
        ]);

        return [
            'age_months' => $ageMonths,
            'age_years' => $ageYears,
            'age_band' => $band,
            'age_label' => $this->formatAgeLabel($ageMonths, $ageYears),
            'sex_label' => $sexLabel,
            'muac_applicable' => $muacApplicable,
            'bmi_applicable' => $band !== null && $this->bmiApplicable($band),
            'weight_for_age_applicable' => $band !== null && $this->weightForAgeApplicable($band),
            'weight_for_height_applicable' => $band !== null && $this->weightForHeightApplicable($band),
            'weight_for_age' => $weightForAge,
            'height_for_age' => $heightForAge,
            'weight_for_height' => $weightForHeight,
            'muac_status' => $muacStatus,
            'bmi_value' => $bmiValue,
            'bmi_status' => $bmiStatus,
            'overall_nutritional_status' => $overall,
        ];
    }

    /**
     * Age/sex/applicability context for the empty "Add Measurement" form,
     * using today as the default measurement date (the date input's initial
     * value). Purely for the initial server-rendered age-adaptive layout —
     * client JS recomputes the same bands live as the operator edits the
     * date, and the server always re-derives everything from the submitted
     * measurement_date on save regardless of what this returned.
     *
     * @return array{
     *     age_months: int|null,
     *     age_years: int|null,
     *     age_band: string|null,
     *     age_label: string,
     *     sex_label: string|null,
     *     muac_applicable: bool,
     *     bmi_applicable: bool,
     *     weight_for_age_applicable: bool,
     *     weight_for_height_applicable: bool,
     *     weight_for_age: string|null,
     *     height_for_age: string|null,
     *     weight_for_height: string|null,
     *     muac_status: string|null,
     *     bmi_value: float|null,
     *     bmi_status: string|null,
     *     overall_nutritional_status: string|null
     * }
     */
    public function assessForToday(Resident $resident): array
    {
        return $this->assess($resident, ['measurement_date' => Carbon::now()->toDateString()]);
    }

    /**
     * Recompute display-only classification context for an already-persisted
     * record (history page). Never mutates or re-persists — historical rows
     * are immutable. Falls back to a live BMI calculation for legacy rows
     * saved before bmi_value existed.
     *
     * @return array{
     *     age_band: string|null,
     *     age_label: string,
     *     muac_applicable: bool,
     *     bmi_applicable: bool,
     *     weight_for_age_applicable: bool,
     *     bmi_display: float|null
     * }
     */
    public function displayContextForRecord(Resident $resident, TimbangRecord $record): array
    {
        $measurementDate = $record->measurement_date instanceof Carbon
            ? $record->measurement_date->toDateString()
            : (string) $record->measurement_date;

        $ageMonths = $this->ageInMonthsAtMeasurement($resident->birthday, $measurementDate);
        $ageYears = $this->ageInYearsAtMeasurement($resident->birthday, $measurementDate);
        $band = $this->ageBand($resident->birthday, $measurementDate);

        $bmiDisplay = $record->bmi_value !== null ? (float) $record->bmi_value : null;
        if ($bmiDisplay === null && $band !== null && $this->bmiApplicable($band)) {
            $bmiDisplay = $this->calculateBmi($record->weight_kg, $record->height_cm);
        }

        return [
            'age_band' => $band,
            'age_label' => $this->formatAgeLabel($ageMonths, $ageYears),
            'muac_applicable' => $band !== null && $this->muacApplicable($band),
            'bmi_applicable' => $band !== null && $this->bmiApplicable($band),
            'weight_for_age_applicable' => $band !== null && $this->weightForAgeApplicable($band),
            'bmi_display' => $bmiDisplay,
        ];
    }

    /**
     * Months under 2 years (finer-grained for infants/toddlers), years above
     * that (an adult/elderly resident should never see "708 Months").
     */
    private function formatAgeLabel(?int $ageMonths, ?int $ageYears): string
    {
        if ($ageMonths === null) {
            return 'Age not recorded';
        }

        if ($ageMonths < 24) {
            return HealthRecordsChildCare::formatAgeMonths($ageMonths);
        }

        $years = $ageYears ?? intdiv($ageMonths, 12);

        return $years.' '.($years === 1 ? 'Year' : 'Years');
    }

    private function toCarbonDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse(trim($value))->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
