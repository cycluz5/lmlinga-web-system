<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkerAppointment;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UserManagementErdMode;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HealthWorkerCreateLoginFlowTest extends TestCase
{
    use RefreshDatabase;

    private function seedAdmin(): User
    {
        $admin = User::factory()->create([
            'first_name' => 'Maria',
            'middle_name' => 'Lopez',
            'last_name' => 'Santos',
            'email' => 'maria.admin.flow@example.test',
            'username' => 'maria.admin.flow',
            'password' => 'AdminFlow!1',
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

    public function test_admin_create_worker_persists_user_and_appointment_then_worker_can_login(): void
    {
        $admin = $this->seedAdmin();

        $this->post(route('login.store'), [
            'email' => 'maria.admin.flow@example.test',
            'password' => 'AdminFlow!1',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);

        $this->post(route('user-management.health-workers.store'), [
            'first_name' => 'Created',
            'middle_name' => 'Flow',
            'last_name' => 'Worker',
            'email' => 'created.flow@example.test',
            'username' => 'created.flow',
            'mobile' => '09171234567',
            'role' => 'BHW',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => 'WorkerFlow!9',
            'password_confirmation' => 'WorkerFlow!9',
        ])->assertRedirect();

        $worker = User::query()->where('email', 'created.flow@example.test')->firstOrFail();
        $this->assertSame('Created', $worker->first_name);
        $this->assertSame('Flow', $worker->middle_name);
        $this->assertSame('Worker', $worker->last_name);
        if (UserManagementErdMode::isActive()) {
            $this->assertSame('created.flow', $worker->username);
        }
        $this->assertSame(StaffAccountStatus::ACTIVE, $worker->status);
        $this->assertSame($admin->id, $worker->created_by);
        $this->assertTrue(Hash::check('WorkerFlow!9', (string) $worker->password));

        $appointment = WorkerAppointment::query()
            ->where('user_id', $worker->id)
            ->latest('appointment_id')
            ->first();

        $this->assertNotNull($appointment);
        $this->assertSame(StaffRole::BHW, StaffRole::normalize($appointment->role));

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertFalse(Auth::check());

        $this->post(route('login.store'), [
            'email' => 'created.flow@example.test',
            'password' => 'WorkerFlow!9',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($worker);
        $this->assertSame(StaffRole::BHW, session(UiRole::SESSION_KEY));
        $this->assertTrue(session(DemoStaffLogin::SESSION_LOGIN_ESTABLISHED));

        $this->get(route('dashboard'))->assertOk()->assertSee('Created Flow Worker', false);

        $this->post(route('password.change.store'), [
            'new_password' => 'WorkerFlow!9',
            'new_password_confirmation' => 'WorkerFlow!9',
        ])->assertRedirect(route('profile.show'));

        $dashboard = $this->get(route('dashboard'));
        $dashboard->assertSee('Created Flow Worker', false);

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'email' => 'maria.admin.flow@example.test',
            'password' => 'AdminFlow!1',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
        $dashboard = $this->get(route('dashboard'));
        $dashboard->assertSee('Maria Lopez Santos', false);
        $dashboard->assertDontSee('Created Flow Worker', false);
    }
}
