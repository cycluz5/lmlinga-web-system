<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkerAppointment;
use App\Services\HealthWorkerAccountService;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use App\Support\UserManagementErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class AdminHealthWorkerDeactivateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_deactivate_eligible_non_admin_and_row_is_preserved(): void
    {
        $worker = $this->seedWorker([
            'email' => 'deactivate.ok@example.test',
            'username' => 'deactivate.ok',
            'password' => 'WorkerPass!123',
        ]);
        $appointmentId = $worker->currentAppointment?->getKey();
        $beforeUsers = User::query()->count();
        $beforeAppointments = WorkerAppointment::query()->where('user_id', $worker->id)->count();

        $this->actingAsAdminSession();
        $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.deactivate', ['id' => (string) $worker->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']))
            ->assertSessionHas('status');

        $this->assertSame($beforeUsers, User::query()->count());
        $this->assertTrue(User::query()->whereKey($worker->id)->exists());
        $worker->refresh();
        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($worker->status));
        $this->assertSame($beforeAppointments, WorkerAppointment::query()->where('user_id', $worker->id)->count());
        $this->assertSame($appointmentId, $worker->fresh(['currentAppointment'])->currentAppointment?->getKey());
        $this->assertSame(StaffRole::BHW, $worker->role);

        $this->app['auth']->logout();
        $this->flushSession();

        $this->post(route('login.store'), [
            'email' => 'deactivate.ok@example.test',
            'password' => 'WorkerPass!123',
        ])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_index_and_view_show_deactivate_for_eligible_worker_not_admin_or_self(): void
    {
        $admin = $this->actingAsAdminSession();
        $worker = $this->seedWorker([
            'email' => 'visible.deactivate@example.test',
            'username' => 'visible.deactivate',
        ]);
        $peerAdmin = $this->seedWorker([
            'email' => 'peer.admin@example.test',
            'username' => 'peer.admin',
        ], [
            'role' => StaffRole::ADMIN,
        ]);

        $this->actingAsAdminSession();
        $index = $this->get(route('user-management.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-hw-deactivate-id="'.$worker->id.'"', $index);
        $this->assertStringContainsString('Deactivate Account', $index);
        $this->assertStringNotContainsString('data-hw-deactivate-id="'.$admin->id.'"', $index);
        $this->assertStringNotContainsString('data-hw-deactivate-id="'.$peerAdmin->id.'"', $index);

        $this->actingAsAdminSession();
        $this->get(route('user-management.health-workers.view', ['id' => (string) $worker->id]))
            ->assertOk()
            ->assertSee('data-hw-deactivate-id="'.$worker->id.'"', false);

        $this->actingAsAdminSession();
        $this->get(route('user-management.health-workers.view', ['id' => (string) $peerAdmin->id]))
            ->assertOk()
            ->assertDontSee('data-hw-deactivate-id="'.$peerAdmin->id.'"', false);
    }

    public function test_admin_session_survives_deactivating_another_worker(): void
    {
        $admin = $this->actingAsAdminSession();
        $worker = $this->seedWorker([
            'email' => 'session.survive.deact@example.test',
            'username' => 'session.survive.deact',
        ]);

        $this->actingAs($admin);
        $this->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $tokenBefore = session()->token();

        $response = $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.deactivate', ['id' => (string) $worker->id]));

        $response->assertRedirect(route('user-management.index', ['tab' => 'workers']));
        $response->assertStatus(302);
        $this->assertNotSame(419, $response->status());
        $this->assertAuthenticatedAs($admin);
        $this->assertSame($tokenBefore, session()->token());
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($admin->fresh()->status));
        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));

        $follow = $this->get(route('user-management.index', ['tab' => 'workers']));
        $follow->assertOk();
        $this->assertAuthenticatedAs($admin);
        $this->assertSame($tokenBefore, session()->token());

        // Follow-up mutating POST must not 419 — admin CSRF stays valid.
        $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.activate', ['id' => (string) $worker->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']))
            ->assertSessionHas('status');
        $this->assertAuthenticatedAs($admin);
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));
    }

    public function test_admin_session_survives_deactivating_then_activating_another_worker(): void
    {
        $admin = $this->actingAsAdminSession();
        $worker = $this->seedWorker([
            'email' => 'deact.then.act@example.test',
            'username' => 'deact.then.act',
        ]);

        $this->actingAs($admin);
        $this->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $tokenBefore = session()->token();

        $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.deactivate', ['id' => (string) $worker->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']));
        $this->assertAuthenticatedAs($admin);
        $this->assertSame($tokenBefore, session()->token());

        $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.activate', ['id' => (string) $worker->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']))
            ->assertSessionHas('status');

        $this->assertAuthenticatedAs($admin);
        $this->assertSame($tokenBefore, session()->token());

        $follow = $this->get(route('user-management.index', ['tab' => 'workers']));
        $follow->assertOk();
        $this->assertAuthenticatedAs($admin);
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));
    }

    public function test_admin_account_cannot_be_deactivated_through_the_route(): void
    {
        $target = $this->seedWorker([
            'email' => 'keep.admin@example.test',
            'username' => 'keep.admin',
        ], [
            'role' => StaffRole::ADMIN,
        ]);
        $this->seedWorker([
            'email' => 'other.admin@example.test',
            'username' => 'other.admin',
        ], [
            'role' => StaffRole::ADMIN,
        ]);

        $this->actingAsAdminSession();
        $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.deactivate', ['id' => (string) $target->id]))
            ->assertRedirect()
            ->assertSessionHasErrors('hw_status');

        $index = $this->get(route('user-management.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-um-toast-error', $index);
        $this->assertStringContainsString(
            'Administrator accounts cannot be deactivated from this action.',
            $index
        );

        $target->refresh();
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($target->status));
        $this->assertSame(StaffRole::ADMIN, $target->role);
        $this->assertTrue(User::query()->whereKey($target->id)->exists());
    }

    public function test_service_rejects_admin_deactivation(): void
    {
        $admin = $this->actingAsAdminSession();
        $target = $this->seedWorker([
            'email' => 'service.admin@example.test',
            'username' => 'service.admin',
        ], [
            'role' => StaffRole::ADMIN,
        ]);

        try {
            app(HealthWorkerAccountService::class)->deactivateAccount($target);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hw_status', $exception->errors());
        }

        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($target->fresh()->status));
        $this->assertTrue(User::query()->whereKey($admin->id)->exists());
    }

    public function test_authenticated_admin_cannot_deactivate_self(): void
    {
        $admin = $this->actingAsAdminSession();

        $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.deactivate', ['id' => (string) $admin->id]))
            ->assertRedirect()
            ->assertSessionHasErrors('hw_status');

        $admin->refresh();
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($admin->status));
        $this->assertSame(StaffRole::ADMIN, $admin->role);
    }

    public function test_non_admin_cannot_deactivate_another_account(): void
    {
        $worker = $this->seedWorker([
            'email' => 'target.bhw@example.test',
            'username' => 'target.bhw',
        ]);
        $this->actingAsStaff(StaffRole::BHW, [
            'email' => 'actor.bhw@example.test',
            'username' => 'actor.bhw',
        ]);

        $this->post(route('user-management.health-workers.deactivate', ['id' => (string) $worker->id]))
            ->assertForbidden();

        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));
    }

    public function test_unauthenticated_deactivate_redirects_to_login(): void
    {
        $worker = $this->seedWorker([
            'email' => 'guest.target@example.test',
            'username' => 'guest.target',
        ]);

        $this->app['auth']->logout();
        $this->flushSession();

        $this->post(route('user-management.health-workers.deactivate', ['id' => (string) $worker->id]))
            ->assertRedirect(route('login'));

        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));
    }

    public function test_already_inactive_account_is_idempotent(): void
    {
        $worker = $this->seedWorker([
            'email' => 'already.inactive@example.test',
            'username' => 'already.inactive',
        ]);
        $worker->deactivate();
        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));
        $before = User::query()->count();

        $this->actingAsAdminSession();
        $this->post(route('user-management.health-workers.deactivate', ['id' => (string) $worker->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']))
            ->assertSessionHas('status');

        $this->assertSame($before, User::query()->count());
        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));
    }

    public function test_last_admin_edit_protection_is_unchanged(): void
    {
        $admin = $this->actingAsAdminSession();

        $this->from(route('user-management.health-workers.edit', ['id' => (string) $admin->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $admin->id]),
                $this->validUpdatePayload($admin, [
                    'hw_role' => 'Admin',
                    'hw_status' => StaffAccountStatus::INACTIVE,
                ])
            )
            ->assertSessionHasErrors('hw_status');

        $admin->refresh();
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($admin->status));
        $this->assertSame(StaffRole::ADMIN, $admin->role);
    }

    public function test_erd_mode_deactivates_user_management_row_without_deleting(): void
    {
        ClientTestingErdSchema::ensure();
        UserManagementErdMode::resetCachedState();
        $this->assertTrue(UserManagementErdMode::isActive());
        $this->assertSame('user_management', UserManagementErdMode::staffTableName());

        $admin = $this->createErdStaff('erd.admin.deact@example.test', 'erd.admin.deact', StaffRole::ADMIN);
        $worker = $this->createErdStaff('erd.bhw.deact@example.test', 'erd.bhw.deact', StaffRole::BHW);

        $this->actingAs($admin);
        $this->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $before = (int) DB::table('user_management')->count();
        $appointmentsBefore = (int) DB::table('worker_appointments')->where('user_id', $worker->getKey())->count();

        $this->post(route('user-management.health-workers.deactivate', ['id' => (string) $worker->getKey()]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']));

        $this->assertSame($before, (int) DB::table('user_management')->count());
        $row = DB::table('user_management')->where('user_id', $worker->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($row->status));
        $this->assertSame($appointmentsBefore, (int) DB::table('worker_appointments')->where('user_id', $worker->getKey())->count());
    }

    public function test_erd_mode_admin_session_survives_deactivating_another_worker(): void
    {
        ClientTestingErdSchema::ensure();
        UserManagementErdMode::resetCachedState();
        $this->assertTrue(UserManagementErdMode::isActive());

        $admin = $this->createErdStaff('erd.admin.session@example.test', 'erd.admin.session', StaffRole::ADMIN);
        $worker = $this->createErdStaff('erd.bhw.session@example.test', 'erd.bhw.session', StaffRole::BHW);

        $this->actingAs($admin);
        $this->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $tokenBefore = session()->token();

        $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.deactivate', ['id' => (string) $worker->getKey()]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']));

        $this->assertAuthenticatedAs($admin);
        $this->assertSame($tokenBefore, session()->token());

        $adminRow = DB::table('user_management')->where('user_id', $admin->getKey())->first();
        $workerRow = DB::table('user_management')->where('user_id', $worker->getKey())->first();
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($adminRow->status));
        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($workerRow->status));

        $this->get(route('user-management.index', ['tab' => 'workers']))
            ->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    private function actingAsAdminSession(): User
    {
        $admin = User::query()->where('email', 'creator.admin@example.test')->first();
        if ($admin === null) {
            $admin = User::factory()->create([
                'email' => 'creator.admin@example.test',
                'username' => 'creator.admin',
                'password' => 'AdminPass!123',
                'status' => StaffAccountStatus::ACTIVE,
                'must_change_password' => false,
            ]);
            $admin->assignCurrentAppointment([
                'role' => StaffRole::ADMIN,
                'assigned_barangay' => 'La Medalla',
                'assigned_zone' => 'Zone 1',
                'date_appointed' => '2020-01-01',
            ]);
        }

        $this->actingAs($admin);
        $this->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        return $admin->fresh(['currentAppointment']);
    }

    /**
     * @param  array<string, mixed>  $userOverrides
     * @param  array<string, mixed>  $appointmentOverrides
     */
    private function seedWorker(array $userOverrides = [], array $appointmentOverrides = []): User
    {
        $this->actingAsAdminSession();

        $worker = User::factory()->create(array_merge([
            'first_name' => 'Maria',
            'middle_name' => 'Cruz',
            'last_name' => 'Reyes',
            'status' => StaffAccountStatus::ACTIVE,
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validUpdatePayload(User $worker, array $overrides = []): array
    {
        $appointment = $worker->currentAppointment;

        return array_merge([
            'sex' => (string) $worker->sex,
            'hw_first_name' => (string) $worker->first_name,
            'hw_last_name' => (string) $worker->last_name,
            'hw_middle_name' => (string) ($worker->middle_name ?: 'Cruz'),
            'hw_suffix' => (string) ($worker->suffix ?? 'N/A'),
            'hw_dob' => $worker->date_of_birth?->format('Y-m-d') ?? '1990-02-02',
            'hw_civil_status' => (string) ($worker->civil_status ?: 'Single'),
            'hw_nationality' => (string) ($worker->nationality ?: 'Filipino'),
            'hw_mobile' => (string) $worker->mobile_number,
            'hw_email' => (string) $worker->email,
            'hw_house_no' => (string) $worker->house_no,
            'hw_street' => (string) $worker->street,
            'hw_purok_zone' => (string) $worker->purok_zone,
            'hw_barangay' => (string) $worker->barangay,
            'hw_municipality' => (string) $worker->municipality_city,
            'hw_province' => (string) $worker->province,
            'hw_zip' => (string) $worker->zip_code,
            'hw_role' => StaffRole::normalize($appointment?->role) ?? StaffRole::ADMIN,
            'hw_assigned_barangay' => (string) ($appointment?->assigned_barangay ?? 'La Medalla'),
            'hw_assigned_zone' => (string) ($appointment?->assigned_zone ?? 'Zone 1'),
            'hw_date_appointed' => $appointment?->date_appointed?->format('Y-m-d') ?? '2020-01-01',
            'hw_end_appointment' => $appointment?->end_of_appointment?->format('Y-m-d') ?? '2030-12-31',
            'hw_username' => (string) $worker->username,
            'hw_status' => StaffAccountStatus::ACTIVE,
        ], $overrides);
    }

    private function createErdStaff(string $email, string $username, string $role): User
    {
        $userId = (int) DB::table('user_management')->insertGetId([
            'first_name' => 'Erd',
            'middle_name' => 'Test',
            'last_name' => 'Staff',
            'sex' => 'Female',
            'date_of_birth' => '1990-02-02',
            'civil_status' => 'Single',
            'nationality' => 'Filipino',
            'mobile_number' => '09171234567',
            'email' => $email,
            'username' => $username,
            'house_no' => '12',
            'street' => 'Sampaguita St.',
            'purok_zone' => 'Zone 1',
            'barangay' => 'La Medalla',
            'municipality_city' => 'Iriga City',
            'province' => 'Camarines Sur',
            'zip_code' => '4431',
            'password' => 'hashed-placeholder',
            'status' => 'Active',
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'user_id');

        DB::table('worker_appointments')->insert([
            'user_id' => $userId,
            'role' => UserManagementErdMode::appointmentRoleForStorage($role),
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-15',
            'end_of_appointment' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($userId);
    }
}
