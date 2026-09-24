<?php

namespace Tests\Feature;

use App\Support\AtRestRecord;
use App\Models\Household;
use App\Models\Resident;
use App\Models\TimbangRecord;
use App\Models\User;
use App\Services\Offline\OfflineHealthServiceWriter;
use App\Support\StaffRole;
use App\Support\TimbangRecordService;
use App\Support\RiskAssessmentErdMode;
use App\Support\UserManagementErdMode;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\ErdRiskAssessmentChildSchema;
use Tests\TestCase;

class RiskAssessmentTimbangSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-12')->startOfDay());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function provisionErd(): void
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
    private function seedMember(array $overrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => $overrides['household_no'] ?? 'HH-1717',
            'zone' => 'Zone 1',
        ]);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-1717',
            'first_name' => $overrides['first_name'] ?? 'Sync',
            'last_name' => $overrides['last_name'] ?? 'Adult',
            'birthday' => $overrides['birthday'] ?? '1990-05-01',
        ]);

        return compact('household', 'resident');
    }

    private function authenticateStaffUser(): User
    {
        $userId = (int) DB::table('user_management')->insertGetId([
            'name' => 'RA Sync Staff',
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
            'height_cm' => '165',
            'weight_kg' => '60',
            'waist_cm' => '80',
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

    private function sectionRoute(
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

    public function test_create_with_weight_and_height_writes_one_timbang_row(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('risk_assessment')->count());
        $this->assertSame(1, TimbangRecord::query()->count());

        $row = TimbangRecord::query()->first();
        $this->assertSame((int) $resident->getKey(), (int) $row->resident_id);
        $this->assertEqualsWithDelta(60.0, (float) $row->weight_kg, 0.001);
        $this->assertEqualsWithDelta(165.0, (float) $row->height_cm, 0.001);
        $this->assertSame('2026-09-12', $row->measurement_date->toDateString());
        $this->assertFalse(Schema::hasColumn('timbang_records', 'bmi'));
        $this->assertNull($row->muac_cm);
        $this->assertNull($row->weight_for_age);
        $this->assertNull($row->height_for_age);
        $this->assertNull($row->weight_for_height);
        $this->assertNull($row->remarks);
    }

    public function test_create_without_measurements_writes_zero_timbang_rows(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'height_cm' => '',
            'weight_kg' => '',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('risk_assessment')->count());
        $this->assertSame(0, TimbangRecord::query()->count());
    }

    public function test_create_with_weight_only_syncs_partial_row(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'height_cm' => '',
            'weight_kg' => '58.5',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $row = TimbangRecord::query()->first();
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(58.5, (float) $row->weight_kg, 0.001);
        $this->assertNull($row->height_cm);
    }

    public function test_physical_weight_change_inserts_one_new_history_row(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->authenticateStaffUser();
        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();

        $this->put($this->sectionRoute($household, $resident, 'RA-001', 'physical'), [
            'height_cm' => '165',
            'weight_kg' => '62',
            'waist_cm' => '80',
            'systolic' => '120',
            'diastolic' => '80',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, TimbangRecord::query()->count());
        $latest = app(TimbangRecordService::class)->latestForResident($resident);
        $this->assertEqualsWithDelta(62.0, (float) $latest->weight_kg, 0.001);
        $this->assertEqualsWithDelta(165.0, (float) $latest->height_cm, 0.001);
    }

    public function test_physical_height_change_inserts_one_new_history_row(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->authenticateStaffUser();
        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();

        $this->put($this->sectionRoute($household, $resident, 'RA-001', 'physical'), [
            'height_cm' => '166',
            'weight_kg' => '60',
            'waist_cm' => '80',
            'systolic' => '120',
            'diastolic' => '80',
        ])->assertRedirect();

        $this->assertSame(2, TimbangRecord::query()->count());
        $latest = app(TimbangRecordService::class)->latestForResident($resident);
        $this->assertEqualsWithDelta(166.0, (float) $latest->height_cm, 0.001);
    }

    public function test_changing_both_measurements_inserts_one_row_not_two(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->authenticateStaffUser();
        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();

        $this->put($this->sectionRoute($household, $resident, 'RA-001', 'physical'), [
            'height_cm' => '166',
            'weight_kg' => '62',
            'waist_cm' => '80',
            'systolic' => '120',
            'diastolic' => '80',
        ])->assertRedirect();

        $this->assertSame(2, TimbangRecord::query()->count());
    }

    public function test_identical_normalized_physical_save_creates_zero_new_rows(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->authenticateStaffUser();
        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();

        $this->put($this->sectionRoute($household, $resident, 'RA-001', 'physical'), [
            'height_cm' => '165.00',
            'weight_kg' => '60.00',
            'waist_cm' => '80',
            'systolic' => '120',
            'diastolic' => '80',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->put($this->sectionRoute($household, $resident, 'RA-001', 'physical'), [
            'height_cm' => '165',
            'weight_kg' => '60.0',
            'waist_cm' => '81',
            'systolic' => '118',
            'diastolic' => '76',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, TimbangRecord::query()->count());
        $this->assertTrue(TimbangRecordService::physicalMeasurementsChanged(null, null, '60', '165'));
        $this->assertFalse(TimbangRecordService::physicalMeasurementsChanged('70', '165', '70.00', '165.0'));
    }

    public function test_clearing_both_measurements_does_not_insert_empty_timbang_row(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->authenticateStaffUser();
        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();

        $this->put($this->sectionRoute($household, $resident, 'RA-001', 'physical'), [
            'height_cm' => '',
            'weight_kg' => '',
            'waist_cm' => '80',
            'systolic' => '120',
            'diastolic' => '80',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, TimbangRecord::query()->count());
        $this->assertNull(AtRestRecord::open(DB::table('risk_assessment')->value('weight_kg'), 'risk_assessment', 'weight_kg'));
        $this->assertNull(AtRestRecord::open(DB::table('risk_assessment')->value('height_cm'), 'risk_assessment', 'height_cm'));
    }

    public function test_non_physical_section_updates_do_not_create_timbang_rows(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->authenticateStaffUser();
        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();
        $this->assertSame(1, TimbangRecord::query()->count());

        $this->put($this->sectionRoute($household, $resident, 'RA-001', 'lifestyle'), [
            'tobacco' => 'current',
            'alcohol' => 'never',
            'dietary' => 'no',
            'physical_activity' => 'no',
        ])->assertRedirect();

        $this->put($this->sectionRoute($household, $resident, 'RA-001', 'red-flags'), [
            'red_flags' => ['chest_pain'],
        ])->assertRedirect();

        $this->put($this->sectionRoute($household, $resident, 'RA-001', 'past-medical'), [
            'past_medical' => ['asthma'],
        ])->assertRedirect();

        $this->put($this->sectionRoute($household, $resident, 'RA-001', 'family-history'), [
            'family_history' => ['stroke'],
        ])->assertRedirect();

        $this->assertSame(1, TimbangRecord::query()->count());
    }

    public function test_timbang_insert_failure_rolls_back_risk_assessment_create(): void
    {
        $this->provisionErd();
        Schema::dropIfExists('timbang_records');
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->authenticateStaffUser();

        $this->from(route('household-profiling.members.risk-assessment.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))
            ->post($this->storeRoute($household, $resident), $this->validPayload())
            ->assertRedirect()
            ->assertSessionHasErrors('measurement');

        $this->assertSame(0, DB::table('risk_assessment')->count());
        $this->assertSame(0, DB::table('red_flags_assessment')->count());
    }

    public function test_nutritional_status_latest_follows_ra_sync(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();

        $latest = app(TimbangRecordService::class)->latestForResident($resident);
        $this->assertNotNull($latest);
        $this->assertEqualsWithDelta(60.0, (float) $latest->weight_kg, 0.001);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('household-profiling.members.nutritional-status', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('60 kg', $html);
        $this->assertStringContainsString('165 cm', $html);

        $this->authenticateStaffUser();
        $this->put($this->sectionRoute($household, $resident, 'RA-001', 'physical'), [
            'height_cm' => '165',
            'weight_kg' => '62',
            'waist_cm' => '80',
            'systolic' => '120',
            'diastolic' => '80',
        ])->assertRedirect();

        $latest = app(TimbangRecordService::class)->latestForResident($resident);
        $this->assertEqualsWithDelta(62.0, (float) $latest->weight_kg, 0.001);
        $this->assertSame(2, TimbangRecord::query()->count());
    }

    public function test_under_19_create_writes_neither_ra_nor_timbang(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'birthday' => '2015-01-01',
        ]);
        $this->authenticateStaffUser();

        $this->from(route('household-profiling.members.risk-assessment.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))
            ->post($this->storeRoute($household, $resident), $this->validPayload())
            ->assertRedirect()
            ->assertSessionHasErrors('assessment');

        $this->assertSame(0, DB::table('risk_assessment')->count());
        $this->assertSame(0, TimbangRecord::query()->count());
    }

    public function test_resident_isolation_syncs_only_owner_timbang_row(): void
    {
        $this->provisionErd();
        $a = $this->seedMember(['household_no' => 'HH-1718', 'member_no' => 'MB-1718']);
        $b = $this->seedMember(['household_no' => 'HH-1719', 'member_no' => 'MB-1719']);
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($a['household'], $a['resident']), $this->validPayload())->assertRedirect();

        $this->assertSame(1, TimbangRecord::query()->where('resident_id', $a['resident']->id)->count());
        $this->assertSame(0, TimbangRecord::query()->where('resident_id', $b['resident']->id)->count());

        $this->put($this->sectionRoute($b['household'], $b['resident'], 'RA-001', 'physical'), [
            'height_cm' => '180',
            'weight_kg' => '90',
        ])->assertForbidden();

        $this->assertSame(0, TimbangRecord::query()->where('resident_id', $b['resident']->id)->count());
    }

    public function test_crafted_timbang_id_is_prohibited_on_create(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->authenticateStaffUser();

        $this->from(route('household-profiling.members.risk-assessment.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))
            ->post($this->storeRoute($household, $resident), $this->validPayload([
                'timbang_id' => 99,
                'resident_id' => $resident->id + 1,
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['timbang_id', 'resident_id']);

        $this->assertSame(0, DB::table('risk_assessment')->count());
        $this->assertSame(0, TimbangRecord::query()->count());
    }

    public function test_offline_create_replay_writes_one_ra_and_one_timbang_row(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->authenticateStaffUser();

        app(OfflineHealthServiceWriter::class)->write(
            $resident,
            $household,
            array_merge($this->validPayload(), [
                '_health_action' => 'risk_assessment_store',
            ])
        );

        $this->assertSame(1, DB::table('risk_assessment')->count());
        $this->assertSame(1, TimbangRecord::query()->count());
    }

    public function test_offline_physical_update_syncs_only_when_measurements_change(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->authenticateStaffUser();

        app(OfflineHealthServiceWriter::class)->write(
            $resident,
            $household,
            array_merge($this->validPayload(), [
                '_health_action' => 'risk_assessment_store',
            ])
        );

        app(OfflineHealthServiceWriter::class)->write(
            $resident,
            $household,
            [
                '_health_action' => 'risk_assessment_section_update',
                '_health_assessment_id' => 'RA-001',
                '_health_section' => 'physical',
                'height_cm' => '165.00',
                'weight_kg' => '60.00',
                'waist_cm' => '80',
                'systolic' => '120',
                'diastolic' => '80',
            ]
        );
        $this->assertSame(1, TimbangRecord::query()->count());

        app(OfflineHealthServiceWriter::class)->write(
            $resident,
            $household,
            [
                '_health_action' => 'risk_assessment_section_update',
                '_health_assessment_id' => 'RA-001',
                '_health_section' => 'physical',
                'height_cm' => '165',
                'weight_kg' => '62',
                'waist_cm' => '80',
                'systolic' => '120',
                'diastolic' => '80',
            ]
        );
        $this->assertSame(2, TimbangRecord::query()->count());
    }

    public function test_legacy_create_also_syncs_timbang_history(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1720',
            'member_no' => 'MB-1720',
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
        ])->assertRedirect();

        $this->assertFalse(RiskAssessmentErdMode::isActive());
        $this->assertSame(1, TimbangRecord::query()->where('resident_id', $resident->id)->count());
    }
}
