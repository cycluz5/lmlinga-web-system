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
 * Environmental Health Step 2 — ERD environmental_sanitation write persistence.
 */
class EnvironmentalHealthStep2ErdWriteTest extends TestCase
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
            'microbiological_validation_date' => '2026-01-06',
            'microbio_result' => 'Passed',
            'physico_chem_test_date' => '2026-01-06',
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

    private function linkHousehold(Household $household): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        DemoHouseholdWaterSupply::linkFromHousehold($household);
    }

    /**
     * @return array<string, mixed>
     */
    private function validStep2Payload(array $overrides = []): array
    {
        return array_merge([
            'household_no' => 'HH-005',
            'microbiological_test_date' => '2026-06-01',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-06-01',
            'physicochemical_result' => 'passed',
        ], $overrides);
    }

    public function test_erd_step2_update_maps_dates_and_passed_results(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $presentation = app(HouseholdEnvironmentalProfileService::class)->saveStep2($household, [
            'microbiological_test_date' => '2026-06-01',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-06-01',
            'physicochemical_result' => 'passed',
        ]);

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertSame('2026-06-01', $row->microbiological_validation_date);
        $this->assertSame('Passed', $row->microbio_result);
        $this->assertSame('2026-06-01', $row->physico_chem_test_date);
        $this->assertSame('Passed', $row->physico_chem_result);
        $this->assertSame('passed', $presentation['microbiological_result']);
        $this->assertSame('passed', $presentation['physicochemical_result']);
        $this->assertSame('erd', $presentation['source']);
    }

    public function test_erd_step2_failed_result_maps_to_erd_enum(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        app(HouseholdEnvironmentalProfileService::class)->saveStep2($household, [
            'microbiological_test_date' => '2026-07-10',
            'microbiological_result' => 'failed',
            'physicochemical_test_date' => '2026-07-11',
            'physicochemical_result' => 'failed',
        ]);

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertSame('Failed', $row->microbio_result);
        $this->assertSame('Failed', $row->physico_chem_result);
    }

    public function test_erd_step2_preserves_unrelated_columns(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        app(HouseholdEnvironmentalProfileService::class)->saveStep2($household, [
            'microbiological_test_date' => '2026-06-01',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-06-01',
            'physicochemical_result' => 'passed',
        ]);

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertSame('Level II', $row->water_supply_status);
        $this->assertSame('Community faucet', $row->water_source_location);
        $this->assertSame(1, (int) $row->water_availability);
        $this->assertSame(18, (int) $row->user_id);
        $this->assertSame('water_sealed', $row->toilet_type);
        $this->assertSame('On-site Disposed', $row->sewage_disposal_method);
    }

    public function test_erd_step2_blank_submission_preserves_existing_not_null_dates(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        app(HouseholdEnvironmentalProfileService::class)->saveStep2($household, [
            'microbiological_test_date' => null,
            'microbiological_result' => null,
            'physicochemical_test_date' => null,
            'physicochemical_result' => null,
        ]);

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertSame('2026-01-06', $row->microbiological_validation_date);
        $this->assertSame('Passed', $row->microbio_result);
        $this->assertSame('2026-01-06', $row->physico_chem_test_date);
        $this->assertSame('Passed', $row->physico_chem_result);
    }

    public function test_erd_step2_does_not_create_extra_row(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $before = DB::table('environmental_sanitation')->count();

        app(HouseholdEnvironmentalProfileService::class)->saveStep2($household, [
            'microbiological_test_date' => '2026-06-01',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-06-01',
            'physicochemical_result' => 'passed',
        ]);

        $this->assertSame($before, DB::table('environmental_sanitation')->count());
    }

    public function test_erd_step2_unrelated_household_row_is_not_updated(): void
    {
        $target = $this->seedHouseholdWithSanitation('HH-005');
        $other = $this->seedHouseholdWithSanitation('HH-006', [
            'microbiological_validation_date' => '2026-02-01',
            'microbio_result' => 'Failed',
            'physico_chem_test_date' => '2026-02-02',
            'physico_chem_result' => 'Failed',
        ]);
        $this->linkHousehold($target);

        app(HouseholdEnvironmentalProfileService::class)->saveStep2($target, [
            'microbiological_test_date' => '2026-08-01',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-08-02',
            'physicochemical_result' => 'passed',
        ]);

        $otherRow = DB::table('environmental_sanitation')
            ->where('household_id', $other->getKey())
            ->first();

        $this->assertSame('2026-02-01', $otherRow->microbiological_validation_date);
        $this->assertSame('Failed', $otherRow->microbio_result);
        $this->assertSame('2026-02-02', $otherRow->physico_chem_test_date);
        $this->assertSame('Failed', $otherRow->physico_chem_result);
    }

    public function test_erd_step2_post_redirects_to_step3_without_legacy_table(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $this->post(
            route('environmental-health.household-water-supply.step2.store', ['householdNo' => 'HH-005']),
            $this->validStep2Payload()
        )->assertRedirect(route('environmental-health.household-water-supply.step3', [
            'householdNo' => 'HH-005',
        ]));
    }

    public function test_erd_step2_get_hydrates_saved_values_after_write(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        app(HouseholdEnvironmentalProfileService::class)->saveStep2($household, [
            'microbiological_test_date' => '2026-06-01',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-06-01',
            'physicochemical_result' => 'passed',
        ]);

        $html = $this->get(route('environmental-health.household-water-supply.step2', [
            'householdNo' => 'HH-005',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('2026-06-01', $html);
        $this->assertMatchesRegularExpression(
            '/name="microbiological_result"\s+value="passed"[^>]*checked|value="passed"[^>]*name="microbiological_result"[^>]*checked/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="physicochemical_result"\s+value="passed"[^>]*checked|value="passed"[^>]*name="physicochemical_result"[^>]*checked/',
            $html
        );
    }

    public function test_erd_step2_stashes_values_when_no_existing_row(): void
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

        $presentation = app(HouseholdEnvironmentalProfileService::class)->saveStep2($household, [
            'microbiological_test_date' => '2026-06-01',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-06-01',
            'physicochemical_result' => 'passed',
        ]);

        $this->assertSame(0, DB::table('environmental_sanitation')->count());
        $this->assertSame('session', $presentation['source']);
        $this->assertSame('2026-06-01', $presentation['microbiological_test_date']);
        $this->assertSame('passed', $presentation['microbiological_result']);
    }

    public function test_erd_step2_does_not_query_legacy_profiles_table(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHousehold($household);

        $this->assertFalse(Schema::hasTable('household_environmental_profiles'));

        app(HouseholdEnvironmentalProfileService::class)->saveStep2($household, [
            'microbiological_test_date' => '2026-06-01',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-06-01',
            'physicochemical_result' => 'passed',
        ]);

        $this->assertFalse(Schema::hasTable('household_environmental_profiles'));
    }

    public function test_legacy_mode_still_writes_household_environmental_profiles_step2(): void
    {
        Schema::dropIfExists('environmental_sanitation');
        EnvironmentalSanitationErdMode::resetCachedState();

        Schema::create('household_environmental_profiles', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->date('microbiological_test_date')->nullable();
            $table->string('microbiological_result', 16)->nullable();
            $table->date('physicochemical_test_date')->nullable();
            $table->string('physicochemical_result', 16)->nullable();
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
            'household_no' => 'HH-802',
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => null,
            'longitude' => null,
        ]);

        app(HouseholdEnvironmentalProfileService::class)->saveStep2($household, [
            'microbiological_test_date' => '2026-07-15',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-07-16',
            'physicochemical_result' => 'failed',
        ]);

        $profile = HouseholdEnvironmentalProfile::query()
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertNotNull($profile);
        $this->assertSame('2026-07-15', $profile->microbiological_test_date?->format('Y-m-d'));
        $this->assertSame('passed', $profile->microbiological_result);
        $this->assertSame('2026-07-16', $profile->physicochemical_test_date?->format('Y-m-d'));
        $this->assertSame('failed', $profile->physicochemical_result);
        $this->assertSame(2, (int) $profile->completed_step);
        $this->assertSame(1, HouseholdEnvironmentalProfile::query()->count());
    }
}
