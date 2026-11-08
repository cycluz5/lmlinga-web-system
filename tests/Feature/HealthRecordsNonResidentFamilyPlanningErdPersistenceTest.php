<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DemoStaffLogin;
use App\Support\FamilyPlanningErdMode;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use App\Support\UserManagementErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AssertsAtRestStoredField;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

/**
 * ERD persistence for Health Records → Family Planning → Non-Residents.
 *
 * sqlite :memory: only. Does not write to lmlinga_erd_reference.
 */
class HealthRecordsNonResidentFamilyPlanningErdPersistenceTest extends TestCase
{
    use AssertsAtRestStoredField;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ClientTestingErdSchema::ensure();
        $this->provisionAuthoritativeFamilyPlanningTables();
        UserManagementErdMode::resetCachedState();
        FamilyPlanningErdMode::resetCachedState();
    }

    public function test_list_loads_for_authenticated_bhw(): void
    {
        $this->authenticateErdBhw();

        $this->get(route('health-records.family-planning.non-residents.index'))
            ->assertOk()
            ->assertSee('Family Planning | Non Residents', false)
            ->assertSee('data-lml-hr-fp-nr', false);
    }

    public function test_create_form_loads(): void
    {
        $this->authenticateErdBhw();

        $this->get(route('health-records.family-planning.non-residents.create'))
            ->assertOk()
            ->assertSee('Add New Non Resident', false)
            ->assertSee('name="first_name"', false)
            ->assertSee(route('health-records.family-planning.non-residents.store'), false)
            ->assertDontSee('Backend persistence is not yet implemented', false);
    }

    public function test_valid_create_persists_one_authoritative_row(): void
    {
        $this->authenticateErdBhw();

        $this->post(
            route('health-records.family-planning.non-residents.store'),
            $this->validCreatePayload()
        )->assertRedirect();

        $this->assertSame(1, DB::table('family_planning')->count());
        $this->assertSame(1, DB::table('residents')->where('relation_to_household_head', 'Non-Resident')->count());
        $this->assertDatabaseHas('family_planning', [
            'visitation_date' => '2026-03-15',
        ]);
        $this->assertPlaintextStoredField('family_planning', 'remarks', 'First counseling visit');
        $this->assertDatabaseHas('fp_commodities_given', [
            'commodity_name' => 'Pills',
            'quantity' => 30,
        ]);
    }

    public function test_invalid_create_returns_validation_error_and_persists_nothing(): void
    {
        $this->authenticateErdBhw();

        $this->from(route('health-records.family-planning.non-residents.create'))
            ->post(route('health-records.family-planning.non-residents.store'), [
                'first_name' => '',
                'last_name' => '',
            ])
            ->assertRedirect(route('health-records.family-planning.non-residents.create'))
            ->assertSessionHasErrors(['first_name', 'last_name', 'birthday', 'sex', 'civil_status', 'visited_at']);

        $this->assertSame(0, DB::table('family_planning')->count());
        $this->assertSame(0, DB::table('residents')->count());
    }

    public function test_edit_form_loads_the_stored_row(): void
    {
        $this->authenticateErdBhw();
        $this->post(route('health-records.family-planning.non-residents.store'), $this->validCreatePayload())
            ->assertRedirect();

        $residentId = (int) DB::table('residents')->value('resident_id');
        $fpId = (int) DB::table('family_planning')->value('fp_id');
        $clientKey = 'nr-'.$residentId;
        $visitId = 'FP-'.$fpId;

        $this->get(route('health-records.family-planning.non-residents.visits.edit', [
            'clientKey' => $clientKey,
            'visitId' => $visitId,
        ]))
            ->assertOk()
            ->assertSee('EDIT VISIT', false)
            ->assertSee('value="2026-03-15"', false)
            ->assertSee('First counseling visit', false)
            ->assertSee('data-visit-id="'.$visitId.'"', false);
    }

    public function test_update_changes_the_same_row(): void
    {
        $this->authenticateErdBhw();
        $this->post(route('health-records.family-planning.non-residents.store'), $this->validCreatePayload())
            ->assertRedirect();

        $residentId = (int) DB::table('residents')->value('resident_id');
        $fpId = (int) DB::table('family_planning')->value('fp_id');

        $this->put(route('health-records.family-planning.non-residents.visits.update', [
            'clientKey' => 'nr-'.$residentId,
            'visitId' => 'FP-'.$fpId,
        ]), [
            'visited_at' => '2026-04-01',
            'remarks' => 'Follow-up; method tolerated',
            'commodities' => [
                ['name' => 'IUD', 'quantity' => 1],
            ],
        ])->assertRedirect(route('health-records.family-planning.non-residents.show', [
            'clientKey' => 'nr-'.$residentId,
        ]));

        $this->assertSame(1, DB::table('family_planning')->count());
        $this->assertDatabaseHas('family_planning', [
            'fp_id' => $fpId,
            'resident_id' => $residentId,
            'visitation_date' => '2026-04-01',
        ]);
        $this->assertPlaintextStoredField('family_planning', 'remarks', 'Follow-up; method tolerated', [
            'fp_id' => $fpId,
        ]);
        $this->assertSame(1, DB::table('fp_commodities_given')->count());
        $this->assertDatabaseHas('fp_commodities_given', [
            'fp_id' => $fpId,
            'commodity_name' => 'IUD',
            'quantity' => 1,
        ]);
    }

    public function test_list_displays_persisted_result(): void
    {
        $this->authenticateErdBhw();
        $this->post(route('health-records.family-planning.non-residents.store'), $this->validCreatePayload())
            ->assertRedirect();

        $this->get(route('health-records.family-planning.non-residents.index'))
            ->assertOk()
            ->assertSee('Ana Cruz Santos', false)
            ->assertSee('Pills', false)
            ->assertSee('Showing 1 to 1 of 1 entries', false);
    }

    public function test_pdf_export_still_works(): void
    {
        $this->authenticateErdBhw();
        $this->post(route('health-records.family-planning.non-residents.store'), $this->validCreatePayload());

        $response = $this->get(route('health-records.family-planning.non-residents.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_flows_do_not_query_users_or_legacy_columns(): void
    {
        $this->authenticateErdBhw();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('health-records.family-planning.non-residents.index'))->assertOk();
        $this->get(route('health-records.family-planning.non-residents.create'))->assertOk();
        $this->post(route('health-records.family-planning.non-residents.store'), $this->validCreatePayload())
            ->assertRedirect();

        $residentId = (int) DB::table('residents')->value('resident_id');
        $fpId = (int) DB::table('family_planning')->value('fp_id');

        $this->get(route('health-records.family-planning.non-residents.show', [
            'clientKey' => 'nr-'.$residentId,
        ]))->assertOk();
        $this->get(route('health-records.family-planning.non-residents.visits.edit', [
            'clientKey' => 'nr-'.$residentId,
            'visitId' => 'FP-'.$fpId,
        ]))->assertOk();
        $this->get(route('health-records.family-planning.non-residents.export'))->assertOk();

        $sql = strtolower(collect(DB::getQueryLog())->pluck('query')->implode(' | '));
        DB::disableQueryLog();

        $this->assertStringNotContainsString('from "users"', $sql);
        $this->assertStringNotContainsString('from users', $sql);
        $this->assertStringNotContainsString('member_no', $sql);
        $this->assertStringNotContainsString('relationship_status', $sql);
        $this->assertStringNotContainsString('from "family_planning_visits"', $sql);
        $this->assertStringNotContainsString('accomplished_by', $sql);
    }

    public function test_authenticated_staff_reaches_the_flows(): void
    {
        $this->authenticateErdBhw();

        $this->get(route('health-records.family-planning.non-residents.index'))->assertOk();
        $this->get(route('health-records.family-planning.non-residents.create'))->assertOk();

        $response = $this->post(
            route('health-records.family-planning.non-residents.store'),
            $this->validCreatePayload()
        );
        $response->assertRedirect();

        $residentId = (int) DB::table('residents')->value('resident_id');
        $this->get(route('health-records.family-planning.non-residents.show', [
            'clientKey' => 'nr-'.$residentId,
        ]))->assertOk()->assertSee('ANA CRUZ SANTOS', false);
    }

    public function test_guest_cannot_create(): void
    {
        $this->post(route('health-records.family-planning.non-residents.store'), $this->validCreatePayload())
            ->assertRedirect();

        $this->assertSame(0, DB::table('family_planning')->count());
    }

    public function test_create_does_not_duplicate_on_edit(): void
    {
        $this->authenticateErdBhw();
        $this->post(route('health-records.family-planning.non-residents.store'), $this->validCreatePayload());

        $residentId = (int) DB::table('residents')->value('resident_id');
        $fpId = (int) DB::table('family_planning')->value('fp_id');

        $this->put(route('health-records.family-planning.non-residents.visits.update', [
            'clientKey' => 'nr-'.$residentId,
            'visitId' => 'FP-'.$fpId,
        ]), [
            'visited_at' => '2026-05-20',
            'remarks' => 'Updated once',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('residents')->count());
        $this->assertSame(1, DB::table('family_planning')->count());
        $this->assertSame($fpId, (int) DB::table('family_planning')->value('fp_id'));
    }

    public function test_delete_removes_authoritative_client_rows(): void
    {
        $this->authenticateErdBhw();
        $this->post(route('health-records.family-planning.non-residents.store'), $this->validCreatePayload());

        $residentId = (int) DB::table('residents')->value('resident_id');

        $this->delete(route('health-records.family-planning.non-residents.destroy', [
            'clientKey' => 'nr-'.$residentId,
        ]))->assertRedirect(route('health-records.family-planning.non-residents.index'));

        $this->assertSame(0, DB::table('family_planning')->count());
        $this->assertSame(0, DB::table('fp_commodities_given')->count());
        $this->assertSame(0, DB::table('residents')->where('relation_to_household_head', 'Non-Resident')->count());
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
            'resident_statuses',
            'death_requests',
            'child_birth_histories',
            'immunization_doses',
            'child_immunizations',
            'school_immunization_doses',
            'school_immunizations',
            'child_nutrition_sfp_outcomes',
            'child_nutritions',
            'risk_assessments',
            'maternal_pregnancies',
            'operation_timbang_measurements',
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
            'last_name' => 'FpNr',
            'email' => 'erd.fpnr@example.test',
            'username' => 'erd.fpnr',
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
