<?php

namespace Tests\Unit;

use App\Support\DemoMaternalCare;
use App\Support\RiskAssessmentClinicalValues;
use Tests\TestCase;

class MaternalCareBmiDerivationTest extends TestCase
{
    public function test_registration_bmi_equals_shared_calculator_and_ignores_payload(): void
    {
        $row = DemoMaternalCare::buildRegistrationPregnancy([
            'lmp' => '2026-01-15',
            'weight' => '60',
            'height' => '161',
            'bmi' => '99.9',
            'blood_pressure' => '110/70',
        ], 1);

        $expected = RiskAssessmentClinicalValues::calculateBmi(161, 60);
        $this->assertSame($expected, $row['bmi']);
        $this->assertSame($expected, $row['prenatal']['t1_v1']['bmi']);
        $this->assertNotSame('99.9', $row['bmi']);
    }

    public function test_prenatal_visit_bmi_equals_shared_calculator_for_that_visit(): void
    {
        $base = DemoMaternalCare::buildRegistrationPregnancy([
            'lmp' => '2026-01-15',
            'weight' => '58.5',
            'height' => '160',
        ], 1);

        $result = DemoMaternalCare::applySectionToPregnancy($base, 'prenatal', [
            'visits' => [
                't1_v1' => ['height' => '160', 'weight' => '59', 'bmi' => '99.9'],
                't2_v1' => ['height' => '160', 'weight' => '55', 'bmi' => '1.0'],
            ],
        ]);

        $this->assertNotNull($result);
        $prenatal = $result['pregnancy']['prenatal'];
        $this->assertSame(
            RiskAssessmentClinicalValues::calculateBmi(160, 59),
            $prenatal['t1_v1']['bmi']
        );
        $this->assertSame(
            RiskAssessmentClinicalValues::calculateBmi(160, 55),
            $prenatal['t2_v1']['bmi']
        );
        $this->assertSame('23.0', $prenatal['t1_v1']['bmi']);
        $this->assertSame('21.5', $prenatal['t2_v1']['bmi']);
    }

    public function test_missing_height_or_weight_produces_empty_bmi(): void
    {
        $this->assertSame('', DemoMaternalCare::buildRegistrationPregnancy([
            'weight' => '59',
            'height' => '',
            'bmi' => '22.0',
        ], 1)['bmi']);

        $this->assertSame('', DemoMaternalCare::buildRegistrationPregnancy([
            'weight' => '',
            'height' => '160',
            'bmi' => '22.0',
        ], 1)['bmi']);

        $this->assertNull(RiskAssessmentClinicalValues::calculateBmi('', 59));
        $this->assertNull(RiskAssessmentClinicalValues::calculateBmi(160, ''));
        $this->assertNull(RiskAssessmentClinicalValues::calculateBmi(0, 59));
        $this->assertNull(RiskAssessmentClinicalValues::calculateBmi(160, 0));
    }

    public function test_presentation_recomputes_bmi_from_stored_height_and_weight(): void
    {
        $presented = DemoMaternalCare::present([
            'weight' => '60',
            'height' => '161',
            'bmi' => '99.9',
            'prenatal' => [
                't1_v1' => ['height' => '160', 'weight' => '59', 'bmi' => '1.0'],
            ],
        ]);

        $this->assertSame(RiskAssessmentClinicalValues::calculateBmi(161, 60), $presented['bmi']);
        $this->assertSame(
            RiskAssessmentClinicalValues::calculateBmi(160, 59),
            $presented['prenatal']['t1_v1']['bmi']
        );
        $this->assertNotSame('99.9', $presented['bmi']);
        $this->assertNotSame('1.0', $presented['prenatal']['t1_v1']['bmi']);
    }
}
