<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\DewormingRecord;
use App\Models\FamilyPlanningVisit;
use App\Models\Household;
use App\Models\Resident;
use App\Support\FamilyPlanningErdMode;
use App\Support\FamilyPlanningVisitService;
use App\Support\HouseholdProfilingWriteGuard;
use App\Support\RiskAssessmentErdMode;
use App\Support\RiskAssessmentService;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\AssertsAtRestStoredField;
use Tests\TestCase;

class HouseholdProfilingErdClientSafetyTest extends TestCase
{
    use AssertsAtRestStoredField;
    use RefreshDatabase;

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedPersistedMember(): array
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-901',
            'zone' => 'Zone 1',
            'street' => 'Safety St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-901',
            'first_name' => 'Isabel',
            'last_name' => 'Villanueva',
            'sex' => 'Female',
            'birthday' => '1990-05-01',
        ]);

        return compact('household', 'resident');
    }

    public function test_family_planning_erd_detail_lookup_resolves_fp_id(): void
    {
        Schema::dropIfExists('family_planning_visits');
        Schema::create('family_planning', function ($table): void {
            $table->id('fp_id');
            $table->unsignedBigInteger('resident_id');
            $table->date('visitation_date');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        ['resident' => $resident] = $this->seedPersistedMember();

        DB::table('family_planning')->insert([
            'resident_id' => $resident->id,
            'visitation_date' => '2026-02-01',
            'remarks' => 'ERD visit note',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $presentation = app(FamilyPlanningVisitService::class)
            ->findPresentationForResident($resident, 'FP-001');

        $this->assertNotNull($presentation);
        $this->assertSame('FP-001', $presentation['id']);
        $this->assertSame('2026-02-01', $presentation['visited_at']);
        $this->assertSame('ERD visit note', $presentation['remarks']);
    }

    public function test_family_planning_erd_detail_get_returns_ok(): void
    {
        Schema::dropIfExists('family_planning_visits');
        Schema::create('family_planning', function ($table): void {
            $table->id('fp_id');
            $table->unsignedBigInteger('resident_id');
            $table->date('visitation_date');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        DB::table('family_planning')->insert([
            'resident_id' => $resident->id,
            'visitation_date' => '2026-02-01',
            'remarks' => 'Detail GET',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $this->get(route('household-profiling.members.family-planning.show', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
                'visitId' => 'FP-001',
            ]))
            ->assertOk()
            ->assertSee('Detail GET', false);
    }

    public function test_risk_assessment_erd_detail_lookup_resolves_risk_assessment_id(): void
    {
        Schema::dropIfExists('risk_assessments');
        Schema::create('risk_assessment', function ($table): void {
            $table->id('risk_assessment_id');
            $table->unsignedBigInteger('resident_id');
            $table->unsignedBigInteger('user_id');
            $table->string('tobacco_vape_usage')->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->unsignedSmallInteger('systolic_blood_pressure')->nullable();
            $table->unsignedSmallInteger('diastolic_blood_pressure')->nullable();
            $table->timestamps();
        });

        ['resident' => $resident] = $this->seedPersistedMember();

        DB::table('risk_assessment')->insert([
            'resident_id' => $resident->id,
            'user_id' => 1,
            'tobacco_vape_usage' => 'Never',
            'height_cm' => 170,
            'weight_kg' => 70,
            'systolic_blood_pressure' => 120,
            'diastolic_blood_pressure' => 80,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $presentation = app(RiskAssessmentService::class)
            ->findPresentationForResident($resident, 'RA-001');

        $this->assertNotNull($presentation);
        $this->assertSame('RA-001', $presentation['id']);
        $this->assertSame('120/80', $presentation['bp_reading']);
        $this->assertSame('never', $presentation['tobacco']);
    }

    public function test_risk_assessment_erd_detail_get_returns_ok(): void
    {
        Schema::dropIfExists('risk_assessments');
        Schema::create('risk_assessment', function ($table): void {
            $table->id('risk_assessment_id');
            $table->unsignedBigInteger('resident_id');
            $table->unsignedBigInteger('user_id');
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->unsignedSmallInteger('systolic_blood_pressure')->nullable();
            $table->unsignedSmallInteger('diastolic_blood_pressure')->nullable();
            $table->timestamps();
        });

        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        DB::table('risk_assessment')->insert([
            'resident_id' => $resident->id,
            'user_id' => 1,
            'height_cm' => 170,
            'weight_kg' => 70,
            'systolic_blood_pressure' => 120,
            'diastolic_blood_pressure' => 80,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $this->get(route('household-profiling.members.risk-assessment.show', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
                'assessmentId' => 'RA-001',
            ]))
            ->assertOk()
            ->assertSee('data-assessment-id="RA-001"', false);
    }

    public function test_erd_family_planning_post_persists_when_authoritative_schema_present(): void
    {
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
        $response = $this->from(route('household-profiling.members.family-planning.create', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]))
            ->post(route('household-profiling.members.family-planning.store', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]), [
                'visited_at' => '2026-03-01',
                'remarks' => 'ERD persisted visit',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertSame(1, DB::table('family_planning')->count());
        $this->assertDatabaseHas('family_planning', [
            'resident_id' => $resident->id,
        ]);
        $this->assertPlaintextStoredField('family_planning', 'remarks', 'ERD persisted visit', [
            'resident_id' => $resident->id,
        ]);
    }

    public function test_erd_risk_assessment_lifestyle_put_persists_when_authoritative_schema_present(): void
    {
        Schema::dropIfExists('risk_assessments');
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
            $table->timestamps();
        });
        RiskAssessmentErdMode::resetCachedState();
        \App\Support\UserManagementErdMode::resetCachedState();

        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();
        $user = \App\Models\User::query()->findOrFail((int) DB::table('user_management')->insertGetId([
            'name' => 'Safety Staff',
            'email' => 'safety.staff@test.local',
            'username' => 'safety.staff',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $this->actingAs($user);
        \App\Support\UiRole::set('bhw');

        DB::table('risk_assessment')->insert([
            'resident_id' => $resident->id,
            'user_id' => $user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sectionUrl = route('household-profiling.members.risk-assessment.section.edit', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'assessmentId' => 'RA-001',
            'section' => 'lifestyle',
        ]);

        $response = $this->from($sectionUrl)
            ->put(route('household-profiling.members.risk-assessment.section.update', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
                'assessmentId' => 'RA-001',
                'section' => 'lifestyle',
            ]), [
                'tobacco' => 'never',
                'alcohol' => 'never',
                'dietary' => 'yes',
                'physical_activity' => 'yes',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertAtRestDatabaseHas('risk_assessment', [
            'resident_id' => $resident->id,
            'tobacco_vape_usage' => 'Never',
        ]);
    }

    public function test_unsupported_erd_risk_assessment_put_returns_forbidden_not_server_error(): void
    {
        Schema::dropIfExists('risk_assessments');
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
            $table->timestamps();
        });
        RiskAssessmentErdMode::resetCachedState();

        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        DB::table('risk_assessment')->insert([
            'resident_id' => $resident->id,
            'user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $this->put(route('household-profiling.members.risk-assessment.section.update', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
                'assessmentId' => 'RA-001',
                'section' => 'red-flags',
            ]), [
                'red_flags' => ['none'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('red_flags');
    }

    public function test_legacy_family_planning_post_still_persists_when_visits_table_exists(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('household-profiling.members.family-planning.store', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]), [
                'visited_at' => '2026-04-01',
                'remarks' => 'Legacy visit',
            ])
            ->assertRedirect();

        $this->assertSame(1, FamilyPlanningVisit::query()->count());
    }

    public function test_deworming_write_is_not_guarded_and_can_persist_on_legacy_schema(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->assertFalse(HouseholdProfilingWriteGuard::isHouseholdShellWriteUnsupported());

        $record = app(\App\Support\DewormingRecordService::class)->createForResident($resident, [
            'year' => (int) now()->year,
            'round' => 1,
            'se_status' => 'NHTS',
            'date_given' => now()->toDateString(),
        ]);

        $this->assertInstanceOf(DewormingRecord::class, $record);
        $this->assertSame(1, DewormingRecord::query()->count());
    }

    public function test_erd_household_write_guard_allows_purok_only_schema(): void
    {
        Schema::dropIfExists('households');
        Schema::create('households', function ($table): void {
            $table->id('household_id');
            $table->string('household_no')->unique();
            $table->string('purok');
            $table->date('date_registered');
            $table->timestamps();
        });
        \App\Models\Household::resetResolvedKeyName();

        $this->assertFalse(HouseholdProfilingWriteGuard::isHouseholdShellWriteUnsupported());
        HouseholdProfilingWriteGuard::rejectHouseholdShellWrite();
    }

    public function test_household_write_guard_rejects_when_location_column_missing(): void
    {
        Schema::dropIfExists('households');
        Schema::create('households', function ($table): void {
            $table->id();
            $table->string('household_no')->unique();
            $table->timestamps();
        });
        \App\Models\Household::resetResolvedKeyName();

        $this->assertTrue(HouseholdProfilingWriteGuard::isHouseholdShellWriteUnsupported());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(HouseholdProfilingWriteGuard::MESSAGE);

        HouseholdProfilingWriteGuard::rejectHouseholdShellWrite();
    }
}
