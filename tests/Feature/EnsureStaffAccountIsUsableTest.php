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

class EnsureStaffAccountIsUsableTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_admin_remains_authenticated_when_another_worker_is_inactive(): void
    {
        $admin = $this->seedStaff(StaffRole::ADMIN, [
            'email' => 'usable.admin@example.test',
            'username' => 'usable.admin',
        ]);
        $worker = $this->seedStaff(StaffRole::BHW, [
            'email' => 'usable.worker@example.test',
            'username' => 'usable.worker',
        ]);
        $worker->deactivate();

        $this->actingAs($admin);
        $this->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $tokenBefore = session()->token();

        $this->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($admin);
        $this->assertSame($tokenBefore, session()->token());
        $this->assertTrue(Auth::user()->isActive());
        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($worker->fresh()->status));
    }

    public function test_inactive_staff_is_logged_out_and_csrf_regenerated(): void
    {
        $worker = $this->seedStaff(StaffRole::BHW, [
            'email' => 'inactive.self@example.test',
            'username' => 'inactive.self',
        ]);
        $worker->deactivate();

        $this->actingAs($worker);
        $this->withSession([
            UiRole::SESSION_KEY => StaffRole::BHW,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $tokenBefore = session()->token();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotSame($tokenBefore, session()->token());
    }

    public function test_deactivated_worker_session_is_forced_to_login_while_admin_stays(): void
    {
        $admin = $this->seedStaff(StaffRole::ADMIN, [
            'email' => 'kick.admin@example.test',
            'username' => 'kick.admin',
        ]);
        $worker = $this->seedStaff(StaffRole::BHW, [
            'email' => 'kick.worker@example.test',
            'username' => 'kick.worker',
        ]);

        // Worker is logged in on their own session/browser.
        $this->actingAs($worker);
        $this->withSession([
            Auth::guard()->getName() => $worker->getAuthIdentifier(),
            UiRole::SESSION_KEY => StaffRole::BHW,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);
        $this->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($worker);

        // Admin deactivates that worker from a separate auth context.
        $this->actingAs($admin);
        $this->withSession([
            Auth::guard()->getName() => $admin->getAuthIdentifier(),
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);
        $this->post(route('user-management.health-workers.deactivate', ['id' => (string) $worker->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']));
        $this->assertAuthenticatedAs($admin);

        // Worker's next request must go to login (their own browser session).
        $worker->refresh();
        $this->assertFalse($worker->isActive());

        $this->actingAs($worker);
        $this->withSession([
            Auth::guard()->getName() => $worker->getAuthIdentifier(),
            UiRole::SESSION_KEY => StaffRole::BHW,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_lifecycle_routes_never_logout_active_admin_for_other_worker(): void
    {
        $admin = $this->seedStaff(StaffRole::ADMIN, [
            'email' => 'lifecycle.admin@example.test',
            'username' => 'lifecycle.admin',
        ]);
        $worker = $this->seedStaff(StaffRole::BHW, [
            'email' => 'lifecycle.worker@example.test',
            'username' => 'lifecycle.worker',
        ]);
        $worker->deactivate();

        $this->actingAs($admin);
        $this->withSession([
            Auth::guard()->getName() => $admin->getAuthIdentifier(),
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $tokenBefore = session()->token();

        $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.activate', ['id' => (string) $worker->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']))
            ->assertSessionHas('status');

        $this->assertAuthenticatedAs($admin);
        $this->assertSame($tokenBefore, session()->token());
        $this->get(route('user-management.index'))->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedStaff(string $role, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
        ], $overrides));

        $user->assignCurrentAppointment([
            'role' => $role,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        return $user->fresh(['currentAppointment']);
    }
}
