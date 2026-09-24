<?php

namespace Tests\Unit;

use App\Support\RiskAssessmentClinicalValues;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RiskAssessmentClinicalValuesTest extends TestCase
{
    public function test_bmi_178_cm_55_kg_is_17_4(): void
    {
        $this->assertSame('17.4', RiskAssessmentClinicalValues::calculateBmi(178, 55));
        $this->assertSame('17.4', RiskAssessmentClinicalValues::calculateBmi('178', '55'));
    }

    public function test_bmi_missing_or_invalid_returns_null(): void
    {
        $this->assertNull(RiskAssessmentClinicalValues::calculateBmi(null, 55));
        $this->assertNull(RiskAssessmentClinicalValues::calculateBmi(178, null));
        $this->assertNull(RiskAssessmentClinicalValues::calculateBmi('', 55));
        $this->assertNull(RiskAssessmentClinicalValues::calculateBmi(0, 55));
        $this->assertNull(RiskAssessmentClinicalValues::calculateBmi(178, 0));
        $this->assertNull(RiskAssessmentClinicalValues::calculateBmi(-170, 55));
        $this->assertNull(RiskAssessmentClinicalValues::calculateBmi('abc', 55));
    }

    #[DataProvider('bpExamples')]
    public function test_bp_status_examples(int $systolic, int $diastolic, string $expected): void
    {
        $this->assertSame(
            $expected,
            RiskAssessmentClinicalValues::calculateBpStatus($systolic, $diastolic)
        );
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    public static function bpExamples(): array
    {
        return [
            'normal' => [118, 76, RiskAssessmentClinicalValues::BP_NORMAL],
            'elevated' => [124, 76, RiskAssessmentClinicalValues::BP_ELEVATED],
            'stage1_dia' => [111, 80, RiskAssessmentClinicalValues::BP_STAGE_1],
            'stage1_sys' => [135, 75, RiskAssessmentClinicalValues::BP_STAGE_1],
            'stage2' => [145, 82, RiskAssessmentClinicalValues::BP_STAGE_2],
            'severe' => [181, 100, RiskAssessmentClinicalValues::BP_SEVERE],
        ];
    }

    public function test_bmi_status_ranges_match_specified_bands(): void
    {
        $this->assertSame('Underweight', RiskAssessmentClinicalValues::bmiStatusFromValue('18.4'));
        $this->assertSame('Normal', RiskAssessmentClinicalValues::bmiStatusFromValue('18.5'));
        $this->assertSame('Normal', RiskAssessmentClinicalValues::bmiStatusFromValue('24.9'));
        $this->assertSame('Overweight', RiskAssessmentClinicalValues::bmiStatusFromValue('25.0'));
        $this->assertSame('Overweight', RiskAssessmentClinicalValues::bmiStatusFromValue('29.9'));
        $this->assertSame('Obesity', RiskAssessmentClinicalValues::bmiStatusFromValue('30.0'));
        $this->assertSame('Obesity', RiskAssessmentClinicalValues::bmiStatusFromValue('34.9'));
        $this->assertNull(RiskAssessmentClinicalValues::bmiStatusFromValue('35.0'));
        $this->assertNull(RiskAssessmentClinicalValues::bmiStatusFromValue('40.0'));
        $this->assertSame(
            'Underweight',
            RiskAssessmentClinicalValues::calculateBmiStatus(178, 55)
        );
    }

    public function test_bmi_status_missing_returns_null(): void
    {
        $this->assertNull(RiskAssessmentClinicalValues::bmiStatusFromValue(null));
        $this->assertNull(RiskAssessmentClinicalValues::bmiStatusFromValue(''));
        $this->assertNull(RiskAssessmentClinicalValues::calculateBmiStatus(null, 55));
    }

    public function test_bp_status_missing_returns_null(): void
    {
        $this->assertNull(RiskAssessmentClinicalValues::calculateBpStatus(null, 76));
        $this->assertNull(RiskAssessmentClinicalValues::calculateBpStatus(118, null));
        $this->assertNull(RiskAssessmentClinicalValues::calculateBpStatus('', 76));
    }
}
