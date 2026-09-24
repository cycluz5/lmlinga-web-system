<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Models\Resident;
use App\Support\Offline\OfflineOperationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class OfflineSyncMemberTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    public function test_valid_member_is_created_with_server_allocated_member_no(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create(['household_no' => '121']);
        Resident::factory()->head()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-001',
        ]);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_CREATE,
            $this->residentMemberPayload(),
            ['parent_server' => ['household_id' => $household->getKey()]],
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
        $response->assertJsonPath('household.id', $household->getKey());
        $this->assertNotNull($response->json('resident.id'));
        $this->assertNotSame('MB-999', $response->json('resident.member_no'));
        $this->assertMatchesRegularExpression('/^MB-\d+$/', (string) $response->json('resident.member_no'));

        $resident = Resident::query()->whereKey($response->json('resident.id'))->firstOrFail();
        $this->assertSame((int) $household->getKey(), (int) $resident->household_id);
        $this->assertSame('Spouse', (string) $resident->relation);
    }

    public function test_client_local_member_id_is_stripped_and_does_not_become_member_no(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create(['household_no' => '121']);
        Resident::factory()->head()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-001',
        ]);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_CREATE,
            $this->residentMemberPayload(['client_local_member_id' => 'MB-Labc12']),
            ['parent_server' => ['household_id' => $household->getKey()]],
        ));

        $response->assertOk();
        $this->assertMatchesRegularExpression('/^MB-\d+$/', (string) $response->json('resident.member_no'));
        $this->assertNotSame('MB-Labc12', $response->json('resident.member_no'));
        $this->assertSame(2, Resident::query()->count());
    }

    public function test_client_member_no_is_prohibited(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_CREATE,
            $this->residentMemberPayload(['member_no' => 'MB-999']),
            ['parent_server' => ['household_id' => $household->getKey()]],
        ));

        $response->assertStatus(422);
        $this->assertSame(0, Resident::query()->count());
        $this->assertNoAppliedReceipt();
    }

    public function test_second_head_is_rejected(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create();
        Resident::factory()->head()->create(['household_id' => $household->id]);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_CREATE,
            $this->residentMemberPayload(['relation' => 'Head']),
            ['parent_server' => ['household_id' => $household->getKey()]],
        ));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertSame(1, Resident::query()->where('relation', 'Head')->count());
        $this->assertNoAppliedReceipt();
    }

    public function test_resident_create_without_sex_returns_validation_failed_and_no_receipt(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create(['household_no' => '999']);
        Resident::factory()->head()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-001',
        ]);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_CREATE,
            $this->residentMemberPayload([
                'first_name' => 'Vicky',
                'last_name' => 'Morales',
                'sex' => null,
            ]),
            ['parent_server' => ['household_id' => $household->getKey()]],
        ));

        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertNotEmpty($response->json('errors.sex'));
        $this->assertSame('The sex field is required.', $response->json('errors.sex.0'));
        $this->assertSame(1, Resident::query()->count());
        $this->assertNoAppliedReceipt();
    }

    public function test_missing_parent_returns_409(): void
    {
        $this->actingAsFieldStaff();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_CREATE,
            $this->residentMemberPayload(),
            ['parent_server' => ['household_id' => 999999]],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'TARGET_MISSING');
        $this->assertSame(0, Resident::query()->count());
        $this->assertNoAppliedReceipt();
    }

    public function test_local_household_uuid_is_not_accepted_as_erd_pk(): void
    {
        $this->actingAsFieldStaff();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_CREATE,
            $this->residentMemberPayload(),
            ['parent_server' => ['household_id' => (string) Str::uuid()]],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'TARGET_MISSING');
        $this->assertSame(0, Resident::query()->count());
    }

    public function test_same_id_replay_does_not_create_another_member(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create();
        $envelope = $this->offlineEnvelope(
            OfflineOperationType::RESIDENT_CREATE,
            $this->residentMemberPayload(),
            ['parent_server' => ['household_id' => $household->getKey()]],
        );

        $first = $this->postOfflineSync($envelope)->assertOk();
        $second = $this->postOfflineSync($envelope)->assertOk();

        $second->assertJsonPath('code', 'ALREADY_APPLIED');
        $this->assertSame($first->json('resident.id'), $second->json('resident.id'));
        $this->assertSame(1, Resident::query()->count());
    }

    public function test_create_returns_field_hash_used_by_follow_up_update(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create(['household_no' => '121']);
        Resident::factory()->head()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-001',
        ]);

        $created = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_CREATE,
            $this->residentMemberPayload(['client_local_member_id' => 'MB-L-abc12']),
            ['parent_server' => ['household_id' => $household->getKey()]],
        ))->assertOk();

        $created->assertJsonPath('code', 'SYNCED');
        $fieldHash = (string) $created->json('resident.field_hash');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fieldHash);

        $residentId = (int) $created->json('resident.id');
        $updated = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_UPDATE,
            $this->residentMemberPayload(['first_name' => 'Anita']),
            [
                'parent_server' => ['resident_id' => $residentId],
                'base_snapshot' => ['field_hash' => $fieldHash],
            ],
        ))->assertOk();

        $updated->assertJsonPath('code', 'SYNCED');
        $this->assertSame('Anita', (string) Resident::query()->findOrFail($residentId)->first_name);
    }
}
