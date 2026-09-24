<?php

namespace App\Support;

/**
 * DB-12 Step 5 — authoritative BMI and BP status derivation.
 * Server-side only for persistence; JS mirrors for UX.
 */
final class RiskAssessmentClinicalValues
{
    public const BP_NORMAL = 'NORMAL';

    public const BP_ELEVATED = 'ELEVATED';

    public const BP_STAGE_1 = 'STAGE 1 HYPERTENSION';

    public const BP_STAGE_2 = 'STAGE 2 HYPERTENSION';

    public const BP_SEVERE = 'SEVERE HYPERTENSION';

    public const BMI_UNDERWEIGHT = 'Underweight';

    public const BMI_NORMAL = 'Normal';

    public const BMI_OVERWEIGHT = 'Overweight';

    public const BMI_OBESITY = 'Obesity';

    /**
     * BMI = weight_kg / (height_m^2), rounded to 1 decimal.
     * Returns null when height/weight missing, zero, negative, or non-numeric.
     */
    public static function calculateBmi(mixed $heightCm, mixed $weightKg): ?string
    {
        $height = self::positiveNumber($heightCm);
        $weight = self::positiveNumber($weightKg);
        if ($height === null || $weight === null) {
            return null;
        }

        $heightM = $height / 100.0;
        if ($heightM <= 0.0) {
            return null;
        }

        $bmi = $weight / ($heightM * $heightM);

        return number_format(round($bmi, 1), 1, '.', '');
    }

    /**
     * Adult (19y+) BMI classification. The one standard for the whole project —
     * HealthRecordsRiskAssessment::displayBmiStatus() and
     * NutritionAssessmentService::classifyAdultBmi() both call this so Risk
     * Assessment and Nutritional Status never disagree on adult BMI bands.
     */
    public static function classifyAdultBmi(float $bmi): string
    {
        return match (true) {
            $bmi < 18.5 => 'Underweight',
            $bmi < 25 => 'Normal',
            $bmi < 30 => 'Overweight',
            default => 'Obese',
        };
    }

    /**
     * BMI status from the same height/weight inputs used for BMI.
     * >=35 is unspecified (null); no fifth category is invented.
     */
    public static function calculateBmiStatus(mixed $heightCm, mixed $weightKg): ?string
    {
        return self::bmiStatusFromValue(self::calculateBmi($heightCm, $weightKg));
    }

    /**
     * Map a numeric BMI to the four specified ranges.
     * <18.5 Underweight, 18.5–24.9 Normal, 25.0–29.9 Overweight, 30.0–34.9 Obesity.
     * >=35 returns null.
     */
    public static function bmiStatusFromValue(mixed $bmi): ?string
    {
        if ($bmi === null || $bmi === '') {
            return null;
        }

        $scalar = trim((string) $bmi);
        if ($scalar === '' || ! is_numeric($scalar)) {
            return null;
        }

        $number = (float) $scalar;
        if ($number < 18.5) {
            return self::BMI_UNDERWEIGHT;
        }
        if ($number < 25.0) {
            return self::BMI_NORMAL;
        }
        if ($number < 30.0) {
            return self::BMI_OVERWEIGHT;
        }
        if ($number < 35.0) {
            return self::BMI_OBESITY;
        }

        return null;
    }

    /**
     * Derive BP status from systolic + diastolic.
     * When categories differ, the higher severity wins (checked most-severe-first).
     */
    public static function calculateBpStatus(mixed $systolic, mixed $diastolic): ?string
    {
        $sys = self::nonNegativeInt($systolic);
        $dia = self::nonNegativeInt($diastolic);
        if ($sys === null || $dia === null) {
            return null;
        }

        if ($sys > 180 || $dia > 120) {
            return self::BP_SEVERE;
        }

        if ($sys >= 140 || $dia >= 90) {
            return self::BP_STAGE_2;
        }

        if ($sys >= 130 || $dia >= 80) {
            return self::BP_STAGE_1;
        }

        if ($sys >= 120 && $dia < 80) {
            return self::BP_ELEVATED;
        }

        if ($sys < 120 && $dia < 80) {
            return self::BP_NORMAL;
        }

        return null;
    }

    private static function positiveNumber(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $scalar = trim((string) $value);
        if ($scalar === '' || ! is_numeric($scalar)) {
            return null;
        }

        $number = (float) $scalar;
        if ($number <= 0.0) {
            return null;
        }

        return $number;
    }

    private static function nonNegativeInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $scalar = trim((string) $value);
        if ($scalar === '' || ! is_numeric($scalar)) {
            return null;
        }

        $asFloat = (float) $scalar;
        if ($asFloat < 0) {
            return null;
        }

        return (int) round($asFloat);
    }
}
