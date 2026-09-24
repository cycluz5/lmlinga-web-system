<?php

namespace Tests\Feature;

use App\Support\AtRestColumns;
use App\Support\AtRestNarrativeField;
use App\Support\AtRestRecord;
use App\Support\StaffRole;

use App\Models\DewormingRecord;
use App\Models\FamilyPlanningVisit;
use App\Models\Household;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Support\FamilyPlanningErdMode;
use App\Support\HouseholdProfilingWriteGuard;
use App\Services\Offline\OfflineHealthServiceWriter;
use App\Support\RiskAssessmentClinicalValues;
use App\Support\RiskAssessmentErdMode;
use App\Support\RiskAssessmentService;
use App\Support\UserManagementErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\ErdRiskAssessmentChildSchema;
use Tests\TestCase;

class HouseholdProfilingRiskAssessmentErdPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function provisionErdRiskAssessmentTable(): void
    {
        ErdRiskAssessmentChildSchema::drop();
        Schema::dropIfExists('risk_assessments');
        Schema::dropIfExists('risk_assessment');
        Schema::dropIfExists('users');
        Schema::dropIfExists('user_management');

        Schema::create('user_management', function ($table): void {
            $table->id('user_id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('username')->unique();
            $table->string('password');
            $table->string('status')->nullable();
            $table->timestamps();
        });
        UserManagementErdMode::resetCachedState();

        Schema::create('risk_assessment', function ($table): void {
            $table->id('risk_assessment_id');
            $table->unsignedBigInteger('resident_id');
            $table->unsignedBigInteger('user_id');
            $table->string('tobacco_vape_usage')->nullable();
            $table->string('alcohol_intake')->nullable();
            $table->string('dietary_habits')->nullable();
            $table->string('physical_activity')->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->decimal('waist_circum_cm', 8, 2)->nullable();
            $table->unsignedSmallInteger('systolic_blood_pressure')->nullable();
            $table->unsignedSmallInteger('diastolic_blood_pressure')->nullable();
            $table->string('blood_pressure_status')->nullable();
            $table->timestamps();
        });
        ErdRiskAssessmentChildSchema::create();
        RiskAssessmentErdMode::resetCachedState();
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedPersistedMember(array $overrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => $overrides['household_no'] ?? 'HH-930',
            'zone' => 'Zone 1',
            'street' => 'RA ERD St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-930',
            'first_name' => $overrides['first_name'] ?? 'Rosa',
            'last_name' => $overrides['last_name'] ?? 'Mendoza',
            'birthday' => $overrides['birthday'] ?? '1990-05-01',
            'sex' => $overrides['sex'] ?? 'Female',
        ]);

        return compact('household', 'resident');
    }

    private function authenticateStaffUser(): User
    {
        $userId = (int) DB::table('user_management')->insertGetId([
            'name' => 'Test Staff',
            'email' => fake()->unique()->safeEmail(),
            'username' => fake()->unique()->userName(),
            'password' => Hash::make('password'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var User $user */
        $user = User::query()->findOrFail($userId);
        $this->actingAs($user);

        return $user;
    }

    private function insertStaffUserId(): int
    {
        return (int) DB::table('user_management')->insertGetId([
            'name' => 'Seed Staff',
            'email' => fake()->unique()->safeEmail(),
            'username' => fake()->unique()->userName(),
            'password' => Hash::make('password'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'tobacco' => 'never',
            'alcohol' => 'light',
            'dietary' => 'yes',
            'physical_activity' => 'yes',
            'height_cm' => '170',
            'weight_kg' => '70',
            'waist_cm' => '85',
            'systolic' => '120',
            'diastolic' => '80',
        ], $overrides);
    }

    private function storeRoute(Household $household, Resident $resident): string
    {
        return route('household-profiling.members.risk-assessment.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);
    }

    private function sectionUpdateRoute(
        Household $household,
        Resident $resident,
        string $assessmentId,
        string $section
    ): string {
        return route('household-profiling.members.risk-assessment.section.update', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'assessmentId' => $assessmentId,
            'section' => $section,
        ]);
    }

    public function test_erd_mode_detection_is_active_when_authoritative_table_present(): void
    {
        $this->provisionErdRiskAssessmentTable();

        $this->assertTrue(RiskAssessmentErdMode::isActive());
        $this->assertFalse(HouseholdProfilingWriteGuard::isRiskAssessmentWriteUnsupported());
    }

    public function test_erd_create_inserts_one_new_row(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->actingAsStaff(StaffRole::BHW);
        $this->post($this->storeRoute($household, $resident), $this->validPayload())
            ->assertRedirect();

        $this->assertSame(1, DB::table('risk_assessment')->count());
    }

    public function test_erd_create_persists_resident_id(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();

        $this->assertAtRestDatabaseHas('risk_assessment', [
            'resident_id' => $resident->id,
        ]);
    }

    public function test_erd_create_persists_authenticated_user_id(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $user = $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();

        $this->assertAtRestDatabaseHas('risk_assessment', [
            'resident_id' => $resident->id,
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_missing_authenticated_staff_fails_safely_on_create(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->from(route('household-profiling.members.risk-assessment.create', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]))
            ->post($this->storeRoute($household, $resident), $this->validPayload())
            ->assertRedirect();

        $this->assertSame(0, DB::table('risk_assessment')->count());
    }

    public function test_tobacco_key_maps_to_erd_enum_on_create(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'tobacco' => 'current',
        ]))->assertRedirect();

        $this->assertAtRestDatabaseHas('risk_assessment', [
            'tobacco_vape_usage' => 'Current User',
        ]);
    }

    public function test_alcohol_key_maps_to_erd_enum_on_create(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'alcohol' => 'excessive',
        ]))->assertRedirect();

        $this->assertAtRestDatabaseHas('risk_assessment', [
            'alcohol_intake' => 'Excessive',
        ]);
    }

    public function test_physical_activity_key_maps_to_erd_enum_on_create(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'physical_activity' => 'no',
        ]))->assertRedirect();

        $this->assertAtRestDatabaseHas('risk_assessment', [
            'physical_activity' => 'No',
        ]);
    }

    public function test_dietary_single_value_maps_to_erd_enum_on_create(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'dietary' => 'no',
        ]))->assertRedirect();

        $this->assertAtRestDatabaseHas('risk_assessment', [
            'dietary_habits' => 'No',
        ]);
    }

    public function test_physical_columns_rename_on_create(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'waist_cm' => '88.5',
            'systolic' => '118',
            'diastolic' => '76',
        ]))->assertRedirect();

        $this->assertAtRestDatabaseHas('risk_assessment', [
            'waist_circum_cm' => '88.50',
            'systolic_blood_pressure' => 118,
            'diastolic_blood_pressure' => 76,
        ]);
    }

    public function test_blood_pressure_status_is_computed_not_client_supplied_on_create(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        app(RiskAssessmentService::class)->createForResident($resident, $this->validPayload([
            'bp_status' => 'Hypertensive',
            'systolic' => '142',
            'diastolic' => '85',
        ]));

        $row = AtRestRecord::openRow('risk_assessment', DB::table('risk_assessment')->first());
        $this->assertNotNull($row);
        $this->assertSame('Hypertension Stage 2', $row->blood_pressure_status);
    }

    public function test_erd_create_stores_lifestyle_physical_and_history_as_ciphertext(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        app(RiskAssessmentService::class)->createForResident($resident, $this->validPayload([
            'red_flags' => ['chest_pain'],
            'past_medical' => ['hypertension'],
            'family_history' => ['stroke'],
        ]));

        $raw = DB::table('risk_assessment')->first();
        foreach (AtRestColumns::columns('risk_assessment') as $column) {
            $this->assertTrue(AtRestNarrativeField::isSealed($raw->{$column}), "risk_assessment.{$column} should be sealed");
        }
        $this->assertSame((int) $resident->getKey(), (int) $raw->resident_id);

        foreach (['red_flags_assessment', 'past_medical_history', 'family_history'] as $table) {
            $child = DB::table($table)->first();
            foreach (AtRestColumns::columns($table) as $column) {
                $this->assertTrue(AtRestNarrativeField::isSealed($child->{$column}), "{$table}.{$column} should be sealed");
            }
        }

        $presentation = app(RiskAssessmentService::class)->findPresentationForResident($resident, 'RA-001');
        $this->assertSame('never', $presentation['tobacco']);
        $this->assertSame('light', $presentation['alcohol']);
        $this->assertSame('170.00', $presentation['height_cm']);
        $this->assertSame('120', $presentation['systolic']);
        $this->assertSame('Hypertension Stage 1', $presentation['bp_status']);
        $this->assertSame(['chest_pain'], $presentation['red_flags']);
        $this->assertSame(['hypertension'], $presentation['past_medical']);
        $this->assertSame(['stroke'], $presentation['family_history']);
    }

    public function test_erd_rejects_lifestyle_label_outside_the_fixed_list(): void
    {
        $this->assertTrue(RiskAssessmentErdMode::isValidEnumValue('tobacco_vape_usage', 'Current User'));
        $this->assertFalse(RiskAssessmentErdMode::isValidEnumValue('tobacco_vape_usage', 'Sometimes'));
        $this->assertFalse(RiskAssessmentErdMode::isValidEnumValue('dietary_habits', 'Balanced Diet'));
    }

    public function test_ra_presentation_id_contract(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        app(RiskAssessmentService::class)->createForResident($resident, $this->validPayload());

        $id = (int) DB::table('risk_assessment')->value('risk_assessment_id');
        $presentation = app(RiskAssessmentService::class)
            ->findPresentationForResident($resident, sprintf('RA-%03d', $id));

        $this->assertNotNull($presentation);
        $this->assertSame(sprintf('RA-%03d', $id), $presentation['id']);
    }

    public function test_second_create_inserts_second_row_without_upsert(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();
        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'tobacco' => 'stopped_lt_1y',
        ]))->assertRedirect();

        $this->assertSame(2, DB::table('risk_assessment')->where('resident_id', $resident->id)->count());
    }

    public function test_lifestyle_update_scoped_by_assessment_pk_and_resident(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();

        $this->actingAsStaff(StaffRole::BHW);
        $this->put($this->sectionUpdateRoute($household, $resident, 'RA-001', 'lifestyle'), [
                'tobacco' => 'stopped_lt_1y',
                'alcohol' => 'never',
                'dietary' => 'no',
                'physical_activity' => 'no',
            ])
            ->assertRedirect();

        $this->assertAtRestDatabaseHas('risk_assessment', [
            'risk_assessment_id' => 1,
            'resident_id' => $resident->id,
            'tobacco_vape_usage' => 'Stopped < 1 year',
            'alcohol_intake' => 'Never',
            'dietary_habits' => 'No',
            'physical_activity' => 'No',
        ]);
    }

    public function test_physical_update_scoped_by_assessment_pk_and_resident(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();

        $this->put($this->sectionUpdateRoute($household, $resident, 'RA-001', 'physical'), [
            'height_cm' => '165',
            'weight_kg' => '62',
            'waist_cm' => '80',
            'systolic' => '110',
            'diastolic' => '70',
        ])->assertRedirect();

        $this->assertAtRestDatabaseHas('risk_assessment', [
            'risk_assessment_id' => 1,
            'resident_id' => $resident->id,
            'height_cm' => '165.00',
            'weight_kg' => '62.00',
            'waist_circum_cm' => '80.00',
            'systolic_blood_pressure' => 110,
            'diastolic_blood_pressure' => 70,
        ]);
    }

    public function test_cross_resident_update_is_rejected(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $householdA, 'resident' => $residentA] = $this->seedPersistedMember([
            'household_no' => 'HH-931',
            'member_no' => 'MB-931',
        ]);
        ['household' => $householdB, 'resident' => $residentB] = $this->seedPersistedMember([
            'household_no' => 'HH-932',
            'member_no' => 'MB-932',
        ]);
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($householdA, $residentA), $this->validPayload())->assertRedirect();

        $this->put($this->sectionUpdateRoute($householdB, $residentB, 'RA-001', 'lifestyle'), [
            'tobacco' => 'current',
            'alcohol' => 'never',
            'dietary' => 'yes',
            'physical_activity' => 'yes',
        ])->assertForbidden();
    }

    public function test_unsupported_fields_rejected_in_erd_mode_on_create(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->from(route('household-profiling.members.risk-assessment.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))
            ->post($this->storeRoute($household, $resident), array_merge($this->validPayload(), [
                'red_flags' => ['severe_injuries'],
                'past_medical' => ['kidney'],
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['red_flags.0', 'past_medical.0']);

        $this->assertSame(0, DB::table('risk_assessment')->count());
    }

    public function test_unsupported_section_put_is_rejected_in_erd_mode(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();

        $this->actingAsStaff(StaffRole::BHW);
        $this->from(route('household-profiling.members.risk-assessment.section.edit', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
                'assessmentId' => 'RA-001',
                'section' => 'past-medical',
            ]))
            ->put($this->sectionUpdateRoute($household, $resident, 'RA-001', 'past-medical'), [
                'past_medical' => ['kidney'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('past_medical.0');

        $this->assertSame(0, DB::table('past_medical_history')->count());
    }

    public function test_erd_create_form_hides_unsupported_sections_and_uses_single_select_dietary(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('household-profiling.members.risk-assessment.create', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-wizard-total="5"', $html);
        $this->assertStringContainsString('Red Flag Assessment', $html);
        $this->assertStringContainsString('Past Medical History', $html);
        $this->assertStringContainsString('Family History', $html);
        $this->assertStringContainsString('Lifestyle &amp; Risk Factor', $html);
        $this->assertStringContainsString('Physical Measurements', $html);
        $this->assertStringContainsString('name="dietary"', $html);
        $this->assertStringNotContainsString('name="dietary[]"', $html);
        $this->assertStringNotContainsString('value="severe_injuries"', $html);
        $this->assertStringNotContainsString('value="kidney"', $html);
        $this->assertDoesNotMatchRegularExpression('/\bname="bmi"/', $html);
        $this->assertDoesNotMatchRegularExpression('/\bname="bp_status"/', $html);
        $this->assertStringNotContainsString('name="visual_no_screening"', $html);
    }

    public function test_erd_create_form_does_not_submit_bmi_or_bp_status_names(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('household-profiling.members.risk-assessment.create', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="lml-risk-bmi"', $html);
        $this->assertStringContainsString('data-risk-assess-bmi', $html);
        $this->assertDoesNotMatchRegularExpression('/\bname="bmi"/', $html);
        $this->assertStringContainsString('id="lml-risk-bp-status"', $html);
        $this->assertStringContainsString('data-risk-assess-bp-status', $html);
        $this->assertDoesNotMatchRegularExpression('/\bname="bp_status"/', $html);
        $this->assertStringContainsString('name="height_cm"', $html);
        $this->assertStringContainsString('name="weight_kg"', $html);
        $this->assertStringContainsString('name="systolic"', $html);
        $this->assertStringContainsString('name="diastolic"', $html);
    }

    public function test_erd_physical_edit_form_does_not_submit_bmi_or_bp_status_names(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('household-profiling.members.risk-assessment.section.edit', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
                'assessmentId' => 'RA-001',
                'section' => 'physical',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="lml-risk-hist-bmi"', $html);
        $this->assertStringContainsString('data-risk-assess-bmi', $html);
        $this->assertDoesNotMatchRegularExpression('/\bname="bmi"/', $html);
        $this->assertStringContainsString('id="lml-risk-hist-bp-status"', $html);
        $this->assertStringContainsString('data-risk-assess-bp-status', $html);
        $this->assertDoesNotMatchRegularExpression('/\bname="bp_status"/', $html);
        $this->assertStringContainsString('name="height_cm"', $html);
        $this->assertStringContainsString('name="weight_kg"', $html);
        $this->assertStringContainsString('name="systolic"', $html);
        $this->assertStringContainsString('name="diastolic"', $html);
    }

    public function test_erd_create_without_bmi_or_bp_status_succeeds_and_derives_display_bmi(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->from(route('household-profiling.members.risk-assessment.create', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]))
            ->post($this->storeRoute($household, $resident), $this->validPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $row = AtRestRecord::openRow('risk_assessment', DB::table('risk_assessment')->first());
        $this->assertNotNull($row);
        $this->assertFalse(Schema::hasColumn('risk_assessment', 'bmi'));
        $this->assertEqualsWithDelta(170.0, (float) $row->height_cm, 0.001);
        $this->assertEqualsWithDelta(70.0, (float) $row->weight_kg, 0.001);

        $expected = RiskAssessmentClinicalValues::calculateBmi($row->height_cm, $row->weight_kg);
        $presentation = app(RiskAssessmentService::class)
            ->findPresentationForResident($resident, 'RA-001');
        $this->assertSame($expected, $presentation['bmi']);
        $this->assertSame($expected, $presentation['bmi_label']);
    }

    public function test_erd_create_rejects_submitted_bmi(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->from(route('household-profiling.members.risk-assessment.create', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]))
            ->post($this->storeRoute($household, $resident), $this->validPayload([
                'bmi' => '99.9',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('bmi');

        $this->assertSame(0, DB::table('risk_assessment')->count());
    }

    public function test_erd_create_rejects_submitted_bp_status(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->from(route('household-profiling.members.risk-assessment.create', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]))
            ->post($this->storeRoute($household, $resident), $this->validPayload([
                'bp_status' => 'NORMAL',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('bp_status');

        $this->assertSame(0, DB::table('risk_assessment')->count());
    }

    public function test_erd_physical_edit_rejects_submitted_bmi_and_bp_status(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();

        $this->from(route('household-profiling.members.risk-assessment.section.edit', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
                'assessmentId' => 'RA-001',
                'section' => 'physical',
            ]))
            ->put($this->sectionUpdateRoute($household, $resident, 'RA-001', 'physical'), [
                'height_cm' => '165',
                'weight_kg' => '62',
                'waist_cm' => '80',
                'systolic' => '110',
                'diastolic' => '70',
                'bmi' => '22.8',
                'bp_status' => 'NORMAL',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['bmi', 'bp_status']);

        $this->assertAtRestDatabaseHas('risk_assessment', [
            'risk_assessment_id' => 1,
            'height_cm' => '170.00',
            'weight_kg' => '70.00',
        ]);
    }

    public function test_erd_edit_prefill_converts_enum_labels_to_ui_keys(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        DB::table('risk_assessment')->insert([
            'resident_id' => $resident->id,
            'user_id' => $this->insertStaffUserId(),
            'tobacco_vape_usage' => 'Never',
            'alcohol_intake' => 'Light (Occasional)',
            'dietary_habits' => 'Yes',
            'physical_activity' => 'Yes',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $presentation = app(RiskAssessmentService::class)
            ->findPresentationForResident($resident, 'RA-001');

        $this->assertSame('never', $presentation['tobacco']);
        $this->assertSame('light', $presentation['alcohol']);
        $this->assertSame('yes', $presentation['dietary']);
        $this->assertSame('yes', $presentation['physical_activity']);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('household-profiling.members.risk-assessment.section.edit', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
                'assessmentId' => 'RA-001',
                'section' => 'lifestyle',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/name="tobacco"[^>]*value="never"[^>]*checked/s', $html);
    }

    public function test_erd_history_detail_shows_truthful_supported_sections_only(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        DB::table('risk_assessment')->insert([
            'resident_id' => $resident->id,
            'user_id' => $this->insertStaffUserId(),
            'height_cm' => 170,
            'weight_kg' => 70,
            'systolic_blood_pressure' => 120,
            'diastolic_blood_pressure' => 80,
            'created_at' => '2026-02-01 10:00:00',
            'updated_at' => now(),
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $show = $this->get(route('household-profiling.members.risk-assessment.show', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
                'assessmentId' => 'RA-001',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Lifestyle', $show);
        $this->assertStringContainsString('Physical', $show);
        $this->assertStringContainsString('Red Flag', $show);
        $this->assertStringContainsString('Past Medical', $show);
        $this->assertStringContainsString('Family History', $show);
        $this->assertStringContainsString('Recorded Date', $show);
    }

    public function test_explicit_bidirectional_lifestyle_mappings(): void
    {
        $this->assertSame('Never', RiskAssessmentErdMode::tobaccoKeyToLabel('never'));
        $this->assertSame('never', RiskAssessmentErdMode::tobaccoLabelToKey('Never'));
        $this->assertSame('Current User', RiskAssessmentErdMode::tobaccoKeyToLabel('current'));
        $this->assertSame('current', RiskAssessmentErdMode::tobaccoLabelToKey('Current User'));
        $this->assertSame('Stopped < 1 year', RiskAssessmentErdMode::tobaccoKeyToLabel('stopped_lt_1y'));
        $this->assertSame('stopped_lt_1y', RiskAssessmentErdMode::tobaccoLabelToKey('Stopped < 1 year'));

        $this->assertSame('Never', RiskAssessmentErdMode::alcoholKeyToLabel('never'));
        $this->assertSame('never', RiskAssessmentErdMode::alcoholLabelToKey('Never'));
        $this->assertSame('Light (Occasional)', RiskAssessmentErdMode::alcoholKeyToLabel('light'));
        $this->assertSame('light', RiskAssessmentErdMode::alcoholLabelToKey('Light (Occasional)'));
        $this->assertSame('Excessive', RiskAssessmentErdMode::alcoholKeyToLabel('excessive'));
        $this->assertSame('excessive', RiskAssessmentErdMode::alcoholLabelToKey('Excessive'));

        $this->assertSame('Yes', RiskAssessmentErdMode::physicalActivityKeyToLabel('yes'));
        $this->assertSame('yes', RiskAssessmentErdMode::physicalActivityLabelToKey('Yes'));
        $this->assertSame('No', RiskAssessmentErdMode::physicalActivityKeyToLabel('no'));
        $this->assertSame('no', RiskAssessmentErdMode::physicalActivityLabelToKey('No'));
        $this->assertNull(RiskAssessmentErdMode::physicalActivityKeyToLabel('meets'));
        $this->assertNull(RiskAssessmentErdMode::physicalActivityLabelToKey('More than 2.5hrs/week'));
        $this->assertNull(RiskAssessmentErdMode::physicalActivityKeyToLabel('below'));
        $this->assertNull(RiskAssessmentErdMode::physicalActivityLabelToKey('Below 2.5hrs/week'));

        $this->assertSame('Yes', RiskAssessmentErdMode::dietaryKeyToLabel('yes'));
        $this->assertSame('yes', RiskAssessmentErdMode::dietaryLabelToKey('Yes'));
        $this->assertSame('No', RiskAssessmentErdMode::dietaryKeyToLabel('no'));
        $this->assertSame('no', RiskAssessmentErdMode::dietaryLabelToKey('No'));
        $this->assertNull(RiskAssessmentErdMode::dietaryKeyToLabel('balanced'));
        $this->assertNull(RiskAssessmentErdMode::dietaryLabelToKey('Balanced Diet'));
        $this->assertNull(RiskAssessmentErdMode::dietaryKeyToLabel('high_salt'));
        $this->assertNull(RiskAssessmentErdMode::dietaryLabelToKey('High Salt'));
        $this->assertNull(RiskAssessmentErdMode::dietaryKeyToLabel('low_fruits'));
        $this->assertNull(RiskAssessmentErdMode::dietaryLabelToKey('Low Fruits/Vegetables'));
    }

    public function test_legacy_risk_assessment_behavior_unchanged(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember([
            'household_no' => 'HH-940',
            'member_no' => 'MB-940',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $this->post($this->storeRoute($household, $resident), [
                'red_flags' => ['none'],
                'past_medical' => ['none'],
                'family_history' => ['none'],
                'tobacco' => 'never',
                'alcohol' => 'never',
                'dietary' => 'yes',
                'physical_activity' => 'yes',
                'height_cm' => '170',
                'weight_kg' => '70',
                'waist_cm' => '85',
                'systolic' => '120',
                'diastolic' => '80',
            ])
            ->assertRedirect();

        $this->assertSame(1, RiskAssessment::query()->count());
        $this->assertFalse(RiskAssessmentErdMode::isActive());
    }

    public function test_family_planning_unaffected(): void
    {
        $this->provisionErdRiskAssessmentTable();
        Schema::dropIfExists('family_planning_visits');
        Schema::create('family_planning', function ($table): void {
            $table->id('fp_id');
            $table->unsignedBigInteger('resident_id');
            $table->date('visitation_date');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
        FamilyPlanningErdMode::resetCachedState();

        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('household-profiling.members.family-planning.store', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]), [
                'visited_at' => '2026-03-01',
                'remarks' => 'Still works',
            ])
            ->assertRedirect();

        $this->assertSame(1, DB::table('family_planning')->count());
    }

    public function test_deworming_unaffected(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['resident' => $resident] = $this->seedPersistedMember();

        $record = app(\App\Support\DewormingRecordService::class)->createForResident($resident, [
            'year' => (int) now()->year,
            'round' => 1,
            'se_status' => 'NHTS',
            'date_given' => now()->toDateString(),
        ]);

        $this->assertInstanceOf(DewormingRecord::class, $record);
    }

    public function test_other_phase_one_write_guards_remain_active_with_incompatible_ra_schema(): void
    {
        Schema::dropIfExists('risk_assessments');
        Schema::create('risk_assessment', function ($table): void {
            $table->id('risk_assessment_id');
            $table->unsignedBigInteger('resident_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });
        RiskAssessmentErdMode::resetCachedState();

        $this->assertTrue(RiskAssessmentErdMode::isActive());
        $this->assertTrue(HouseholdProfilingWriteGuard::isRiskAssessmentWriteUnsupported());

        $this->expectException(ValidationException::class);
        HouseholdProfilingWriteGuard::rejectRiskAssessmentWrite();
    }

    public function test_legacy_family_planning_visits_table_still_works(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember([
            'household_no' => 'HH-941',
            'member_no' => 'MB-941',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('household-profiling.members.family-planning.store', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]), [
                'visited_at' => '2026-04-01',
                'remarks' => 'Legacy FP',
            ])
            ->assertRedirect();

        $this->assertSame(1, FamilyPlanningVisit::query()->count());
    }

    public function test_legacy_create_form_still_renders_five_steps_including_unsupported_figma_fields(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember([
            'household_no' => 'HH-942',
            'member_no' => 'MB-942',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('household-profiling.members.risk-assessment.create', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertFalse(RiskAssessmentErdMode::isActive());
        $this->assertStringContainsString('data-wizard-total="5"', $html);
        $this->assertStringContainsString('value="severe_injuries"', $html);
        $this->assertStringContainsString('value="kidney"', $html);
        $this->assertMatchesRegularExpression('/\bname="bmi"/', $html);
    }

    public function test_erd_under_19_create_does_not_expose_five_tab_wizard(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember([
            'birthday' => '2010-01-01',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('household-profiling.members.risk-assessment.create', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-risk-assess-ineligible', $html);
        $this->assertStringNotContainsString('data-risk-assess-form', $html);
        $this->assertStringNotContainsString('data-risk-assess-wizard', $html);
    }

    public function test_erd_empty_optional_history_sections_do_not_insert_child_rows(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();

        $this->assertSame(1, DB::table('risk_assessment')->count());
        $this->assertSame(0, DB::table('red_flags_assessment')->count());
        $this->assertSame(0, DB::table('past_medical_history')->count());
        $this->assertSame(0, DB::table('family_history')->count());
    }

    public function test_erd_create_persists_and_hydrates_supported_history_children(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'red_flags' => ['chest_pain', 'facial_asymmetry'],
            'past_medical' => ['hypertension', 'mental_neuro_substance'],
            'family_history' => ['isch_heart_disease', 'family_tb'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('risk_assessment')->count());
        $this->assertSame(1, DB::table('red_flags_assessment')->count());
        $this->assertSame(1, DB::table('past_medical_history')->count());
        $this->assertSame(1, DB::table('family_history')->count());

        $parentId = (int) DB::table('risk_assessment')->value('risk_assessment_id');
        $red = AtRestRecord::openRow('red_flags_assessment', DB::table('red_flags_assessment')->where('risk_assessment_id', $parentId)->first());
        $this->assertSame(1, (int) $red->chest_pain);
        $this->assertSame(1, (int) $red->facial_assym);
        $this->assertSame(0, (int) $red->no_red_flags);
        $this->assertSame(0, (int) $red->diff_breathing);

        $pmh = AtRestRecord::openRow('past_medical_history', DB::table('past_medical_history')->where('risk_assessment_id', $parentId)->first());
        $this->assertSame(1, (int) $pmh->hypertension);
        $this->assertSame(1, (int) $pmh->mental_disorders);
        $this->assertSame(0, (int) $pmh->none_past_medical);

        $fh = AtRestRecord::openRow('family_history', DB::table('family_history')->where('risk_assessment_id', $parentId)->first());
        $this->assertSame(1, (int) $fh->heart_disease);
        $this->assertSame(1, (int) $fh->tb);
        $this->assertSame(0, (int) $fh->none_family_history);

        $presentation = app(RiskAssessmentService::class)
            ->findPresentationForResident($resident, 'RA-001');
        $this->assertSame(['chest_pain', 'facial_asymmetry'], $presentation['red_flags']);
        $this->assertSame(['hypertension', 'mental_neuro_substance'], $presentation['past_medical']);
        $this->assertSame(['isch_heart_disease', 'family_tb'], $presentation['family_history']);

        $this->actingAsStaff(StaffRole::BHW);
        $show = $this->get(route('household-profiling.members.risk-assessment.show', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
                'assessmentId' => 'RA-001',
            ]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('RA-001', $show);
    }

    public function test_erd_none_semantics_persist_none_flags_only(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'red_flags' => ['none', 'chest_pain'],
            'past_medical' => ['none'],
            'family_history' => ['none'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $red = AtRestRecord::openRow('red_flags_assessment', DB::table('red_flags_assessment')->first());
        $this->assertSame(1, (int) $red->no_red_flags);
        $this->assertSame(0, (int) $red->chest_pain);

        $presentation = app(RiskAssessmentService::class)
            ->findPresentationForResident($resident, 'RA-001');
        $this->assertSame(['none'], $presentation['red_flags']);
        $this->assertSame(['none'], $presentation['past_medical']);
        $this->assertSame(['none'], $presentation['family_history']);
    }

    public function test_erd_section_edit_updates_same_child_row_without_duplicates(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'red_flags' => ['chest_pain'],
            'past_medical' => ['asthma'],
            'family_history' => ['stroke'],
        ]))->assertRedirect();

        $this->put($this->sectionUpdateRoute($household, $resident, 'RA-001', 'red-flags'), [
            'red_flags' => ['seizure', 'eye_injury'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->put($this->sectionUpdateRoute($household, $resident, 'RA-001', 'past-medical'), [
            'past_medical' => ['diabetes'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->put($this->sectionUpdateRoute($household, $resident, 'RA-001', 'family-history'), [
            'family_history' => ['none'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('red_flags_assessment')->count());
        $this->assertSame(1, DB::table('past_medical_history')->count());
        $this->assertSame(1, DB::table('family_history')->count());

        $red = AtRestRecord::openRow('red_flags_assessment', DB::table('red_flags_assessment')->first());
        $this->assertSame(0, (int) $red->chest_pain);
        $this->assertSame(1, (int) $red->seizure);
        $this->assertSame(1, (int) $red->eye_injury);

        $presentation = app(RiskAssessmentService::class)
            ->findPresentationForResident($resident, 'RA-001');
        $this->assertSame(['seizure', 'eye_injury'], $presentation['red_flags']);
        $this->assertSame(['diabetes'], $presentation['past_medical']);
        $this->assertSame(['none'], $presentation['family_history']);
    }

    public function test_erd_section_edit_can_create_child_row_when_missing(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();
        $this->assertSame(0, DB::table('red_flags_assessment')->count());

        $this->put($this->sectionUpdateRoute($household, $resident, 'RA-001', 'red-flags'), [
            'red_flags' => ['none'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('red_flags_assessment')->count());
        $this->assertSame(1, (int) AtRestRecord::open(DB::table('red_flags_assessment')->value('no_red_flags'), 'red_flags_assessment', 'no_red_flags'));
    }

    public function test_erd_child_write_failure_rolls_back_parent(): void
    {
        $this->provisionErdRiskAssessmentTable();
        Schema::dropIfExists('red_flags_assessment');
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->from(route('household-profiling.members.risk-assessment.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))
            ->post($this->storeRoute($household, $resident), $this->validPayload([
                'red_flags' => ['chest_pain'],
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('red_flags');

        $this->assertSame(0, DB::table('risk_assessment')->count());
        $this->assertSame(0, DB::table('past_medical_history')->count());
    }

    public function test_erd_child_rows_are_isolated_by_resident(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $householdA, 'resident' => $residentA] = $this->seedPersistedMember([
            'household_no' => 'HH-951',
            'member_no' => 'MB-951',
        ]);
        ['household' => $householdB, 'resident' => $residentB] = $this->seedPersistedMember([
            'household_no' => 'HH-952',
            'member_no' => 'MB-952',
        ]);
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($householdA, $residentA), $this->validPayload([
            'red_flags' => ['chest_pain'],
        ]))->assertRedirect();

        $this->put($this->sectionUpdateRoute($householdB, $residentB, 'RA-001', 'red-flags'), [
            'red_flags' => ['none'],
        ])->assertForbidden();

        $this->actingAsStaff(StaffRole::BHW);
        $this->get(route('household-profiling.members.risk-assessment.show', [
                'householdNo' => $householdB->household_no,
                'memberId' => $residentB->member_no,
                'assessmentId' => 'RA-001',
            ]))
            ->assertNotFound();

        $this->assertNull(app(RiskAssessmentService::class)
            ->findPresentationForResident($residentB, 'RA-001'));
        $this->assertSame(['chest_pain'], app(RiskAssessmentService::class)
            ->findPresentationForResident($residentA, 'RA-001')['red_flags']);
    }

    public function test_erd_create_prohibits_assessment_and_timbang_identifiers(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->from(route('household-profiling.members.risk-assessment.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))
            ->post($this->storeRoute($household, $resident), $this->validPayload([
                'risk_assessment_id' => 99,
                'timbang_id' => 12,
                'user_id' => 7,
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['risk_assessment_id', 'timbang_id', 'user_id']);

        $this->assertSame(0, DB::table('risk_assessment')->count());
    }

    public function test_offline_replay_writes_supported_parent_and_children(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        app(OfflineHealthServiceWriter::class)->write(
            $resident,
            $household,
            array_merge($this->validPayload([
                'red_flags' => ['chest_pain'],
                'past_medical' => ['allergies'],
                'family_history' => ['none'],
            ]), [
                '_health_action' => 'risk_assessment_store',
            ])
        );

        $this->assertSame(1, DB::table('risk_assessment')->count());
        $this->assertSame(1, (int) AtRestRecord::open(DB::table('red_flags_assessment')->value('chest_pain'), 'red_flags_assessment', 'chest_pain'));
        $this->assertSame(1, (int) AtRestRecord::open(DB::table('past_medical_history')->value('allergies'), 'past_medical_history', 'allergies'));
        $this->assertSame(1, (int) AtRestRecord::open(DB::table('family_history')->value('none_family_history'), 'family_history', 'none_family_history'));
    }

    public function test_offline_section_edit_uses_same_child_mapping(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'red_flags' => ['chest_pain'],
        ]))->assertRedirect();

        app(OfflineHealthServiceWriter::class)->write(
            $resident,
            $household,
            [
                '_health_action' => 'risk_assessment_section_update',
                '_health_assessment_id' => 'RA-001',
                '_health_section' => 'red-flags',
                'red_flags' => ['weakness_numbness'],
            ]
        );

        $this->assertSame(1, DB::table('red_flags_assessment')->count());
        $this->assertSame(1, (int) AtRestRecord::open(DB::table('red_flags_assessment')->value('weakness_body'), 'red_flags_assessment', 'weakness_body'));
        $this->assertSame(0, (int) AtRestRecord::open(DB::table('red_flags_assessment')->value('chest_pain'), 'red_flags_assessment', 'chest_pain'));
    }

    public function test_offline_replay_rejects_under_19_erd_create(): void
    {
        $this->provisionErdRiskAssessmentTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember([
            'birthday' => '2015-01-01',
        ]);
        $this->authenticateStaffUser();

        try {
            app(OfflineHealthServiceWriter::class)->write(
                $resident,
                $household,
                array_merge($this->validPayload([
                    'red_flags' => ['none'],
                ]), [
                    '_health_action' => 'risk_assessment_store',
                ])
            );
            $this->fail('Expected under-19 offline replay to be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assessment', $e->errors());
        }

        $this->assertSame(0, DB::table('risk_assessment')->count());
        $this->assertSame(0, DB::table('red_flags_assessment')->count());
    }
}
