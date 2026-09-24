<?php

namespace Tests\Feature;

use App\Support\ClientTestingProvisioner;
use App\Support\DashboardStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class ClientTestingProvisionerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ClientTestingErdSchema::ensure();
    }

    public function test_schema_validation_passes_on_erd_fixture(): void
    {
        $errors = (new ClientTestingProvisioner)->validateSchema();

        $this->assertSame([], $errors);
    }

    public function test_provision_is_idempotent(): void
    {
        $provisioner = new ClientTestingProvisioner;

        $first = $provisioner->provision();
        $second = $provisioner->provision();

        $this->assertSame($first['counts']['households'] ?? 0, $second['counts']['households'] ?? 0);
        $this->assertSame($first['counts']['residents'] ?? 0, $second['counts']['residents'] ?? 0);
        $this->assertSame(5, $second['counts']['households'] ?? 0);
        $this->assertGreaterThanOrEqual(20, $second['counts']['residents'] ?? 0);
        $this->assertSame(4, $second['counts']['user_management'] ?? 0);
    }

    public function test_zone_one_through_five_have_households_and_population(): void
    {
        (new ClientTestingProvisioner)->provision();

        foreach (DashboardStatistics::zones() as $zone) {
            $this->assertGreaterThan(0, DashboardStatistics::zoneHouseholdCount($zone), $zone);
            $this->assertGreaterThanOrEqual(2, DashboardStatistics::zonePopulationCount($zone), $zone);
        }
    }

    public function test_provisioner_does_not_create_operation_timbang_measurements(): void
    {
        (new ClientTestingProvisioner)->provision();

        if (Schema::hasTable('operation_timbang_measurements')) {
            $this->assertSame(0, DB::table('operation_timbang_measurements')->count());
        } else {
            $this->assertFalse(Schema::hasTable('operation_timbang_measurements'));
        }
    }

    public function test_clinical_and_request_tables_receive_expected_rows(): void
    {
        (new ClientTestingProvisioner)->provision();

        $this->assertGreaterThanOrEqual(1, DB::table('maternal_care')->count());
        $this->assertFalse(ClientTestingProvisioner::wouldWriteMaternalCareBmi());

        $maternal = DB::table('maternal_care')->first();
        $this->assertNotNull($maternal);
        $this->assertSame(55.0, (float) $maternal->weight_kg);
        $this->assertSame(160.0, (float) $maternal->height_cm);
        if (\App\Support\MaternalCareErdMode::isBmiGenerated()) {
            $this->assertEqualsWithDelta(21.5, (float) $maternal->bmi, 0.1);
        }

        $this->assertGreaterThanOrEqual(1, DB::table('child_nutrition')->count());
        $this->assertGreaterThanOrEqual(1, DB::table('nutrition_supplementation')->count());
        $this->assertFalse(ClientTestingProvisioner::wouldWriteInvalidNutritionSupplementation());

        foreach (DB::table('nutrition_supplementation')->get() as $supplement) {
            $this->assertSame('Vitamin A', $supplement->supplement_type);
            $this->assertContains($supplement->age_group, ['6-11 Months', '12-59 Months']);
            $this->assertSame(1, (int) $supplement->dose_number);
        }

        $this->assertGreaterThanOrEqual(1, DB::table('deworming_records')->count());
        $this->assertGreaterThanOrEqual(1, DB::table('risk_assessment')->count());
        $this->assertTrue(ClientTestingProvisioner::riskAssessmentPayloadIncludesUserId());
        $this->assertFalse(ClientTestingProvisioner::wouldWriteInvalidRiskAssessmentPayload());

        $bhwUserId = (int) DB::table('user_management')
            ->where('email', 'angela.reyes@lamedalla.local')
            ->value('user_id');
        $this->assertGreaterThan(0, $bhwUserId);
        foreach (DB::table('risk_assessment')->get() as $assessment) {
            $this->assertSame($bhwUserId, (int) $assessment->user_id);
        }

        $this->assertGreaterThanOrEqual(1, DB::table('family_planning')->count());
        $this->assertGreaterThanOrEqual(1, DB::table('record_requests')->count());
        $this->assertTrue(ClientTestingProvisioner::recordRequestPayloadIncludesAccountId());

        $accountId = (int) DB::table('resident_accounts')
            ->where('email', 'juan.delacruz@example.local')
            ->value('account_id');
        $this->assertGreaterThan(0, $accountId);
        $this->assertSame(
            $accountId,
            (int) DB::table('record_requests')->value('account_id')
        );

        $this->assertGreaterThanOrEqual(1, DB::table('environmental_sanitation')->count());
        $this->assertGreaterThanOrEqual(2, DB::table('nutrition_supplementation')->count());
    }

    public function test_dashboard_summary_indicators_become_non_zero(): void
    {
        (new ClientTestingProvisioner)->provision();

        $summary = DashboardStatistics::summary();

        $this->assertSame(5, $summary['totalHouseholds']);
        $this->assertGreaterThanOrEqual(20, $summary['totalResidents']);
        $this->assertGreaterThan(0, $summary['nhts']);
        $this->assertGreaterThan(0, $summary['nonNhts']);
        $this->assertGreaterThan(0, $summary['pregnant']);
        $this->assertGreaterThan(0, $summary['fpCurrentUser']);
        $this->assertGreaterThan(0, $summary['infants011']);
        $this->assertGreaterThan(0, $summary['hhLargeFamily']);
        $this->assertGreaterThan(0, $summary['hhSanitaryToilet']);
    }
}
