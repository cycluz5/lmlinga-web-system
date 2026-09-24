<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Actor vs Target isolation for Health Worker activate/deactivate.
 *
 * ACTOR = authenticated Admin session owner.
 * TARGET = route {id} Health Worker being mutated.
 */
class AdminHealthWorkerLifecycleSessionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_activate_keeps_admin_authenticated_and_updates_target_only(): void
    {
        $admin = $this->seedAdmin('lifecycle.a.admin@example.test', 'lifecycle.a.admin');
        $worker = $this->seedWorker('lifecycle.a.worker@example.test', 'lifecycle.a.worker');
        $worker->deactivate();

        $this->actingAsAdmin($admin);
        $tokenBefore = session()->token();

        $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.activate', ['id' => (string) $worker->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']))
            ->assertSessionHas('status');

        $this->assertTrue(Auth::check());
        $this->assertAuthenticatedAs($admin);
        $this->assertSame((string) $admin->getAuthIdentifier(), (string) Auth::id());
        $this->assertSame($tokenBefore, session()->token());
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($admin->fresh()->status));
        $this->assertNotSame(route('login'), url()->previous());
    }

    public function test_b_deactivate_keeps_admin_authenticated_and_suspends_target_only(): void
    {
        $admin = $this->seedAdmin('lifecycle.b.admin@example.test', 'lifecycle.b.admin');
        $worker = $this->seedWorker('lifecycle.b.worker@example.test', 'lifecycle.b.worker');

        $this->actingAsAdmin($admin);
        $tokenBefore = session()->token();

        $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.deactivate', ['id' => (string) $worker->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']))
            ->assertSessionHas('status');

        $this->assertTrue(Auth::check());
        $this->assertAuthenticatedAs($admin);
        $this->assertSame((string) $admin->getAuthIdentifier(), (string) Auth::id());
        $this->assertSame($tokenBefore, session()->token());
        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($admin->fresh()->status));
    }

    public function test_c_suspended_health_worker_next_protected_request_is_logged_out(): void
    {
        $worker = $this->seedWorker('lifecycle.c.worker@example.test', 'lifecycle.c.worker');

        $this->actingAs($worker);
        $this->withSession([
            Auth::guard()->getName() => $worker->getAuthIdentifier(),
            UiRole::SESSION_KEY => StaffRole::BHW,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);
        $this->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($worker);

        $worker->deactivate();
        $tokenBefore = session()->token();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotSame($tokenBefore, session()->token());
    }

    public function test_d_suspended_health_worker_cannot_log_in(): void
    {
        $worker = $this->seedWorker('lifecycle.d.worker@example.test', 'lifecycle.d.worker', [
            'password' => 'WorkerPass!123',
        ]);
        $worker->deactivate();

        $this->post(route('login.store'), [
            'email' => 'lifecycle.d.worker@example.test',
            'password' => 'WorkerPass!123',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_e_reactivation_allows_login_while_admin_stays_authenticated(): void
    {
        $admin = $this->seedAdmin('lifecycle.e.admin@example.test', 'lifecycle.e.admin');
        $worker = $this->seedWorker('lifecycle.e.worker@example.test', 'lifecycle.e.worker', [
            'password' => 'WorkerPass!123',
        ]);
        $worker->deactivate();

        $this->actingAsAdmin($admin);
        $adminId = (string) $admin->getAuthIdentifier();
        $tokenBefore = session()->token();

        $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.activate', ['id' => (string) $worker->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']));

        $this->assertAuthenticatedAs($admin);
        $this->assertSame($adminId, (string) Auth::id());
        $this->assertSame($tokenBefore, session()->token());
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));

        $this->app['auth']->logout();
        $this->flushSession();

        $this->post(route('login.store'), [
            'email' => 'lifecycle.e.worker@example.test',
            'password' => 'WorkerPass!123',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($worker->fresh());
    }

    public function test_f_repeated_deactivate_activate_never_419_and_admin_id_stable(): void
    {
        $admin = $this->seedAdmin('lifecycle.f.admin@example.test', 'lifecycle.f.admin');
        $worker = $this->seedWorker('lifecycle.f.worker@example.test', 'lifecycle.f.worker');

        $this->actingAsAdmin($admin);
        $adminId = (string) $admin->getAuthIdentifier();
        $tokenBefore = session()->token();

        $ops = ['deactivate', 'activate', 'deactivate', 'activate'];
        foreach ($ops as $op) {
            $route = $op === 'deactivate'
                ? 'user-management.health-workers.deactivate'
                : 'user-management.health-workers.activate';

            $response = $this->from(route('user-management.index'))
                ->post(route($route, ['id' => (string) $worker->id]));

            $this->assertNotSame(419, $response->status(), "Unexpected 419 on {$op}");
            $response->assertRedirect(route('user-management.index', ['tab' => 'workers']));
            $this->assertTrue(Auth::check());
            $this->assertAuthenticatedAs($admin);
            $this->assertSame($adminId, (string) Auth::id());
            $this->assertSame($tokenBefore, session()->token());
        }

        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));
        $this->get(route('user-management.index', ['tab' => 'workers']))->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_g_deactivating_b_does_not_affect_admin_a_or_worker_c(): void
    {
        $adminA = $this->seedAdmin('lifecycle.g.admin@example.test', 'lifecycle.g.admin');
        $workerB = $this->seedWorker('lifecycle.g.worker.b@example.test', 'lifecycle.g.worker.b');
        $workerC = $this->seedWorker('lifecycle.g.worker.c@example.test', 'lifecycle.g.worker.c');

        $this->actingAsAdmin($adminA);
        $tokenBefore = session()->token();

        $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.deactivate', ['id' => (string) $workerB->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']));

        $this->assertAuthenticatedAs($adminA);
        $this->assertSame((string) $adminA->getAuthIdentifier(), (string) Auth::id());
        $this->assertSame($tokenBefore, session()->token());
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($adminA->fresh()->status));
        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($workerB->fresh()->status));
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($workerC->fresh()->status));
        $this->assertNotSame((string) $workerB->getAuthIdentifier(), (string) Auth::id());
    }

    private function actingAsAdmin(User $admin): void
    {
        $this->actingAs($admin);
        $this->withSession([
            Auth::guard()->getName() => $admin->getAuthIdentifier(),
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);
    }

    private function seedAdmin(string $email, string $username): User
    {
        $admin = User::factory()->create([
            'email' => $email,
            'username' => $username,
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

        return $admin->fresh(['currentAppointment']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedWorker(string $email, string $username, array $overrides = []): User
    {
        $worker = User::factory()->create(array_merge([
            'email' => $email,
            'username' => $username,
            'first_name' => 'Maria',
            'middle_name' => 'Cruz',
            'last_name' => 'Reyes',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
        ], $overrides));

        $worker->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-15',
            'end_of_appointment' => '2030-12-31',
        ]);

        return $worker->fresh(['currentAppointment']);
    }
}
