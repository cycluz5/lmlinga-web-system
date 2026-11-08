<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Support\DashboardStatistics;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\DemoStaffLogin;
use App\Support\EnvironmentalHealthDashboard;
use App\Support\EnvironmentalSanitationErdMode;
use App\Support\FamilyPlanningErdMode;
use App\Support\HealthRecordsNonResidentFamilyPlanning;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use App\Support\UserManagementErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

/**
 * Contain NR-FP from Dashboard and Environmental Health household populations.
 *
 * sqlite :memory: only. Does not write to lmlinga_erd_reference.
 */
class NonResidentFamilyPlanningDashboardEhSentinelIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ClientTestingErdSchema::ensure();
        $this->provisionAuthoritativeFamilyPlanningTables();
        Household::resetResolvedKeyName();
        Resident::resetResolvedKeyName();
        UserManagementErdMode::resetCachedState();
        FamilyPlanningErdMode::resetCachedState();
        EnvironmentalSanitationErdMode::resetCachedState();
    }

    public function test_dashboard_household_and_zone_statistics_ignore_nr_fp(): void
    {
        $this->seedRealHouseholds();
        $this->authenticateErdBhw();
        $this->post(
            route('health-records.family-planning.non-residents.store'),
            $this->validCreatePayload()
        )->assertRedirect();

        $this->assertSame(1, DB::table('households')->where('household_no', 'NR-FP')->count());

        $this->assertSame(2, DashboardStatistics::totalHouseholds());
        $this->assertSame(2, DashboardStatistics::totalResidents());
        $this->assertSame(2, DashboardStatistics::nhtsHouseholds());

        $zones = collect(DashboardStatistics::zoneSummary())->keyBy('zone');
        $this->assertSame(1, $zones['Zone 1']['households']);
        $this->assertSame(1, $zones['Zone 1']['population']);
        $this->assertSame(1, $zones['Zone 2']['households']);
        $this->assertSame(1, $zones['Zone 2']['population']);
        $this->assertSame(0, $zones['Zone 3']['households']);

        $snapshotNos = array_column(DashboardStatistics::householdSnapshot(), 'hhNo');
        $this->assertContains('HH-001', $snapshotNos);
        $this->assertContains('HH-002', $snapshotNos);
        $this->assertNotContains('NR-FP', $snapshotNos);

        $page = $this->get(route('dashboard'))->assertOk();
        $page->assertSee('data-dash-count="households"', false);
        $page->assertSee('data-dash-zone="zone-1"', false);
        $page->assertDontSee('NR-FP', false);
        $page->assertDontSee('No household records to display.', false);
    }

    public function test_environmental_health_list_counts_and_export_ignore_nr_fp(): void
    {
        $this->seedRealHouseholds();
        $this->authenticateErdBhw();
        $this->post(
            route('health-records.family-planning.non-residents.store'),
            $this->validCreatePayload()
        )->assertRedirect();

        $rows = EnvironmentalHealthDashboard::rows();
        $nos = array_column($rows, 'household_no');
        $this->assertSame(['HH-001', 'HH-002'], $nos);
        $this->assertNotContains('NR-FP', $nos);

        $stats = EnvironmentalHealthDashboard::statistics($rows);
        $this->assertSame(2, $stats['overview']['total_households']);

        $this->assertNotNull(DemoHouseholdWaterSupply::findDbHousehold('HH-001'));
        $this->assertNull(DemoHouseholdWaterSupply::findDbHousehold('NR-FP'));

        $index = $this->get(route('environmental-health.index'))->assertOk();
        $index->assertSee('data-household-no="HH-001"', false);
        $index->assertSee('data-household-no="HH-002"', false);
        $index->assertDontSee('data-household-no="NR-FP"', false);
        $index->assertSee('data-stat="overview-total">2', false);

        // The export route now defaults to Excel; request CSV explicitly
        // since that's the streamed format this assertion checks.
        $csv = $this->get(route('environmental-health.export', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('HH-001', $csv);
        $this->assertStringContainsString('HH-002', $csv);
        $this->assertStringNotContainsString('NR-FP', $csv);
    }

    public function test_non_resident_family_planning_still_lists_sentinel_clients(): void
    {
        $this->seedRealHouseholds();
        $this->authenticateErdBhw();
        $this->post(
            route('health-records.family-planning.non-residents.store'),
            $this->validCreatePayload()
        )->assertRedirect();

        $names = array_column(HealthRecordsNonResidentFamilyPlanning::clients(), 'full_name');
        $this->assertContains('Ana Cruz Santos', $names);

        $this->get(route('health-records.family-planning.non-residents.index'))
            ->assertOk()
            ->assertSee('Ana Cruz Santos', false);
    }

    private function seedRealHouseholds(): void
    {
        $plottedId = $this->insertHousehold('HH-001', '1', '13.38110000', '123.43060000');
        $pendingId = $this->insertHousehold('HH-002', '2', '0', '0');

        $this->insertResident($plottedId, [
            'first_name' => 'Juan',
            'middle_name' => 'M',
            'last_name' => 'Dela Cruz',
            'relation_to_household_head' => 'Head',
            'sex' => 'Male',
        ]);
        $this->insertResident($pendingId, [
            'first_name' => 'Pedro',
            'middle_name' => null,
            'last_name' => 'Reyes',
            'relation_to_household_head' => 'Head',
            'sex' => 'Male',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertResident(int $householdId, array $overrides): int
    {
        return (int) DB::table('residents')->insertGetId(array_merge([
            'household_id' => $householdId,
            'first_name' => 'Test',
            'middle_name' => null,
            'last_name' => 'Resident',
            'relation_to_household_head' => 'Head',
            'birthday' => '1985-01-15',
            'sex' => 'Male',
            'civil_status' => 'Married',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'resident_id');
    }

    private function insertHousehold(string $householdNo, string $purok, string $latitude, string $longitude): int
    {
        return (int) DB::table('households')->insertGetId([
            'household_no' => $householdNo,
            'purok' => $purok,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'household_type' => 'NHTS',
            'date_registered' => '2026-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'household_id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validCreatePayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'birthday' => '1992-06-18',
            'sex' => 'Female',
            'civil_status' => 'Married',
            'address_zone' => 'Poblacion, Brgy. San Jose',
            'visited_at' => '2026-03-15',
            'method' => 'Pills',
            'remarks' => 'First counseling visit',
            'commodities' => [
                ['name' => 'Pills', 'quantity' => 30],
            ],
        ], $overrides);
    }

    private function provisionAuthoritativeFamilyPlanningTables(): void
    {
        foreach (['fp_commodities_given', 'family_planning_visits', 'family_planning'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('family_planning', function ($table): void {
            $table->id('fp_id');
            $table->unsignedBigInteger('resident_id');
            $table->date('visitation_date');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('fp_commodities_given', function ($table): void {
            $table->id('commodity_given_id');
            $table->unsignedBigInteger('fp_id');
            $table->string('commodity_name');
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
    }

    private function authenticateErdBhw(): User
    {
        $userId = (int) DB::table('user_management')->insertGetId([
            'first_name' => 'Erd',
            'last_name' => 'DashEh',
            'email' => 'erd.dasheh@example.test',
            'username' => 'erd.dasheh',
            'password' => 'hashed-placeholder',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'user_id');

        /** @var User $user */
        $user = User::query()->findOrFail($userId);
        $user->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);
        $user = $user->fresh(['currentAppointment']);

        $this->actingAs($user);
        $user->syncUiRoleSession();
        session([
            UiRole::SESSION_KEY => StaffRole::BHW,
            DemoStaffLogin::SESSION_DISPLAY_NAME => $user->composeDisplayName(),
            DemoStaffLogin::SESSION_EMAIL => (string) $user->email,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        return $user;
    }
}
