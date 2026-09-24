<?php

namespace Tests\Feature;

use App\Support\AtRestRecord;
use App\Models\Household;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\Offline\OfflineHealthServiceWriter;
use App\Support\DemoRiskAssessment;
use App\Support\RiskAssessmentClinicalValues;
use App\Support\RiskAssessmentErdMode;
use App\Support\RiskAssessmentService;
use App\Support\StaffRole;
use App\Support\UserManagementErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\ErdRiskAssessmentChildSchema;
use Tests\Support\PersistCatalogHousehold;
use Tests\TestCase;

/**
 * #5 — Risk Assessment dietary/physical Yes/No, BMI status, history View Full Details.
 */
class RiskAssessmentLifestyleYesNoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedLaravelMember(array $overrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => $overrides['household_no'] ?? 'HH-950',
            'zone' => 'Zone 1',
        ]);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-950',
            'first_name' => 'Risk',
            'last_name' => 'YesNo',
            'birthday' => $overrides['birthday'] ?? '1990-01-01',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedErdMember(array $overrides = []): array
    {
        $this->provisionErd();

        return $this->seedLaravelMember($overrides);
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

    private function authenticateStaffUser(): User
    {
        $userId = (int) DB::table('user_management')->insertGetId([
            'name' => 'YesNo Staff',
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
    private function laravelPayload(array $overrides = []): array
    {
        return array_merge([
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
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function erdPayload(array $overrides = []): array
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

    public function test_create_form_shows_dietary_yes_and_no_radios(): void
    {
        PersistCatalogHousehold::persist('HH-151');
        $html = $this->get(route('household-profiling.members.risk-assessment.create', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString(DemoRiskAssessment::dietaryQuestion(), $html);
        $this->assertMatchesRegularExpression('/name="dietary"[^>]*value="yes"/', $html);
        $this->assertMatchesRegularExpression('/name="dietary"[^>]*value="no"/', $html);
        $this->assertStringNotContainsString('name="dietary[]"', $html);
        $this->assertStringNotContainsString('Balanced Diet', $html);
        $this->assertStringNotContainsString('High Salt', $html);
        $this->assertStringNotContainsString('Low Fruits/Vegetables', $html);
    }

    public function test_create_form_shows_physical_activity_yes_and_no_radios(): void
    {
        PersistCatalogHousehold::persist('HH-151');
        $html = $this->get(route('household-profiling.members.risk-assessment.create', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString(DemoRiskAssessment::physicalActivityQuestion(), $html);
        $this->assertMatchesRegularExpression('/name="physical_activity"[^>]*value="yes"/', $html);
        $this->assertMatchesRegularExpression('/name="physical_activity"[^>]*value="no"/', $html);
        $this->assertStringNotContainsString('More than 2.5', $html);
        $this->assertStringNotContainsString('Below 2.5', $html);
    }

    public function test_laravel_dietary_yes_and_no_persist_as_scalar_keys(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedLaravelMember();

        $this->post($this->storeRoute($household, $resident), $this->laravelPayload([
            'dietary' => 'yes',
        ]))->assertRedirect();

        $this->assertSame('yes', RiskAssessment::query()->first()?->dietary);

        RiskAssessment::query()->delete();

        $this->post($this->storeRoute($household, $resident), $this->laravelPayload([
            'dietary' => 'no',
        ]))->assertRedirect();

        $this->assertSame('no', RiskAssessment::query()->first()?->dietary);
    }

    public function test_erd_dietary_yes_and_no_persist_exact_labels(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedErdMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->erdPayload([
            'dietary' => 'yes',
        ]))->assertRedirect();

        $this->assertSame('Yes', AtRestRecord::open(DB::table('risk_assessment')->value('dietary_habits'), 'risk_assessment', 'dietary_habits'));

        DB::table('risk_assessment')->delete();

        $this->post($this->storeRoute($household, $resident), $this->erdPayload([
            'dietary' => 'no',
        ]))->assertRedirect();

        $this->assertSame('No', AtRestRecord::open(DB::table('risk_assessment')->value('dietary_habits'), 'risk_assessment', 'dietary_habits'));
    }

    public function test_erd_physical_activity_yes_and_no_persist_exact_labels(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedErdMember();
        $this->authenticateStaffUser();

        $this->post($this->storeRoute($household, $resident), $this->erdPayload([
            'physical_activity' => 'yes',
        ]))->assertRedirect();

        $this->assertSame('Yes', AtRestRecord::open(DB::table('risk_assessment')->value('physical_activity'), 'risk_assessment', 'physical_activity'));

        DB::table('risk_assessment')->delete();

        $this->post($this->storeRoute($household, $resident), $this->erdPayload([
            'physical_activity' => 'no',
        ]))->assertRedirect();

        $this->assertSame('No', AtRestRecord::open(DB::table('risk_assessment')->value('physical_activity'), 'risk_assessment', 'physical_activity'));
    }

    public function test_old_dietary_and_physical_keys_are_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedLaravelMember();
        $create = route('household-profiling.members.risk-assessment.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);

        foreach ([
            ['dietary' => 'balanced'],
            ['dietary' => 'high_salt'],
            ['dietary' => 'low_fruits'],
            ['dietary' => ['yes']],
            ['physical_activity' => 'meets'],
            ['physical_activity' => 'below'],
            ['physical_activity' => 'More than 2.5hrs/week'],
            ['physical_activity' => 'Below 2.5hrs/week'],
            ['dietary' => 'maybe'],
            ['physical_activity' => 'sometimes'],
        ] as $invalid) {
            $this->from($create)
                ->post($this->storeRoute($household, $resident), $this->laravelPayload($invalid))
                ->assertRedirect()
                ->assertSessionHasErrors();
            $this->assertSame(0, RiskAssessment::query()->count());
        }
    }

    public function test_blank_dietary_and_physical_activity_remain_nullable(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedLaravelMember();

        $this->post($this->storeRoute($household, $resident), $this->laravelPayload([
            'dietary' => '',
            'physical_activity' => '',
        ]))->assertRedirect();

        $row = RiskAssessment::query()->first();
        $this->assertNotNull($row);
        $this->assertNull($row->dietary);
        $this->assertNull($row->physical_activity);
    }

    public function test_old_erd_physical_activity_labels_read_safely_without_mapping_to_yes_no(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedErdMember();

        DB::table('risk_assessment')->insert([
            'resident_id' => $resident->id,
            'user_id' => $this->authenticateStaffUser()->getKey(),
            'dietary_habits' => null,
            'physical_activity' => 'More than 2.5hrs/week',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $presentation = app(RiskAssessmentService::class)
            ->findPresentationForResident($resident, 'RA-001');

        $this->assertNotNull($presentation);
        $this->assertSame('', $presentation['physical_activity']);
        $this->assertSame('', $presentation['dietary']);
        $this->assertNull(RiskAssessmentErdMode::physicalActivityLabelToKey('More than 2.5hrs/week'));
        $this->assertNull(RiskAssessmentErdMode::physicalActivityLabelToKey('Below 2.5hrs/week'));
    }

    public function test_bmi_calculation_and_status_bands_remain_authoritative(): void
    {
        $this->assertSame('17.4', RiskAssessmentClinicalValues::calculateBmi(178, 55));
        $this->assertSame('Underweight', RiskAssessmentClinicalValues::bmiStatusFromValue(18.4));
        $this->assertSame('Normal', RiskAssessmentClinicalValues::bmiStatusFromValue(18.5));
        $this->assertSame('Normal', RiskAssessmentClinicalValues::bmiStatusFromValue(24.9));
        $this->assertSame('Overweight', RiskAssessmentClinicalValues::bmiStatusFromValue(25.0));
        $this->assertSame('Overweight', RiskAssessmentClinicalValues::bmiStatusFromValue(29.9));
        $this->assertSame('Obesity', RiskAssessmentClinicalValues::bmiStatusFromValue(30.0));
        $this->assertSame('Obesity', RiskAssessmentClinicalValues::bmiStatusFromValue(34.9));
        $this->assertNull(RiskAssessmentClinicalValues::bmiStatusFromValue(35.0));
        $this->assertNull(RiskAssessmentClinicalValues::bmiStatusFromValue(42.1));
        $this->assertNotSame('Obese', RiskAssessmentClinicalValues::bmiStatusFromValue(35.0));
        $this->assertNotSame('Extreme Obesity', RiskAssessmentClinicalValues::bmiStatusFromValue(40));
    }

    public function test_risk_assessment_save_still_works_and_ignores_browser_bmi_status(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedLaravelMember();

        $this->from(route('household-profiling.members.risk-assessment.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))
            ->post($this->storeRoute($household, $resident), $this->laravelPayload([
                'height_cm' => '178',
                'weight_kg' => '55',
                'bmi' => '99.9',
                'bmi_status' => 'Obesity',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('bmi_status');

        $this->assertSame(0, RiskAssessment::query()->count());

        $this->post($this->storeRoute($household, $resident), $this->laravelPayload([
            'height_cm' => '178',
            'weight_kg' => '55',
            'bmi' => '99.9',
        ]))->assertRedirect();

        $row = RiskAssessment::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('17.4', number_format((float) $row->bmi, 1, '.', ''));
        $presentation = app(RiskAssessmentService::class)->toPresentation($row);
        $this->assertSame('Underweight', $presentation['bmi_status']);
    }

    public function test_history_shows_view_full_details_date_and_resident_isolation(): void
    {
        PersistCatalogHousehold::persist('HH-151');
        PersistCatalogHousehold::persistRiskAssessments('HH-151');

        $html = $this->get(route('household-profiling.members.risk-assessment', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('View Full Details', $html);
        $this->assertStringContainsString('06/08/2026', $html);
        $this->assertStringContainsString('data-risk-assess-view', $html);
        $this->assertStringContainsString(
            route('household-profiling.members.risk-assessment.show', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-001',
                'assessmentId' => 'RA-001',
            ]),
            $html
        );

        $this->get(route('household-profiling.members.risk-assessment.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-002',
            'assessmentId' => 'RA-001',
        ]))->assertNotFound();
    }

    public function test_offline_store_accepts_yes_no_and_rejects_old_keys(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedErdMember([
            'household_no' => 'HH-951',
            'member_no' => 'MB-951',
        ]);
        $this->authenticateStaffUser();

        app(OfflineHealthServiceWriter::class)->write(
            $resident,
            $household,
            array_merge($this->erdPayload([
                'dietary' => 'no',
                'physical_activity' => 'yes',
                'red_flags' => ['none'],
                'past_medical' => ['none'],
                'family_history' => ['none'],
            ]), [
                '_health_action' => 'risk_assessment_store',
            ])
        );

        $this->assertSame(1, DB::table('risk_assessment')->count());
        $this->assertSame('No', AtRestRecord::open(DB::table('risk_assessment')->value('dietary_habits'), 'risk_assessment', 'dietary_habits'));
        $this->assertSame('Yes', AtRestRecord::open(DB::table('risk_assessment')->value('physical_activity'), 'risk_assessment', 'physical_activity'));

        try {
            app(OfflineHealthServiceWriter::class)->write(
                $resident,
                $household,
                array_merge($this->erdPayload([
                    'dietary' => 'balanced',
                    'physical_activity' => 'meets',
                ]), [
                    '_health_action' => 'risk_assessment_store',
                ])
            );
            $this->fail('Old offline dietary/physical keys should be rejected.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        } catch (\App\Support\Offline\OfflineSyncException $e) {
            $this->assertNotEmpty($e->errors);
        }

        $this->assertSame(1, DB::table('risk_assessment')->count());
    }

    public function test_offline_section_update_accepts_yes_no(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedLaravelMember([
            'household_no' => 'HH-952',
            'member_no' => 'MB-952',
        ]);

        $this->post($this->storeRoute($household, $resident), $this->laravelPayload())->assertRedirect();

        app(OfflineHealthServiceWriter::class)->write(
            $resident,
            $household,
            [
                '_health_action' => 'risk_assessment_section_update',
                '_health_assessment_id' => 'RA-001',
                '_health_section' => 'lifestyle',
                'tobacco' => 'never',
                'alcohol' => 'never',
                'dietary' => 'no',
                'physical_activity' => 'no',
            ]
        );

        $row = RiskAssessment::query()->first();
        $this->assertSame('no', $row?->dietary);
        $this->assertSame('no', $row?->physical_activity);
    }

    public function test_allowed_values_are_the_yes_no_labels(): void
    {
        $values = RiskAssessmentErdMode::allowedValues('dietary_habits');

        $this->assertSame(['Yes', 'No'], $values);
        $this->assertSame(['Yes', 'No'], RiskAssessmentErdMode::allowedValues('physical_activity'));
        $this->assertSame('Yes', RiskAssessmentErdMode::dietaryKeyToLabel('yes'));
        $this->assertSame('No', RiskAssessmentErdMode::physicalActivityKeyToLabel('no'));
    }

    public function test_hasher_and_service_worker_files_were_not_replaced(): void
    {
        $this->assertFileExists(app_path('Support/Offline/OfflineFieldHasher.php'));
        $this->assertFileExists(resource_path('js/offline/offline-sw.js'));
        $hasher = (string) file_get_contents(app_path('Support/Offline/OfflineFieldHasher.php'));
        $sw = (string) file_get_contents(resource_path('js/offline/offline-sw.js'));
        $this->assertStringNotContainsString('risk_assessment_lifestyle_yes_no', $hasher);
        $this->assertStringNotContainsString('risk_assessment_lifestyle_yes_no', $sw);
    }
}
