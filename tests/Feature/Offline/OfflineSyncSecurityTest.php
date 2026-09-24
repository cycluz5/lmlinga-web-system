<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Models\Resident;
use App\Services\SpotMappingHandoffService;
use App\Support\Offline\OfflineOperationType;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class OfflineSyncSecurityTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    public function test_unknown_operation_is_rejected(): void
    {
        $this->actingAsFieldStaff();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            'FP_REMARKS_CREATE',
            ['remarks' => 'secret'],
        ));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'UNKNOWN_OPERATION');
        $this->assertNoAppliedReceipt();
    }

    public function test_malformed_uuid_is_rejected(): void
    {
        $this->actingAsFieldStaff();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(),
            ['operation_id' => 'not-a-uuid'],
        ));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'MALFORMED');
        $this->assertSame(0, Household::query()->count());
    }

    public function test_unsupported_schema_version_is_rejected(): void
    {
        $this->actingAsFieldStaff();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(),
            ['schema_version' => 2],
        ));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'MALFORMED');
        $this->assertSame(0, Household::query()->count());
    }

    public function test_client_authoritative_pk_attempts_are_rejected(): void
    {
        $this->actingAsFieldStaff();

        $create = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(['id' => 50, 'household_id' => 50]),
        ));
        $create->assertStatus(422);

        $plot = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::PLOT_HOUSEHOLD_WITH_HEAD,
            $this->plotHouseholdPayload([
                'household_id' => 50,
                'resident_id' => 50,
                'member_no' => 'MB-050',
            ]),
        ));
        $plot->assertStatus(422);

        $this->assertSame(0, Household::query()->count());
        $this->assertSame(0, Resident::query()->count());
    }

    public function test_sync_response_does_not_expose_aes_secrets(): void
    {
        $this->actingAsFieldStaff();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(['household_no' => '188']),
        ))->assertOk();

        $body = $response->getContent();
        $this->assertStringNotContainsString('LMLINGA_AT_REST_KEY', $body);
        $this->assertStringNotContainsString('at_rest', $body);
        $this->assertStringNotContainsString('base64:', $body);
        $this->assertStringNotContainsString((string) config('lmlinga.at_rest.key'), $body);
        $this->assertStringNotContainsString((string) config('app.key'), $body);
    }

    public function test_offline_plot_does_not_issue_eh_handoff(): void
    {
        \Tests\Support\ClientTestingErdSchema::ensure();
        \App\Models\Household::resetResolvedKeyName();
        \App\Models\Resident::resetResolvedKeyName();
        \App\Support\UserManagementErdMode::resetCachedState();
        $this->actingAsErdFieldStaff();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::PLOT_HOUSEHOLD_WITH_HEAD,
            $this->plotHouseholdPayload(['household_no' => '199']),
        ))->assertOk();

        $response->assertJsonMissingPath('handoff_token');
        $this->assertSame([], session(SpotMappingHandoffService::SESSION_KEY, []));
    }

    public function test_deactivation_blocks_writes(): void
    {
        $this->actingAsFieldStaff(StaffRole::BHW, [
            'status' => StaffAccountStatus::INACTIVE,
        ]);

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(),
        ))->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_INACTIVE');

        $this->assertSame(0, Household::query()->count());
    }

    public function test_staff_without_appointment_role_is_forbidden(): void
    {
        $user = \App\Models\User::factory()->create([
            'must_change_password' => false,
            'status' => StaffAccountStatus::ACTIVE,
        ]);
        $this->actingAs($user);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(),
        ));

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'FORBIDDEN');
        $this->assertSame(0, Household::query()->count());
    }

    public function test_death_and_fp_remark_operation_types_are_not_accepted(): void
    {
        $this->actingAsFieldStaff();

        foreach (['DEATH_REJECT', 'FP_VISIT_REMARKS', 'AES_STORE'] as $type) {
            $this->postOfflineSync($this->offlineEnvelope($type, ['rejection_reason' => 'x']))
                ->assertStatus(422)
                ->assertJsonPath('code', 'UNKNOWN_OPERATION');
        }
    }

    public function test_client_uuid_is_not_used_as_household_pk(): void
    {
        $this->actingAsFieldStaff();
        Household::factory()->create(['household_no' => '121']);

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_CREATE,
            $this->residentMemberPayload(),
            ['parent_server' => ['household_id' => (string) Str::uuid()]],
        ))->assertStatus(409)->assertJsonPath('code', 'TARGET_MISSING');

        $this->assertSame(0, Resident::query()->count());
    }

    public function test_client_supplied_actor_and_result_fields_cannot_control_the_server(): void
    {
        $user = $this->actingAsFieldStaff();

        $envelope = $this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload([
                'household_no' => '189',
                'user_id' => 50,
                'actor_user_id' => 50,
                'server_result' => ['id' => 50],
                'status' => 'applied',
            ]),
        );
        $envelope['actor_user_id'] = 50;
        $envelope['user_id'] = 50;
        $envelope['server_result'] = ['household' => ['id' => 50]];
        $envelope['status'] = 'applied';

        $response = $this->postOfflineSync($envelope)->assertOk();

        $response->assertJsonPath('code', 'SYNCED');
        $this->assertNotSame(50, $response->json('household.id'));
        $this->assertSame((int) $user->getKey(), (int) \App\Models\OfflineSyncReceipt::query()->firstOrFail()->actor_user_id);
        $this->assertSame('189', $response->json('household.household_no'));
    }
}
