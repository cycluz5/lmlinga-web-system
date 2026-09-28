<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Support\AtRestRecord;
use App\Support\RiskAssessmentClinicalValues;
use App\Support\RiskAssessmentErdMode;
use App\Support\RiskAssessmentService;
use App\Support\UserManagementErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ErdRiskAssessmentChildSchema;
use Tests\TestCase;

/**
 * Normalization guard (Phase 1): the BP status users see is always
 * RiskAssessmentClinicalValues::calculateBpStatus(systolic, diastolic), never the
 * stored risk_assessment.blood_pressure_status. Protects the future removal of that column.
 *
 * Uses the ERD risk_assessment table (live schema), which still has blood_pressure_status.
 */
class NormalizationGuardRiskAssessmentBpStatusTest extends TestCase
{
    use RefreshDatabase;

    /** Display label for each calculateBpStatus() result (kept independent of the code under test). */
    private const LABELS = [
        RiskAssessmentClinicalValues::BP_NORMAL => 'Normal',
        RiskAssessmentClinicalValues::BP_ELEVATED => 'Elevated',
        RiskAssessmentClinicalValues::BP_STAGE_1 => 'Hypertension Stage 1',
        RiskAssessmentClinicalValues::BP_STAGE_2 => 'Hypertension Stage 2',
        RiskAssessmentClinicalValues::BP_SEVERE => 'Hypertensive Crisis',
    ];

    protected function setUp(): void
    {
        parent::setUp();

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

        $userId = (int) DB::table('user_management')->insertGetId([
            'name' => 'BP Guard Staff',
            'email' => 'bp.guard@example.test',
            'username' => 'bp.guard',
            'password' => Hash::make('password'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs(User::query()->findOrFail($userId));
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedMember(): array
    {
        $household = Household::factory()->create(['household_no' => 'HH-941', 'zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-941',
            'first_name' => 'Bp',
            'last_name' => 'Guard',
            'birthday' => '1980-05-01',
            'sex' => 'Female',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    private function saveAssessment(Household $household, Resident $resident, int $systolic, int $diastolic): string
    {
        $this->post(route('household-profiling.members.risk-assessment.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'tobacco' => 'never',
            'alcohol' => 'light',
            'dietary' => 'yes',
            'physical_activity' => 'yes',
            'height_cm' => '160',
            'weight_kg' => '60',
            'waist_cm' => '80',
            'systolic' => (string) $systolic,
            'diastolic' => (string) $diastolic,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return sprintf('RA-%03d', (int) DB::table('risk_assessment')->max('risk_assessment_id'));
    }

    private function expectedLabel(int $systolic, int $diastolic): string
    {
        $status = RiskAssessmentClinicalValues::calculateBpStatus($systolic, $diastolic);
        $this->assertNotNull($status);

        return self::LABELS[$status];
    }

    /**
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function readings(): iterable
    {
        yield 'normal' => [118, 76];
        yield 'elevated' => [125, 75];
        yield 'stage 1' => [132, 82];
        yield 'stage 2' => [145, 92];
        yield 'crisis' => [185, 125];
    }

    #[DataProvider('readings')]
    public function test_displayed_bp_status_equals_calculated_status(int $systolic, int $diastolic): void
    {
        $this->assertTrue(RiskAssessmentErdMode::isActive());
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $assessmentNo = $this->saveAssessment($household, $resident, $systolic, $diastolic);
        $expected = $this->expectedLabel($systolic, $diastolic);

        $presentation = app(RiskAssessmentService::class)->findPresentationForResident($resident, $assessmentNo);
        $this->assertSame($expected, $presentation['bp_status']);

        // Physical section page of the saved assessment: the read-only BP Status field carries the label.
        $html = (string) $this->get(route('household-profiling.members.risk-assessment.section', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'assessmentId' => $assessmentNo,
            'section' => 'physical',
        ]))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<input[^>]*data-risk-assess-bp-status[^>]*>/s', $html, $match), 'BP Status field missing.');
        $this->assertStringContainsString('value="'.e($expected).'"', $match[0], "BP Status field was: {$match[0]}");
    }

    public function test_stale_stored_bp_status_is_not_what_users_see(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $assessmentNo = $this->saveAssessment($household, $resident, 185, 125);

        // Simulate a stale/incorrect stored value; reads must still recalculate.
        DB::table('risk_assessment')->update([
            'blood_pressure_status' => AtRestRecord::seal('Normal', 'risk_assessment', 'blood_pressure_status'),
        ]);

        $presentation = app(RiskAssessmentService::class)->findPresentationForResident($resident, $assessmentNo);
        $this->assertSame($this->expectedLabel(185, 125), $presentation['bp_status']);
        $this->assertNotSame('Normal', $presentation['bp_status']);

        $html = (string) $this->get(route('household-profiling.members.risk-assessment.section', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'assessmentId' => $assessmentNo,
            'section' => 'physical',
        ]))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<input[^>]*data-risk-assess-bp-status[^>]*>/s', $html, $match));
        $this->assertStringContainsString('value="Hypertensive Crisis"', $match[0]);
    }
}
