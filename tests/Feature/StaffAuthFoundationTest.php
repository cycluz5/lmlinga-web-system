<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffAuthFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_dashboard_redirects_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_unauthenticated_user_management_redirects_to_login(): void
    {
        $this->get(route('user-management.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_admin_can_access_user_management(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->get(route('user-management.index'))->assertOk();
    }

    public function test_authenticated_bhw_cannot_access_user_management(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $this->get(route('user-management.index'))->assertForbidden();
    }

    public function test_authenticated_bns_cannot_access_user_management(): void
    {
        $this->actingAsStaff(StaffRole::BNS);

        $this->get(route('user-management.index'))->assertForbidden();
    }

    public function test_authenticated_bspo_cannot_access_user_management(): void
    {
        $this->actingAsStaff(StaffRole::BSPO);

        $this->get(route('user-management.index'))->assertForbidden();
    }

    public function test_active_staff_login_succeeds(): void
    {
        $user = User::factory()->create([
            'email' => 'active.worker@example.test',
            'username' => 'active.worker',
            'password' => 'ActivePass!123',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
        ]);
        $user->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        $this->post(route('login.store'), [
            'email' => 'active.worker@example.test',
            'password' => 'ActivePass!123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user->fresh(['currentAppointment']));
    }

    public function test_inactive_staff_login_fails(): void
    {
        $user = User::factory()->inactive()->create([
            'email' => 'inactive.worker@example.test',
            'username' => 'inactive.worker',
            'password' => 'InactivePass!1',
        ]);
        $user->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        $this->post(route('login.store'), [
            'email' => 'inactive.worker@example.test',
            'password' => 'InactivePass!1',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_login_regenerates_session_id(): void
    {
        $user = User::factory()->create([
            'email' => 'session.worker@example.test',
            'username' => 'session.worker',
            'password' => 'SessionPass!1',
            'must_change_password' => false,
        ]);
        $user->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        $this->get(route('login'));
        $before = session()->getId();

        $this->post(route('login.store'), [
            'email' => 'session.worker@example.test',
            'password' => 'SessionPass!1',
        ])->assertRedirect(route('dashboard'));

        $this->assertNotSame($before, session()->getId());
    }

    public function test_logout_clears_authentication(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_must_change_password_does_not_block_dashboard(): void
    {
        $this->actingAsStaff(StaffRole::BHW, [
            'must_change_password' => true,
        ]);

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_must_change_password_allows_change_password_get(): void
    {
        $this->actingAsStaff(StaffRole::BHW, [
            'must_change_password' => true,
        ]);

        $this->get(route('password.change.required'))->assertOk();
    }

    public function test_valid_password_replacement_hashes_password(): void
    {
        $user = $this->actingAsStaff(StaffRole::BHW, [
            'must_change_password' => true,
            'password' => 'TempPass!123',
        ]);

        $this->post(route('password.change.store'), [
            'new_password' => 'NewStrong!456',
            'new_password_confirmation' => 'NewStrong!456',
        ])->assertRedirect(route('profile.show'));

        $this->assertTrue(Hash::check('NewStrong!456', (string) $user->fresh()->password));
    }

    public function test_successful_replacement_clears_must_change_password(): void
    {
        $this->actingAsStaff(StaffRole::BHW, [
            'must_change_password' => true,
            'password' => 'TempPass!123',
        ]);

        $this->post(route('password.change.store'), [
            'new_password' => 'NewStrong!456',
            'new_password_confirmation' => 'NewStrong!456',
        ])->assertRedirect(route('profile.show'));

        $this->assertFalse(Auth::user()->fresh()->must_change_password);
    }

    public function test_after_replacement_dashboard_is_allowed(): void
    {
        $this->actingAsStaff(StaffRole::BHW, [
            'must_change_password' => true,
            'password' => 'TempPass!123',
        ]);

        $this->post(route('password.change.store'), [
            'new_password' => 'NewStrong!456',
            'new_password_confirmation' => 'NewStrong!456',
        ])->assertRedirect(route('profile.show'));

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_authenticated_appointment_role_wins_over_stale_session_role(): void
    {
        $worker = $this->actingAsStaff(StaffRole::BHW);
        session([UiRole::SESSION_KEY => StaffRole::ADMIN]);

        $this->get(route('dashboard'))->assertOk();
        $this->assertSame(StaffRole::BHW, UiRole::current());
        $this->assertSame(StaffRole::BHW, $worker->role);
    }

    public function test_query_role_admin_cannot_grant_unauthenticated_admin_access(): void
    {
        $this->get(route('user-management.index').'?role=admin')
            ->assertRedirect(route('login'));
    }
}
