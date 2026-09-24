<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Support\DashboardStatistics;
use App\Support\DashboardUiData;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R05-A — Dashboard zone-based household and population summary.
 */
class DashboardZoneSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BNS);
    }

    public function test_zone_summary_returns_zone_one_through_five(): void
    {
        $summary = DashboardStatistics::zoneSummary();

        $this->assertCount(5, $summary);
        $this->assertSame(
            ['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5'],
            array_column($summary, 'zone')
        );
        $this->assertSame([1, 2, 3, 4, 5], array_column($summary, 'zoneNumber'));
    }

    public function test_household_counts_are_db_backed(): void
    {
        Household::factory()->create(['zone' => 'Zone 1', 'household_no' => 'HH-Z101']);
        Household::factory()->create(['zone' => 'Zone 1', 'household_no' => 'HH-Z102']);
        Household::factory()->create(['zone' => 'Zone 3', 'household_no' => 'HH-Z301']);

        $summary = collect(DashboardStatistics::zoneSummary())->keyBy('zone');

        $this->assertSame(2, $summary['Zone 1']['households']);
        $this->assertSame(0, $summary['Zone 2']['households']);
        $this->assertSame(1, $summary['Zone 3']['households']);
        $this->assertSame(0, $summary['Zone 4']['households']);
        $this->assertSame(0, $summary['Zone 5']['households']);
    }

    public function test_population_counts_are_db_backed(): void
    {
        $zoneOne = Household::factory()->create(['zone' => 'Zone 1', 'household_no' => 'HH-P101']);
        $zoneTwo = Household::factory()->create(['zone' => 'Zone 2', 'household_no' => 'HH-P201']);

        Resident::factory()->count(2)->create(['household_id' => $zoneOne->id]);
        Resident::factory()->create(['household_id' => $zoneTwo->id]);

        $summary = collect(DashboardStatistics::zoneSummary())->keyBy('zone');

        $this->assertSame(2, $summary['Zone 1']['population']);
        $this->assertSame(1, $summary['Zone 2']['population']);
        $this->assertSame(0, $summary['Zone 3']['population']);
    }

    public function test_zone_one_counts_only_affect_zone_one(): void
    {
        $zoneOne = Household::factory()->create(['zone' => 'Zone 1', 'household_no' => 'HH-I101']);
        $zoneTwo = Household::factory()->create(['zone' => 'Zone 2', 'household_no' => 'HH-I201']);

        Resident::factory()->count(3)->create(['household_id' => $zoneOne->id]);
        Resident::factory()->create(['household_id' => $zoneTwo->id]);

        $summary = collect(DashboardStatistics::zoneSummary())->keyBy('zone');

        $this->assertSame(1, $summary['Zone 1']['households']);
        $this->assertSame(3, $summary['Zone 1']['population']);
        $this->assertSame(1, $summary['Zone 2']['households']);
        $this->assertSame(1, $summary['Zone 2']['population']);
    }

    public function test_zones_two_through_five_remain_isolated(): void
    {
        $zones = [
            'Zone 2' => 'HH-I202',
            'Zone 3' => 'HH-I303',
            'Zone 4' => 'HH-I404',
            'Zone 5' => 'HH-I505',
        ];

        foreach ($zones as $zone => $householdNo) {
            $household = Household::factory()->create([
                'zone' => $zone,
                'household_no' => $householdNo,
            ]);
            Resident::factory()->count(2)->create(['household_id' => $household->id]);
        }

        $summary = collect(DashboardStatistics::zoneSummary())->keyBy('zone');

        $this->assertSame(0, $summary['Zone 1']['households']);
        $this->assertSame(0, $summary['Zone 1']['population']);

        foreach (['Zone 2', 'Zone 3', 'Zone 4', 'Zone 5'] as $zone) {
            $this->assertSame(1, $summary[$zone]['households'], $zone.' household isolation failed');
            $this->assertSame(2, $summary[$zone]['population'], $zone.' population isolation failed');
        }
    }

    public function test_empty_database_returns_zero_zone_counts(): void
    {
        foreach (DashboardStatistics::zoneSummary() as $row) {
            $this->assertSame(0, $row['households']);
            $this->assertSame(0, $row['population']);
        }
    }

    public function test_soft_deleted_household_excluded_from_zone_household_count(): void
    {
        $active = Household::factory()->create(['zone' => 'Zone 1', 'household_no' => 'HH-SD01']);
        $trashed = Household::factory()->create(['zone' => 'Zone 1', 'household_no' => 'HH-SD02']);
        $trashed->delete();

        $this->assertSame(1, DashboardStatistics::zoneHouseholdCount('Zone 1'));
        $this->assertSame($active->id, Household::query()->first()->id);
    }

    public function test_soft_deleted_resident_excluded_from_zone_population_count(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 4', 'household_no' => 'HH-SD41']);
        $alive = Resident::factory()->create(['household_id' => $household->id]);
        $deleted = Resident::factory()->create(['household_id' => $household->id]);
        $deleted->delete();

        $this->assertSame(1, DashboardStatistics::zonePopulationCount('Zone 4'));
        $this->assertSame($alive->id, Resident::query()->first()->id);
    }

    public function test_resident_accounts_do_not_affect_zone_population(): void
    {
        ResidentAccount::factory()->create(['email' => 'zone-only-portal@example.test']);

        $this->assertSame(0, DashboardStatistics::zonePopulationCount('Zone 1'));
        $this->assertSame(0, DashboardStatistics::totalResidents());
    }

    public function test_no_demo_fixture_numbers_rendered_on_dashboard(): void
    {
        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('635', $html);
        $this->assertStringNotContainsString('2,103', $html);
        $this->assertStringNotContainsString('2103', $html);
        $this->assertStringNotContainsString('HH-151', $html);
        $this->assertStringNotContainsString('temporary UI demo values', $html);
    }

    public function test_existing_db21_metrics_remain_unchanged_with_zone_section(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-Z700',
            'zone' => 'Zone 2',
            'street' => 'Rizal Street',
            'date_registered' => '2026-08-01',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Head',
            'first_name' => 'Ada',
            'middle_name' => null,
            'last_name' => 'Reyes',
            'fp_user' => 'Yes',
        ]);

        $counts = DashboardUiData::summaryCounts();
        $response = $this->get(route('dashboard'))->assertOk();

        $response->assertSee('Total Household', false);
        $response->assertSee('Total Residents', false);
        $response->assertSee('Health Indicators', false);
        $response->assertSee((string) $counts['totalHouseholds'], false);
        $response->assertSee((string) $counts['totalResidents'], false);
        $response->assertDontSee('lml-dash-panel--table', false);
        $response->assertDontSee('Household snapshot', false);
    }

    public function test_dashboard_page_renders_five_zone_items_with_icons_and_values_only(): void
    {
        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Zone-Based Household and Population Map', $html);
        $this->assertStringContainsString('Visual representation of residential data by zone', $html);
        $this->assertSame(5, substr_count($html, 'data-dash-zone="zone-'));
        $this->assertStringContainsString('>Zone 1</h3>', $html);
        $this->assertStringContainsString('>Zone 5</h3>', $html);
        $this->assertStringNotContainsString('lml-dash-zone__metric-label', $html);
        $this->assertStringContainsString('bi-house-door-fill', $html);
        $this->assertStringContainsString('bi-people-fill', $html);
        $this->assertStringContainsString('lml-dash-zone__indicator--1', $html);
        $this->assertStringContainsString('lml-dash-zone__indicator--5', $html);
    }

    public function test_dashboard_sidebar_active_on_dashboard_route(): void
    {
        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame('dashboard', UiRole::sidebarActiveKey());
        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/dashboard"[^>]*class="[^"]*lml-sidebar__link--active/',
            $html
        );
    }

    public function test_spot_mapping_not_active_on_dashboard_route(): void
    {
        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/href="[^"]*\/spot-mapping"[^>]*class="[^"]*lml-sidebar__link--active/',
            $html
        );
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function test_spot_mapping_remains_active_on_spot_mapping_route(): void
    {
        $html = $this->get(route('spot-mapping.index'))->assertOk()->getContent();

        $this->assertSame('spot-mapping', UiRole::sidebarActiveKey());
        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/spot-mapping"[^>]*class="[^"]*lml-sidebar__link--active/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/href="[^"]*\/dashboard"[^>]*class="[^"]*lml-sidebar__link--active/',
            $html
        );
    }

    public function test_supported_shell_roles_see_same_zone_values(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 3', 'household_no' => 'HH-R303']);
        Resident::factory()->count(2)->create(['household_id' => $household->id]);

        $zoneCards = DashboardUiData::zoneSummaryCards();
        $zoneThree = collect($zoneCards)->firstWhere('zone', 'Zone 3');

        $this->assertNotNull($zoneThree);
        $this->assertSame(1, $zoneThree['households']);
        $this->assertSame(2, $zoneThree['population']);

        foreach (UiRole::ALLOWED as $role) {
            $this->actingAsStaff($role);
        $html = $this->get(route('dashboard'))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('Zone-Based Household and Population Map', $html);
            $this->assertStringContainsString('>1</p>', $html);
            $this->assertStringContainsString('>2</p>', $html);
        }
    }

    public function test_dashboard_get_remains_read_only(): void
    {
        $before = [
            'households' => Household::query()->count(),
            'residents' => Resident::query()->count(),
            'resident_accounts' => ResidentAccount::query()->count(),
        ];

        $this->get(route('dashboard'))->assertOk();

        $this->assertSame($before['households'], Household::query()->count());
        $this->assertSame($before['residents'], Resident::query()->count());
        $this->assertSame($before['resident_accounts'], ResidentAccount::query()->count());
    }

    public function test_zero_data_renders_zero_for_zone_metrics(): void
    {
        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(5, substr_count($html, 'data-dash-zone="zone-'));
        $this->assertGreaterThanOrEqual(10, substr_count($html, '>0</p>'));
    }
}
