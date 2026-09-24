<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\HouseholdEnvironmentalProfile;
use App\Models\HouseholdSolidWastePractice;
use App\Services\HouseholdEnvironmentalProfileService;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\EnvironmentalSanitationErdMode;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Environmental Health Step 4 — ERD waste_management_practices read/write persistence.
 */
class EnvironmentalHealthStep4ErdWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provisionErdTables();
    }

    private function provisionErdTables(): void
    {
        Schema::dropIfExists('household_solid_waste_practices');
        Schema::dropIfExists('household_environmental_profiles');
        Schema::dropIfExists('waste_management_practices');
        Schema::dropIfExists('environmental_sanitation');

        Schema::create('environmental_sanitation', function ($table): void {
            $table->id('env_assessment_id');
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('water_supply_status');
            $table->string('water_source_location');
            $table->unsignedTinyInteger('water_availability');
            $table->date('microbiological_validation_date');
            $table->string('microbio_result')->nullable();
            $table->date('physico_chem_test_date');
            $table->string('physico_chem_result')->nullable();
            $table->string('toilet_type')->nullable();
            $table->unsignedTinyInteger('open_defecation_place')->nullable();
            $table->unsignedTinyInteger('shared_toilet')->nullable();
            $table->string('sewage_disposal_method')->nullable();
            $table->timestamps();
        });

        Schema::create('waste_management_practices', function ($table): void {
            $table->id('waste_management_practices_id');
            $table->unsignedBigInteger('env_assessment_id')->unique();
            $table->unsignedTinyInteger('waste_segregation')->default(0);
            $table->unsignedTinyInteger('backyard_composting')->default(0);
            $table->unsignedTinyInteger('recycling_reuse')->default(0);
            $table->unsignedTinyInteger('collected_by_municipality')->default(0);
            $table->timestamps();
        });

        EnvironmentalSanitationErdMode::resetCachedState();
    }

    /**
     * @param  array<string, mixed>  $sanitationOverrides
     * @param  array<string, mixed>|null  $wasteOverrides
     */
    private function seedHouseholdWithSanitation(
        string $householdNo,
        array $sanitationOverrides = [],
        ?array $wasteOverrides = null,
    ): Household {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 5',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => 13.3811,
            'longitude' => 123.4306,
        ]);

        $envAssessmentId = DB::table('environmental_sanitation')->insertGetId(array_merge([
            'household_id' => $household->getKey(),
            'user_id' => 18,
            'water_supply_status' => 'Level II',
            'water_source_location' => 'Community faucet',
            'water_availability' => 1,
            'microbiological_validation_date' => '2026-06-01',
            'microbio_result' => 'Passed',
            'physico_chem_test_date' => '2026-06-01',
            'physico_chem_result' => 'Passed',
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_place' => 0,
            'shared_toilet' => 0,
            'sewage_disposal_method' => 'On-site Disposed',
            'created_at' => now(),
            'updated_at' => now(),
        ], $sanitationOverrides), 'env_assessment_id');

        if ($wasteOverrides !== null) {
            DB::table('waste_management_practices')->insert(array_merge([
                'env_assessment_id' => $envAssessmentId,
                'waste_segregation' => 0,
                'backyard_composting' => 0,
                'recycling_reuse' => 0,
                'collected_by_municipality' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ], $wasteOverrides));
        }

        return $household->fresh();
    }

    private function linkHousehold(Household $household): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        DemoHouseholdWaterSupply::linkFromHousehold($household);
    }

    /**
     * @return array<string, mixed>
     */
    private function validStep4Payload(string $householdNo, array $overrides = []): array
    {
        return array_merge([
            'household_no' => $householdNo,
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
            ],
        ], $overrides);
    }

    public function test_erd_step4_get_with_no_waste_row_returns_not_yet_determined(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $presentation = app(HouseholdEnvironmentalProfileService::class)->findPresentation($household);

        $this->assertNotNull($presentation);
        $this->assertSame([], $presentation['solid_waste_practices']);
        $this->assertSame('not_yet_determined', $presentation['solid_waste_status']);
    }

    public function test_erd_step4_get_hydrates_existing_waste_row(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005', [], [
            'waste_segregation' => 1,
            'backyard_composting' => 0,
            'recycling_reuse' => 1,
            'collected_by_municipality' => 1,
        ]);
        $this->linkHousehold($household);

        $presentation = app(HouseholdEnvironmentalProfileService::class)->findPresentation($household);

        $this->assertSame([
            'waste_segregation',
            'recycling_reuse',
            'municipal_collection',
        ], $presentation['solid_waste_practices']);
        $this->assertSame('good_practice', $presentation['solid_waste_status']);
    }

    public function test_erd_step4_get_hydrates_step4_page_after_existing_waste_row(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005', [], [
            'waste_segregation' => 0,
            'backyard_composting' => 1,
            'recycling_reuse' => 0,
            'collected_by_municipality' => 0,
        ]);
        $this->linkHousehold($household);

        $html = $this->get(route('environmental-health.household-water-supply.step4', [
            'householdNo' => 'HH-005',
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/name="solid_waste_practices\[\]"\s+value="backyard_composting"[^>]*checked|value="backyard_composting"[^>]*name="solid_waste_practices\[\]"[^>]*checked/',
            $html
        );
        $this->assertStringContainsString('GOOD PRACTICE', $html);
    }

    public function test_erd_step4_post_creates_one_waste_row(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $envAssessmentId = (int) DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->value('env_assessment_id');

        app(HouseholdEnvironmentalProfileService::class)->saveStep4($household, [
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
                DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION,
            ],
        ]);

        $this->assertSame(1, DB::table('waste_management_practices')->count());

        $waste = DB::table('waste_management_practices')
            ->where('env_assessment_id', $envAssessmentId)
            ->first();

        $this->assertNotNull($waste);
        $this->assertSame(1, (int) $waste->waste_segregation);
        $this->assertSame(0, (int) $waste->backyard_composting);
        $this->assertSame(0, (int) $waste->recycling_reuse);
        $this->assertSame(1, (int) $waste->collected_by_municipality);
    }

    public function test_erd_step4_post_updates_existing_waste_row_without_duplicate(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005', [], [
            'waste_segregation' => 1,
            'backyard_composting' => 0,
            'recycling_reuse' => 0,
            'collected_by_municipality' => 0,
        ]);
        $this->linkHousehold($household);

        app(HouseholdEnvironmentalProfileService::class)->saveStep4($household, [
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE,
            ],
        ]);

        app(HouseholdEnvironmentalProfileService::class)->saveStep4($household, [
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_BACKYARD_COMPOSTING,
                DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION,
            ],
        ]);

        $this->assertSame(1, DB::table('waste_management_practices')->count());

        $waste = DB::table('waste_management_practices')->first();
        $this->assertSame(0, (int) $waste->waste_segregation);
        $this->assertSame(1, (int) $waste->backyard_composting);
        $this->assertSame(0, (int) $waste->recycling_reuse);
        $this->assertSame(1, (int) $waste->collected_by_municipality);
    }

    public function test_erd_step4_maps_municipal_collection_to_collected_by_municipality(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        app(HouseholdEnvironmentalProfileService::class)->saveStep4($household, [
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION,
            ],
        ]);

        $waste = DB::table('waste_management_practices')->first();
        $this->assertSame(1, (int) $waste->collected_by_municipality);
    }

    public function test_erd_step4_preserves_environmental_sanitation_steps_one_to_three(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        app(HouseholdEnvironmentalProfileService::class)->saveStep4($household, [
            'solid_waste_practices' => DemoHouseholdWaterSupply::solidWastePracticeValues(),
        ]);

        $env = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertSame('Level II', $env->water_supply_status);
        $this->assertSame('Community faucet', $env->water_source_location);
        $this->assertSame(1, (int) $env->water_availability);
        $this->assertSame(18, (int) $env->user_id);
        $this->assertSame('2026-06-01', $env->microbiological_validation_date);
        $this->assertSame('Passed', $env->microbio_result);
        $this->assertSame('pour_flush_with_septic_tank', $env->toilet_type);
        $this->assertSame('On-site Disposed', $env->sewage_disposal_method);
    }

    public function test_erd_step4_unrelated_household_waste_row_is_not_updated(): void
    {
        $target = $this->seedHouseholdWithSanitation('HH-005');
        $other = $this->seedHouseholdWithSanitation('HH-006', [], [
            'waste_segregation' => 1,
            'backyard_composting' => 1,
            'recycling_reuse' => 0,
            'collected_by_municipality' => 0,
        ]);
        $this->linkHousehold($target);

        app(HouseholdEnvironmentalProfileService::class)->saveStep4($target, [
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE,
            ],
        ]);

        $otherEnvAssessmentId = (int) DB::table('environmental_sanitation')
            ->where('household_id', $other->getKey())
            ->value('env_assessment_id');

        $otherWaste = DB::table('waste_management_practices')
            ->where('env_assessment_id', $otherEnvAssessmentId)
            ->first();

        $this->assertSame(1, (int) $otherWaste->waste_segregation);
        $this->assertSame(1, (int) $otherWaste->backyard_composting);
        $this->assertSame(0, (int) $otherWaste->recycling_reuse);
    }

    public function test_erd_step4_post_redirects_to_spot_mapping_without_legacy_tables(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $this->post(
            route('environmental-health.household-water-supply.step4.store', ['householdNo' => 'HH-005']),
            $this->validStep4Payload('HH-005')
        )->assertRedirect(route('spot-mapping.index'))
            ->assertSessionHas('status', 'Household plotted successfully with environmental health information saved.');
    }

    public function test_erd_step4_does_not_insert_incomplete_row_when_required_dates_missing(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-007',
            'zone' => 'Zone 2',
            'street' => 'Dalipay St.',
            'date_registered' => '2026-01-16',
            'latitude' => null,
            'longitude' => null,
        ]);
        $this->linkHousehold($household);

        $presentation = app(HouseholdEnvironmentalProfileService::class)->saveStep4($household, [
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
            ],
        ]);

        $this->assertSame(0, DB::table('environmental_sanitation')->count());
        $this->assertSame('session', $presentation['source']);
        $this->assertContains(
            DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
            $presentation['solid_waste_practices']
        );
    }

    public function test_erd_step4_does_not_query_legacy_step4_tables(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $this->assertFalse(Schema::hasTable('household_environmental_profiles'));
        $this->assertFalse(Schema::hasTable('household_solid_waste_practices'));

        app(HouseholdEnvironmentalProfileService::class)->saveStep4($household, [
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
            ],
        ]);

        $this->assertFalse(Schema::hasTable('household_environmental_profiles'));
        $this->assertFalse(Schema::hasTable('household_solid_waste_practices'));
    }

    public function test_legacy_mode_still_writes_household_solid_waste_practices(): void
    {
        Schema::dropIfExists('waste_management_practices');
        Schema::dropIfExists('environmental_sanitation');
        EnvironmentalSanitationErdMode::resetCachedState();

        Schema::create('household_environmental_profiles', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->string('solid_waste_status', 32)->nullable();
            $table->unsignedTinyInteger('completed_step')->default(0);
            $table->timestamps();
        });

        Schema::create('household_solid_waste_practices', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('household_environmental_profile_id');
            $table->boolean('waste_segregation')->default(false);
            $table->boolean('backyard_composting')->default(false);
            $table->boolean('recycling_reuse')->default(false);
            $table->boolean('municipal_collection')->default(false);
            $table->timestamps();
        });

        EnvironmentalSanitationErdMode::resetCachedState();

        $household = Household::factory()->create([
            'household_no' => 'HH-804',
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => null,
            'longitude' => null,
        ]);

        app(HouseholdEnvironmentalProfileService::class)->saveStep4($household, [
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
                DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE,
            ],
        ]);

        $profile = HouseholdEnvironmentalProfile::query()
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertNotNull($profile);
        $this->assertSame('good_practice', $profile->solid_waste_status);
        $this->assertSame(4, (int) $profile->completed_step);

        $waste = HouseholdSolidWastePractice::query()
            ->where('household_environmental_profile_id', $profile->id)
            ->first();

        $this->assertNotNull($waste);
        $this->assertTrue($waste->waste_segregation);
        $this->assertFalse($waste->backyard_composting);
        $this->assertTrue($waste->recycling_reuse);
        $this->assertFalse($waste->municipal_collection);
    }
}
