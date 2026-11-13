<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Models\Resident;
use App\Support\Offline\OfflineFieldHasher;
use App\Support\Offline\OfflineOperationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class OfflineSyncUpdateConcurrencyTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    public function test_matching_household_hash_updates(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create([
            'zone' => 'Zone 1',
            'accomplished_by' => 'Original BHW',
            'date_registered' => '2026-01-15',
            'address' => null,
            'latitude' => null,
            'longitude' => null,
        ]);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_UPDATE,
            $this->householdUpdatePayload($household, ['accomplished_by' => 'Client BHW']),
            [
                'parent_server' => ['household_id' => $household->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::household($household)],
            ],
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
        $this->assertSame('Client BHW', (string) $household->fresh()->accomplished_by);
    }

    public function test_changed_household_returns_409(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create([
            'accomplished_by' => 'Original BHW',
            'date_registered' => '2026-01-15',
        ]);
        $staleHash = OfflineFieldHasher::household($household);

        $household->forceFill(['accomplished_by' => 'Server BHW'])->save();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_UPDATE,
            $this->householdUpdatePayload($household->fresh(), ['accomplished_by' => 'Client BHW']),
            [
                'parent_server' => ['household_id' => $household->getKey()],
                'base_snapshot' => ['field_hash' => $staleHash],
            ],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'TARGET_CHANGED');
        $this->assertSame('Server BHW', (string) $household->fresh()->accomplished_by);
        $this->assertNoAppliedReceipt();
    }

    public function test_matching_resident_hash_updates(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create();
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Ana',
            'last_name' => 'Santos',
            'relation' => 'Spouse',
            'birthday' => '1990-03-15',
            'sex' => 'Female',
            'relationship_status' => 'Married',
            'occupation' => 'Teacher',
            'monthly_income' => '20,000 – 29,999',
            'religion' => 'Roman Catholic',
            'education' => 'College Graduate',
            'fp_user' => 'No',
            'disability' => ['none'],
            'medical_history' => ['none'],
        ]);

        $payload = $this->residentMemberPayload(['first_name' => 'Anita']);
        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_UPDATE,
            $payload,
            [
                'parent_server' => ['resident_id' => $resident->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::resident($resident)],
            ],
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
        $this->assertSame('Anita', (string) $resident->fresh()->first_name);
    }

    public function test_changed_resident_returns_409(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create();
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Ana',
        ]);
        $staleHash = OfflineFieldHasher::resident($resident);
        $resident->forceFill(['first_name' => 'Maria'])->save();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_UPDATE,
            $this->residentMemberPayload(['first_name' => 'Anita']),
            [
                'parent_server' => ['resident_id' => $resident->getKey()],
                'base_snapshot' => ['field_hash' => $staleHash],
            ],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'TARGET_CHANGED');
        $this->assertSame('Maria', (string) $resident->fresh()->first_name);
        $this->assertNoAppliedReceipt();
    }

    public function test_same_second_updated_at_cannot_bypass_field_hash_conflict(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create([
            'accomplished_by' => 'Original BHW',
            'date_registered' => '2026-01-15',
            'updated_at' => '2026-01-15 10:00:00',
        ]);
        $staleHash = OfflineFieldHasher::household($household);
        $updatedAt = $household->fresh()->updated_at?->format('Y-m-d H:i:s');

        DB::table('households')->where('id', $household->getKey())->update([
            'accomplished_by' => 'Changed In Place BHW',
            'updated_at' => $updatedAt,
        ]);

        $this->assertSame($updatedAt, $household->fresh()->updated_at?->format('Y-m-d H:i:s'));

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_UPDATE,
            $this->householdUpdatePayload($household, ['accomplished_by' => 'Stale Client BHW']),
            [
                'parent_server' => ['household_id' => $household->getKey()],
                'base_snapshot' => ['field_hash' => $staleHash],
            ],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'TARGET_CHANGED');
        $this->assertSame('Changed In Place BHW', (string) $household->fresh()->accomplished_by);
    }

    public function test_successful_update_replay_returns_already_applied(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create([
            'accomplished_by' => 'Original BHW',
            'date_registered' => '2026-01-15',
        ]);
        $envelope = $this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_UPDATE,
            $this->householdUpdatePayload($household, ['accomplished_by' => 'Client BHW']),
            [
                'parent_server' => ['household_id' => $household->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::household($household)],
            ],
        );

        $this->postOfflineSync($envelope)->assertJsonPath('code', 'SYNCED');
        $replay = $this->postOfflineSync($envelope);

        $replay->assertOk();
        $replay->assertJsonPath('code', 'ALREADY_APPLIED');
        $this->assertSame('Client BHW', (string) $household->fresh()->accomplished_by);
    }

    public function test_missing_household_target_returns_409(): void
    {
        $this->actingAsFieldStaff();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_UPDATE,
            [
                'zone' => 'Zone 1',
                'street' => 'Layuan St.',
                'date_registered' => '2026-01-15',
            ],
            [
                'parent_server' => ['household_id' => 999999],
                'base_snapshot' => ['field_hash' => str_repeat('a', 64)],
            ],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'TARGET_MISSING');
        $this->assertNoAppliedReceipt();
        $this->assertSame(0, Household::query()->count());
    }

    public function test_missing_resident_target_returns_409_and_creates_no_replacement(): void
    {
        $this->actingAsFieldStaff();
        $before = Resident::query()->count();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_UPDATE,
            $this->residentMemberPayload(),
            [
                'parent_server' => ['resident_id' => 999999],
                'base_snapshot' => ['field_hash' => str_repeat('b', 64)],
            ],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'TARGET_MISSING');
        $this->assertNoAppliedReceipt();
        $this->assertSame($before, Resident::query()->count());
    }

    public function test_same_second_resident_updated_at_cannot_bypass_field_hash_conflict(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create();
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Ana',
            'updated_at' => '2026-01-15 10:00:00',
        ]);
        $staleHash = OfflineFieldHasher::resident($resident);
        $updatedAt = $resident->fresh()->updated_at?->format('Y-m-d H:i:s');

        DB::table('residents')->where('id', $resident->getKey())->update([
            'first_name' => 'Maria',
            'updated_at' => $updatedAt,
        ]);

        $this->assertSame($updatedAt, $resident->fresh()->updated_at?->format('Y-m-d H:i:s'));

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_UPDATE,
            $this->residentMemberPayload(['first_name' => 'Anita']),
            [
                'parent_server' => ['resident_id' => $resident->getKey()],
                'base_snapshot' => ['field_hash' => $staleHash],
            ],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'TARGET_CHANGED');
        $this->assertSame('Maria', (string) $resident->fresh()->first_name);
        $this->assertNoAppliedReceipt();
    }
}
