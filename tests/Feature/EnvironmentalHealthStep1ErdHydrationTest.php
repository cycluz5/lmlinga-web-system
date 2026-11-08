<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Services\HouseholdEnvironmentalProfileService;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\EnvironmentalSanitationErdMode;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Environmental Health Step 1 — ERD environmental_sanitation hydration (read-only GET).
 */
class EnvironmentalHealthStep1ErdHydrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provisionErdEnvironmentalSanitationTable();
        $this->actingAsStaff(StaffRole::BHW);
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
            $table->timestamps();
        });

        EnvironmentalSanitationErdMode::resetCachedState();
    }

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
            'created_at' => now(),
            'updated_at' => now(),
        ], $sanitationOverrides));

        return $household->fresh();
    }

    private function linkHouseholdForStep1(Household $household): void
    {
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        DemoHouseholdWaterSupply::linkFromHousehold($household);
    }

    public function test_existing_environmental_sanitation_record_loads_for_linked_household(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHouseholdForStep1($household);

        $before = DB::table('environmental_sanitation')->count();

        $presentation = app(HouseholdEnvironmentalProfileService::class)->findPresentation($household);

        $this->assertSame($before, DB::table('environmental_sanitation')->count());
        $this->assertNotNull($presentation);
        $this->assertSame('level_ii', $presentation['water_supply_status']);
        $this->assertSame('yes', $presentation['water_availability']);
        $this->assertSame('erd', $presentation['source']);
    }

    public function test_step1_get_hydrates_level_ii_and_water_availability_yes(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHouseholdForStep1($household);

        $html = $this->get(route('environmental-health.household-water-supply', [
            'household' => 'HH-005',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Household Water Supply Information', $html);
        $this->assertMatchesRegularExpression(
            '/name="water_supply_status"\s+value="level_ii"[^>]*checked|value="level_ii"[^>]*name="water_supply_status"[^>]*checked/',
            $html
        );
        $this->assertStringContainsString('With Basic Safe Water', $html);
        $this->assertMatchesRegularExpression(
            '/name="water_availability"\s+value="yes"[^>]*checked|value="yes"[^>]*name="water_availability"[^>]*checked/',
            $html
        );
    }

    public function test_erd_water_source_location_free_text_does_not_hydrate_yes_no_field(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHouseholdForStep1($household);

        $html = $this->get(route('environmental-health.household-water-supply', [
            'household' => 'HH-005',
        ]))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/name="water_source_location"\s+value="yes"[^>]*checked|value="yes"[^>]*name="water_source_location"[^>]*checked/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/name="water_source_location"\s+value="no"[^>]*checked|value="no"[^>]*name="water_source_location"[^>]*checked/',
            $html
        );
    }

    public function test_unrelated_household_does_not_load_hh_005_sanitation_values(): void
    {
        $this->seedHouseholdWithSanitation('HH-005');

        $other = Household::factory()->create([
            'household_no' => 'HH-006',
            'zone' => 'Zone 2',
            'street' => 'Dalipay St.',
            'date_registered' => '2026-01-16',
            'latitude' => null,
            'longitude' => null,
        ]);
        $this->linkHouseholdForStep1($other);

        $presentation = app(HouseholdEnvironmentalProfileService::class)->findPresentation($other);
        $this->assertNull($presentation);
    }

    public function test_step1_get_remains_successful_with_production_frozen_markup(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->linkHouseholdForStep1($household);

        $response = $this->get(route('environmental-health.household-water-supply', [
            'household' => 'HH-005',
        ]));

        $response->assertOk();
        $response->assertSee('data-lml-hws', false);
        $response->assertSee('data-hws-level-group', false);
        $response->assertSee('Water Source Location', false);
        $response->assertSee('Water Availability', false);
        $response->assertSee('data-hws-next', false);
    }
}
