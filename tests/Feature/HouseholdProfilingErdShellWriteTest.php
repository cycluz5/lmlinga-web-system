<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Services\HouseholdService;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\DemoStaffLogin;
use App\Support\EnvironmentalSanitationErdMode;
use App\Support\HouseholdProfilingWriteGuard;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use App\Support\UserManagementErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

/**
 * Phase 2A — ERD-safe household shell create/update (purok, no legacy columns).
 */
class HouseholdProfilingErdShellWriteTest extends TestCase
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

    public function test_create_on_purok_schema_succeeds_and_maps_zone_one_to_purok(): void
    {
        $this->assertFalse(HouseholdProfilingWriteGuard::isHouseholdShellWriteUnsupported());

        DB::flushQueryLog();
        DB::enableQueryLog();

        $household = app(HouseholdService::class)->create($this->erdCreatePayload());

        $sql = strtolower(collect(DB::getQueryLog())->pluck('query')->implode(' | '));
        DB::disableQueryLog();

        $this->assertSame('121', (string) $household->household_no);
        $this->assertSame('1', (string) $household->purok);
        $this->assertSame('2026-01-15', $household->date_registered?->format('Y-m-d'));
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);
        $this->assertSame('NHTS', (string) $household->household_type);

        $this->assertFalse(Schema::hasColumn('households', 'zone'));
        $this->assertFalse(Schema::hasColumn('households', 'street'));
        $this->assertFalse(Schema::hasColumn('households', 'address'));
        $this->assertFalse(Schema::hasColumn('households', 'accomplished_by'));

        $this->assertStringNotContainsString('"zone"', $sql);
        $this->assertStringNotContainsString('"street"', $sql);
        $this->assertStringNotContainsString('"address"', $sql);
        $this->assertStringNotContainsString('"accomplished_by"', $sql);
        $this->assertStringContainsString('"purok"', $sql);
    }

    public function test_create_ignores_legacy_fields_instead_of_writing_them(): void
    {
        $household = app(HouseholdService::class)->create($this->erdCreatePayload([
            'street' => 'Layuan St.',
            'address' => '123 Layuan St., Brgy. La Medalla',
            'accomplished_by' => 'Maria BHW',
        ]));

        $row = (array) DB::table('households')->where('household_id', $household->getKey())->first();

        $this->assertArrayNotHasKey('zone', $row);
        $this->assertArrayNotHasKey('street', $row);
        $this->assertArrayNotHasKey('address', $row);
        $this->assertArrayNotHasKey('accomplished_by', $row);
        $this->assertSame('1', (string) $row['purok']);
    }

    public function test_update_changes_purok_without_legacy_columns(): void
    {
        $household = app(HouseholdService::class)->create($this->erdCreatePayload());

        DB::flushQueryLog();
        DB::enableQueryLog();

        $updated = app(HouseholdService::class)->update($household, [
            'zone' => 'Zone 3',
            'date_registered' => '2026-02-01',
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NON_NHTS,
            'street' => 'Should Not Persist',
            'address' => 'Should Not Persist',
            'accomplished_by' => 'Should Not Persist',
        ]);

        $sql = strtolower(collect(DB::getQueryLog())->pluck('query')->implode(' | '));
        DB::disableQueryLog();

        $this->assertSame($household->household_no, $updated->household_no);
        $this->assertSame((int) $household->getKey(), (int) $updated->getKey());
        $this->assertSame('3', (string) $updated->purok);
        $this->assertSame('2026-02-01', $updated->date_registered?->format('Y-m-d'));
        $this->assertSame('Non-NHTS', (string) $updated->household_type);
        $this->assertSame('13.38110000', (string) $updated->latitude);
        $this->assertSame('123.43060000', (string) $updated->longitude);

        $this->assertStringNotContainsString('"zone"', $sql);
        $this->assertStringNotContainsString('"street"', $sql);
        $this->assertStringNotContainsString('"address"', $sql);
        $this->assertStringNotContainsString('"accomplished_by"', $sql);
        $this->assertStringContainsString('"purok"', $sql);
    }

    public function test_http_store_and_update_on_erd_schema(): void
    {
        $this->authenticateErdBhw();

        $store = $this->post(route('household-profiling.store'), [
            'household_no' => '121',
            'zone' => 'Zone 1',
            'date_registered' => '2026-01-15',
            'latitude' => '13.38110000',
            'longitude' => '123.43060000',
            'street' => 'Ignored Street',
            'accomplished_by' => 'Ignored Staff',
        ]);

        $household = Household::query()->firstOrFail();
        $store->assertRedirect();
        $this->assertStringContainsString(
            '/environmental-health/household-water-supply',
            (string) $store->headers->get('Location')
        );
        $this->assertStringContainsString('handoff=', (string) $store->headers->get('Location'));
        $this->assertSame('1', (string) $household->purok);

        $this->put(route('household-profiling.update', [
            'householdNo' => $household->household_no,
        ]), [
            'zone' => 'Zone 4',
            'date_registered' => '2026-01-15',
        ])->assertRedirect(route('household-profiling.view', [
            'householdNo' => $household->household_no,
        ]));

        $household->refresh();
        $this->assertSame('4', (string) $household->purok);
        $this->assertSame('13.38110000', (string) $household->latitude);
    }

    public function test_create_form_omits_unsupported_street_and_accomplished_by(): void
    {
        $this->authenticateErdBhw();

        $response = $this->get(route('household-profiling.create'));

        $response->assertOk();
        $response->assertDontSee('name="street"', false);
        $response->assertDontSee('name="accomplished_by"', false);
        $response->assertDontSee('name="address"', false);
        $response->assertSee('name="zone"', false);
        $response->assertSee('name="date_registered"', false);
        $response->assertSee('name="household_no"', false);
    }

    public function test_unsupported_schema_without_location_column_is_rejected(): void
    {
        Schema::dropIfExists('households');
        Schema::create('households', function ($table): void {
            $table->id();
            $table->string('household_no')->unique();
            $table->timestamps();
        });
        Household::resetResolvedKeyName();

        $this->assertTrue(HouseholdProfilingWriteGuard::isHouseholdShellWriteUnsupported());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(HouseholdProfilingWriteGuard::MESSAGE);

        app(HouseholdService::class)->create($this->erdCreatePayload());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function erdCreatePayload(array $overrides = []): array
    {
        return array_merge([
            'household_no' => '121',
            'zone' => 'Zone 1',
            'date_registered' => '2026-01-15',
            'latitude' => '13.38110000',
            'longitude' => '123.43060000',
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
        ], $overrides);
    }

    private function authenticateErdBhw(): User
    {
        $userId = (int) DB::table('user_management')->insertGetId([
            'first_name' => 'Erd',
            'last_name' => 'Writer',
            'email' => 'erd.writer@example.test',
            'username' => 'erd.writer',
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
