<?php

namespace Tests\Feature\Offline;

use App\Models\ChildImmunization;
use App\Models\ChildNutrition;
use App\Models\DewormingRecord;
use App\Models\Household;
use App\Models\Resident;
use App\Support\Offline\OfflineOperationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class OfflineSyncHealthServiceTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedMember(): array
    {
        $household = Household::factory()->create(['household_no' => 'HH-920']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-920',
            'first_name' => 'Baby',
            'last_name' => 'Care',
            'relation' => 'Son',
            'birthday' => now()->subMonths(8)->format('Y-m-d'),
            'sex' => 'Male',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    public function test_deworming_store_syncs_through_health_service_write(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'deworming_store',
                'year' => 2026,
                'round' => '1',
                'se_status' => 'NHTS',
                'date_given' => '2026-07-01',
                'remarks' => 'Routine dose',
            ],
            ['parent_server' => [
                'household_id' => $household->getKey(),
                'household_no' => 'HH-920',
                'resident_id' => $resident->getKey(),
                'member_no' => 'MB-920',
            ]],
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
        $response->assertJsonPath('health_action', 'deworming_store');
        $this->assertSame(1, DewormingRecord::query()->where('resident_id', $resident->getKey())->count());
    }

    public function test_child_immunization_store_syncs_through_health_service_write(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'child_immunization_store',
                'vaccines' => [
                    'bcg' => [0 => '2025-01-15'],
                ],
                'vaccine_types' => ['bcg'],
                'remarks' => 'Offline dose',
            ],
            ['parent_server' => [
                'resident_id' => $resident->getKey(),
                'household_id' => $household->getKey(),
            ]],
        ));

        $response->assertOk();
        $this->assertTrue(ChildImmunization::query()->where('resident_id', $resident->getKey())->exists());
    }

    public function test_unknown_health_action_is_rejected(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            ['_health_action' => 'death_certificate_store'],
            ['parent_server' => [
                'resident_id' => $resident->getKey(),
                'household_id' => $household->getKey(),
            ]],
        ));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'UNKNOWN_OPERATION');
        $this->assertNoAppliedReceipt();
    }

    public function test_death_certificate_file_field_is_rejected(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'deworming_store',
                'death_certificate' => 'file.bin',
                'year' => 2026,
                'round' => '1',
                'se_status' => 'NHTS',
                'date_given' => '2026-07-01',
            ],
            ['parent_server' => [
                'resident_id' => $resident->getKey(),
                'household_id' => $household->getKey(),
            ]],
        ));

        $response->assertStatus(422);
        $this->assertSame(0, DewormingRecord::query()->count());
    }

    public function test_child_nutrition_store_accepts_nested_offline_payload(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'child_nutrition_store',
                'newborn' => [
                    'length' => '50',
                    'weight' => '3.2',
                    'breastfeeding_date' => now()->subMonths(7)->format('Y-m-d'),
                ],
                'iron' => [
                    '1st' => now()->subMonths(6)->format('Y-m-d'),
                    '2nd' => '',
                    '3rd' => '',
                ],
                'vitamin_a' => [
                    'va-6-11' => now()->subMonths(5)->format('Y-m-d'),
                ],
            ],
            ['parent_server' => [
                'household_id' => $household->getKey(),
                'household_no' => 'HH-920',
                'resident_id' => $resident->getKey(),
                'member_no' => 'MB-920',
            ]],
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
        $response->assertJsonPath('health_action', 'child_nutrition_store');
        $this->assertSame(1, ChildNutrition::query()->where('resident_id', $resident->getKey())->count());
    }
}
