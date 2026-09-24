<?php

namespace Tests\Feature\Offline;

use App\Models\DewormingRecord;
use App\Models\Household;
use App\Models\HouseholdEnvironmentalProfile;
use App\Models\Resident;
use App\Support\Offline\OfflineFieldHasher;
use App\Support\Offline\OfflineOperationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class OfflineSyncAmenitiesEnvironmentalHealthTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function amenitiesPayload(string $householdNo, array $overrides = []): array
    {
        return array_merge([
            'household_no' => $householdNo,
            'water_supply_status' => 'level_i',
            'specify_water_source' => null,
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
            'microbiological_test_date' => null,
            'microbiological_result' => null,
            'physicochemical_test_date' => null,
            'physicochemical_result' => null,
            'toilet_type' => 'open_pit_latrine',
            'open_defecation_practiced' => 'yes',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'off_site_collected_and_treated',
            'solid_waste_practices' => ['waste_segregation'],
        ], $overrides);
    }

    public function test_amenities_update_persists_through_offline_sync(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create(['household_no' => 'HH-155']);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_AMENITIES_UPDATE,
            $this->amenitiesPayload('HH-155', [
                'water_supply_status' => 'others',
                'specify_water_source' => 'Open well',
            ]),
            ['parent_server' => ['household_id' => $household->getKey(), 'household_no' => 'HH-155']],
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->getKey(),
            'water_supply_status' => 'others',
            'specify_water_source' => 'Open well',
        ]);
    }

    public function test_amenities_stale_hash_is_rejected(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create(['household_no' => 'HH-156']);
        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_AMENITIES_UPDATE,
            $this->amenitiesPayload('HH-156'),
            ['parent_server' => ['household_id' => $household->getKey()]],
        ))->assertOk();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_AMENITIES_UPDATE,
            $this->amenitiesPayload('HH-156', ['water_supply_status' => 'level_ii']),
            [
                'parent_server' => ['household_id' => $household->getKey()],
                'base_snapshot' => ['field_hash' => 'stale-hash'],
            ],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'TARGET_CHANGED');
        $this->assertSame(
            'level_i',
            HouseholdEnvironmentalProfile::query()->where('household_id', $household->getKey())->value('water_supply_status')
        );
    }

    public function test_environmental_step1_queues_through_existing_sync(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create(['household_no' => 'HH-157']);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
            [
                'household_no' => 'HH-157',
                'water_supply_status' => 'level_ii',
                'specify_water_source' => null,
                'water_source_location' => 'yes',
                'water_availability' => 'yes',
                '_eh_step' => 1,
            ],
            ['parent_server' => ['household_id' => $household->getKey(), 'household_no' => 'HH-157']],
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->getKey(),
            'water_supply_status' => 'level_ii',
        ]);
    }

    public function test_matching_environmental_hash_allows_step_update(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create(['household_no' => 'HH-158']);
        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
            [
                'household_no' => 'HH-158',
                'water_supply_status' => 'level_i',
                'water_source_location' => 'yes',
                'water_availability' => 'yes',
                '_eh_step' => 1,
            ],
            ['parent_server' => ['household_id' => $household->getKey()]],
        ))->assertOk();

        $hash = OfflineFieldHasher::environmental($household->fresh());
        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
            [
                'household_no' => 'HH-158',
                'water_supply_status' => 'level_iii',
                'water_source_location' => 'no',
                'water_availability' => 'yes',
                '_eh_step' => 1,
            ],
            [
                'parent_server' => ['household_id' => $household->getKey()],
                'base_snapshot' => ['field_hash' => $hash],
            ],
        ));

        $response->assertOk();
        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->getKey(),
            'water_supply_status' => 'level_iii',
        ]);
    }
}
