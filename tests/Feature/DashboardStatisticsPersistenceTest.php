<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\HouseholdEnvironmentalProfile;
use App\Models\MaternalPregnancy;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\ResidentStatus;
use App\Support\DashboardStatistics;
use App\Support\DashboardUiData;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\EnvironmentalHealthDashboard;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * DB21 — Dashboard MySQL-backed aggregates replace UI fixtures.
 */
class DashboardStatisticsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-27 12:00:00'));
        $this->actingAsStaff(StaffRole::BNS);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_zero_database_returns_zeros_unavailable_and_empty_snapshot(): void
    {
        $summary = DashboardStatistics::summary();

        $this->assertSame(0, $summary['totalHouseholds']);
        $this->assertSame(0, $summary['totalResidents']);
        $this->assertSame(0, $summary['nhts']);
        $this->assertSame(0, $summary['nonNhts']);
        $this->assertSame(0, $summary['pregnant']);
        $this->assertSame(0, $summary['fpCurrentUser']);
        $this->assertSame(0, $summary['infants011']);
        $this->assertSame(0, $summary['hhLargeFamily']);
        $this->assertSame(0, $summary['hhSanitaryToilet']);
        $this->assertArrayNotHasKey('nonNhtsPoor', $summary);
        $this->assertArrayNotHasKey('fpUnmetNeeds', $summary);
        $this->assertArrayNotHasKey('exclusivelyBreastfed', $summary);
        $this->assertArrayNotHasKey('lactating', $summary);
        $this->assertSame(0, $summary['teenagePregnant']);
        $this->assertSame(0, $summary['normalWeight']);
        $this->assertSame(0, $summary['underweight']);
        $this->assertSame(0, $summary['overweight']);
        $this->assertSame(0, $summary['hhPotableWater']);
        $this->assertSame([], DashboardStatistics::householdSnapshot());
    }

    public function test_no_fixture_bleed_on_dashboard_page(): void
    {
        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('635', $html);
        $this->assertStringNotContainsString('2,103', $html);
        $this->assertStringNotContainsString('2103', $html);
        $this->assertStringNotContainsString('418', $html);
        $this->assertStringNotContainsString('217', $html);
        $this->assertStringNotContainsString('>94<', $html);
        $this->assertStringNotContainsString('HH-151', $html);
        $this->assertStringNotContainsString('temporary UI demo values', $html);
        $this->assertStringNotContainsString('lml-dash-panel--table', $html);
        $this->assertStringContainsString("data-markers='[]'", $html);
        $this->assertStringNotContainsString(DashboardStatistics::UNAVAILABLE, $html);
    }

    public function test_soft_deleted_household_and_resident_excluded(): void
    {
        $this->markTestSkipped(
            'Household/resident archival requires Eloquent SoftDeletes. Live ERD has no deleted_at and models do not use SoftDeletes.'
        );
    }

    public function test_resident_total_includes_deceased_excludes_resident_accounts(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-810']);
        $living = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-001',
            'relation' => 'Head',
            'fp_user' => 'No',
        ]);
        $deceased = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-002',
            'relation' => 'Spouse',
            'fp_user' => 'No',
        ]);

        ResidentStatus::query()->create([
            'household_no' => $household->household_no,
            'member_id' => $deceased->member_no,
            'resident_id' => $deceased->id,
            'status' => ResidentStatus::STATUS_DECEASED,
            'recorded_at' => now(),
        ]);

        ResidentAccount::factory()->create([
            'email' => 'portal-only@example.test',
        ]);

        $this->assertSame(2, DashboardStatistics::totalResidents());
        $this->assertSame(1, ResidentAccount::query()->count());
        $this->assertNotSame($living->id, $deceased->id);
    }

    public function test_nhts_and_non_nhts_classification_rules(): void
    {
        $nhts = Household::factory()->create(['household_no' => 'HH-820']);
        HouseholdEnvironmentalProfile::query()->create([
            'household_id' => $nhts->id,
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
            'completed_step' => 0,
        ]);

        $non = Household::factory()->create(['household_no' => 'HH-821']);
        HouseholdEnvironmentalProfile::query()->create([
            'household_id' => $non->id,
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NON_NHTS,
            'completed_step' => 0,
        ]);

        Household::factory()->create(['household_no' => 'HH-822']);

        $nullType = Household::factory()->create(['household_no' => 'HH-823']);
        HouseholdEnvironmentalProfile::query()->create([
            'household_id' => $nullType->id,
            'household_type' => null,
            'completed_step' => 0,
        ]);

        $bogus = Household::factory()->create(['household_no' => 'HH-824']);
        HouseholdEnvironmentalProfile::query()->create([
            'household_id' => $bogus->id,
            'household_type' => 'bogus',
            'completed_step' => 0,
        ]);

        $this->assertSame(1, DashboardStatistics::nhtsHouseholds());
        $this->assertSame(1, DashboardStatistics::nonNhtsHouseholds());
    }

    public function test_pregnant_counts_active_distinct_residents_only(): void
    {
        $r1 = Resident::factory()->create(['fp_user' => 'No']);
        $r2 = Resident::factory()->create(['fp_user' => 'No']);

        MaternalPregnancy::factory()->create([
            'resident_id' => $r1->id,
            'status' => MaternalPregnancy::STATUS_ACTIVE,
            'pregnancy_no' => 'MC-001',
            'registered_at' => '2024-01-01',
        ]);
        MaternalPregnancy::factory()->create([
            'resident_id' => $r1->id,
            'status' => MaternalPregnancy::STATUS_TRANSFERRED_OUT,
            'pregnancy_no' => 'MC-002',
            'pregnancy_number' => 2,
            'registered_at' => '2023-01-01',
        ]);
        MaternalPregnancy::factory()->create([
            'resident_id' => $r2->id,
            'status' => MaternalPregnancy::STATUS_TRANSFERRED_OUT,
            'pregnancy_no' => 'MC-003',
            'registered_at' => '2025-06-01',
        ]);

        $this->assertSame(1, DashboardStatistics::pregnantResidents());
    }

    public function test_fp_current_user_counts_yes_only(): void
    {
        $household = Household::factory()->create();
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Head',
            'fp_user' => 'Yes',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Spouse',
            'fp_user' => 'No',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Daughter',
            'fp_user' => 'N/A',
        ]);

        $this->assertSame(1, DashboardStatistics::fpCurrentUsers());
    }

    public function test_infant_age_boundaries(): void
    {
        $household = Household::factory()->create();

        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Head',
            'birthday' => Carbon::now()->subMonths(0)->toDateString(),
            'fp_user' => 'N/A',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Son',
            'birthday' => Carbon::now()->subMonths(11)->toDateString(),
            'fp_user' => 'N/A',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Daughter',
            'birthday' => Carbon::now()->subMonths(12)->toDateString(),
            'fp_user' => 'N/A',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Son',
            'birthday' => Carbon::now()->addDay()->toDateString(),
            'fp_user' => 'N/A',
        ]);

        $this->assertSame(2, DashboardStatistics::infantsZeroToElevenMonths());
    }

    public function test_large_family_threshold_and_soft_deleted_member(): void
    {
        $five = Household::factory()->create(['household_no' => 'HH-850']);
        $this->seedMembers($five, 5);

        $six = Household::factory()->create(['household_no' => 'HH-851']);
        $this->seedMembers($six, 6);

        $inflated = Household::factory()->create(['household_no' => 'HH-852']);
        $this->seedMembers($inflated, 5);
        $extra = Resident::factory()->create([
            'household_id' => $inflated->id,
            'relation' => 'Son',
            'fp_user' => 'No',
        ]);
        $extra->delete();

        $this->assertSame(1, DashboardStatistics::largeFamilyHouseholds());
    }

    public function test_sanitary_toilet_parity_with_environmental_health(): void
    {
        $sanitary = Household::factory()->create(['household_no' => 'HH-860']);
        HouseholdEnvironmentalProfile::query()->create([
            'household_id' => $sanitary->id,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'toilet_status' => DemoHouseholdWaterSupply::TOILET_STATUS_SANITARY,
            'completed_step' => 4,
        ]);

        $unsanitary = Household::factory()->create(['household_no' => 'HH-861']);
        HouseholdEnvironmentalProfile::query()->create([
            'household_id' => $unsanitary->id,
            'toilet_type' => 'open_pit_latrine',
            'toilet_status' => DemoHouseholdWaterSupply::TOILET_STATUS_UNSANITARY,
            'completed_step' => 4,
        ]);

        $ehSanitary = EnvironmentalHealthDashboard::statistics(
            EnvironmentalHealthDashboard::rows()
        )['sanitation']['sanitary'];

        $this->assertSame(1, $ehSanitary);
        $this->assertSame(1, DashboardStatistics::sanitaryToiletHouseholds());
        $this->assertSame($ehSanitary, DashboardStatistics::sanitaryToiletHouseholds());
    }

    public function test_household_snapshot_order_limit_head_and_members(): void
    {
        $oldest = Household::factory()->create([
            'household_no' => 'HH-901',
            'zone' => 'Zone A',
            'street' => 'Alpha St.',
            'date_registered' => '2026-01-01',
        ]);
        Resident::factory()->create([
            'household_id' => $oldest->id,
            'relation' => 'Head',
            'first_name' => 'Old',
            'middle_name' => null,
            'last_name' => 'Head',
            'fp_user' => 'No',
        ]);

        foreach ([
            ['HH-902', '2026-02-01', 'Zone B', 'Beta St.', 'Two'],
            ['HH-903', '2026-03-01', 'Zone C', 'Gamma St.', 'Three'],
            ['HH-904', '2026-04-01', 'Zone D', 'Delta St.', 'Four'],
            ['HH-905', '2026-05-01', 'Zone E', 'Epsilon St.', 'Five'],
        ] as [$no, $date, $zone, $street, $first]) {
            $hh = Household::factory()->create([
                'household_no' => $no,
                'zone' => $zone,
                'street' => $street,
                'date_registered' => $date,
            ]);
            Resident::factory()->create([
                'household_id' => $hh->id,
                'relation' => 'Head',
                'first_name' => $first,
                'middle_name' => null,
                'last_name' => 'Head',
                'fp_user' => 'No',
            ]);
            Resident::factory()->create([
                'household_id' => $hh->id,
                'relation' => 'Son',
                'fp_user' => 'No',
            ]);
        }

        $sameDayA = Household::factory()->create([
            'household_no' => 'HH-910',
            'zone' => 'Zone F',
            'street' => 'Zeta St.',
            'date_registered' => '2026-06-01',
        ]);
        $sameDayB = Household::factory()->create([
            'household_no' => 'HH-911',
            'zone' => 'Zone G',
            'street' => 'Eta St.',
            'date_registered' => '2026-06-01',
        ]);
        Resident::factory()->create([
            'household_id' => $sameDayA->id,
            'relation' => 'Spouse',
            'first_name' => 'No',
            'last_name' => 'HeadYet',
            'fp_user' => 'No',
        ]);
        Resident::factory()->create([
            'household_id' => $sameDayB->id,
            'relation' => 'Head',
            'first_name' => 'Tie',
            'middle_name' => null,
            'last_name' => 'Breaker',
            'fp_user' => 'No',
        ]);

        $rows = DashboardStatistics::householdSnapshot();

        $this->assertCount(4, $rows);
        $this->assertSame(['HH-911', 'HH-910', 'HH-905', 'HH-904'], array_column($rows, 'hhNo'));
        $this->assertSame('Tie Breaker', $rows[0]['hhHead']);
        $this->assertSame(DashboardStatistics::UNAVAILABLE, $rows[1]['hhHead']);
        $this->assertSame('Zone E', $rows[2]['zone']);
        $this->assertSame('Epsilon St.', $rows[2]['street']);
        $this->assertSame(2, $rows[2]['members']);
        $this->assertSame('Five Head', $rows[2]['hhHead']);
    }

    public function test_dashboard_get_is_read_only_for_domain_tables(): void
    {
        $before = [
            'households' => Household::query()->count(),
            'residents' => Resident::query()->count(),
            'profiles' => HouseholdEnvironmentalProfile::query()->count(),
            'pregnancies' => MaternalPregnancy::query()->count(),
        ];

        $this->get(route('dashboard'))->assertOk();

        $this->assertSame($before['households'], Household::query()->count());
        $this->assertSame($before['residents'], Resident::query()->count());
        $this->assertSame($before['profiles'], HouseholdEnvironmentalProfile::query()->count());
        $this->assertSame($before['pregnancies'], MaternalPregnancy::query()->count());
    }

    public function test_supported_shell_roles_see_same_metric_values(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-920']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Head',
            'fp_user' => 'Yes',
        ]);

        $expected = DashboardUiData::summaryCounts();
        $indicatorValues = array_column(DashboardUiData::healthIndicators(), 'value');

        foreach (UiRole::ALLOWED as $role) {
            $this->actingAsStaff($role);
        $html = $this->get(route('dashboard'))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString(
                (string) $expected['totalHouseholds'],
                $html,
                "Role {$role} household count mismatch"
            );
            $this->assertStringContainsString(
                (string) $expected['totalResidents'],
                $html,
                "Role {$role} resident count mismatch"
            );
            $this->assertSame(
                $indicatorValues,
                array_column(DashboardUiData::healthIndicators(), 'value'),
                "Role {$role} indicator drift"
            );
        }
    }

    private function seedMembers(Household $household, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Resident::factory()->create([
                'household_id' => $household->id,
                'relation' => $i === 0 ? 'Head' : 'Son',
                'fp_user' => 'No',
            ]);
        }
    }
}
