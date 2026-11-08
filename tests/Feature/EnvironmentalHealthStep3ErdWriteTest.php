<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\HouseholdEnvironmentalProfile;
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
 * Environmental Health Step 3 — ERD environmental_sanitation write persistence.
 */
class EnvironmentalHealthStep3ErdWriteTest extends TestCase
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
        ], $sanitationOverrides));

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
    private function validStep3Payload(string $householdNo, array $overrides = []): array
    {
        return array_merge([
            'household_no' => $householdNo,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'on_site_safely_managed',
        ], $overrides);
    }

    public function test_erd_step3_update_maps_all_step3_columns(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $presentation = app(HouseholdEnvironmentalProfileService::class)->saveStep3($household, [
            'toilet_type' => 'ventilated_improved_pit_latrine',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'yes',
            'sewage_disposal_method' => 'off_site_collected_and_treated',
        ]);

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertSame('ventilated_improved_pit_latrine', $row->toilet_type);
        $this->assertSame(0, (int) $row->open_defecation_place);
        $this->assertSame(1, (int) $row->shared_toilet);
        $this->assertSame('Off-site Disposed', $row->sewage_disposal_method);
        $this->assertSame('ventilated_improved_pit_latrine', $presentation['toilet_type']);
        $this->assertSame('no', $presentation['open_defecation_practiced']);
        $this->assertSame('yes', $presentation['shared_toilet']);
        $this->assertSame('off_site_collected_and_treated', $presentation['sewage_disposal_method']);
        $this->assertSame('erd', $presentation['source']);
    }

    public function test_erd_step3_preserves_step1_and_step2_columns(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        app(HouseholdEnvironmentalProfileService::class)->saveStep3($household, [
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'on_site_safely_managed',
        ]);

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertSame('Level II', $row->water_supply_status);
        $this->assertSame('Community faucet', $row->water_source_location);
        $this->assertSame(1, (int) $row->water_availability);
        $this->assertSame(18, (int) $row->user_id);
        $this->assertSame('2026-06-01', $row->microbiological_validation_date);
        $this->assertSame('Passed', $row->microbio_result);
        $this->assertSame('2026-06-01', $row->physico_chem_test_date);
        $this->assertSame('Passed', $row->physico_chem_result);
    }

    public function test_erd_step3_blank_sewage_with_toilet_persists_null(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $presentation = app(HouseholdEnvironmentalProfileService::class)->saveStep3($household, [
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => '',
        ]);

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertNull($row->sewage_disposal_method);
        $this->assertSame('pour_flush_with_septic_tank', $row->toilet_type);
        $this->assertSame(0, (int) $row->shared_toilet);
        $this->assertNull($presentation['sewage_disposal_method']);
        $this->assertSame(
            DemoHouseholdWaterSupply::MANAGEMENT_STATUS_PENDING,
            $presentation['management_status']
        );
    }

    public function test_erd_step3_omitted_sewage_post_persists_null_without_exception(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $payload = $this->validStep3Payload('HH-005');
        unset($payload['sewage_disposal_method']);

        $this->post(
            route('environmental-health.household-water-supply.step3.store', ['householdNo' => 'HH-005']),
            $payload
        )->assertRedirect(route('environmental-health.household-water-supply.step4', [
            'householdNo' => 'HH-005',
        ]))->assertSessionDoesntHaveErrors();

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertNull($row->sewage_disposal_method);
        $this->assertSame('pour_flush_with_septic_tank', $row->toilet_type);
        $this->assertSame(0, (int) $row->shared_toilet);
    }

    public function test_erd_step3_invalid_sewage_is_rejected_without_writing(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $this->postJson(
            route('environmental-health.household-water-supply.step3.store', ['householdNo' => 'HH-005']),
            $this->validStep3Payload('HH-005', [
                'sewage_disposal_method' => 'not_a_real_sewage_method',
            ])
        )->assertStatus(422)->assertJsonValidationErrors(['sewage_disposal_method']);

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertSame('On-site Disposed', $row->sewage_disposal_method);
        $this->assertSame('pour_flush_with_septic_tank', $row->toilet_type);
    }

    public function test_erd_step3_invalid_non_empty_sewage_still_throws_on_direct_write(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid sewage_disposal_method for ERD write.');

        app(HouseholdEnvironmentalProfileService::class)->saveStep3($household, [
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'not_a_real_sewage_method',
        ]);
    }

    public function test_erd_step3_without_toilet_clears_sewage_and_forces_shared_no(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        app(HouseholdEnvironmentalProfileService::class)->saveStep3($household, [
            'toilet_type' => 'without_toilet',
            'open_defecation_practiced' => 'yes',
            'shared_toilet' => 'yes',
            'sewage_disposal_method' => 'on_site_safely_managed',
        ]);

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertSame('without_toilet', $row->toilet_type);
        $this->assertSame(1, (int) $row->open_defecation_place);
        $this->assertSame(0, (int) $row->shared_toilet);
        $this->assertNull($row->sewage_disposal_method);
    }

    public function test_erd_step3_does_not_create_extra_row(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $before = DB::table('environmental_sanitation')->count();

        app(HouseholdEnvironmentalProfileService::class)->saveStep3($household, [
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'on_site_safely_managed',
        ]);

        $this->assertSame($before, DB::table('environmental_sanitation')->count());
    }

    public function test_erd_step3_unrelated_household_row_is_not_updated(): void
    {
        $target = $this->seedHouseholdWithSanitation('HH-005');
        $other = $this->seedHouseholdWithSanitation('HH-006', [
            'toilet_type' => 'open_pit_latrine',
            'open_defecation_place' => 1,
            'shared_toilet' => 1,
            'sewage_disposal_method' => 'Off-site Disposed',
        ]);
        $this->linkHousehold($target);

        app(HouseholdEnvironmentalProfileService::class)->saveStep3($target, [
            'toilet_type' => 'water_sealed_without_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'on_site_safely_managed',
        ]);

        $otherRow = DB::table('environmental_sanitation')
            ->where('household_id', $other->getKey())
            ->first();

        $this->assertSame('open_pit_latrine', $otherRow->toilet_type);
        $this->assertSame(1, (int) $otherRow->open_defecation_place);
        $this->assertSame(1, (int) $otherRow->shared_toilet);
        $this->assertSame('Off-site Disposed', $otherRow->sewage_disposal_method);
    }

    public function test_erd_step3_post_redirects_to_step4_without_legacy_table(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $this->post(
            route('environmental-health.household-water-supply.step3.store', ['householdNo' => 'HH-005']),
            $this->validStep3Payload('HH-005')
        )->assertRedirect(route('environmental-health.household-water-supply.step4', [
            'householdNo' => 'HH-005',
        ]));
    }

    public function test_erd_step3_get_hydrates_saved_values_after_write(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        app(HouseholdEnvironmentalProfileService::class)->saveStep3($household, [
            'toilet_type' => 'pour_flush_connected_to_septic_or_sewer',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'yes',
            'sewage_disposal_method' => 'off_site_collected_and_treated',
        ]);

        $html = $this->get(route('environmental-health.household-water-supply.step3', [
            'householdNo' => 'HH-005',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('pour_flush_connected_to_septic_or_sewer', $html);
        $this->assertMatchesRegularExpression(
            '/name="open_defecation_practiced"\s+value="no"[^>]*checked|value="no"[^>]*name="open_defecation_practiced"[^>]*checked/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="shared_toilet"\s+value="yes"[^>]*checked|value="yes"[^>]*name="shared_toilet"[^>]*checked/',
            $html
        );
        $this->assertStringContainsString('off_site_collected_and_treated', $html);
    }

    public function test_erd_step3_stashes_values_when_no_existing_row(): void
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

        $presentation = app(HouseholdEnvironmentalProfileService::class)->saveStep3($household, [
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'on_site_safely_managed',
        ]);

        $this->assertSame(0, DB::table('environmental_sanitation')->count());
        $this->assertSame('session', $presentation['source']);
        $this->assertSame('pour_flush_with_septic_tank', $presentation['toilet_type']);
    }

    public function test_erd_step3_does_not_query_legacy_profiles_table(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $this->assertFalse(Schema::hasTable('household_environmental_profiles'));

        app(HouseholdEnvironmentalProfileService::class)->saveStep3($household, [
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'on_site_safely_managed',
        ]);

        $this->assertFalse(Schema::hasTable('household_environmental_profiles'));
    }

    public function test_legacy_mode_still_writes_household_environmental_profiles_step3(): void
    {
        Schema::dropIfExists('environmental_sanitation');
        EnvironmentalSanitationErdMode::resetCachedState();

        Schema::create('household_environmental_profiles', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->string('toilet_type', 64)->nullable();
            $table->string('toilet_status', 32)->nullable();
            $table->string('open_defecation_practiced', 8)->nullable();
            $table->string('shared_toilet', 8)->nullable();
            $table->string('sewage_disposal_method', 64)->nullable();
            $table->string('management_status', 32)->nullable();
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
            'household_no' => 'HH-803',
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => null,
            'longitude' => null,
        ]);

        app(HouseholdEnvironmentalProfileService::class)->saveStep3($household, [
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'yes',
            'sewage_disposal_method' => 'on_site_safely_managed',
        ]);

        $profile = HouseholdEnvironmentalProfile::query()
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertNotNull($profile);
        $this->assertSame('pour_flush_with_septic_tank', $profile->toilet_type);
        $this->assertSame('no', $profile->open_defecation_practiced);
        $this->assertSame('yes', $profile->shared_toilet);
        $this->assertSame('on_site_safely_managed', $profile->sewage_disposal_method);
        $this->assertSame(3, (int) $profile->completed_step);
    }
}
