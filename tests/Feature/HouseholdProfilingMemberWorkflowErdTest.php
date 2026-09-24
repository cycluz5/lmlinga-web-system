<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\ChildImmunizationService;
use App\Support\ChildNutritionService;
use App\Support\FamilyPlanningVisitService;
use App\Support\MaternalPregnancyService;
use App\Support\RiskAssessmentService;
use App\Services\HouseholdEnvironmentalProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HouseholdProfilingMemberWorkflowErdTest extends TestCase
{
    use RefreshDatabase;

    public function test_environmental_profile_service_returns_null_when_legacy_table_absent(): void
    {
        Schema::dropIfExists('household_environmental_profiles');

        $household = Household::factory()->create(['household_no' => 'HH-AMN-1']);

        $this->assertFalse(HouseholdEnvironmentalProfileService::persistenceAvailable());
        $this->assertNull(app(HouseholdEnvironmentalProfileService::class)->findPresentation($household));
    }

    public function test_child_immunization_returns_empty_state_when_legacy_table_absent(): void
    {
        Schema::dropIfExists('child_immunizations');

        $household = Household::factory()->create();
        $resident = Resident::factory()->create(['household_id' => $household->id]);

        $state = app(ChildImmunizationService::class)->forResident($resident);

        $this->assertFalse($state['persisted']);
    }

    public function test_risk_assessment_history_reads_erd_table_when_legacy_table_absent(): void
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

        $household = Household::factory()->create();
        $resident = Resident::factory()->create(['household_id' => $household->id]);

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

        $rows = app(RiskAssessmentService::class)->historyRowsForResident($resident);

        $this->assertCount(1, $rows);
        $this->assertSame('120/80', $rows[0]['bp_reading']);
    }

    public function test_family_planning_history_reads_erd_fp_id_primary_key(): void
    {
        Schema::dropIfExists('family_planning_visits');
        Schema::create('family_planning', function ($table): void {
            $table->id('fp_id');
            $table->unsignedBigInteger('resident_id');
            $table->date('visitation_date')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        $household = Household::factory()->create();
        $resident = Resident::factory()->create(['household_id' => $household->id]);

        DB::table('family_planning')->insert([
            'resident_id' => $resident->id,
            'visitation_date' => '2026-02-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = app(FamilyPlanningVisitService::class)->historyRowsForResident($resident);

        $this->assertCount(1, $rows);
        $this->assertSame('2026-02-01', $rows[0]['visited_at']);
    }

    public function test_maternal_care_active_presentation_reads_erd_maternal_care_table(): void
    {
        Schema::dropIfExists('maternal_pregnancies');
        Schema::create('maternal_care', function ($table): void {
            $table->id('maternal_care_id');
            $table->unsignedBigInteger('resident_id');
            $table->date('lmp_date')->nullable();
            $table->unsignedInteger('gravida')->nullable();
            $table->unsignedInteger('parity')->nullable();
            $table->date('edd')->nullable();
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->decimal('bmi', 8, 2)->nullable();
            $table->unsignedSmallInteger('bp_systolic')->nullable();
            $table->unsignedSmallInteger('bp_diastolic')->nullable();
            $table->string('pregnancy_status')->nullable();
            $table->timestamps();
        });

        $household = Household::factory()->create();
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'sex' => 'Female',
        ]);

        DB::table('maternal_care')->insert([
            'resident_id' => $resident->id,
            'lmp_date' => '2026-01-01',
            'gravida' => 1,
            'parity' => 0,
            'pregnancy_status' => 'Active',
            'weight_kg' => 55,
            'height_cm' => 160,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $active = app(MaternalPregnancyService::class)->activePresentationForResident($resident);

        $this->assertNotNull($active);
        $this->assertSame('2026-01-01', $active['lmp'] ?? null);
    }

    public function test_child_nutrition_reads_erd_table_without_legacy_sfp_outcomes(): void
    {
        Schema::dropIfExists('child_nutritions');
        Schema::dropIfExists('child_nutrition_sfp_outcomes');
        Schema::create('child_nutrition', function ($table): void {
            $table->id('child_nutrition_id');
            $table->unsignedBigInteger('resident_id');
            $table->decimal('length_at_birth_cm', 8, 2)->nullable();
            $table->decimal('weight_at_birth_kg', 8, 2)->nullable();
            $table->date('initiated_breastfeeding_date')->nullable();
            $table->timestamps();
        });
        \App\Support\ChildNutritionErdMode::resetCachedState();

        $household = Household::factory()->create();
        $resident = Resident::factory()->create(['household_id' => $household->id]);

        DB::table('child_nutrition')->insert([
            'resident_id' => $resident->id,
            'length_at_birth_cm' => 50.0,
            'weight_at_birth_kg' => 3.25,
            'initiated_breastfeeding_date' => '2026-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $state = app(ChildNutritionService::class)->forResident($resident);

        $this->assertTrue($state['persisted']);
        $this->assertSame('50', $state['newborn']['length']);
        $this->assertSame('3.25', $state['newborn']['weight']);
    }
}
