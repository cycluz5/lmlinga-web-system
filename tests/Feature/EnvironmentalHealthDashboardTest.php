<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\HouseholdEnvironmentalProfile;
use App\Models\HouseholdSolidWastePractice;
use App\Models\Resident;
use App\Support\DemoCatalog;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\EnvironmentalHealthDashboard;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * DB19-F — Environmental Health dashboard MySQL-backed monitoring.
 */
class EnvironmentalHealthDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
    }

    public function test_dashboard_loads_with_computed_statistics(): void
    {
        $this->seedCompletedHousehold('HH-701', [
            'zone' => 'Zone 1',
            'street' => 'Alpha St.',
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_I,
            'toilet_type' => 'pour_flush_with_septic_tank',
        ]);

        $response = $this->get(route('environmental-health.index'));

        $response->assertOk();
        $response->assertSee('Water Supply Status', false);
        $response->assertSee('Sanitation Services', false);
        $response->assertSee('With Toilet', false);
        $response->assertSee('Without Toilet', false);
        $response->assertSee('Build Report', false);
        $response->assertSee(route('environmental-health.report-builder'), false);
        $response->assertDontSee('data-eh-report', false);
        $response->assertDontSee('Recent Exports', false);
        $response->assertSee('HH-701', false);
        $response->assertSee('lml-eh-dashboard__level', false);
        $response->assertSee('Household Number', false);
        $response->assertSee('>Zone</label>', false);
        $response->assertSee('>Street</label>', false);
        $response->assertSee('lml-eh-toilet-icon--with', false);
        $response->assertDontSee('bi-badge-wc', false);
        $response->assertDontSee('Sanitation Status', false);
        $response->assertDontSee('Water Level', false);
        $response->assertDontSee('data-eh-filter="sanitation"', false);
        $response->assertDontSee('data-eh-filter="water_supply"', false);
        $this->assertMatchesRegularExpression(
            '/lml-eh-dashboard__level[^>]*>\s*I\s*</',
            $response->getContent()
        );
        $response->assertDontSee('Environmental Health module content will be added', false);
        $response->assertDontSee('Not yet determined:', false);
        $response->assertDontSee('Not Yet Determined', false);
        $response->assertDontSee('More Filters', false);
        $response->assertDontSee('Record Status', false);
        $response->assertDontSee('Validation Status', false);
        $response->assertDontSee('Household Head', false);
        $response->assertDontSee('All Sanitation Statuses', false);
        $response->assertDontSee('Environmental Health Overview', false);
    }

    public function test_active_mysql_households_outside_demo_range_appear(): void
    {
        $this->seedHouseholdShell('HH-880', 'Zone 3', 'Db Only St.');

        $rows = EnvironmentalHealthDashboard::rows();
        $nos = array_column($rows, 'household_no');

        $this->assertContains('HH-880', $nos);
        $this->assertNotContains('HH-151', $nos);

        $this->get(route('environmental-health.index'))
            ->assertOk()
            ->assertSee('HH-880', false)
            ->assertDontSee('HH-151', false);
    }

    public function test_all_active_households_form_candidate_population(): void
    {
        $this->seedHouseholdShell('HH-801', 'Zone 1', 'A St.');
        $this->seedHouseholdShell('HH-802', 'Zone 2', 'B St.');
        $this->seedHouseholdShell('HH-803', 'Zone 3', 'C St.');

        $rows = EnvironmentalHealthDashboard::rows();

        $this->assertCount(3, $rows);
        $this->assertSame(
            ['HH-801', 'HH-802', 'HH-803'],
            array_column($rows, 'household_no')
        );
    }

    public function test_no_profile_household_appears_pending_with_add_and_no_side_effects(): void
    {
        $household = $this->seedHouseholdShell('HH-810', 'Zone 2', 'Pending St.');

        $profilesBefore = HouseholdEnvironmentalProfile::query()->count();
        $practicesBefore = HouseholdSolidWastePractice::query()->count();

        $rows = EnvironmentalHealthDashboard::rows();
        $row = $this->rowByNo($rows, 'HH-810');

        $this->assertSame(EnvironmentalHealthDashboard::RECORD_STATUS_PENDING, $row['record_status']);
        $this->assertSame('add', $row['action_mode']);
        $this->assertSame('unknown', $row['toilet_presence']);
        $this->assertSame('', $row['water_supply_status']);
        $this->assertSame('—', $row['water_supply_short']);

        $this->get(route('environmental-health.index'))
            ->assertOk()
            ->assertSee('aria-label="Add amenities for HH-810"', false);

        $this->assertSame($profilesBefore, HouseholdEnvironmentalProfile::query()->count());
        $this->assertSame($practicesBefore, HouseholdSolidWastePractice::query()->count());
        $this->assertDatabaseMissing('household_environmental_profiles', [
            'household_id' => $household->id,
        ]);
    }

    public function test_completed_step_drives_pending_and_completed_status(): void
    {
        foreach ([0, 1, 2, 3] as $step) {
            $no = 'HH-82'.$step;
            $this->seedProfileHousehold($no, [
                'completed_step' => $step,
                'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_II,
                'toilet_type' => 'pour_flush_with_septic_tank',
            ]);

            $row = $this->rowByNo(EnvironmentalHealthDashboard::rows(), $no);
            $this->assertSame(
                EnvironmentalHealthDashboard::RECORD_STATUS_PENDING,
                $row['record_status'],
                "expected pending for completed_step={$step}"
            );
            $this->assertSame('add', $row['action_mode']);
        }

        $this->seedProfileHousehold('HH-824', [
            'completed_step' => 4,
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_I,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'toilet_status' => DemoHouseholdWaterSupply::TOILET_STATUS_SANITARY,
            'solid_waste_status' => 'good_practice',
        ]);

        $completed = $this->rowByNo(EnvironmentalHealthDashboard::rows(), 'HH-824');
        $this->assertSame(EnvironmentalHealthDashboard::RECORD_STATUS_COMPLETED, $completed['record_status']);
        $this->assertSame('edit', $completed['action_mode']);

        $this->seedProfileHousehold('HH-825', [
            'completed_step' => 5,
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_III,
            'toilet_type' => 'open_pit_latrine',
            'toilet_status' => DemoHouseholdWaterSupply::TOILET_STATUS_UNSANITARY,
            'solid_waste_status' => 'good_practice',
        ]);

        $over = $this->rowByNo(EnvironmentalHealthDashboard::rows(), 'HH-825');
        $this->assertSame(EnvironmentalHealthDashboard::RECORD_STATUS_COMPLETED, $over['record_status']);
    }

    public function test_populated_water_and_toilet_do_not_bypass_completed_step(): void
    {
        $this->seedProfileHousehold('HH-830', [
            'completed_step' => 2,
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_III,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'toilet_status' => DemoHouseholdWaterSupply::TOILET_STATUS_SANITARY,
            'sewage_disposal_method' => DemoHouseholdWaterSupply::SEWAGE_ON_SITE,
            'management_status' => DemoHouseholdWaterSupply::MANAGEMENT_STATUS_SAFELY_MANAGED,
            'solid_waste_status' => 'good_practice',
        ]);

        $row = $this->rowByNo(EnvironmentalHealthDashboard::rows(), 'HH-830');

        $this->assertSame(EnvironmentalHealthDashboard::RECORD_STATUS_PENDING, $row['record_status']);
        $this->assertSame('add', $row['action_mode']);
    }

    public function test_soft_deleted_household_is_excluded_including_demo_shadow(): void
    {
        $this->markTestSkipped(
            'Household/resident archival requires Eloquent SoftDeletes. Live ERD has no deleted_at and models do not use SoftDeletes.'
        );

        $this->assertNotNull(DemoCatalog::findHousehold('HH-151'));

        $household = $this->seedCompletedHousehold('HH-151', [
            'zone' => 'Zone 9',
            'street' => 'Real St.',
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_OTHERS,
            'toilet_type' => 'without_toilet',
            'head_first' => 'Real',
            'head_last' => 'Head',
        ]);

        $this->assertContains('HH-151', array_column(EnvironmentalHealthDashboard::rows(), 'household_no'));

        $household->delete();

        $rows = EnvironmentalHealthDashboard::rows();
        $this->assertNotContains('HH-151', array_column($rows, 'household_no'));

        $stats = EnvironmentalHealthDashboard::statistics($rows);
        $this->assertSame(0, $stats['overview']['total_households']);

        $csv = $this->get(route('environmental-health.export', ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringNotContainsString('HH-151', $csv->streamedContent());

        $this->get(route('environmental-health.index'))
            ->assertOk()
            ->assertDontSee('HH-151', false);
    }

    public function test_db_values_beat_demo_fixture_for_conflicting_household_no(): void
    {
        $this->assertNotNull(DemoCatalog::findHousehold('HH-152'));

        $this->seedCompletedHousehold('HH-152', [
            'zone' => 'Zone DB',
            'street' => 'Canonical Ave.',
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_I,
            'toilet_type' => 'without_toilet',
            'head_first' => 'Canonical',
            'head_last' => 'Person',
        ]);

        Session::put(DemoHouseholdWaterSupply::SESSION_KEY, [
            'HH-152' => [
                'actor_id' => 'foreign-actor',
                'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_OTHERS,
                'toilet_type' => 'open_pit_latrine',
                'house_head' => 'Session Shadow',
                'zone' => 'Zone Session',
                'street' => 'Session St.',
                'step' => 4,
            ],
        ]);

        $row = $this->rowByNo(EnvironmentalHealthDashboard::rows(), 'HH-152');

        // Environmental Health displays household heads as "Last, First Middle".
        $this->assertSame('Person, Canonical', $row['house_head']);
        $this->assertSame('Zone DB', $row['zone']);
        $this->assertSame('Canonical Ave.', $row['street']);
        $this->assertSame(DemoHouseholdWaterSupply::WATER_LEVEL_I, $row['water_supply_status']);
        $this->assertSame('without_toilet', $row['toilet_presence']);
        $this->assertSame(EnvironmentalHealthDashboard::RECORD_STATUS_COMPLETED, $row['record_status']);
    }

    public function test_dashboard_get_does_not_materialize_session_or_persist_rows(): void
    {
        $this->seedHouseholdShell('HH-840', 'Zone 1', 'Safe St.');

        Session::forget(DemoHouseholdWaterSupply::SESSION_KEY);
        $profilesBefore = HouseholdEnvironmentalProfile::query()->count();
        $practicesBefore = HouseholdSolidWastePractice::query()->count();

        $this->get(route('environmental-health.index'))->assertOk();

        $this->assertSame([], Session::get(DemoHouseholdWaterSupply::SESSION_KEY, []));
        $this->assertSame($profilesBefore, HouseholdEnvironmentalProfile::query()->count());
        $this->assertSame($practicesBefore, HouseholdSolidWastePractice::query()->count());
    }

    public function test_water_levels_and_null_do_not_contaminate_counts(): void
    {
        $this->seedProfileHousehold('HH-851', [
            'completed_step' => 4,
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_I,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'toilet_status' => 'sanitary',
        ]);
        $this->seedProfileHousehold('HH-852', [
            'completed_step' => 4,
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_II,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'toilet_status' => 'sanitary',
        ]);
        $this->seedProfileHousehold('HH-853', [
            'completed_step' => 4,
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_III,
            'toilet_type' => 'without_toilet',
        ]);
        $this->seedProfileHousehold('HH-854', [
            'completed_step' => 4,
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_OTHERS,
            'toilet_type' => 'open_pit_latrine',
            'toilet_status' => 'unsanitary',
        ]);
        $this->seedProfileHousehold('HH-855', [
            'completed_step' => 1,
            'water_supply_status' => null,
            'toilet_type' => null,
        ]);
        $this->seedHouseholdShell('HH-856', 'Zone 4', 'No Profile St.');

        $stats = EnvironmentalHealthDashboard::statistics(EnvironmentalHealthDashboard::rows());

        $this->assertSame(1, $stats['water_supply']['level_i']);
        $this->assertSame(1, $stats['water_supply']['level_ii']);
        $this->assertSame(1, $stats['water_supply']['level_iii']);
        $this->assertSame(1, $stats['water_supply']['others']);
        $this->assertSame(6, $stats['overview']['total_households']);

        $nullWater = $this->rowByNo(EnvironmentalHealthDashboard::rows(), 'HH-855');
        $this->assertSame('', $nullWater['water_supply_status']);
        $this->assertSame('—', $nullWater['water_supply_short']);
    }

    public function test_sanitation_presence_with_without_and_unknown(): void
    {
        $this->seedProfileHousehold('HH-861', [
            'completed_step' => 3,
            'toilet_type' => 'without_toilet',
        ]);
        $this->seedProfileHousehold('HH-862', [
            'completed_step' => 3,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'toilet_status' => 'sanitary',
        ]);
        $this->seedProfileHousehold('HH-863', [
            'completed_step' => 1,
            'toilet_type' => null,
        ]);
        $this->seedHouseholdShell('HH-864', 'Zone 1', 'Unknown Toilet St.');

        $stats = EnvironmentalHealthDashboard::statistics(EnvironmentalHealthDashboard::rows());

        $this->assertSame(1, $stats['toilet_presence']['without_toilet']);
        $this->assertSame(1, $stats['toilet_presence']['with_toilet']);
        $this->assertSame(2, $stats['toilet_presence']['unknown']);

        $this->assertSame('without_toilet', $this->rowByNo(EnvironmentalHealthDashboard::rows(), 'HH-861')['toilet_presence']);
        $this->assertSame('with_toilet', $this->rowByNo(EnvironmentalHealthDashboard::rows(), 'HH-862')['toilet_presence']);
        $this->assertSame('unknown', $this->rowByNo(EnvironmentalHealthDashboard::rows(), 'HH-863')['toilet_presence']);
        $this->assertSame('unknown', $this->rowByNo(EnvironmentalHealthDashboard::rows(), 'HH-864')['toilet_presence']);
    }

    public function test_solid_waste_status_reads_db_and_missing_practices_is_safe(): void
    {
        $household = $this->seedProfileHousehold('HH-870', [
            'completed_step' => 4,
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_I,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'toilet_status' => 'sanitary',
            'solid_waste_status' => 'good_practice',
        ]);

        $this->assertDatabaseMissing('household_solid_waste_practices', [
            'household_environmental_profile_id' => $household->environmentalProfile->id,
        ]);

        $before = HouseholdSolidWastePractice::query()->count();
        $row = $this->rowByNo(EnvironmentalHealthDashboard::rows(), 'HH-870');
        $this->assertSame('good_practice', $row['solid_waste_status']);
        $this->assertSame('Good Practice', $row['solid_waste_label']);

        $this->get(route('environmental-health.index'))->assertOk();
        $this->assertSame($before, HouseholdSolidWastePractice::query()->count());
    }

    public function test_household_head_from_residents_and_missing_head_fallback(): void
    {
        $withHead = $this->seedHouseholdShell('HH-881', 'Zone 1', 'Head St.');
        Resident::factory()->create([
            'household_id' => $withHead->id,
            'member_no' => 'MB-001',
            'first_name' => 'Maria',
            'middle_name' => 'L',
            'last_name' => 'Santos',
            'relation' => 'Head',
        ]);

        $this->seedHouseholdShell('HH-882', 'Zone 1', 'No Head St.');

        $rows = EnvironmentalHealthDashboard::rows();
        // "Last, First Middle" — see EnvironmentalHealthDashboard::formatHouseHeadName().
        $this->assertSame('Santos, Maria L', $this->rowByNo($rows, 'HH-881')['house_head']);
        $this->assertSame('Not available', $this->rowByNo($rows, 'HH-882')['house_head']);
    }

    public function test_filters_narrow_dashboard_rows(): void
    {
        $this->seedCompletedHousehold('HH-891', [
            'zone' => 'Zone A',
            'street' => 'Filter St.',
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_I,
            'toilet_type' => 'pour_flush_with_septic_tank',
        ]);
        $this->seedCompletedHousehold('HH-892', [
            'zone' => 'Zone B',
            'street' => 'Other St.',
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_III,
            'toilet_type' => 'without_toilet',
        ]);

        $byZone = EnvironmentalHealthDashboard::rows([
            'household_no' => '',
            'house_head' => '',
            'zone' => 'Zone A',
            'street' => 'all',
            'water_supply' => 'all',
            'sanitation' => 'all',
            'validation' => 'all',
            'record_status' => 'all',
        ]);
        $this->assertCount(1, $byZone);
        $this->assertSame('HH-891', $byZone[0]['household_no']);

        $byStreet = EnvironmentalHealthDashboard::rows([
            'household_no' => '',
            'house_head' => '',
            'zone' => 'all',
            'street' => 'Other St.',
            'water_supply' => 'all',
            'sanitation' => 'all',
            'validation' => 'all',
            'record_status' => 'all',
        ]);
        $this->assertCount(1, $byStreet);
        $this->assertSame('HH-892', $byStreet[0]['household_no']);

        $byNo = EnvironmentalHealthDashboard::rows([
            'household_no' => '891',
            'house_head' => '',
            'zone' => 'all',
            'street' => 'all',
            'water_supply' => 'all',
            'sanitation' => 'all',
            'validation' => 'all',
            'record_status' => 'all',
        ]);
        $this->assertCount(1, $byNo);

        $levelI = EnvironmentalHealthDashboard::rows([
            'household_no' => '',
            'house_head' => '',
            'zone' => 'all',
            'street' => 'all',
            'water_supply' => DemoHouseholdWaterSupply::WATER_LEVEL_I,
            'sanitation' => 'all',
            'validation' => 'all',
            'record_status' => 'all',
        ]);
        foreach ($levelI as $row) {
            $this->assertSame(DemoHouseholdWaterSupply::WATER_LEVEL_I, $row['water_supply_status']);
        }

        $withToilet = EnvironmentalHealthDashboard::rows([
            'household_no' => '',
            'house_head' => '',
            'zone' => 'all',
            'street' => 'all',
            'water_supply' => 'all',
            'sanitation' => 'with_toilet',
            'validation' => 'all',
            'record_status' => 'all',
        ]);
        foreach ($withToilet as $row) {
            $this->assertSame('with_toilet', $row['toilet_presence']);
        }

        $response = $this->get(route('environmental-health.index', [
            'zone' => 'Zone A',
        ]));
        $response->assertOk();
        $response->assertSee('data-stat="water-level_i"', false);
        $this->assertMatchesRegularExpression(
            '/data-stat="water-level_i"[^>]*>\s*1\s*</',
            $response->getContent()
        );
    }

    public function test_view_and_edit_actions_use_household_no_routes(): void
    {
        $this->seedCompletedHousehold('HH-900', [
            'zone' => 'Zone 1',
            'street' => 'Action St.',
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_II,
            'toilet_type' => 'pour_flush_with_septic_tank',
        ]);
        $this->seedHouseholdShell('HH-901', 'Zone 1', 'Add St.');

        $response = $this->get(route('environmental-health.index'));
        $response->assertOk();
        $response->assertSee(
            'href="'.e(route('household-profiling.amenities.show', ['householdNo' => 'HH-900'])).'"',
            false
        );
        $response->assertSee(
            'href="'.e(route('household-profiling.amenities.edit', ['householdNo' => 'HH-900'])).'"',
            false
        );
        $response->assertSee('aria-label="Edit amenities for HH-900"', false);
        $response->assertSee('aria-label="Add amenities for HH-901"', false);
        $response->assertDontSee('profile_id=', false);
        $response->assertDontSee('solid_waste_id=', false);
    }

    public function test_csv_and_pdf_export_use_same_authoritative_population(): void
    {
        $this->seedCompletedHousehold('HH-910', [
            'zone' => 'Zone X',
            'street' => 'Export St.',
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_III,
            'toilet_type' => 'pour_flush_connected_to_septic_or_sewer',
            'head_first' => 'Export',
            'head_last' => 'Head',
        ]);
        $this->seedHouseholdShell('HH-911', 'Zone Y', 'Other Export St.');

        $trashed = $this->seedHouseholdShell('HH-912', 'Zone Z', 'Gone St.');
        $trashed->delete();

        $csv = $this->get(route('environmental-health.export', [
            'format' => 'csv',
            'zone' => 'Zone X',
            'scope' => 'zone',
        ]));
        $csv->assertOk();
        $csvContent = $csv->streamedContent();
        $this->assertStringContainsString('La Medalla Iriga City Health Center', $csvContent);
        $this->assertStringContainsString('ENVIRONMENTAL SANITATION AND OCCUPATIONAL HEALTH PROGRAM', $csvContent);
        $this->assertStringContainsString('Year: All Years', $csvContent);
        $this->assertStringContainsString('Date of export:', $csvContent);
        $this->assertStringContainsString('HH No.', $csvContent);
        $this->assertStringContainsString('Date Surveyed', $csvContent);
        $this->assertStringContainsString('Basic Safe Water Status', $csvContent);
        $this->assertStringContainsString('HH-910', $csvContent);
        // "Last, First Middle" — see EnvironmentalHealthDashboard::formatHouseHeadName().
        $this->assertStringContainsString('Head, Export', $csvContent);
        $this->assertStringNotContainsString('HH-911', $csvContent);
        $this->assertStringNotContainsString('HH-912', $csvContent);
        $this->assertStringNotContainsString('HH-151', $csvContent);

        $pdf = $this->get(route('environmental-health.export', [
            'format' => 'pdf',
        ]));
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $pdfContent = $pdf->getContent();
        $this->assertStringStartsWith('%PDF', $pdfContent);
        $this->assertStringContainsString('La Medalla Iriga City', $pdfContent);
        $this->assertStringContainsString('Health Center', $pdfContent);
        $this->assertStringContainsString('ZONE X', $pdfContent);
        $this->assertStringContainsString('ZONE Y', $pdfContent);
        $this->assertStringContainsString('HH-910', $pdfContent);
        $this->assertStringContainsString('HH-911', $pdfContent);
        $this->assertStringContainsString('HOUSEHOLD INFORMATION', $pdfContent);
        $this->assertStringContainsString('WATER SUPPLY', $pdfContent);
        $this->assertStringContainsString('VALIDATION', $pdfContent);
        $this->assertStringContainsString('SANITATION', $pdfContent);
        $this->assertStringContainsString('SOLID WASTE', $pdfContent);
        $this->assertStringContainsString('SUMMARY', $pdfContent);
        $this->assertStringContainsString('Household Total', $pdfContent);
        $this->assertStringContainsString('With Toilet :', $pdfContent);
        $this->assertStringContainsString('Without Toilet :', $pdfContent);
        $this->assertStringNotContainsString('Scope:', $pdfContent);
        $this->assertStringContainsString('Nothing follows', $pdfContent);
        $this->assertStringContainsString('Period: All Time', $pdfContent);
        $this->assertStringContainsString('La Medalla Iriga City Health Center', $pdfContent);
        $this->assertStringContainsString('/BaseFont /Poppins', $pdfContent);
        $this->assertStringContainsString('/Subtype /Image', $pdfContent);
        $this->assertStringNotContainsString('(continued)', $pdfContent);
        $this->assertStringNotContainsString('Year: All Years', $pdfContent);
        $this->assertStringNotContainsString('HH-912', $pdfContent);
        $this->assertStringNotContainsString('localhost', $pdfContent);
        $this->assertStringNotContainsString('127.0.0.1', $pdfContent);
    }

    /**
     * A zone with enough fully-populated households overflows a single PDF
     * page — the report is a single merged table (group header row + field
     * header row, one row per household), and a household row is atomic:
     * it always moves to the next page whole rather than splitting, behind
     * a small strip identifying the zone and the household the table
     * resumes at. The table header repeats on every page so a continuation
     * page is self-explanatory without flipping back to where the zone
     * started.
     */
    public function test_pdf_continuation_pages_carry_zone_and_household_context(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->seedCompletedHousehold('HH-96'.$i, [
                'zone' => 'Zone Continuity',
                'street' => 'Continuity St.',
                'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_III,
                'toilet_type' => 'pour_flush_connected_to_septic_or_sewer',
                'head_first' => 'Continuity',
                'head_last' => 'Head'.$i,
            ]);
        }

        $pdf = $this->get(route('environmental-health.export', [
            'format' => 'pdf',
            'scope' => 'zone',
            'zone' => 'Zone Continuity',
        ]));
        $pdf->assertOk();
        $pdfContent = $pdf->getContent();

        // Every text label is its own (...) Tj text-show operator in the
        // content stream — extract them (honoring \( \) \\ escapes) to
        // inspect what each one actually says, since the export writes
        // literal PDF string escapes.
        preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/', $pdfContent, $matches);
        $textRuns = $matches[1];

        $zoneTitleRuns = array_values(array_filter(
            $textRuns,
            static fn (string $run): bool => $run === 'ZONE CONTINUITY REPORT'
        ));
        $continuationStripRuns = array_values(array_filter(
            $textRuns,
            static fn (string $run): bool => str_contains($run, 'Zone Continuity') && str_contains($run, 'HH No.')
        ));
        $groupHeaderRuns = array_values(array_filter(
            $textRuns,
            static fn (string $run): bool => $run === 'HOUSEHOLD INFORMATION'
        ));

        // Requirement 1: at least one continuation page exists once the
        // zone overflows a page, carrying zone + HH No./Head context, and
        // every one of them is marked "(continued)" — a row never splits,
        // so every continuation page is, by construction, resuming the
        // same table.
        $this->assertNotEmpty($continuationStripRuns, 'expected at least one continuation strip once the zone overflows a page');
        foreach ($continuationStripRuns as $run) {
            $this->assertMatchesRegularExpression('/HH No\. \d+/', $run);
            $this->assertStringContainsString('\\(continued\\)', $run);
        }

        // Requirement 2: no household row is dropped or duplicated across
        // the page break — every seeded household appears in the table
        // exactly once.
        for ($i = 1; $i <= 8; $i++) {
            $this->assertSame(1, substr_count($pdfContent, 'HH-96'.$i), 'HH-96'.$i.' should appear exactly once across all pages');
        }

        // Requirement 3: the group header row (and so the whole table
        // header) repeats on every page — once per continuation page, plus
        // the zone's first page.
        $this->assertCount(
            count($continuationStripRuns) + 1,
            $groupHeaderRuns,
            'the table header must repeat on every page, including the zone\'s first'
        );

        // Requirement 4: the zone title (new-zone page treatment) is drawn
        // exactly once — never repeated onto a continuation page — and
        // precedes every continuation strip in the byte stream, i.e. it
        // only ever appears on the zone's first page.
        $this->assertCount(1, $zoneTitleRuns, 'the zone title must not repeat on continuation pages');
        $titleOffset = strpos($pdfContent, 'ZONE CONTINUITY REPORT');
        $this->assertNotFalse($titleOffset);
        foreach ($continuationStripRuns as $run) {
            $stripOffset = strpos($pdfContent, $run);
            $this->assertNotFalse($stripOffset);
            $this->assertGreaterThan($titleOffset, $stripOffset, 'a continuation strip must never appear before the zone title');
        }
    }

    public function test_report_builder_page_loads_with_sectioned_preview(): void
    {
        $this->seedCompletedHousehold('HH-917', [
            'zone' => 'Zone R',
            'street' => 'Report St.',
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_I,
            'toilet_type' => 'pour_flush_with_septic_tank',
        ]);

        $response = $this->get(route('environmental-health.report-builder'));

        $response->assertOk();
        $response->assertSee('Environmental Health Report', false);
        $response->assertSee('All Zones', false);
        $response->assertSee('Export PDF', false);
        $response->assertSee('No records found.', false);
        $response->assertSee('data-eh-report', false);
        $response->assertSee('data-eh-report-sections', false);
        $response->assertSee('HOUSEHOLD INFORMATION', false);
        $response->assertSee('data-eh-export="pdf"', false);
        $response->assertDontSee('Export Excel', false);
        $response->assertDontSee('data-eh-export="excel"', false);
        $response->assertDontSee('Recent Exports', false);
        $response->assertDontSee('Download CSV', false);
        $response->assertDontSee('Full Household List', false);
        $response->assertDontSee('Print / PDF', false);
        $response->assertDontSee('Citywide', false);
    }

    public function test_export_header_uses_custom_banner_and_period_year(): void
    {
        $this->seedCompletedHousehold('HH-913', [
            'zone' => 'Zone H',
            'street' => 'Header St.',
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_I,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'head_first' => 'Header',
            'head_last' => 'Case',
        ]);

        $banner = 'CUSTOM ENVIRONMENTAL HEALTH BANNER';

        $csv = $this->get(route('environmental-health.export', [
            'format' => 'csv',
            'program_banner' => $banner,
            'period_mode' => 'year',
            'year' => '2026',
        ]));
        $csv->assertOk();
        $csvContent = $csv->streamedContent();
        $this->assertStringContainsString($banner, $csvContent);
        $this->assertStringContainsString('Year: 2026', $csvContent);
        $this->assertStringNotContainsString('Year: All Years', $csvContent);

        $pdf = $this->get(route('environmental-health.export', [
            'format' => 'pdf',
            'program_banner' => $banner,
            'period_mode' => 'month',
            'year' => '2026',
            'month' => '01',
        ]));
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $pdfContent = $pdf->getContent();
        $this->assertStringStartsWith('%PDF', $pdfContent);
        $this->assertStringContainsString($banner, $pdfContent);
        $this->assertStringContainsString('Year: 2026', $pdfContent);
    }

    public function test_amenities_details_page_is_unchanged_by_dashboard(): void
    {
        $this->seedCompletedHousehold('HH-920', [
            'zone' => 'Zone 1',
            'street' => 'Amenities St.',
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_I,
            'toilet_type' => 'pour_flush_with_septic_tank',
        ]);

        $response = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-920']));

        $response->assertOk();
        $response->assertSee('Household Amenities Details', false);
        $response->assertDontSee('data-lml-eh-dashboard', false);
    }

    public function test_household_profiling_list_still_loads(): void
    {
        $response = $this->get(route('household-profiling.index'));

        $response->assertOk();
        $response->assertSee('Total Households', false);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function seedHouseholdShell(string $householdNo, string $zone, string $street): Household
    {
        return Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => $zone,
            'street' => $street,
            'date_registered' => '2026-01-15',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function seedProfileHousehold(string $householdNo, array $attrs): Household
    {
        $household = $this->seedHouseholdShell(
            $householdNo,
            (string) ($attrs['zone'] ?? 'Zone 1'),
            (string) ($attrs['street'] ?? 'Profile St.')
        );

        HouseholdEnvironmentalProfile::query()->create([
            'household_id' => $household->id,
            'household_type' => $attrs['household_type'] ?? null,
            'water_supply_status' => $attrs['water_supply_status'] ?? null,
            'specify_water_source' => $attrs['specify_water_source'] ?? null,
            'water_source_location' => $attrs['water_source_location'] ?? null,
            'water_availability' => $attrs['water_availability'] ?? null,
            'basic_safe_water_status' => $attrs['basic_safe_water_status'] ?? null,
            'microbiological_test_date' => $attrs['microbiological_test_date'] ?? null,
            'microbiological_result' => $attrs['microbiological_result'] ?? null,
            'physicochemical_test_date' => $attrs['physicochemical_test_date'] ?? null,
            'physicochemical_result' => $attrs['physicochemical_result'] ?? null,
            'toilet_type' => $attrs['toilet_type'] ?? null,
            'toilet_status' => $attrs['toilet_status'] ?? null,
            'open_defecation_practiced' => $attrs['open_defecation_practiced'] ?? null,
            'shared_toilet' => $attrs['shared_toilet'] ?? null,
            'sewage_disposal_method' => $attrs['sewage_disposal_method'] ?? null,
            'management_status' => $attrs['management_status'] ?? null,
            'solid_waste_status' => $attrs['solid_waste_status'] ?? null,
            'completed_step' => (int) ($attrs['completed_step'] ?? 0),
        ]);

        return $household->fresh(['environmentalProfile', 'residents']);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function seedCompletedHousehold(string $householdNo, array $attrs): Household
    {
        $household = $this->seedProfileHousehold($householdNo, array_merge([
            'completed_step' => 4,
            'toilet_status' => isset($attrs['toilet_type']) && $attrs['toilet_type'] === 'without_toilet'
                ? null
                : DemoHouseholdWaterSupply::TOILET_STATUS_SANITARY,
            'solid_waste_status' => 'good_practice',
            'management_status' => DemoHouseholdWaterSupply::MANAGEMENT_STATUS_SAFELY_MANAGED,
            'sewage_disposal_method' => DemoHouseholdWaterSupply::SEWAGE_ON_SITE,
            'microbiological_test_date' => '2026-06-10',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-06-12',
            'physicochemical_result' => 'passed',
        ], $attrs));

        $memberSeq = preg_replace('/\D+/', '', $householdNo) ?: (string) $household->id;

        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-'.str_pad(substr($memberSeq, -3), 3, '0', STR_PAD_LEFT),
            'first_name' => (string) ($attrs['head_first'] ?? 'Ada'),
            'middle_name' => null,
            'last_name' => (string) ($attrs['head_last'] ?? 'Reyes'),
            'relation' => 'Head',
        ]);

        return $household->fresh(['environmentalProfile', 'residents']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowByNo(array $rows, string $householdNo): array
    {
        foreach ($rows as $row) {
            if (($row['household_no'] ?? '') === $householdNo) {
                return $row;
            }
        }

        $this->fail("Dashboard row not found for {$householdNo}");
    }
}
