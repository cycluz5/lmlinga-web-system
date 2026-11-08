<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Services\HouseholdService;
use App\Services\SpotMappingService;
use App\Support\DemoStaffLogin;
use App\Support\FamilyPlanningErdMode;
use App\Support\HealthRecordsFamilyPlanning;
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
 * Contain NR-FP sentinel household and its residents to Non-Resident Family Planning.
 *
 * sqlite :memory: only. Does not write to lmlinga_erd_reference.
 */
class NonResidentFamilyPlanningSentinelIsolationTest extends TestCase
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
    }

    public function test_nr_fp_is_hidden_from_household_profiling_index_totals_and_pdf(): void
    {
        $this->seedRealHouseholds();
        $this->authenticateErdBhw();
        $this->post(
            route('health-records.family-planning.non-residents.store'),
            $this->validCreatePayload()
        )->assertRedirect();

        $this->assertSame(1, DB::table('households')->where('household_no', 'NR-FP')->count());

        $service = app(HouseholdService::class);
        $listNos = array_column($service->profilingListRows(), 'householdNo');
        $this->assertSame(['001', '002'], $listNos);
        $this->assertNotContains('NR-FP', $listNos);

        $summary = $service->profilingSummary();
        $this->assertSame(2, $summary['households']);
        $this->assertSame(2, $summary['respondents']);
        $this->assertSame(2, $summary['male']);
        $this->assertSame(0, $summary['female']);

        $exportNos = array_column($service->profilingExportRows(), 'householdNo');
        $this->assertSame(['001', '002'], $exportNos);
        $this->assertNotContains('NR-FP', $exportNos);

        $index = $this->get(route('household-profiling.index'))->assertOk();
        $index->assertSee('data-household-no="001"', false);
        $index->assertSee('data-household-no="002"', false);
        $index->assertDontSee('data-household-no="NR-FP"', false);
        $index->assertDontSee('>NR-FP<', false);
        $index->assertSee('data-stat="households">2', false);
        $index->assertSee('data-stat="respondents">2', false);

        $pdf = $this->get(route('household-profiling.export'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $pdfBody = $pdf->getContent();
        $this->assertStringStartsWith('%PDF', $pdfBody);
        $this->assertStringContainsString('001', $pdfBody);
        $this->assertStringContainsString('002', $pdfBody);
        $this->assertStringNotContainsString('NR-FP', $pdfBody);
    }

    public function test_nr_fp_is_hidden_from_spot_mapping_while_real_households_remain(): void
    {
        $this->seedRealHouseholds();
        $this->authenticateErdBhw();
        $this->post(
            route('health-records.family-planning.non-residents.store'),
            $this->validCreatePayload()
        )->assertRedirect();

        $spot = app(SpotMappingService::class);
        $stats = $spot->stats();
        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['plotted']);
        $this->assertSame(1, $stats['pending']);

        $markerNos = array_column($spot->mappedMarkers(), 'householdNo');
        $this->assertSame(['001'], $markerNos);
        $this->assertNotContains('NR-FP', $markerNos);

        $pendingNos = array_column($spot->pendingPlotCandidates(), 'householdNo');
        $this->assertSame(['002'], $pendingNos);
        $this->assertNotContains('NR-FP', $pendingNos);
        $this->assertNull($spot->findActiveByHouseholdNo('NR-FP'));
        $this->assertNotNull($spot->findActiveByHouseholdNo('001'));
        $this->assertNotNull($spot->findActiveByHouseholdNo('002'));

        $page = $this->get(route('spot-mapping.index'))->assertOk();
        $page->assertSee('data-stat="total">2', false);
        $page->assertSee('data-stat="plotted">1', false);
        $page->assertSee('data-stat="pending">1', false);
        $page->assertSee('001', false);
        $page->assertSee('002', false);
        $page->assertDontSee('NR-FP', false);
    }

    public function test_nr_fp_records_are_hidden_from_resident_family_planning_and_visible_in_non_resident(): void
    {
        $this->seedRealHouseholds();
        $this->authenticateErdBhw();
        $this->post(
            route('health-records.family-planning.non-residents.store'),
            $this->validCreatePayload()
        )->assertRedirect();

        $residentRows = HealthRecordsFamilyPlanning::rows();
        $residentNames = array_column($residentRows, 'full_name');
        $this->assertContains('Juan M Dela Cruz', $residentNames);
        $this->assertNotContains('Ana Cruz Santos', $residentNames);
        $this->assertCount(1, $residentRows);

        $nrClients = HealthRecordsNonResidentFamilyPlanning::clients();
        $nrNames = array_column($nrClients, 'full_name');
        $this->assertContains('Ana Cruz Santos', $nrNames);
        $this->assertNotContains('Juan M Dela Cruz', $nrNames);

        $this->get(route('health-records.family-planning.index'))
            ->assertOk()
            ->assertSee('Juan M Dela Cruz', false)
            ->assertDontSee('Ana Cruz Santos', false);

        $this->get(route('health-records.family-planning.non-residents.index'))
            ->assertOk()
            ->assertSee('Ana Cruz Santos', false)
            ->assertDontSee('Juan M Dela Cruz', false);
    }

    private function seedRealHouseholds(): void
    {
        $plottedId = $this->insertHousehold('001', '1', '13.38110000', '123.43060000');
        $pendingId = $this->insertHousehold('002', '2', '0', '0');

        $juanId = $this->insertResident($plottedId, [
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

        DB::table('family_planning')->insert([
            'resident_id' => $juanId,
            'visitation_date' => '2026-02-10',
            'remarks' => 'Resident FP visit',
            'created_at' => now(),
            'updated_at' => now(),
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
        foreach ([
            'fp_commodities_given',
            'family_planning_visits',
            'family_planning',
        ] as $table) {
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
            'last_name' => 'FpIso',
            'email' => 'erd.fpiso@example.test',
            'username' => 'erd.fpiso',
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
