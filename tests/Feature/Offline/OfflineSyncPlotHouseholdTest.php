<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Models\OfflineSyncReceipt;
use App\Models\Resident;
use App\Services\SpotMappingHandoffService;
use App\Support\EnvironmentalSanitationErdMode;
use App\Support\Offline\OfflineOperationType;
use App\Support\UserManagementErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ClientTestingErdSchema;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class OfflineSyncPlotHouseholdTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ClientTestingErdSchema::ensure();
        Household::resetResolvedKeyName();
        Resident::resetResolvedKeyName();
        EnvironmentalSanitationErdMode::resetCachedState();
        UserManagementErdMode::resetCachedState();
    }

    public function test_plot_creates_household_head_and_coordinates_atomically(): void
    {
        $this->actingAsErdFieldStaff();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::PLOT_HOUSEHOLD_WITH_HEAD,
            $this->plotHouseholdPayload(),
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
        $response->assertJsonPath('household.household_no', '121');
        $response->assertJsonPath('environmental_health.started', false);
        $response->assertJsonMissingPath('handoff_token');
        $response->assertJsonMissingPath('redirect_url');

        $household = Household::query()->firstOrFail();
        $resident = Resident::query()->firstOrFail();

        $this->assertSame((int) $household->getKey(), (int) $response->json('household.id'));
        $this->assertSame((int) $resident->getKey(), (int) $response->json('resident.id'));
        $this->assertSame((int) $household->getKey(), (int) $resident->household_id);
        $this->assertSame('Head', (string) $resident->relation);
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);
        $this->assertSame('1', (string) $household->purok);

        $memberNo = $resident->member_no;
        $this->assertNotNull($memberNo);
        $this->assertMatchesRegularExpression('/^MB-\d+$/', (string) $memberNo);
        $this->assertSame($memberNo, $response->json('resident.member_no'));
        $this->assertSame([], session(SpotMappingHandoffService::SESSION_KEY, []));
    }

    public function test_offline_plot_does_not_create_eh_handoff_or_session_state(): void
    {
        $this->actingAsErdFieldStaff();

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::PLOT_HOUSEHOLD_WITH_HEAD,
            $this->plotHouseholdPayload(['household_no' => '222']),
        ))->assertOk();

        $this->assertSame([], session(SpotMappingHandoffService::SESSION_KEY, []));
    }

    public function test_existing_online_plot_new_still_creates_handoff(): void
    {
        $this->actingAsErdFieldStaff();

        $response = $this->postJson(route('spot-mapping.plot-new'), $this->plotHouseholdPayload([
            'household_no' => '876',
        ]));

        $response->assertOk();
        $response->assertJsonStructure(['handoff_token', 'redirect_url', 'household_no']);
        $this->assertNotEmpty($response->json('handoff_token'));
        $this->assertStringContainsString(
            'environmental-health/household-water-supply',
            (string) $response->json('redirect_url')
        );
        $this->assertNotEmpty(session(SpotMappingHandoffService::SESSION_KEY, []));
    }

    public function test_observed_indexeddb_plot_payload_replays_household_head_and_receipt(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 14:22:00', 'Asia/Manila'));
        $this->actingAsErdFieldStaff();

        $payload = $this->observedIndexedDbPlotHouseholdPayload();
        $envelope = $this->offlineEnvelope(
            OfflineOperationType::PLOT_HOUSEHOLD_WITH_HEAD,
            $payload,
        );

        $response = $this->postOfflineSync($envelope);

        $this->assertSame(
            'SYNCED',
            $response->json('code'),
            (string) $response->getContent(),
        );
        $response->assertOk();
        $response->assertJsonPath('household.household_no', '210');
        $response->assertJsonPath('environmental_health.started', false);

        $household = Household::query()->where('household_no', '210')->first();
        $this->assertNotNull($household);
        $this->assertEqualsWithDelta(13.376191299053865, (float) $household->latitude, 0.0000002);
        $this->assertEqualsWithDelta(123.43171525236092, (float) $household->longitude, 0.0000002);
        $this->assertSame('5', (string) $household->purok);

        $resident = Resident::query()->where('household_id', $household->getKey())->first();
        $this->assertNotNull($resident);
        $this->assertSame('Zai', (string) $resident->first_name);
        $this->assertSame('Kluz', (string) $resident->last_name);
        $this->assertSame('Head', (string) $resident->relation);
        $this->assertSame((int) $household->getKey(), (int) $resident->household_id);

        $receipt = OfflineSyncReceipt::query()->where('operation_id', $envelope['operation_id'])->first();
        $this->assertNotNull($receipt);
        $this->assertSame(OfflineSyncReceipt::STATUS_APPLIED, $receipt->status);
        $this->assertSame('210', (string) $receipt->household_no);
        $this->assertSame((int) $household->getKey(), (int) $receipt->household_pk);
        $this->assertSame((int) $resident->getKey(), (int) $receipt->resident_pk);

        $duplicate = $this->postOfflineSync($envelope);
        $duplicate->assertOk();
        $duplicate->assertJsonPath('code', 'ALREADY_APPLIED');
        $this->assertSame(1, Household::query()->where('household_no', '210')->count());
        $this->assertSame(1, Resident::query()->where('household_id', $household->getKey())->count());
        $this->assertSame(1, OfflineSyncReceipt::query()->where('operation_id', $envelope['operation_id'])->count());

        Carbon::setTestNow();
    }

    public function test_observed_plot_payload_without_envelope_payload_is_malformed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 14:22:00', 'Asia/Manila'));
        $this->actingAsErdFieldStaff();

        $envelope = $this->offlineEnvelope(
            OfflineOperationType::PLOT_HOUSEHOLD_WITH_HEAD,
            $this->observedIndexedDbPlotHouseholdPayload(),
        );
        unset($envelope['payload']);

        $response = $this->postOfflineSync($envelope);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'MALFORMED');
        $this->assertSame(0, Household::query()->count());
        $this->assertNoAppliedReceipt($envelope['operation_id']);

        Carbon::setTestNow();
    }

    public function test_plot_payload_missing_head_name_is_validation_failed_not_malformed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 14:22:00', 'Asia/Manila'));
        $this->actingAsErdFieldStaff();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::PLOT_HOUSEHOLD_WITH_HEAD,
            $this->observedIndexedDbPlotHouseholdPayload([
                'first_name' => '',
            ]),
        ));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertSame(0, Household::query()->count());
        $this->assertNoAppliedReceipt();

        Carbon::setTestNow();
    }

    public function test_head_creation_failure_rolls_back_household_and_receipt(): void
    {
        $this->actingAsErdFieldStaff();
        $beforeHouseholds = Household::query()->count();
        $beforeResidents = Resident::query()->count();

        Resident::creating(function (): void {
            throw new \RuntimeException('simulated resident failure');
        });

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::PLOT_HOUSEHOLD_WITH_HEAD,
            $this->plotHouseholdPayload(['household_no' => '444']),
        ));

        $response->assertStatus(503);
        $response->assertJsonPath('code', 'RETRYABLE_ERROR');
        $this->assertSame($beforeHouseholds, Household::query()->count());
        $this->assertSame($beforeResidents, Resident::query()->count());
        $this->assertNoAppliedReceipt();
        $this->assertSame([], session(SpotMappingHandoffService::SESSION_KEY, []));
    }
}
