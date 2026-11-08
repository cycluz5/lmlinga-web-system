<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Services\HouseholdService;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\DemoStaffLogin;
use App\Support\EnvironmentalSanitationErdMode;
use App\Support\HouseholdProfilingPresenter;
use App\Support\HouseholdProfilingWriteGuard;
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
 * Phase 1 — Household Profiling ERD read/display (purok, occupation lookup, environmental_sanitation).
 */
class HouseholdProfilingErdReadDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ClientTestingErdSchema::ensure();
        Household::resetResolvedKeyName();
        Resident::resetResolvedKeyName();
        EnvironmentalSanitationErdMode::resetCachedState();
        UserManagementErdMode::resetCachedState();
    }

    public function test_index_displays_zone_one_from_purok(): void
    {
        $this->seedHouseholdWithMember();
        $this->authenticateErdBhw();

        $this->get(route('household-profiling.index'))
            ->assertOk()
            ->assertSee('data-zone="Zone 1"', false)
            ->assertSee('data-household-no="HH-001"', false);
    }

    public function test_show_displays_zone_one_from_purok(): void
    {
        $this->seedHouseholdWithMember();
        $this->authenticateErdBhw();

        $html = $this->get(route('household-profiling.view', ['householdNo' => 'HH-001']))
            ->assertOk()
            ->assertSee('Juan M Dela Cruz', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<span>Zone<\/span>\s*<\/dt>\s*<dd>Zone 1<\/dd>/u',
            $html
        );
    }

    public function test_presenter_maps_purok_one_to_zone_one_for_index_and_show(): void
    {
        $this->seedHouseholdWithMember();

        $household = Household::query()->where('household_no', 'HH-001')->firstOrFail();
        $presentation = HouseholdProfilingPresenter::fromModel($household);
        $row = HouseholdProfilingPresenter::listRowFromModel($household);

        $this->assertSame('Zone 1', $presentation['zone']);
        $this->assertSame('1', $presentation['purok']);
        $this->assertSame('Zone 1', $row['zone']);
        $this->assertSame('HH-001', $row['householdNo']);
        $this->assertSame('001', $row['displayNo']);

        $rows = app(HouseholdService::class)->profilingListRows();
        $this->assertSame('Zone 1', $rows[0]['zone']);
    }

    public function test_member_occupation_uses_occupation_lookup_name(): void
    {
        $this->seedHouseholdWithMember();
        $this->authenticateErdBhw();

        $household = Household::query()->where('household_no', 'HH-001')->with('residents.occupationLookup')->firstOrFail();
        $member = HouseholdProfilingPresenter::memberFromModel($household->residents->first());
        $this->assertSame('Farmer', $member['occupation']);

        $this->get(route('household-profiling.view', ['householdNo' => 'HH-001']))
            ->assertOk()
            ->assertSee('Farmer', false);
    }

    public function test_occupation_other_is_used_for_other_lookup(): void
    {
        if (! Schema::hasColumn('residents', 'occupation_other')) {
            Schema::table('residents', function ($table): void {
                $table->string('occupation_other')->nullable();
            });
        }

        $otherId = (int) DB::table('occupation')->insertGetId(
            ['occupation_name' => 'Other'],
            'occupation_id'
        );
        $householdId = $this->insertHousehold('HH-010', '2');
        DB::table('residents')->insert([
            'household_id' => $householdId,
            'first_name' => 'Ana',
            'last_name' => 'Santos',
            'relation_to_household_head' => 'Head',
            'birthday' => '1990-02-02',
            'sex' => 'Female',
            'civil_status' => 'Married',
            'occupation_id' => $otherId,
            'occupation_other' => 'Basket weaver',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $household = Household::query()->where('household_no', 'HH-010')->firstOrFail();
        $household->load('residents.occupationLookup');
        $member = HouseholdProfilingPresenter::memberFromModel($household->residents->first());

        $this->assertSame('Basket weaver', $member['occupation']);
    }

    public function test_overview_uses_environmental_sanitation_instead_of_not_recorded(): void
    {
        $this->seedHouseholdWithMember(withSanitation: true);
        $this->authenticateErdBhw();

        $household = Household::query()->where('household_no', 'HH-001')->firstOrFail();
        $presentation = HouseholdProfilingPresenter::fromModel($household);

        $this->assertSame('Level II', $presentation['water']['level']);
        $this->assertSame(
            DemoHouseholdWaterSupply::basicSafeWaterStatusLabel(DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITH),
            $presentation['water']['status']
        );
        $this->assertNotSame('Not recorded', $presentation['water']['status']);
        $this->assertSame('Sanitary', $presentation['sanitation']['facility']);
        $this->assertSame('Safely Managed', $presentation['sanitation']['status']);

        $html = $this->get(route('household-profiling.view', ['householdNo' => 'HH-001']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Level II', $html);
        $this->assertStringContainsString('With Basic Safe Water', $html);
        $this->assertStringContainsString('Sanitary', $html);
        $this->assertStringContainsString('Safely Managed', $html);
        $this->assertStringNotContainsString('Not recorded', $html);
    }

    public function test_overview_stays_not_recorded_without_environmental_sanitation_row(): void
    {
        $this->seedHouseholdWithMember(withSanitation: false);
        $this->authenticateErdBhw();

        $household = Household::query()->where('household_no', 'HH-001')->firstOrFail();
        $presentation = HouseholdProfilingPresenter::fromModel($household);

        $this->assertSame('Not recorded', $presentation['water']['status']);
        $this->assertSame('Not recorded', $presentation['sanitation']['status']);
        $this->assertSame('—', $presentation['water']['level']);
        $this->assertSame('—', $presentation['sanitation']['facility']);

        $this->get(route('household-profiling.view', ['householdNo' => 'HH-001']))
            ->assertOk()
            ->assertSee('Not recorded', false);
    }

    public function test_missing_street_and_accomplished_by_use_em_dash_without_unknown_column_query(): void
    {
        $this->seedHouseholdWithMember();

        $this->assertFalse(Schema::hasColumn('households', 'street'));
        $this->assertFalse(Schema::hasColumn('households', 'address'));
        $this->assertFalse(Schema::hasColumn('households', 'accomplished_by'));
        $this->assertFalse(Schema::hasColumn('households', 'zone'));

        $household = Household::query()->where('household_no', 'HH-001')->firstOrFail();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $presentation = HouseholdProfilingPresenter::fromModel($household);
        $row = HouseholdProfilingPresenter::listRowFromModel($household);
        $sql = strtolower(collect(DB::getQueryLog())->pluck('query')->implode(' '));
        DB::disableQueryLog();

        $this->assertSame('—', $presentation['street']);
        $this->assertSame('—', $presentation['accomplishedBy']);
        $this->assertSame('01/15/2026', $presentation['accomplishedDate']);
        $this->assertSame('—', $row['street']);
        $this->assertStringNotContainsString('accomplished_by', $sql);
        $this->assertDoesNotMatchRegularExpression('/households[^;]*\bstreet\b/', $sql);
    }

    public function test_write_guard_allows_purok_only_household_shell(): void
    {
        $this->assertFalse(HouseholdProfilingWriteGuard::isHouseholdShellWriteUnsupported());
    }

    /**
     * @return int household_id
     */
    private function seedHouseholdWithMember(bool $withSanitation = false): int
    {
        $staffId = $this->insertStaffUserId();
        $occupationId = (int) DB::table('occupation')->insertGetId(
            ['occupation_name' => 'Farmer'],
            'occupation_id'
        );
        $householdId = $this->insertHousehold('HH-001', '1');

        DB::table('residents')->insert([
            'household_id' => $householdId,
            'first_name' => 'Juan',
            'middle_name' => 'M',
            'last_name' => 'Dela Cruz',
            'relation_to_household_head' => 'Head',
            'birthday' => '1985-01-15',
            'sex' => 'Male',
            'civil_status' => 'Married',
            'occupation_id' => $occupationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($withSanitation) {
            $row = [
                'household_id' => $householdId,
                'user_id' => $staffId,
                'water_supply_status' => 'Level II',
                'toilet_type' => DemoHouseholdWaterSupply::SANITARY_TOILET_TYPES[0],
                'sewage_disposal_method' => 'On-site Disposed',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::table('environmental_sanitation')->insert($row);
        }

        return $householdId;
    }

    private function insertHousehold(string $householdNo, string $purok): int
    {
        return (int) DB::table('households')->insertGetId([
            'household_no' => $householdNo,
            'purok' => $purok,
            'latitude' => '13.38110000',
            'longitude' => '123.43060000',
            'household_type' => 'NHTS',
            'date_registered' => '2026-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'household_id');
    }

    private function insertStaffUserId(): int
    {
        $existing = DB::table('user_management')->where('email', 'erd.bhw@example.test')->value('user_id');
        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('user_management')->insertGetId([
            'first_name' => 'Erd',
            'last_name' => 'Bhw',
            'email' => 'erd.bhw@example.test',
            'username' => 'erd.bhw',
            'password' => 'hashed-placeholder',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'user_id');
    }

    private function authenticateErdBhw(): User
    {
        $user = User::query()->where('email', 'erd.bhw@example.test')->first();
        if ($user === null) {
            $this->insertStaffUserId();
            $user = User::query()->where('email', 'erd.bhw@example.test')->firstOrFail();
        }

        if ($user->resolveCurrentAppointment() === null) {
            $user->assignCurrentAppointment([
                'role' => StaffRole::BHW,
                'assigned_barangay' => 'La Medalla',
                'assigned_zone' => 'Zone 1',
                'date_appointed' => '2020-01-01',
            ]);
            $user = $user->fresh(['currentAppointment']);
        }

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
