<?php

namespace Tests\Feature;

use App\Support\ClientTestingProvisioner;
use App\Support\RiskAssessmentErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class RiskAssessmentErdModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_requires_user_id_and_omits_generated_blood_pressure_status(): void
    {
        ClientTestingErdSchema::ensure();

        $this->assertTrue(RiskAssessmentErdMode::requiresUserId());
        $this->assertFalse(RiskAssessmentErdMode::isBloodPressureStatusGenerated());
        $this->assertTrue(ClientTestingProvisioner::riskAssessmentPayloadIncludesUserId());
        $this->assertFalse(ClientTestingProvisioner::wouldWriteGeneratedRiskAssessmentBloodPressureStatus());
        $this->assertFalse(ClientTestingProvisioner::wouldWriteInvalidRiskAssessmentPayload());
    }

    public function test_provisioner_payload_uses_bhw_user_id_and_physical_enum_values(): void
    {
        ClientTestingErdSchema::ensure();

        $payload = ClientTestingProvisioner::riskAssessmentInsertPayloadForScenario(
            ClientTestingProvisioner::riskAssessmentScenarios()[0],
            42
        );

        $this->assertSame(42, $payload['user_id']);
        $this->assertArrayNotHasKey('blood_pressure_status', $payload);
        $this->assertSame(115, $payload['systolic_blood_pressure']);
        $this->assertSame(75, $payload['diastolic_blood_pressure']);
        $this->assertSame('Never', $payload['tobacco_vape_usage']);
        $this->assertSame('Never', $payload['alcohol_intake']);
        $this->assertSame('Yes', $payload['physical_activity']);
        $this->assertSame('Yes', $payload['dietary_habits']);
    }

    public function test_provision_persists_user_id_from_deterministic_bhw_staff(): void
    {
        ClientTestingErdSchema::ensure();

        (new ClientTestingProvisioner)->provision();

        $bhwUserId = (int) DB::table('user_management')
            ->where('email', 'angela.reyes@lamedalla.local')
            ->value('user_id');

        $this->assertGreaterThan(0, $bhwUserId);

        $rows = DB::table('risk_assessment')->get();
        $this->assertGreaterThanOrEqual(1, $rows->count());

        foreach ($rows as $row) {
            $this->assertSame($bhwUserId, (int) $row->user_id);
        }
    }

    public function test_reprovision_remains_idempotent_for_risk_assessment(): void
    {
        ClientTestingErdSchema::ensure();

        $provisioner = new ClientTestingProvisioner;
        $provisioner->provision();
        $firstCount = DB::table('risk_assessment')->count();

        $provisioner->provision();
        $secondCount = DB::table('risk_assessment')->count();

        $this->assertSame($firstCount, $secondCount);
        $this->assertGreaterThanOrEqual(1, $secondCount);
    }

    public function test_legacy_display_labels_normalize_to_authoritative_values(): void
    {
        $this->assertSame('Current User', RiskAssessmentErdMode::normalizeTobaccoUsage('Current'));
        $this->assertSame('Never', RiskAssessmentErdMode::normalizeAlcoholIntake('None'));
        $this->assertSame('Light (Occasional)', RiskAssessmentErdMode::normalizeAlcoholIntake('Moderate'));
        $this->assertSame('Inactive', RiskAssessmentErdMode::normalizePhysicalActivity('Inactive'));
        $this->assertSame('Yes', RiskAssessmentErdMode::normalizePhysicalActivity('yes'));
        $this->assertSame('No', RiskAssessmentErdMode::normalizePhysicalActivity('no'));
        $this->assertSame('More than 2.5hrs/week', RiskAssessmentErdMode::normalizePhysicalActivity('More than 2.5hrs/week'));
        $this->assertSame('Yes', RiskAssessmentErdMode::normalizeDietaryHabits('yes'));
        $this->assertSame('No', RiskAssessmentErdMode::normalizeDietaryHabits('No'));
    }
}
