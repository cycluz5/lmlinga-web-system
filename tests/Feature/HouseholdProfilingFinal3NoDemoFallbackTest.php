<?php

namespace Tests\Feature;

use App\Models\DewormingRecord;
use App\Models\Household;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Services\SpotMappingService;
use App\Support\ChildBirthHistoryService;
use App\Support\ChildNutritionService;
use App\Support\DemoCatalog;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\HealthRecordsDeworming;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PersistCatalogHousehold;
use Tests\TestCase;

/**
 * FINAL-3 — production Deworming, Environmental Health, and Risk Assessment
 * never substitute DemoCatalog for missing persisted identities.
 */
class HouseholdProfilingFinal3NoDemoFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
    }

    /**
     * @return array<string, int>
     */
    private function persistenceCounts(): array
    {
        $counts = [
            'households' => Household::query()->count(),
            'residents' => Resident::query()->count(),
            'deworming' => Schema::hasTable('deworming_records') ? DB::table('deworming_records')->count() : 0,
            'risk' => Schema::hasTable('risk_assessments') ? DB::table('risk_assessments')->count() : 0,
            'eh_profiles' => Schema::hasTable('household_environmental_profiles')
                ? DB::table('household_environmental_profiles')->count()
                : 0,
            'eh_sanitation' => Schema::hasTable('environmental_sanitation')
                ? DB::table('environmental_sanitation')->count()
                : 0,
        ];

        $session = session(DemoHouseholdWaterSupply::SESSION_KEY, []);
        $counts['eh_session'] = is_array($session) ? count($session) : 0;

        return $counts;
    }

    public function test_deworming_persisted_resident_resolves_from_db(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-001',
            'zone' => 'Zone 2',
        ]);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-010',
            'first_name' => 'Persisted',
            'last_name' => 'Child',
            'middle_name' => null,
            'birthday' => '2024-01-15',
            'sex' => 'Female',
        ]);

        DewormingRecord::factory()->create([
            'resident_id' => $resident->id,
            'year' => 2026,
            'round' => 1,
            'se_status' => 'NHTS',
            'date_given' => '2026-07-01',
        ]);

        $child = HealthRecordsDeworming::findChildForMember('HH-001', 'MB-010');
        $this->assertNotNull($child);
        $this->assertSame('Persisted Child', $child['full_name']);

        $html = $this->get(route('household-profiling.members.deworming', [
            'householdNo' => 'HH-001',
            'memberId' => 'MB-010',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Persisted Child', $html);
        $this->assertStringContainsString('NHTS', $html);
        $this->assertStringNotContainsString('Kristine Reyes', $html);
        $this->assertStringNotContainsString('demo household', $html);
    }

    public function test_catalog_only_child_does_not_resolve_on_production_deworming(): void
    {
        $this->assertNotNull(DemoCatalog::findHousehold('HH-151'));
        $this->assertNull(Household::query()->where('household_no', 'HH-151')->first());
        $this->assertNull(HealthRecordsDeworming::findChild('kristine-b-reyes'));
        $this->assertNull(HealthRecordsDeworming::findChildForMember('HH-151', 'MB-009'));
        $this->assertNull(HealthRecordsDeworming::resolveCanonicalMemberDewormingUrl('kristine-b-reyes'));

        $html = $this->get(route('household-profiling.members.deworming', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-009',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('No record found.', $html);
        $this->assertStringNotContainsString('Kristine B. Reyes', $html);
        $this->assertStringNotContainsString('Kristine Reyes', $html);

        $legacy = $this->get(route('health-records.child-care.deworming.show', [
            'childKey' => 'kristine-b-reyes',
        ]));
        $legacy->assertOk();
        $this->assertStringNotContainsString('Kristine B. Reyes', $legacy->getContent());
        $this->assertNull($legacy->headers->get('Location'));
    }

    public function test_deworming_cross_household_access_fails_safely(): void
    {
        $alpha = Household::factory()->create(['household_no' => 'HH-001', 'zone' => 'Zone 1']);
        $beta = Household::factory()->create(['household_no' => '121', 'zone' => 'Zone 2']);
        Resident::factory()->create([
            'household_id' => $alpha->id,
            'member_no' => 'MB-010',
            'first_name' => 'Alpha',
            'last_name' => 'Child',
            'birthday' => '2023-01-01',
        ]);

        $this->assertNull(HealthRecordsDeworming::findChildForMember('121', 'MB-010'));

        $html = $this->get(route('household-profiling.members.deworming', [
            'householdNo' => '121',
            'memberId' => 'MB-010',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('No record found.', $html);
        $this->assertStringNotContainsString('Alpha Child', $html);
        $this->assertSame([], HealthRecordsDeworming::recordsForMember('121', 'MB-010'));
    }

    public function test_deworming_unknown_resident_does_not_render_catalog_identity(): void
    {
        $before = $this->persistenceCounts();

        $html = $this->get(route('household-profiling.members.deworming', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-009',
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Kristine B. Reyes', $html);
        $this->assertStringNotContainsString('3 yrs old', $html);
        $this->assertStringNotContainsString('demo household', $html);

        $this->assertSame($before, $this->persistenceCounts());
    }

    public function test_existing_db_deworming_record_still_renders_and_saves(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-001', 'zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-010',
            'first_name' => 'Saved',
            'last_name' => 'Dose',
            'birthday' => '2022-06-01',
        ]);
        DewormingRecord::factory()->create([
            'resident_id' => $resident->id,
            'year' => 2025,
            'round' => 1,
            'se_status' => 'Non-NHTS',
            'date_given' => '2025-07-01',
        ]);

        $this->get(route('household-profiling.members.deworming', [
            'householdNo' => 'HH-001',
            'memberId' => 'MB-010',
        ]))->assertOk()->assertSee('Non-NHTS', false);

        $this->post(route('household-profiling.members.deworming.store', [
            'householdNo' => 'HH-001',
            'memberId' => 'MB-010',
        ]), [
            'year' => 2026,
            'round' => '2',
            'se_status' => 'NHTS',
            'date_given' => '2026-01-20',
            'remarks' => 'Follow-up',
        ])->assertRedirect(route('household-profiling.members.child-nutrition', [
            'householdNo' => 'HH-001',
            'memberId' => 'MB-010',
        ]));

        $this->assertSame(2, DewormingRecord::query()->where('resident_id', $resident->id)->count());
    }

    public function test_environmental_health_persisted_household_resolves_from_db(): void
    {
        [$householdNo] = $this->seedLinkedHousehold('HH-001');

        $this->post(route('environmental-health.household-water-supply.store'), [
            'household_no' => $householdNo,
            'water_supply_status' => 'level_i',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
        ])->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]));

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertNotNull($record);
        $this->assertSame('level_i', $record['water_supply_status'] ?? null);
        $this->assertNotSame('household_profiling_demo', $record['source'] ?? null);
    }

    public function test_catalog_only_household_does_not_materialize_on_production_eh(): void
    {
        $this->assertNotNull(DemoCatalog::findHousehold('HH-151'));
        $this->assertNull(Household::query()->where('household_no', 'HH-151')->first());
        $this->assertNull(DemoHouseholdWaterSupply::findForActor('HH-151'));

        $before = $this->persistenceCounts();

        $this->get(route('environmental-health.household-water-supply', [
            'household' => 'HH-151',
        ]))->assertRedirect(route('spot-mapping.index'));

        $this->post(route('environmental-health.household-water-supply.store'), [
            'household_no' => 'HH-151',
            'water_supply_status' => 'level_iii',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
        ])->assertSessionHasErrors('household_no');

        $this->assertSame($before, $this->persistenceCounts());
        $this->assertNull(DemoHouseholdWaterSupply::findForActor('HH-151'));
        $this->assertSame([], session(DemoHouseholdWaterSupply::SESSION_KEY, []));
    }

    public function test_eh_unknown_household_does_not_create_session_or_db_data(): void
    {
        $before = $this->persistenceCounts();

        $this->get(route('environmental-health.household-water-supply.step2', [
            'householdNo' => 'HH-151',
        ]))->assertRedirect();

        $this->assertSame($before, $this->persistenceCounts());
        $this->assertFalse(DemoHouseholdWaterSupply::isRecognized('HH-151'));
    }

    public function test_existing_eh_persisted_workflow_still_works(): void
    {
        [$householdNo] = $this->seedLinkedHousehold('121');

        $this->post(route('environmental-health.household-water-supply.store'), [
            'household_no' => $householdNo,
            'water_supply_status' => 'level_ii',
            'water_source_location' => 'no',
            'water_availability' => 'yes',
        ])->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]));

        $this->get(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->assertOk();
    }

    public function test_risk_assessment_write_uses_db_identity_and_rejects_catalog_only(): void
    {
        $this->assertNotNull(DemoCatalog::findHousehold('HH-151'));

        $this->put(route('household-profiling.members.risk-assessment.section.update', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
            'assessmentId' => 'RA-001',
            'section' => 'red-flags',
        ]), ['red_flags' => ['chest_pain']])->assertForbidden();

        $this->assertSame(0, RiskAssessment::query()->count());
    }

    public function test_risk_assessment_cross_household_write_is_rejected(): void
    {
        $alpha = Household::factory()->create(['household_no' => 'HH-001']);
        $beta = Household::factory()->create(['household_no' => '121']);
        $resident = Resident::factory()->create([
            'household_id' => $alpha->id,
            'member_no' => 'MB-010',
            'first_name' => 'Alpha',
            'last_name' => 'Risk',
        ]);
        RiskAssessment::factory()->create([
            'resident_id' => $resident->id,
            'assessment_no' => 'RA-001',
            'red_flags' => ['none'],
        ]);

        $this->put(route('household-profiling.members.risk-assessment.section.update', [
            'householdNo' => '121',
            'memberId' => 'MB-010',
            'assessmentId' => 'RA-001',
            'section' => 'red-flags',
        ]), ['red_flags' => ['chest_pain']])->assertForbidden();

        $this->assertSame(['none'], RiskAssessment::query()->where('resident_id', $resident->id)->value('red_flags'));
    }

    public function test_valid_db_risk_assessment_update_still_persists(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-001']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-010',
            'first_name' => 'Valid',
            'last_name' => 'Risk',
            'birthday' => '1985-07-09',
        ]);
        RiskAssessment::factory()->create([
            'resident_id' => $resident->id,
            'assessment_no' => 'RA-001',
            'red_flags' => ['none'],
        ]);

        $this->put(route('household-profiling.members.risk-assessment.section.update', [
            'householdNo' => 'HH-001',
            'memberId' => 'MB-010',
            'assessmentId' => 'RA-001',
            'section' => 'red-flags',
        ]), ['red_flags' => ['chest_pain']])->assertRedirect();

        $this->assertSame(['chest_pain'], RiskAssessment::query()->where('resident_id', $resident->id)->value('red_flags'));
    }

    public function test_child_care_no_record_state_contains_no_demo_household_copy(): void
    {
        $html = $this->get(route('household-profiling.members.child-nutrition', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('No record found.', $html);
        $this->assertStringNotContainsString('demo household', $html);
        $this->assertStringNotContainsString('No demo member', $html);
        $this->assertStringNotContainsString('Kristine Reyes', $html);
        $this->assertStringNotContainsString('Normal', $html);
        $this->assertStringNotContainsString('Completed', $html);
        $this->assertStringNotContainsString('Good Practice', $html);
    }

    public function test_child_nutrition_status_absent_when_insufficient_data(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-001']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-010',
            'first_name' => 'Empty',
            'last_name' => 'Nutrition',
            'birthday' => '2024-03-01',
            'sex' => 'Female',
        ]);

        $html = $this->get(route('household-profiling.members.child-nutrition', [
            'householdNo' => 'HH-001',
            'memberId' => 'MB-010',
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-child-nut-status-overall[\s\S]{0,280}\bNo record\b/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-child-nut-status-overall[\s\S]{0,280}\bNormal\b/u',
            $html
        );

        $resident = Resident::query()->where('member_no', 'MB-010')->firstOrFail();
        $state = app(ChildNutritionService::class)->forResident($resident);
        $this->assertFalse($state['persisted']);
    }

    public function test_birth_history_incomplete_data_remains_neutral(): void
    {
        $household = Household::factory()->create(['household_no' => '121']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-121',
            'first_name' => 'Neutral',
            'last_name' => 'Birth',
            'birthday' => '2024-04-01',
        ]);

        $presentation = ChildBirthHistoryService::presentationForResident($resident);
        $this->assertTrue($presentation === null || trim((string) ($presentation['status'] ?? '')) === '');

        $html = $this->get(route('household-profiling.members.child-immunization.birth-history.edit', [
            'householdNo' => '121',
            'memberId' => 'MB-121',
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Low Birth Weight', $html);
        $this->assertStringNotContainsString('demo household', $html);
    }

    public function test_legacy_and_three_digit_compat_and_maps_remain_db_only(): void
    {
        Household::factory()->create([
            'household_no' => 'HH-001',
            'zone' => 'Zone 1',
            'latitude' => 13.3811,
            'longitude' => 123.4306,
        ]);
        Household::factory()->create([
            'household_no' => '121',
            'zone' => 'Zone 2',
            'latitude' => 13.378472,
            'longitude' => 123.430925,
        ]);

        $this->get(route('household-profiling.members.deworming', [
            'householdNo' => 'HH-001',
            'memberId' => 'MB-010',
        ]))->assertOk();

        $this->get(route('household-profiling.members.deworming', [
            'householdNo' => '121',
            'memberId' => 'MB-121',
        ]))->assertOk();

        $markers = app(SpotMappingService::class)->mappedMarkers();
        $nos = array_map(static fn (array $m): string => (string) ($m['householdNo'] ?? ''), $markers);
        $this->assertNotContains('HH-151', $nos);

        $dash = $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Kristine Reyes', $dash);
    }

    public function test_viewing_missing_records_performs_zero_writes(): void
    {
        $before = $this->persistenceCounts();

        $this->get(route('household-profiling.members.deworming', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-009',
        ]))->assertOk();
        $this->get(route('household-profiling.members.risk-assessment', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk();
        $this->get(route('household-profiling.members.child-nutrition', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk();
        $this->get(route('environmental-health.household-water-supply', [
            'household' => 'HH-151',
        ]))->assertRedirect();

        $this->assertSame($before, $this->persistenceCounts());
    }

    /**
     * @return array{0: string, 1: Household}
     */
    private function seedLinkedHousehold(string $householdNo): array
    {
        session([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
        ]);

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), [
            'household_no' => $householdNo,
            'house_head' => 'Test Head',
            'household_type' => 'HHTS',
            'zone' => '1',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
            'client_marker_id' => 'client-marker-final3-'.$householdNo,
        ])->assertOk();

        $this->get(route('environmental-health.household-water-supply', [
            'handoff' => (string) $issue->json('handoff_token'),
        ]))->assertRedirect();

        return [$householdNo, $household->fresh()];
    }
}
