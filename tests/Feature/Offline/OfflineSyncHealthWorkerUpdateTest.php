<?php

namespace Tests\Feature\Offline;

use App\Models\OfflineSyncReceipt;
use App\Models\User;
use App\Support\Offline\OfflineFieldHasher;
use App\Support\Offline\OfflineOperationType;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class OfflineSyncHealthWorkerUpdateTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function healthWorkerUpdatePayload(User $worker, array $overrides = []): array
    {
        $appointment = $worker->currentAppointment ?? $worker->resolveCurrentAppointment();

        return array_merge([
            'sex' => 'Female',
            'hw_first_name' => 'Maria',
            'hw_last_name' => 'Reyes',
            'hw_middle_name' => 'Cruz',
            'hw_suffix' => 'N/A',
            'hw_dob' => '1990-02-02',
            'hw_civil_status' => 'Married',
            'hw_nationality' => 'Filipino',
            'hw_mobile' => '09179876543',
            'hw_email' => $worker->email,
            'hw_house_no' => '99',
            'hw_street' => 'Updated St.',
            'hw_purok_zone' => 'Zone 2',
            'hw_barangay' => 'La Medalla',
            'hw_municipality' => 'Iriga City',
            'hw_province' => 'Camarines Sur',
            'hw_zip' => '4431',
            'hw_role' => 'BNS',
            'hw_assigned_barangay' => 'La Medalla',
            'hw_assigned_zone' => 'Zone 2',
            'hw_date_appointed' => $appointment?->date_appointed?->format('Y-m-d') ?? '2020-01-15',
            'hw_end_appointment' => '2031-12-31',
            'hw_username' => $worker->username,
            'hw_status' => StaffAccountStatus::ACTIVE,
        ], $overrides);
    }

    private function seedHealthWorker(array $userOverrides = [], array $appointmentOverrides = []): User
    {
        $worker = User::factory()->create(array_merge([
            'first_name' => 'Maria',
            'middle_name' => 'Cruz',
            'last_name' => 'Reyes',
            'suffix' => 'N/A',
            'sex' => 'Female',
            'date_of_birth' => '1990-02-02',
            'civil_status' => 'Single',
            'nationality' => 'Filipino',
            'mobile_number' => '09171234567',
            'email' => 'maria.reyes.offline@example.test',
            'username' => 'maria.reyes.offline',
            'house_no' => '12',
            'street' => 'Sampaguita St.',
            'purok_zone' => 'Zone 1',
            'barangay' => 'La Medalla',
            'municipality_city' => 'Iriga City',
            'province' => 'Camarines Sur',
            'zip_code' => '4431',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => 'OriginalPass!123',
            'must_change_password' => false,
        ], $userOverrides));

        $worker->assignCurrentAppointment(array_merge([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-15',
            'end_of_appointment' => '2030-12-31',
        ], $appointmentOverrides));

        return $worker->fresh(['currentAppointment']);
    }

    public function test_admin_can_replay_health_worker_update(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $worker = $this->seedHealthWorker();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            $this->healthWorkerUpdatePayload($worker),
            [
                'parent_server' => ['user_id' => $worker->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::healthWorker($worker)],
            ],
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
        $response->assertJsonPath('user.id', $worker->getKey());
        $this->assertSame('Updated St.', (string) $worker->fresh()->street);
        $this->assertSame('Married', (string) $worker->fresh()->civil_status);
        $this->assertSame(StaffRole::BNS, $worker->fresh(['currentAppointment'])->role);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_cannot_replay_health_worker_update(string $role): void
    {
        $this->actingAsStaff($role);
        $worker = $this->seedHealthWorker([
            'email' => 'target.'.$role.'@example.test',
            'username' => 'target.'.$role,
        ]);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            $this->healthWorkerUpdatePayload($worker),
            [
                'parent_server' => ['user_id' => $worker->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::healthWorker($worker)],
            ],
        ));

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'FORBIDDEN');
        $this->assertSame('Sampaguita St.', (string) $worker->fresh()->street);
        $this->assertNoAppliedReceipt();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonAdminRoles(): array
    {
        return [
            'bhw' => [StaffRole::BHW],
            'bns' => [StaffRole::BNS],
            'bspo' => [StaffRole::BSPO],
        ];
    }

    public function test_demoted_admin_cannot_replay_queued_health_worker_update(): void
    {
        $actor = $this->actingAsStaff(StaffRole::ADMIN, [
            'email' => 'soon.demoted@example.test',
            'username' => 'soon.demoted',
        ]);
        $worker = $this->seedHealthWorker();

        $actor->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);
        $actor = $actor->fresh(['currentAppointment']);
        $this->actingAs($actor);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            $this->healthWorkerUpdatePayload($worker),
            [
                'parent_server' => ['user_id' => $worker->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::healthWorker($worker)],
            ],
        ));

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'FORBIDDEN');
        $this->assertSame('Sampaguita St.', (string) $worker->fresh()->street);
        $this->assertNoAppliedReceipt();
    }

    public function test_inactive_actor_cannot_replay_health_worker_update(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN, [
            'status' => StaffAccountStatus::INACTIVE,
            'email' => 'inactive.admin@example.test',
            'username' => 'inactive.admin',
        ]);
        $worker = $this->seedHealthWorker();

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            $this->healthWorkerUpdatePayload($worker),
            [
                'parent_server' => ['user_id' => $worker->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::healthWorker($worker)],
            ],
        ))->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_INACTIVE');

        $this->assertSame('Sampaguita St.', (string) $worker->fresh()->street);
        $this->assertNoAppliedReceipt();
    }

    public function test_missing_target_returns_target_missing(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            $this->healthWorkerUpdatePayload($this->seedHealthWorker(), ['hw_street' => 'Ghost St.']),
            [
                'parent_server' => ['user_id' => 999999],
                'base_snapshot' => ['field_hash' => str_repeat('a', 64)],
            ],
        ))->assertStatus(409)->assertJsonPath('code', 'TARGET_MISSING');

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            [
                'sex' => 'Female',
                'hw_first_name' => 'Demo',
                'hw_last_name' => 'Worker',
                'hw_middle_name' => 'X',
                'hw_dob' => '1990-02-02',
                'hw_civil_status' => 'Single',
                'hw_nationality' => 'Filipino',
                'hw_mobile' => '09171234567',
                'hw_email' => 'demo.hw@example.test',
                'hw_house_no' => '1',
                'hw_street' => 'Demo St.',
                'hw_purok_zone' => 'Zone 1',
                'hw_barangay' => 'La Medalla',
                'hw_municipality' => 'Iriga City',
                'hw_province' => 'Camarines Sur',
                'hw_zip' => '4431',
                'hw_role' => 'BHW',
                'hw_assigned_barangay' => 'La Medalla',
                'hw_assigned_zone' => 'Zone 1',
                'hw_date_appointed' => '2020-01-15',
                'hw_username' => 'demo.hw',
                'hw_status' => StaffAccountStatus::ACTIVE,
            ],
            [
                'parent_server' => ['user_id' => 'hw-001'],
                'base_snapshot' => ['field_hash' => str_repeat('a', 64)],
            ],
        ))->assertStatus(409)->assertJsonPath('code', 'TARGET_MISSING');

        $this->assertNoAppliedReceipt();
    }

    public function test_concurrent_server_edit_returns_target_changed(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $worker = $this->seedHealthWorker();
        $staleHash = OfflineFieldHasher::healthWorker($worker);

        $worker->forceFill(['street' => 'Dalipay St.'])->save();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            $this->healthWorkerUpdatePayload($worker->fresh(), ['hw_street' => 'Client St.']),
            [
                'parent_server' => ['user_id' => $worker->getKey()],
                'base_snapshot' => ['field_hash' => $staleHash],
            ],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'TARGET_CHANGED');
        $this->assertSame('Dalipay St.', (string) $worker->fresh()->street);
        $this->assertNoAppliedReceipt();
    }

    public function test_unchanged_email_and_username_pass_unique_validation(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $worker = $this->seedHealthWorker();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            $this->healthWorkerUpdatePayload($worker, [
                'hw_email' => $worker->email,
                'hw_username' => $worker->username,
                'hw_street' => 'Same Identity St.',
            ]),
            [
                'parent_server' => ['user_id' => $worker->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::healthWorker($worker)],
            ],
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
        $this->assertSame('Same Identity St.', (string) $worker->fresh()->street);
        $this->assertSame($worker->email, $worker->fresh()->email);
        $this->assertSame($worker->username, $worker->fresh()->username);
    }

    public function test_duplicate_email_or_username_of_another_user_fails_validation(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $worker = $this->seedHealthWorker();
        $other = $this->seedHealthWorker([
            'email' => 'other.worker@example.test',
            'username' => 'other.worker',
        ]);

        $emailClash = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            $this->healthWorkerUpdatePayload($worker, ['hw_email' => $other->email]),
            [
                'parent_server' => ['user_id' => $worker->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::healthWorker($worker)],
            ],
        ));
        $emailClash->assertStatus(422);
        $emailClash->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertSame($worker->email, $worker->fresh()->email);

        $usernameClash = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            $this->healthWorkerUpdatePayload($worker->fresh(), ['hw_username' => $other->username]),
            [
                'parent_server' => ['user_id' => $worker->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::healthWorker($worker->fresh())],
            ],
        ));
        $usernameClash->assertStatus(422);
        $usernameClash->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertSame($worker->username, $worker->fresh()->username);
        $this->assertNoAppliedReceipt();
    }

    public function test_last_active_admin_invariant_remains_enforced(): void
    {
        $admin = $this->actingAsStaff(StaffRole::ADMIN, [
            'first_name' => 'Sole',
            'middle_name' => 'A',
            'last_name' => 'Admin',
            'suffix' => 'N/A',
            'sex' => 'Female',
            'date_of_birth' => '1990-02-02',
            'civil_status' => 'Single',
            'nationality' => 'Filipino',
            'mobile_number' => '09171234567',
            'email' => 'sole.admin.offline@example.test',
            'username' => 'sole.admin.offline',
            'house_no' => '12',
            'street' => 'Sampaguita St.',
            'purok_zone' => 'Zone 1',
            'barangay' => 'La Medalla',
            'municipality_city' => 'Iriga City',
            'province' => 'Camarines Sur',
            'zip_code' => '4431',
            'status' => StaffAccountStatus::ACTIVE,
        ]);
        $admin = $admin->fresh(['currentAppointment']);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            $this->healthWorkerUpdatePayload($admin, [
                'hw_role' => 'BHW',
                'hw_email' => $admin->email,
                'hw_username' => $admin->username,
            ]),
            [
                'parent_server' => ['user_id' => $admin->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::healthWorker($admin)],
            ],
        ));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertSame(StaffRole::ADMIN, $admin->fresh(['currentAppointment'])->role);
        $this->assertNoAppliedReceipt();
    }

    public function test_duplicate_identical_operation_returns_already_applied(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $worker = $this->seedHealthWorker();
        $envelope = $this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            $this->healthWorkerUpdatePayload($worker),
            [
                'parent_server' => ['user_id' => $worker->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::healthWorker($worker)],
            ],
        );

        $this->postOfflineSync($envelope)->assertJsonPath('code', 'SYNCED');
        $replay = $this->postOfflineSync($envelope);

        $replay->assertOk();
        $replay->assertJsonPath('code', 'ALREADY_APPLIED');
        $this->assertSame(1, OfflineSyncReceipt::query()->where('operation_id', $envelope['operation_id'])->count());
        $this->assertSame('Updated St.', (string) $worker->fresh()->street);
    }

    public function test_payload_mismatch_after_success_returns_attention(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $worker = $this->seedHealthWorker();
        $operationId = (string) Str::uuid();

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            $this->healthWorkerUpdatePayload($worker),
            [
                'operation_id' => $operationId,
                'parent_server' => ['user_id' => $worker->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::healthWorker($worker)],
            ],
        ))->assertJsonPath('code', 'SYNCED');

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            $this->healthWorkerUpdatePayload($worker->fresh(), ['hw_street' => 'Other St.']),
            [
                'operation_id' => $operationId,
                'parent_server' => ['user_id' => $worker->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::healthWorker($worker->fresh())],
            ],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
        $this->assertSame('Updated St.', (string) $worker->fresh()->street);
    }

    public function test_actor_mismatch_returns_attention(): void
    {
        $first = $this->actingAsStaff(StaffRole::ADMIN, [
            'email' => 'first.admin.offline@example.test',
            'username' => 'first.admin.offline',
        ]);
        $worker = $this->seedHealthWorker();
        $operationId = (string) Str::uuid();

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            $this->healthWorkerUpdatePayload($worker),
            [
                'operation_id' => $operationId,
                'parent_server' => ['user_id' => $worker->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::healthWorker($worker)],
            ],
        ))->assertJsonPath('code', 'SYNCED');

        $this->actingAsStaff(StaffRole::ADMIN, [
            'email' => 'second.admin.offline@example.test',
            'username' => 'second.admin.offline',
        ]);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            $this->healthWorkerUpdatePayload($worker->fresh()),
            [
                'operation_id' => $operationId,
                'parent_server' => ['user_id' => $worker->getKey()],
                'base_snapshot' => ['field_hash' => OfflineFieldHasher::healthWorker($worker->fresh())],
            ],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'IDEMPOTENCY_ACTOR_MISMATCH');
        $this->assertSame((int) $first->getKey(), (int) OfflineSyncReceipt::query()->firstOrFail()->actor_user_id);
    }

    public function test_health_worker_create_is_not_an_offline_operation(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->postOfflineSync($this->offlineEnvelope(
            'HEALTH_WORKER_CREATE',
            ['hw_email' => 'new@example.test', 'password' => 'SecretPass!123'],
        ))->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_OPERATION');

        $this->assertNoAppliedReceipt();
    }

    public function test_existing_online_health_worker_update_still_works(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $worker = $this->seedHealthWorker();

        $this->put(
            route('user-management.health-workers.update', ['id' => (string) $worker->getKey()]),
            $this->healthWorkerUpdatePayload($worker, ['hw_street' => 'Online St.']),
        )->assertRedirect(route('user-management.health-workers.view', ['id' => (string) $worker->getKey()]));

        $this->assertSame('Online St.', (string) $worker->fresh()->street);
    }
}
