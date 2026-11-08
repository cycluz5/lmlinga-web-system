<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Models\OfflineSyncReceipt;
use App\Support\HouseholdNumber;
use App\Support\Offline\OfflineOperationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class OfflineSyncHouseholdTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    public function test_valid_create_returns_server_pk_and_household_no(): void
    {
        $this->actingAsFieldStaff();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(['household_no' => '042']),
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
        $response->assertJsonPath('household.household_no', '042');
        $this->assertNotNull($response->json('household.id'));
        $this->assertSame(1, Household::query()->count());

        $household = Household::query()->firstOrFail();
        $this->assertSame((int) $household->getKey(), (int) $response->json('household.id'));
        $this->assertSame('042', (string) $household->household_no);
        $this->assertSame(0, \App\Models\Resident::query()->count());
        $response->assertJsonMissingPath('household.zone');
        $response->assertJsonMissingPath('household.street');
    }

    public function test_duplicate_household_no_returns_422_without_receipt(): void
    {
        $this->actingAsFieldStaff();
        Household::factory()->create(['household_no' => '042']);

        $operationId = (string) Str::uuid();
        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(['household_no' => '042']),
            ['operation_id' => $operationId],
        ));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertStringContainsString(
            HouseholdNumber::DUPLICATE_MESSAGE,
            json_encode($response->json('errors')) ?: '',
        );
        $this->assertSame(1, Household::query()->count());
        $this->assertNoAppliedReceipt($operationId);
    }

    public function test_corrected_payload_with_same_unsucceeded_operation_id_can_succeed(): void
    {
        $this->actingAsFieldStaff();
        Household::factory()->create(['household_no' => '042']);
        $operationId = (string) Str::uuid();

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(['household_no' => '042']),
            ['operation_id' => $operationId],
        ))->assertStatus(422);

        $this->assertNoAppliedReceipt($operationId);

        $retry = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(['household_no' => '043']),
            ['operation_id' => $operationId],
        ));

        $retry->assertOk();
        $retry->assertJsonPath('code', 'SYNCED');
        $retry->assertJsonPath('household.household_no', '043');
        $this->assertTrue(Household::query()->where('household_no', '043')->exists());
        $this->assertSame(1, OfflineSyncReceipt::query()->where('operation_id', $operationId)->count());

        $immutable = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(['household_no' => '044']),
            ['operation_id' => $operationId],
        ));

        $immutable->assertStatus(409);
        $immutable->assertJsonPath('code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
        $this->assertFalse(Household::query()->where('household_no', '044')->exists());
    }

    public function test_client_pk_is_rejected(): void
    {
        $this->actingAsFieldStaff();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload([
                'id' => 999,
                'household_id' => 999,
            ]),
        ));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertSame(0, Household::query()->count());
        $this->assertNoAppliedReceipt();
    }

    public function test_create_without_street_succeeds_when_schema_omits_street(): void
    {
        \Tests\Support\ClientTestingErdSchema::ensure();
        Household::resetResolvedKeyName();

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('households', 'street'));

        $this->actingAsErdFieldStaff();

        $payload = [
            'household_no' => '451',
            'zone' => 'Zone 1',
            'date_registered' => '2026-09-22',
            'latitude' => null,
            'longitude' => null,
        ];

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $payload,
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
        $response->assertJsonPath('household.household_no', '451');
        $this->assertSame(1, Household::query()->count());
        $household = Household::query()->firstOrFail();
        $this->assertSame('451', (string) $household->household_no);
    }
}
