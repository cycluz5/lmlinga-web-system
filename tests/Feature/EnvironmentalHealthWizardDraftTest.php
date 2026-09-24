<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\EnvironmentalSanitationErdMode;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EnvironmentalHealthWizardDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->actingAsStaff(StaffRole::BHW);
    }

    public function test_step1_post_without_existing_row_reaches_step2_and_keeps_values(): void
    {
        $household = $this->seedPlottedHousehold('HH-009');
        DemoHouseholdWaterSupply::linkFromHousehold($household);

        $this->post(route('environmental-health.household-water-supply.store'), [
            'household_no' => 'HH-009',
            'water_supply_status' => 'level_ii',
            'specify_water_source' => null,
            'water_source_location' => 'yes',
            'water_availability' => 'no',
        ])->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => 'HH-009',
        ]));

        $this->assertSame(0, DB::table('environmental_sanitation')->count());

        $html = $this->get(route('environmental-health.household-water-supply.step2', [
            'householdNo' => 'HH-009',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('HH-009', $html);
        $this->assertSame('level_ii', session(DemoHouseholdWaterSupply::SESSION_KEY)['HH-009']['water_supply_status'] ?? null);
        $this->assertSame('yes', session(DemoHouseholdWaterSupply::SESSION_KEY)['HH-009']['water_source_location'] ?? null);
        $this->assertSame('no', session(DemoHouseholdWaterSupply::SESSION_KEY)['HH-009']['water_availability'] ?? null);
    }

    public function test_full_wizard_inserts_collected_values_once_and_updates_existing_row(): void
    {
        $household = $this->seedPlottedHousehold('HH-009');
        DemoHouseholdWaterSupply::linkFromHousehold($household);

        $this->post(route('environmental-health.household-water-supply.store'), [
            'household_no' => 'HH-009',
            'water_supply_status' => 'level_i',
            'water_source_location' => 'no',
            'water_availability' => 'yes',
        ])->assertRedirect();

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => 'HH-009',
        ]), [
            'microbiological_test_date' => '2026-03-01',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-03-02',
            'physicochemical_result' => 'failed',
        ])->assertRedirect();

        $this->post(route('environmental-health.household-water-supply.step3.store', [
            'householdNo' => 'HH-009',
        ]), [
            'household_no' => 'HH-009',
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'on_site_safely_managed',
        ])->assertRedirect();

        $this->assertSame(0, DB::table('environmental_sanitation')->count());

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => 'HH-009',
        ]), [
            'household_no' => 'HH-009',
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
            ],
        ])->assertRedirect(route('spot-mapping.index'));

        $spotHtml = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $this->assertStringContainsString(
            'Household plotted successfully with environmental health information saved.',
            $spotHtml
        );
        $this->assertStringContainsString('lml-spot-map__server-alert--success', $spotHtml);

        $this->assertSame(1, DB::table('environmental_sanitation')->count());

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Level I', $row->water_supply_status);
        $this->assertSame('no', $row->water_source_location);
        $this->assertSame(1, (int) $row->water_availability);
        $this->assertSame('2026-03-01', $row->microbiological_validation_date);
        $this->assertSame('Passed', $row->microbio_result);
        $this->assertSame('2026-03-02', $row->physico_chem_test_date);
        $this->assertSame('Failed', $row->physico_chem_result);
        $this->assertSame('pour_flush_with_septic_tank', $row->toilet_type);
        $this->assertSame('On-site Disposed', $row->sewage_disposal_method);

        $this->post(route('environmental-health.household-water-supply.store'), [
            'household_no' => 'HH-009',
            'water_supply_status' => 'level_iii',
            'water_source_location' => 'yes',
            'water_availability' => 'no',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('environmental_sanitation')->count());
        $updated = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();
        $this->assertSame('Level III', $updated->water_supply_status);
        $this->assertSame(0, (int) $updated->water_availability);
        $this->assertSame('no', $updated->water_source_location);
        $this->assertSame('2026-03-01', $updated->microbiological_validation_date);
    }

    public function test_wizard_has_no_skip_control_and_does_not_insert_until_complete(): void
    {
        $household = $this->seedPlottedHousehold('HH-009');
        DemoHouseholdWaterSupply::linkFromHousehold($household);

        $html = $this->get(route('environmental-health.household-water-supply', [
            'household' => 'HH-009',
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Skip for now', $html);
        $this->assertStringNotContainsString('data-hws-skip', $html);
        $this->assertStringContainsString('data-hws-next', $html);
        $this->assertStringNotContainsString('data-hws-previous', $html);

        $this->assertTrue(Household::query()->where('household_no', 'HH-009')->exists());
        $this->assertSame(0, DB::table('environmental_sanitation')->count());
        $this->assertSame(13.3811, (float) $household->fresh()->latitude);
    }

    private function seedPlottedHousehold(string $householdNo): Household
    {
        return Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => 13.3811,
            'longitude' => 123.4306,
        ]);
    }
}
