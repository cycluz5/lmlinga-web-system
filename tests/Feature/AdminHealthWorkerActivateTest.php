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

class AdminHealthWorkerActivateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_activate_a_deactivated_worker_and_login_works_again(): void
    {
        $worker = $this->seedWorker([
            'email' => 'activate.ok@example.test',
            'username' => 'activate.ok',
            'password' => 'WorkerPass!123',
        ]);
        $worker->deactivate();
        $appointmentId = $worker->currentAppointment?->getKey();
        $beforeUsers = User::query()->count();
        $beforeAppointments = WorkerAppointment::query()->where('user_id', $worker->id)->count();

        $this->actingAsAdminSession();
        $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.activate', ['id' => (string) $worker->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']))
            ->assertSessionHas('status');

        $this->assertSame($beforeUsers, User::query()->count());
        $worker->refresh();
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($worker->status));
        $this->assertSame($beforeAppointments, WorkerAppointment::query()->where('user_id', $worker->id)->count());
        $this->assertSame($appointmentId, $worker->fresh(['currentAppointment'])->currentAppointment?->getKey());

        $this->app['auth']->logout();
        $this->flushSession();

        $this->post(route('login.store'), [
            'email' => 'activate.ok@example.test',
            'password' => 'WorkerPass!123',
        ])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_view_shows_activate_only_for_inactive_worker(): void
    {
        $this->actingAsAdminSession();
        $activeWorker = $this->seedWorker([
            'email' => 'stays.active@example.test',
            'username' => 'stays.active',
        ]);
        $inactiveWorker = $this->seedWorker([
            'email' => 'stays.inactive@example.test',
            'username' => 'stays.inactive',
        ]);
        $inactiveWorker->deactivate();

        $this->actingAsAdminSession();
        $activeView = $this->get(route('user-management.health-workers.view', ['id' => (string) $activeWorker->id]))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('data-hw-activate', $activeView);
        $this->assertStringNotContainsString('Activate Account', $activeView);

        $this->actingAsAdminSession();
        $inactiveView = $this->get(route('user-management.health-workers.view', ['id' => (string) $inactiveWorker->id]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-hw-activate', $inactiveView);
        $this->assertStringContainsString('Activate Account', $inactiveView);
        $this->assertStringNotContainsString('data-hw-deactivate-id="'.$inactiveWorker->id.'"', $inactiveView);
    }

    public function test_admin_session_survives_activating_another_worker(): void
    {
        $admin = $this->actingAsAdminSession();
        $worker = $this->seedWorker([
            'email' => 'session.survive@example.test',
            'username' => 'session.survive',
        ]);
        $worker->deactivate();

        $this->actingAs($admin);
        $this->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $response = $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.activate', ['id' => (string) $worker->id]));

        $response->assertRedirect(route('user-management.index', ['tab' => 'workers']));
        $this->assertAuthenticatedAs($admin);

        $follow = $this->get(route('user-management.index', ['tab' => 'workers']));
        $follow->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_index_card_shows_activate_for_inactive_worker(): void
    {
        $this->actingAsAdminSession();
        $inactiveWorker = $this->seedWorker([
            'email' => 'card.inactive@example.test',
            'username' => 'card.inactive',
        ]);
        $inactiveWorker->deactivate();

        $this->actingAsAdminSession();
        $index = $this->get(route('user-management.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-hw-activate-id="'.$inactiveWorker->id.'"', $index);
        $this->assertStringContainsString('Activate Account', $index);
        $this->assertStringContainsString('Activate', $index);
        $this->assertStringNotContainsString('data-hw-deactivate-id="'.$inactiveWorker->id.'"', $index);
    }

    public function test_admin_account_cannot_be_activated_through_the_route(): void
    {
        $this->actingAsAdminSession();
        $target = $this->seedWorker([
            'email' => 'inactive.admin@example.test',
            'username' => 'inactive.admin',
        ], [
            'role' => StaffRole::ADMIN,
        ]);
        $target->deactivate();

        $this->actingAsAdminSession();
        $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.activate', ['id' => (string) $target->id]))
            ->assertRedirect()
            ->assertSessionHasErrors('hw_status');

        $target->refresh();
        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($target->status));
        $this->assertSame(StaffRole::ADMIN, $target->role);
    }

    public function test_view_and_card_never_show_activate_for_admin_accounts(): void
    {
        $this->actingAsAdminSession();
        $inactiveAdmin = $this->seedWorker([
            'email' => 'hidden.admin@example.test',
            'username' => 'hidden.admin',
        ], [
            'role' => StaffRole::ADMIN,
        ]);
        $inactiveAdmin->deactivate();

        $this->actingAsAdminSession();
        $view = $this->get(route('user-management.health-workers.view', ['id' => (string) $inactiveAdmin->id]))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('data-hw-activate', $view);

        $this->actingAsAdminSession();
        $index = $this->get(route('user-management.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('data-hw-activate-id="'.$inactiveAdmin->id.'"', $index);
    }

    public function test_service_rejects_admin_activation(): void
    {
        $this->actingAsAdminSession();
        $target = $this->seedWorker([
            'email' => 'service.inactive.admin@example.test',
            'username' => 'service.inactive.admin',
        ], [
            'role' => StaffRole::ADMIN,
        ]);
        $target->deactivate();

        try {
            app(HealthWorkerAccountService::class)->activateAccount($target->fresh());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hw_status', $exception->errors());
        }

        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($target->fresh()->status));
    }

    public function test_non_admin_cannot_activate_another_account(): void
    {
        $worker = $this->seedWorker([
            'email' => 'target.inactive@example.test',
            'username' => 'target.inactive',
        ]);
        $worker->deactivate();
        $this->actingAsStaff(StaffRole::BHW, [
            'email' => 'actor.bhw2@example.test',
            'username' => 'actor.bhw2',
        ]);

        $this->post(route('user-management.health-workers.activate', ['id' => (string) $worker->id]))
            ->assertForbidden();

        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));
    }

    public function test_unauthenticated_activate_redirects_to_login(): void
    {
        $worker = $this->seedWorker([
            'email' => 'guest.activate@example.test',
            'username' => 'guest.activate',
        ]);
        $worker->deactivate();

        $this->app['auth']->logout();
        $this->flushSession();

        $this->post(route('user-management.health-workers.activate', ['id' => (string) $worker->id]))
            ->assertRedirect(route('login'));

        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));
    }

    public function test_already_active_account_is_idempotent(): void
    {
        $worker = $this->seedWorker([
            'email' => 'already.active@example.test',
            'username' => 'already.active',
        ]);
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));
        $before = User::query()->count();

        $this->actingAsAdminSession();
        $this->post(route('user-management.health-workers.activate', ['id' => (string) $worker->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']))
            ->assertSessionHas('status');

        $this->assertSame($before, User::query()->count());
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));
    }

    public function test_erd_mode_activates_user_management_row(): void
    {
        ClientTestingErdSchema::ensure();
        UserManagementErdMode::resetCachedState();
        $this->assertTrue(UserManagementErdMode::isActive());

        $admin = $this->createErdStaff('erd.admin.act@example.test', 'erd.admin.act', StaffRole::ADMIN);
        $worker = $this->createErdStaff('erd.bhw.act@example.test', 'erd.bhw.act', StaffRole::BHW);
        DB::table('user_management')->where('user_id', $worker->getKey())->update([
            'status' => StaffAccountStatus::INACTIVE,
        ]);

        $this->actingAs($admin);
        $this->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $before = (int) DB::table('user_management')->count();

        $this->post(route('user-management.health-workers.activate', ['id' => (string) $worker->getKey()]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']));

        $this->assertSame($before, (int) DB::table('user_management')->count());
        $row = DB::table('user_management')->where('user_id', $worker->getKey())->first();
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($row->status));
    }

    private function actingAsAdminSession(): User
    {
        $admin = User::query()->where('email', 'creator.admin.act@example.test')->first();
        if ($admin === null) {
            $admin = User::factory()->create([
                'email' => 'creator.admin.act@example.test',
                'username' => 'creator.admin.act',
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
