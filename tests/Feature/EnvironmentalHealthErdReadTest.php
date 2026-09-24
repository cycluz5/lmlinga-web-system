<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\EnvironmentalHealthDashboard;
use App\Support\EnvironmentalSanitationErdMode;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EnvironmentalHealthErdReadTest extends TestCase
{
    use RefreshDatabase;

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
            $table->date('microbiological_validation_date')->nullable();
            $table->string('microbio_result')->nullable();
            $table->date('physico_chem_test_date')->nullable();
            $table->string('physico_chem_result')->nullable();
            $table->string('toilet_type')->nullable();
            $table->unsignedTinyInteger('open_defecation_place')->nullable();
            $table->unsignedTinyInteger('shared_toilet')->nullable();
            $table->string('sewage_disposal_method')->nullable();
            $table->timestamps();
        });

        EnvironmentalSanitationErdMode::resetCachedState();
    }

    public function test_erd_mode_active_when_environmental_sanitation_exists_without_legacy_profiles(): void
    {
        $this->provisionErdEnvironmentalSanitationTable();

        $this->assertTrue(EnvironmentalSanitationErdMode::isActive());
    }

    public function test_environmental_health_index_renders_without_legacy_profiles_table(): void
    {
        $this->provisionErdEnvironmentalSanitationTable();

        $household = Household::factory()->create([
            'household_no' => 'HH-801',
            'zone' => 'Zone 2',
            'street' => 'ERD EH St.',
        ]);

        DB::table('environmental_sanitation')->insert([
            'household_id' => $household->getKey(),
            'user_id' => null,
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
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $this->get(route('environmental-health.index'))
            ->assertOk()
            ->assertSee('HH-801', false)
            ->assertSee('Level II', false);
    }

    public function test_authoritative_environmental_sanitation_values_map_to_dashboard_row(): void
    {
        $this->provisionErdEnvironmentalSanitationTable();

        $household = Household::factory()->create([
            'household_no' => 'HH-802',
            'zone' => 'Zone 3',
            'street' => 'Mapped St.',
        ]);

        DB::table('environmental_sanitation')->insert([
            'household_id' => $household->getKey(),
            'water_supply_status' => 'Level I',
            'water_source_location' => 'Deep well',
            'water_availability' => 1,
            'toilet_type' => 'open_pit_latrine',
            'sewage_disposal_method' => 'Off-site Disposed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = EnvironmentalHealthDashboard::rows();
        $row = collect($rows)->firstWhere('household_no', 'HH-802');

        $this->assertNotNull($row);
        $this->assertSame(DemoHouseholdWaterSupply::WATER_LEVEL_I, $row['water_supply_status']);
        $this->assertSame('open_pit_latrine', $row['toilet_type']);
        $this->assertSame(DemoHouseholdWaterSupply::TOILET_STATUS_UNSANITARY, $row['toilet_status']);
        $this->assertSame('with_toilet', $row['toilet_presence']);
        $this->assertSame(EnvironmentalHealthDashboard::RECORD_STATUS_COMPLETED, $row['record_status']);
        $this->assertSame('not_yet_determined', $row['solid_waste_status']);
        $this->assertSame('Zone 3', $row['zone']);
    }

    public function test_household_without_environmental_sanitation_row_stays_pending_without_fabrication(): void
    {
        $this->provisionErdEnvironmentalSanitationTable();

        Household::factory()->create([
            'household_no' => 'HH-803',
            'zone' => 'Zone 1',
            'street' => 'Empty EH St.',
        ]);

        $row = collect(EnvironmentalHealthDashboard::rows())->firstWhere('household_no', 'HH-803');

        $this->assertNotNull($row);
        $this->assertSame('', $row['water_supply_status']);
        $this->assertSame(EnvironmentalHealthDashboard::RECORD_STATUS_PENDING, $row['record_status']);
        $this->assertSame('not_yet_determined', $row['solid_waste_status']);
    }

    public function test_legacy_household_environmental_profiles_behavior_remains_supported(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-804',
            'zone' => 'Zone 1',
            'street' => 'Legacy EH St.',
        ]);

        $profile = \App\Models\HouseholdEnvironmentalProfile::query()->create([
            'household_id' => $household->id,
            'water_supply_status' => DemoHouseholdWaterSupply::WATER_LEVEL_III,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'completed_step' => 4,
        ]);

        $this->assertFalse(EnvironmentalSanitationErdMode::isActive());

        $row = collect(EnvironmentalHealthDashboard::rows())->firstWhere('household_no', 'HH-804');

        $this->assertNotNull($row);
        $this->assertSame(DemoHouseholdWaterSupply::WATER_LEVEL_III, $row['water_supply_status']);
        $this->assertSame(EnvironmentalHealthDashboard::RECORD_STATUS_COMPLETED, $row['record_status']);
        $this->assertSame($profile->id, \App\Models\HouseholdEnvironmentalProfile::query()->value('id'));
    }

    public function test_dashboard_does_not_assume_households_zone_when_purok_is_authoritative(): void
    {
        $this->provisionErdEnvironmentalSanitationTable();

        Schema::dropIfExists('households');
        Schema::create('households', function ($table): void {
            $table->id('household_id');
            $table->string('household_no')->unique();
            $table->string('purok');
            $table->string('street')->nullable();
            $table->date('date_registered');
            $table->timestamps();
        });

        $householdId = DB::table('households')->insertGetId([
            'household_no' => 'HH-805',
            'purok' => 'Zone 4',
            'street' => 'Purok Only St.',
            'date_registered' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('environmental_sanitation')->insert([
            'household_id' => $householdId,
            'water_supply_status' => 'Level III',
            'water_source_location' => 'Piped',
            'water_availability' => 1,
            'toilet_type' => 'ventilated_improved_pit_latrine',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = collect(EnvironmentalHealthDashboard::rows())->firstWhere('household_no', 'HH-805');

        $this->assertNotNull($row);
        $this->assertSame('Zone 4', $row['zone']);
    }
}
