<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\EnvironmentalSanitationErdMode;
use App\Support\Offline\OfflineFieldHasher;
use App\Support\Offline\OfflineOperationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class OfflineSyncErdAmenitiesUpdateTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provisionErdAmenitiesTables();
    }

    public function test_offline_amenities_update_persists_through_erd_save_all(): void
    {
        $this->actingAsFieldStaff();

        $household = Household::factory()->create([
            'household_no' => 'HH-155',
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
        ]);

        $envAssessmentId = DB::table('environmental_sanitation')->insertGetId([
            'household_id' => $household->getKey(),
            'user_id' => 18,
            'water_supply_status' => 'Level I',
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
        ], 'env_assessment_id');

        DB::table('waste_management_practices')->insert([
            'env_assessment_id' => $envAssessmentId,
            'waste_segregation' => 1,
            'backyard_composting' => 0,
            'recycling_reuse' => 0,
            'collected_by_municipality' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(Schema::hasTable('household_environmental_profiles'));

        $hash = OfflineFieldHasher::environmental($household->fresh());

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_AMENITIES_UPDATE,
            [
                'household_no' => 'HH-155',
                'water_supply_status' => 'level_ii',
                'specify_water_source' => 'Open well',
                'water_source_location' => 'yes',
                'water_availability' => 'no',
                'microbiological_test_date' => '2026-08-01',
                'microbiological_result' => 'failed',
                'physicochemical_test_date' => '2026-08-02',
                'physicochemical_result' => 'passed',
                'toilet_type' => 'open_pit_latrine',
                'open_defecation_practiced' => 'yes',
                'shared_toilet' => 'no',
                'sewage_disposal_method' => DemoHouseholdWaterSupply::SEWAGE_OFF_SITE,
                'solid_waste_practices' => [
                    DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION,
                ],
            ],
            [
                'parent_server' => ['household_id' => $household->getKey(), 'household_no' => 'HH-155'],
                'base_snapshot' => ['field_hash' => $hash],
            ],
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $this->assertFalse(Schema::hasTable('household_environmental_profiles'));
        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();
        $this->assertSame('Level II', (string) $row->water_supply_status);
        $this->assertSame(0, (int) $row->water_availability);
        $this->assertSame('Community faucet', (string) $row->water_source_location);
        $this->assertSame('Failed', (string) $row->microbio_result);
        $this->assertSame('open_pit_latrine', (string) $row->toilet_type);
        $this->assertSame('Off-site Disposed', (string) $row->sewage_disposal_method);
        $this->assertFalse(isset($row->household_type));

        $waste = DB::table('waste_management_practices')
            ->where('env_assessment_id', $envAssessmentId)
            ->first();
        $this->assertSame(0, (int) $waste->waste_segregation);
        $this->assertSame(1, (int) $waste->collected_by_municipality);
    }

    private function provisionErdAmenitiesTables(): void
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
}
