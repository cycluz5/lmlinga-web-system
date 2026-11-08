<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Models\OfflineSyncReceipt;
use App\Models\User;
use App\Support\Offline\OfflineOperationType;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class OfflineSyncIdempotencyTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    public function test_first_application_returns_synced(): void
    {
        $this->actingAsFieldStaff();

        $envelope = $this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(),
        );

        $response = $this->postOfflineSync($envelope);

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
        $response->assertJsonPath('operation_id', $envelope['operation_id']);
        $this->assertSame(1, Household::query()->count());
        $this->assertSame(1, OfflineSyncReceipt::query()->count());
    }

    public function test_same_id_and_payload_returns_already_applied(): void
    {
        $this->actingAsFieldStaff();

        $envelope = $this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(),
        );

        $first = $this->postOfflineSync($envelope)->assertOk();
        $second = $this->postOfflineSync($envelope)->assertOk();

        $second->assertJsonPath('code', 'ALREADY_APPLIED');
        $this->assertSame($first->json('household.id'), $second->json('household.id'));
        $this->assertSame($first->json('household.household_no'), $second->json('household.household_no'));
        $this->assertSame(1, Household::query()->count());
        $this->assertSame(1, OfflineSyncReceipt::query()->count());
    }

    public function test_timeout_style_replay_does_not_create_another_record(): void
    {
        $this->actingAsFieldStaff();

        $envelope = $this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(['household_no' => '155']),
        );

        $this->postOfflineSync($envelope)->assertJsonPath('code', 'SYNCED');
        $this->postOfflineSync($envelope)->assertJsonPath('code', 'ALREADY_APPLIED');
        $this->postOfflineSync($envelope)->assertJsonPath('code', 'ALREADY_APPLIED');

        $this->assertSame(1, Household::query()->where('household_no', '155')->count());
    }

    public function test_same_id_different_payload_after_success_returns_409(): void
    {
        $this->actingAsFieldStaff();
        $operationId = (string) Str::uuid();

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(['household_no' => '161']),
            ['operation_id' => $operationId],
        ))->assertOk();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload([
                'household_no' => '162',
                'street' => 'Other St.',
            ]),
            ['operation_id' => $operationId],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
        $this->assertSame(1, Household::query()->count());
        $this->assertTrue(Household::query()->where('household_no', '161')->exists());
        $this->assertFalse(Household::query()->where('household_no', '162')->exists());
    }

    public function test_same_id_different_actor_returns_409(): void
    {
        $first = $this->actingAsFieldStaff(StaffRole::BHW);
        $operationId = (string) Str::uuid();

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(['household_no' => '171']),
            ['operation_id' => $operationId],
        ))->assertOk();

        $this->actingAsFieldStaff(StaffRole::BNS, [
            'email' => 'second.actor@example.test',
            'username' => 'second.actor',
        ]);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(['household_no' => '171']),
            ['operation_id' => $operationId],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'IDEMPOTENCY_ACTOR_MISMATCH');
        $this->assertSame(1, Household::query()->count());
        $this->assertSame((int) $first->getKey(), (int) OfflineSyncReceipt::query()->firstOrFail()->actor_user_id);
    }

    public function test_same_id_different_type_after_success_returns_409(): void
    {
        $this->actingAsFieldStaff();
        $operationId = (string) Str::uuid();

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(['household_no' => '175']),
            ['operation_id' => $operationId],
        ))->assertOk();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_UPDATE,
            $this->householdCreatePayload(['household_no' => '175']),
            ['operation_id' => $operationId],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
        $this->assertSame(1, Household::query()->count());
    }

    public function test_same_id_nested_payload_change_after_success_returns_409(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create();
        $operationId = (string) Str::uuid();
        $payload = $this->residentMemberPayload([
            'disability' => ['none'],
            'medical_history' => ['none'],
        ]);

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_CREATE,
            $payload,
            [
                'operation_id' => $operationId,
                'parent_server' => ['household_id' => $household->getKey()],
            ],
        ))->assertOk();

        $altered = $payload;
        $altered['disability'] = ['Physical Disability (PD)'];

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_CREATE,
            $altered,
            [
                'operation_id' => $operationId,
                'parent_server' => ['household_id' => $household->getKey()],
            ],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
        $this->assertSame(1, \App\Models\Resident::query()->count());
    }

    public function test_duplicate_receipt_protection_replays_existing_row(): void
    {
        $user = $this->actingAsFieldStaff();
        $operationId = (string) Str::uuid();

        $first = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(['household_no' => '181']),
            ['operation_id' => $operationId],
        ))->assertOk();

        $this->assertSame(1, OfflineSyncReceipt::query()->where('operation_id', $operationId)->count());

        $replay = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(['household_no' => '181']),
            ['operation_id' => $operationId],
        ));

        $replay->assertOk();
        $replay->assertJsonPath('code', 'ALREADY_APPLIED');
        $this->assertSame($first->json('household.id'), $replay->json('household.id'));
        $this->assertSame(1, OfflineSyncReceipt::query()->count());
        $this->assertInstanceOf(User::class, $user);
    }
}
