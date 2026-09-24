<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Services\HouseholdService;
use App\Services\ResidentService;
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
use Illuminate\Validation\ValidationException;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

/**
 * Phase 2B — ERD-safe Plot New Household (create household + head resident).
 */
class SpotMappingErdPlotNewHouseholdTest extends TestCase
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

    public function test_plot_new_household_form_has_editable_name_inputs_and_no_existing_select(): void
    {
        $this->authenticateErdBhw();

        $response = $this->get(route('spot-mapping.index'));

        $response->assertOk();
        $response->assertSee('name="first_name"', false);
        $response->assertSee('name="middle_name"', false);
        $response->assertSee('name="last_name"', false);
        $response->assertSee('name="birthday"', false);
        $response->assertSee('name="sex"', false);
        $response->assertSee('name="civil_status"', false);
        $response->assertSee('type="date"', false);
        $response->assertSee('data-spot-map-head-first', false);
        $response->assertSee('data-spot-map-head-middle', false);
        $response->assertSee('data-spot-map-head-last', false);
        $response->assertSee('data-spot-map-head-birthday', false);
        $response->assertSee('data-spot-map-head-sex', false);
        $response->assertSee('data-spot-map-head-civil-status', false);
        $response->assertSee('value="Male"', false);
        $response->assertSee('value="Female"', false);
        $response->assertSee('value="Single"', false);
        $response->assertSee('value="Married"', false);
        $response->assertSee('value="Widowed"', false);
        $response->assertSee('value="Separated"', false);
        $response->assertSee('value="Live-In"', false);
        $response->assertDontSee('value="Live-in"', false);
        $response->assertSee('data-plot-new-url', false);
        $response->assertSee(route('spot-mapping.plot-new'), false);
        $response->assertDontSee('Select a registered household', false);
        $response->assertSee('data-spot-map-household-no', false);
        $response->assertSee('name="household_no"', false);
        $response->assertSee('Household No.', false);
        $response->assertDontSee('Assigned on save', false);
    }

    public function test_automatic_coordinate_js_hooks_remain_present(): void
    {
        $js = (string) file_get_contents(base_path('resources/js/pages/spot-mapping.js'));

        $this->assertStringContainsString('navigator.geolocation', $js);
        $this->assertStringContainsString('getCurrentPosition', $js);
        $this->assertStringContainsString('isClientOffline', $js);
        $this->assertStringContainsString('gpsPlotPlan', $js);
        $this->assertStringContainsString("You're offline. Click on the map to plot the household location.", $js);
        $this->assertStringContainsString('placeTemporaryMarker', $js);
        $this->assertStringContainsString('data-plot-new-url', $js);
        $this->assertStringContainsString('first_name', $js);
        $this->assertStringContainsString('middle_name', $js);
        $this->assertStringContainsString('last_name', $js);
        $this->assertStringContainsString('birthday', $js);
        $this->assertStringContainsString('civil_status', $js);
        $this->assertStringContainsString("'Live-In'", $js);
        $this->assertStringContainsString('household_no', $js);
        $this->assertStringNotContainsString('Assigned on save', $js);
    }

    public function test_unauthenticated_plot_new_is_rejected(): void
    {
        $response = $this->postJson(route('spot-mapping.plot-new'), $this->validPlotNewPayload());

        $this->assertContains($response->status(), [401, 302]);
        $this->assertSame(0, Household::query()->count());
        $this->assertSame(0, Resident::query()->count());
    }

    public function test_plot_new_creates_household_and_head_on_purok_schema(): void
    {
        $this->authenticateErdBhw();
        $this->assertFalse(HouseholdProfilingWriteGuard::isResidentWriteUnsupported());

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->postJson(route('spot-mapping.plot-new'), $this->validPlotNewPayload());

        $sql = strtolower(collect(DB::getQueryLog())->pluck('query')->implode(' | '));
        DB::disableQueryLog();

        $response->assertOk();
        $response->assertJsonStructure(['handoff_token', 'redirect_url', 'household_no', 'marker']);
        $this->assertStringContainsString(
            'environmental-health/household-water-supply',
            (string) $response->json('redirect_url')
        );

        $household = Household::query()->firstOrFail();
        $this->assertSame('121', (string) $household->household_no);
        $this->assertSame('1', (string) $household->purok);
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);
        $this->assertSame('NHTS', (string) $household->household_type);
        $this->assertSame(now()->toDateString(), $household->date_registered?->format('Y-m-d'));

        $resident = Resident::query()->firstOrFail();
        $this->assertSame((int) $household->getKey(), (int) $resident->household_id);
        $this->assertSame('Ana', (string) $resident->first_name);
        $this->assertSame('Cruz', (string) $resident->middle_name);
        $this->assertSame('Santos', (string) $resident->last_name);
        $this->assertSame('1988-03-12', $resident->birthday?->format('Y-m-d') ?? (string) $resident->birthday);
        $this->assertSame('Female', (string) $resident->sex);
        $this->assertSame('Live-In', (string) $resident->civil_status);
        $this->assertSame('Head', (string) $resident->relation);
        $attrs = $resident->getAttributes();
        $this->assertSame('Head', (string) ($attrs['relation_to_household_head'] ?? ''));
        $this->assertArrayNotHasKey('relation', $attrs);
        $this->assertArrayNotHasKey('member_no', $attrs);

        $this->assertFalse(Schema::hasColumn('households', 'zone'));
        $this->assertFalse(Schema::hasColumn('households', 'street'));
        $this->assertFalse(Schema::hasColumn('residents', 'member_no'));
        $this->assertFalse(Schema::hasColumn('residents', 'relation'));

        $this->assertStringNotContainsString('"zone"', $sql);
        $this->assertStringNotContainsString('"street"', $sql);
        $this->assertStringNotContainsString('"address"', $sql);
        $this->assertStringNotContainsString('"accomplished_by"', $sql);
        $this->assertStringNotContainsString('"member_no"', $sql);
        $this->assertStringNotContainsString('"relation"', $sql);
        $this->assertStringContainsString('"purok"', $sql);
        $this->assertStringContainsString('"relation_to_household_head"', $sql);
        $this->assertStringContainsString('"first_name"', $sql);
        $this->assertStringContainsString('"middle_name"', $sql);
        $this->assertStringContainsString('"last_name"', $sql);
        $this->assertStringContainsString('"birthday"', $sql);
        $this->assertStringContainsString('"sex"', $sql);
        $this->assertStringContainsString('"civil_status"', $sql);
    }

    public function test_plot_new_persists_nhts_household_type_exactly(): void
    {
        $this->authenticateErdBhw();

        $this->postJson(route('spot-mapping.plot-new'), $this->validPlotNewPayload([
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
        ]))->assertOk();

        $household = Household::query()->firstOrFail();
        $this->assertSame(DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS, (string) $household->household_type);
        $this->assertNotSame('HHTS', (string) $household->household_type);
    }

    public function test_legacy_hhts_plot_new_canonicalizes_to_nhts(): void
    {
        $this->authenticateErdBhw();

        $this->postJson(route('spot-mapping.plot-new'), $this->validPlotNewPayload([
            'household_no' => '122',
            'household_type' => 'HHTS',
        ]))->assertOk();

        $household = Household::query()->where('household_no', '122')->firstOrFail();
        $this->assertSame(DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS, (string) $household->household_type);
        $this->assertNotSame('HHTS', (string) $household->household_type);
    }

    public function test_legacy_non_hhts_plot_new_canonicalizes_to_non_nhts(): void
    {
        $this->authenticateErdBhw();

        $this->postJson(route('spot-mapping.plot-new'), $this->validPlotNewPayload([
            'household_no' => '123',
            'household_type' => 'Non-HHTS',
        ]))->assertOk();

        $household = Household::query()->where('household_no', '123')->firstOrFail();
        $this->assertSame(DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NON_NHTS, (string) $household->household_type);
        $this->assertNotSame('Non-HHTS', (string) $household->household_type);
    }

    public function test_plot_new_returns_persisted_three_digit_household_no_and_view_route_works(): void
    {
        $this->authenticateErdBhw();

        $response = $this->postJson(route('spot-mapping.plot-new'), $this->validPlotNewPayload([
            'household_no' => '121',
        ]));

        $response->assertOk();
        $response->assertJsonPath('household_no', '121');
        $response->assertJsonPath('marker.householdNo', '121');
        $response->assertJsonPath('marker.headFirstName', 'Ana');
        $response->assertJsonPath('marker.headMiddleName', 'Cruz');
        $response->assertJsonPath('marker.headLastName', 'Santos');
        $response->assertJsonPath('marker.householdType', 'NHTS');
        $response->assertJsonPath('marker.zoneLabel', 'Zone 1');
        $response->assertJsonPath('marker.members', 1);

        $household = Household::query()->firstOrFail();
        $this->assertSame('121', (string) $household->household_no);
        $this->assertSame((int) $household->getKey(), (int) $household->residents()->first()?->household_id);

        $index = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $this->assertStringContainsString('"householdNo":"121"', $index);
        $this->assertStringContainsString('"headFirstName":"Ana"', $index);
        $this->assertStringContainsString('"headLastName":"Santos"', $index);
        $this->assertStringContainsString('"householdType":"NHTS"', $index);
        $this->assertStringContainsString('"zoneLabel":"Zone 1"', $index);

        $this->get(route('household-profiling.view', ['householdNo' => '121']))
            ->assertOk()
            ->assertSee('Ana Cruz Santos', false);
    }

    public function test_plot_new_rejects_semantic_duplicate_of_hh_prefixed_number(): void
    {
        $this->authenticateErdBhw();

        DB::table('households')->insert([
            'household_no' => 'HH-001',
            'purok' => '1',
            'latitude' => '13.38110000',
            'longitude' => '123.43060000',
            'household_type' => 'NHTS',
            'date_registered' => '2026-01-10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson(route('spot-mapping.plot-new'), $this->validPlotNewPayload([
            'household_no' => '001',
        ]))->assertStatus(422);

        $this->assertSame(1, Household::query()->count());
        $this->assertSame('HH-001', (string) Household::query()->firstOrFail()->household_no);
    }

    public function test_plot_new_rejects_invalid_household_numbers(): void
    {
        $this->authenticateErdBhw();

        foreach (['12', '1210', '12A', 'HH-121', 'ABC'] as $invalid) {
            $this->postJson(route('spot-mapping.plot-new'), $this->validPlotNewPayload([
                'household_no' => $invalid,
            ]))->assertStatus(422);
            $this->assertSame(0, Household::query()->count());
        }

        $payload = $this->validPlotNewPayload();
        unset($payload['household_no']);
        $this->postJson(route('spot-mapping.plot-new'), $payload)->assertStatus(422);
        $this->assertSame(0, Household::query()->count());
    }

    public function test_existing_plotted_households_still_display(): void
    {
        $this->authenticateErdBhw();

        $existingId = (int) DB::table('households')->insertGetId([
            'household_no' => 'HH-090',
            'purok' => '2',
            'latitude' => '13.38110000',
            'longitude' => '123.43060000',
            'household_type' => 'Non-NHTS',
            'date_registered' => '2026-01-10',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'household_id');

        DB::table('residents')->insert([
            'household_id' => $existingId,
            'first_name' => 'Existing',
            'middle_name' => null,
            'last_name' => 'Marker',
            'relation_to_household_head' => 'Head',
            'birthday' => '1975-06-01',
            'sex' => 'Female',
            'civil_status' => 'Married',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $index = $this->get(route('spot-mapping.index'));
        $index->assertOk();
        $index->assertSee('HH-090', false);
        $index->assertSee('Existing Marker', false);

        $this->postJson(route('spot-mapping.plot-new'), $this->validPlotNewPayload())->assertOk();

        $this->assertSame(2, Household::query()->count());
        $this->assertTrue(Household::query()->where('household_no', 'HH-090')->exists());
    }

    public function test_household_profiling_reads_newly_created_household_and_head(): void
    {
        $this->authenticateErdBhw();

        $this->postJson(route('spot-mapping.plot-new'), $this->validPlotNewPayload())->assertOk();

        $household = Household::query()->firstOrFail();
        $presentation = HouseholdProfilingPresenter::fromModel($household->load('residents'));

        $this->assertSame($household->household_no, $presentation['householdNo']);
        $this->assertSame('Zone 1', $presentation['zone']);
        $this->assertSame('Ana Cruz Santos', $presentation['houseHead']);
        $this->assertCount(1, $presentation['memberList']);
        $this->assertSame('Head', $presentation['memberList'][0]['relation']);
        $this->assertSame('Ana Cruz Santos', $presentation['memberList'][0]['name']);

        $this->get(route('household-profiling.index'))
            ->assertOk()
            ->assertSee($household->household_no, false)
            ->assertSee('Ana Cruz Santos', false);

        $this->get(route('household-profiling.view', [
            'householdNo' => $household->household_no,
        ]))
            ->assertOk()
            ->assertSee('Ana Cruz Santos', false)
            ->assertSee('Zone 1', false);
    }

    public function test_resident_creation_failure_rolls_back_household(): void
    {
        $beforeHouseholds = Household::query()->count();
        $beforeResidents = Resident::query()->count();

        Resident::creating(function (): void {
            throw new \RuntimeException('simulated resident failure');
        });

        try {
            app(HouseholdService::class)->createWithHead(
                [
                    'household_no' => '121',
                    'zone' => 'Zone 1',
                    'date_registered' => '2026-01-15',
                    'latitude' => '13.38110000',
                    'longitude' => '123.43060000',
                    'household_type' => 'HHTS',
                ],
                [
                    'first_name' => 'Ana',
                    'middle_name' => 'Cruz',
                    'last_name' => 'Santos',
                    'birthday' => '1988-03-12',
                    'sex' => 'Female',
                    'civil_status' => 'Live-In',
                    'relation' => 'Head',
                ],
                app(ResidentService::class),
            );
            $this->fail('Expected resident creation to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated resident failure', $e->getMessage());
        }

        $this->assertSame($beforeHouseholds, Household::query()->count());
        $this->assertSame($beforeResidents, Resident::query()->count());
    }

    public function test_unsupported_resident_schema_is_still_rejected(): void
    {
        Schema::dropIfExists('residents');
        Schema::create('residents', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->timestamps();
        });
        Resident::resetResolvedKeyName();

        $this->assertTrue(HouseholdProfilingWriteGuard::isResidentWriteUnsupported());

        $household = app(HouseholdService::class)->create([
            'household_no' => '121',
            'zone' => 'Zone 1',
            'date_registered' => '2026-01-15',
            'latitude' => '13.38110000',
            'longitude' => '123.43060000',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(HouseholdProfilingWriteGuard::MESSAGE);

        app(ResidentService::class)->create($household, [
            'first_name' => 'Ana',
            'last_name' => 'Santos',
            'relation' => 'Head',
        ]);
    }

    public function test_missing_birthday_is_rejected_and_creates_no_rows(): void
    {
        $this->assertPlotNewValidationCreatesNoRows(['birthday' => null]);
    }

    public function test_missing_sex_is_rejected_and_creates_no_rows(): void
    {
        $this->assertPlotNewValidationCreatesNoRows(['sex' => null]);
    }

    public function test_missing_civil_status_is_rejected_and_creates_no_rows(): void
    {
        $this->assertPlotNewValidationCreatesNoRows(['civil_status' => null]);
    }

    public function test_future_birthday_is_rejected_and_creates_no_rows(): void
    {
        $this->assertPlotNewValidationCreatesNoRows([
            'birthday' => now()->addDay()->toDateString(),
        ]);
    }

    public function test_invalid_sex_is_rejected_and_creates_no_rows(): void
    {
        $this->assertPlotNewValidationCreatesNoRows(['sex' => 'Other']);
    }

    public function test_invalid_civil_status_is_rejected_and_creates_no_rows(): void
    {
        $this->assertPlotNewValidationCreatesNoRows(['civil_status' => 'Partnered']);
    }

    public function test_live_in_with_lowercase_i_is_rejected_and_creates_no_rows(): void
    {
        $this->assertPlotNewValidationCreatesNoRows(['civil_status' => 'Live-in']);
    }

    public function test_arbitrary_household_type_is_rejected_and_creates_no_rows(): void
    {
        $this->assertPlotNewValidationCreatesNoRows(['household_type' => 'Poor']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assertPlotNewValidationCreatesNoRows(array $overrides): void
    {
        $this->authenticateErdBhw();

        $beforeHouseholds = Household::query()->count();
        $beforeResidents = Resident::query()->count();

        $payload = $this->validPlotNewPayload($overrides);
        foreach (array_keys($overrides) as $key) {
            if ($overrides[$key] === null) {
                unset($payload[$key]);
            }
        }

        $response = $this->postJson(route('spot-mapping.plot-new'), $payload);

        $response->assertStatus(422);
        $this->assertSame($beforeHouseholds, Household::query()->count());
        $this->assertSame($beforeResidents, Resident::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPlotNewPayload(array $overrides = []): array
    {
        return array_merge([
            'household_no' => '121',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'birthday' => '1988-03-12',
            'sex' => 'Female',
            'civil_status' => 'Live-In',
            'zone' => '1',
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
            'date_registered' => now()->toDateString(),
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ], $overrides);
    }

    private function authenticateErdBhw(): User
    {
        $userId = (int) DB::table('user_management')->insertGetId([
            'first_name' => 'Erd',
            'last_name' => 'Plotter',
            'email' => 'erd.plotter@example.test',
            'username' => 'erd.plotter',
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
