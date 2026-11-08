<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\HouseholdEnvironmentalProfile;
use App\Services\HouseholdEnvironmentalProfileService;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\EnvironmentalSanitationErdMode;
use App\Support\EnvironmentalSanitationWriteService;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Environmental Health Step 1 — ERD environmental_sanitation write persistence.
 */
class EnvironmentalHealthStep1ErdWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provisionErdEnvironmentalSanitationTable();
    }

    private function provisionErdEnvironmentalSanitationTable(): void
    {
        Schema::dropIfExists('household_environmental_profiles');
        Schema::dropIfExists('household_solid_waste_practices');
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

        EnvironmentalSanitationErdMode::resetCachedState();
    }

    /**
     * @param  array<string, mixed>  $sanitationOverrides
     */
    private function seedHouseholdWithSanitation(
        string $householdNo,
        array $sanitationOverrides = [],
    ): Household {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 5',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => 13.3811,
            'longitude' => 123.4306,
        ]);

        DB::table('environmental_sanitation')->insert(array_merge([
            'household_id' => $household->getKey(),
            'user_id' => 18,
            'water_supply_status' => 'Level II',
            'water_source_location' => 'Community faucet',
            'water_availability' => 1,
            'microbiological_validation_date' => '2026-01-20',
            'microbio_result' => 'Passed',
            'physico_chem_test_date' => '2026-01-21',
            'physico_chem_result' => 'Passed',
            'toilet_type' => 'water_sealed',
            'open_defecation_place' => 0,
            'shared_toilet' => 0,
            'sewage_disposal_method' => 'On-site Disposed',
            'created_at' => now(),
            'updated_at' => now(),
        ], $sanitationOverrides));

        return $household->fresh();
    }

    private function linkHouseholdForStep1(Household $household): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        DemoHouseholdWaterSupply::linkFromHousehold($household);
    }

    /**
     * @return array<string, mixed>
     */
    private function validStep1Payload(string $householdNo, array $overrides = []): array
    {
        return array_merge([
            'household_no' => $householdNo,
            'water_supply_status' => 'level_ii',
            'specify_water_source' => null,
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
        ], $overrides);
    }

    public function test_erd_step1_update_maps_level_ii_and_availability_yes(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHouseholdForStep1($household);

        $presentation = app(HouseholdEnvironmentalProfileService::class)->saveStep1($household, [
            'water_supply_status' => 'level_ii',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
        ]);

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertSame('Level II', $row->water_supply_status);
        $this->assertSame(1, (int) $row->water_availability);
        $this->assertSame('level_ii', $presentation['water_supply_status']);
        $this->assertSame('yes', $presentation['water_availability']);
        $this->assertSame('erd', $presentation['source']);
    }

    public function test_erd_step1_preserves_descriptive_water_source_location(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHouseholdForStep1($household);

        app(HouseholdEnvironmentalProfileService::class)->saveStep1($household, [
            'water_supply_status' => 'level_iii',
            'water_source_location' => 'yes',
            'water_availability' => 'no',
        ]);

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertSame('Community faucet', $row->water_source_location);
        $this->assertSame('Level III', $row->water_supply_status);
        $this->assertSame(0, (int) $row->water_availability);
    }

    public function test_erd_step1_preserves_unrelated_columns_and_user_id(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHouseholdForStep1($household);

        app(HouseholdEnvironmentalProfileService::class)->saveStep1($household, [
            'water_supply_status' => 'level_i',
            'water_source_location' => 'no',
            'water_availability' => 'yes',
        ]);

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertSame(18, (int) $row->user_id);
        $this->assertSame('2026-01-20', $row->microbiological_validation_date);
        $this->assertSame('Passed', $row->microbio_result);
        $this->assertSame('2026-01-21', $row->physico_chem_test_date);
        $this->assertSame('Passed', $row->physico_chem_result);
        $this->assertSame('water_sealed', $row->toilet_type);
        $this->assertSame('On-site Disposed', $row->sewage_disposal_method);
    }

    public function test_erd_step1_does_not_query_legacy_profiles_table(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHouseholdForStep1($household);

        $this->assertFalse(Schema::hasTable('household_environmental_profiles'));

        app(HouseholdEnvironmentalProfileService::class)->saveStep1($household, [
            'water_supply_status' => 'level_ii',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
        ]);

        $this->assertFalse(Schema::hasTable('household_environmental_profiles'));
        $this->assertSame(1, DB::table('environmental_sanitation')->count());
    }

    public function test_erd_step1_post_redirects_to_step2_without_legacy_table(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHouseholdForStep1($household);

        $this->post(
            route('environmental-health.household-water-supply.store'),
            $this->validStep1Payload('HH-005', [
                'water_supply_status' => 'level_ii',
                'water_availability' => 'yes',
            ])
        )->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => 'HH-005',
        ]));
    }

    public function test_erd_step1_post_does_not_create_extra_row_for_existing_household(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHouseholdForStep1($household);

        $before = DB::table('environmental_sanitation')->count();

        $this->post(
            route('environmental-health.household-water-supply.store'),
            $this->validStep1Payload('HH-005')
        )->assertRedirect();

        $this->assertSame($before, DB::table('environmental_sanitation')->count());
    }

    public function test_erd_step1_does_not_insert_when_later_required_columns_are_missing(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-007',
            'zone' => 'Zone 2',
            'street' => 'Dalipay St.',
            'date_registered' => '2026-01-16',
            'latitude' => null,
            'longitude' => null,
        ]);
        $this->linkHouseholdForStep1($household);

        $presentation = app(HouseholdEnvironmentalProfileService::class)->saveStep1($household, [
            'water_supply_status' => 'level_ii',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
        ]);

        $this->assertSame(0, DB::table('environmental_sanitation')->count());
        $this->assertSame('session', $presentation['source']);
        $this->assertSame('level_ii', $presentation['water_supply_status']);
        $this->assertSame('yes', $presentation['water_source_location']);
        $this->assertSame('yes', $presentation['water_availability']);
        $this->assertSame(1, (int) $presentation['step']);
        $this->assertNotSame('Community faucet', $presentation['water_source_location']);
    }

    public function test_erd_step1_unrelated_household_row_is_not_updated(): void
    {
        $target = $this->seedHouseholdWithSanitation('HH-005');
        $other = $this->seedHouseholdWithSanitation('HH-006', [
            'water_supply_status' => 'Level I',
            'water_source_location' => 'Private well',
            'water_availability' => 0,
        ]);
        $this->linkHouseholdForStep1($target);

        app(HouseholdEnvironmentalProfileService::class)->saveStep1($target, [
            'water_supply_status' => 'level_iii',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
        ]);

        $otherRow = DB::table('environmental_sanitation')
            ->where('household_id', $other->getKey())
            ->first();

        $this->assertSame('Level I', $otherRow->water_supply_status);
        $this->assertSame('Private well', $otherRow->water_source_location);
        $this->assertSame(0, (int) $otherRow->water_availability);
    }

    public function test_legacy_mode_still_writes_household_environmental_profiles(): void
    {
        Schema::dropIfExists('environmental_sanitation');
        EnvironmentalSanitationErdMode::resetCachedState();

        Schema::create('household_environmental_profiles', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->string('household_type', 50)->nullable();
            $table->string('water_supply_status', 32)->nullable();
            $table->string('specify_water_source', 255)->nullable();
            $table->string('water_source_location', 8)->nullable();
            $table->string('water_availability', 8)->nullable();
            $table->string('basic_safe_water_status', 32)->nullable();
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
            'household_no' => 'HH-801',
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => null,
            'longitude' => null,
        ]);

        app(HouseholdEnvironmentalProfileService::class)->saveStep1($household, [
            'water_supply_status' => 'level_ii',
            'water_source_location' => 'yes',
            'water_availability' => 'no',
        ]);

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->getKey(),
            'water_supply_status' => 'level_ii',
            'water_source_location' => 'yes',
            'water_availability' => 'no',
        ]);
        $this->assertSame(1, HouseholdEnvironmentalProfile::query()->count());
    }

    public function test_should_preserve_descriptive_water_source_location_helper(): void
    {
        $this->assertTrue(
            EnvironmentalSanitationWriteService::shouldPreserveWaterSourceLocation('Community faucet')
        );
        $this->assertFalse(
            EnvironmentalSanitationWriteService::shouldPreserveWaterSourceLocation('yes')
        );
        $this->assertFalse(
            EnvironmentalSanitationWriteService::shouldPreserveWaterSourceLocation('')
        );
    }

    public function test_erd_step1_does_not_require_or_write_household_type_column(): void
    {
        $this->assertFalse(Schema::hasColumn('environmental_sanitation', 'household_type'));

        $household = $this->seedHouseholdWithSanitation('HH-005');
        if (Schema::hasColumn('households', 'household_type')) {
            DB::table('households')->where($household->getKeyName(), $household->getKey())->update([
                'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
            ]);
        }
        $this->linkHouseholdForStep1($household);

        $before = DB::table('environmental_sanitation')->first();
        $this->assertNotNull($before);
        $this->assertFalse(isset($before->household_type));

        $this->post(
            route('environmental-health.household-water-supply.store'),
            $this->validStep1Payload('HH-005', [
                'water_supply_status' => 'level_i',
                'water_availability' => 'no',
            ])
        )->assertRedirect();

        $this->assertFalse(Schema::hasColumn('environmental_sanitation', 'household_type'));
        $this->assertSame(1, DB::table('environmental_sanitation')->count());
        $row = DB::table('environmental_sanitation')->first();
        $this->assertSame('Level I', (string) $row->water_supply_status);
        $this->assertSame(0, (int) $row->water_availability);
        $this->assertFalse(isset($row->household_type));
        $this->assertFalse(Schema::hasTable('household_environmental_profiles'));
    }
}
