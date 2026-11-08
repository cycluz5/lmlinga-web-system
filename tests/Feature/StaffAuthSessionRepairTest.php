<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StaffAuthSessionRepairTest extends TestCase
{
    use RefreshDatabase;

    private function seedAdmin(array $overrides = []): User
    {
        $admin = User::factory()->create(array_merge([
            'first_name' => 'Admin',
            'middle_name' => 'A',
            'last_name' => 'User',
            'suffix' => 'N/A',
            'email' => 'admin.auth@example.test',
            'username' => 'admin.auth',
            'password' => 'AdminPass!123',
            'must_change_password' => false,
            'status' => StaffAccountStatus::ACTIVE,
        ], $overrides));

        $admin->assignCurrentAppointment([
            'role' => StaffRole::ADMIN,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        return $admin->fresh(['currentAppointment']);
    }

    private function seedBhw(array $overrides = []): User
    {
        $worker = User::factory()->create(array_merge([
            'first_name' => 'Juan',
            'middle_name' => 'Carlos',
            'last_name' => 'Taway',
            'suffix' => 'N/A',
            'email' => 'juan.taway@example.test',
            'username' => 'juan.taway',
            'password' => 'WorkerPass!123',
            'must_change_password' => false,
            'status' => StaffAccountStatus::ACTIVE,
        ], $overrides));

        $worker->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 2',
            'date_appointed' => '2021-06-01',
        ]);

        return $worker->fresh(['currentAppointment']);
    }

    public function test_database_admin_login_establishes_auth_user(): void
    {
        $admin = $this->seedAdmin();

        $this->post(route('login.store'), [
            'email' => 'admin.auth@example.test',
            'password' => 'AdminPass!123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
        $this->assertSame(StaffRole::ADMIN, session(UiRole::SESSION_KEY));
        $this->assertTrue(session(DemoStaffLogin::SESSION_LOGIN_ESTABLISHED));
    }

    public function test_admin_creates_bhw_and_remains_authenticated_admin(): void
    {
        $admin = $this->seedAdmin();

        $this->post(route('login.store'), [
            'email' => 'admin.auth@example.test',
            'password' => 'AdminPass!123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);

        $this->post(route('user-management.health-workers.store'), [
            'first_name' => 'New',
            'last_name' => 'Worker',
            'middle_name' => '',
            'email' => 'new.bhw@example.test',
            'mobile' => '09170001111',
            'role' => 'BHW',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => 'TempPass!123',
            'password_confirmation' => 'TempPass!123',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($admin);
        $this->assertSame($admin->id, Auth::id());
    }

    public function test_created_by_references_admin_when_authenticated(): void
    {
        $admin = $this->seedAdmin();

        $this->actingAs($admin);
        session([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $this->post(route('user-management.health-workers.store'), [
            'first_name' => 'Created',
            'last_name' => 'ByAdmin',
            'middle_name' => '',
            'email' => 'created.by.admin@example.test',
            'mobile' => '09170002222',
            'role' => 'BHW',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => 'TempPass!123',
            'password_confirmation' => 'TempPass!123',
        ])->assertRedirect();

        $worker = User::query()->where('email', 'created.by.admin@example.test')->first();
        $this->assertNotNull($worker);
        $this->assertSame($admin->id, $worker->created_by);
    }

    public function test_post_logout_clears_auth(): void
    {
        $admin = $this->seedAdmin();

        $this->post(route('login.store'), [
            'email' => 'admin.auth@example.test',
            'password' => 'AdminPass!123',
        ]);

        $this->assertAuthenticated();

        $this->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_post_logout_invalidates_staff_identity_session_values(): void
    {
        $admin = $this->seedAdmin();

        $this->post(route('login.store'), [
            'email' => 'admin.auth@example.test',
            'password' => 'AdminPass!123',
        ]);

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull(session(UiRole::SESSION_KEY));
        $this->assertNull(session(DemoStaffLogin::SESSION_DISPLAY_NAME));
        $this->assertNull(session(DemoStaffLogin::SESSION_EMAIL));
        $this->assertNull(session(DemoStaffLogin::SESSION_LOGIN_ESTABLISHED));
    }

    public function test_bhw_login_displays_bhw_identity(): void
    {
        $this->seedBhw();

        $this->post(route('login.store'), [
            'email' => 'juan.taway@example.test',
            'password' => 'WorkerPass!123',
        ])->assertRedirect(route('dashboard'));

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Juan Carlos Taway', $html);
        $this->assertStringNotContainsString('Juan Carlos Taway BHW', $html);
        $this->assertSame(StaffRole::BHW, UiRole::current());
    }

    public function test_bhw_logout_then_admin_login_resolves_admin_identity(): void
    {
        $this->seedBhw();
        $this->seedAdmin();

        $this->post(route('login.store'), [
            'email' => 'juan.taway@example.test',
            'password' => 'WorkerPass!123',
        ])->assertRedirect(route('dashboard'));

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post(route('login.store'), [
            'email' => 'admin.auth@example.test',
            'password' => 'AdminPass!123',
        ])->assertRedirect(route('dashboard'));

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Admin A User', $html);
        $this->assertStringContainsString('Admin', $html);
        $this->assertStringNotContainsString('Juan Carlos Taway', $html);
        $this->assertSame(StaffRole::ADMIN, UiRole::current());
    }

    public function test_query_role_admin_cannot_elevate_authenticated_bhw(): void
    {
        $worker = $this->seedBhw();

        $this->actingAs($worker);
        session([
            UiRole::SESSION_KEY => StaffRole::BHW,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $this->get(route('user-management.index').'?role=admin')
            ->assertForbidden();

        $this->assertSame(StaffRole::BHW, UiRole::current());
    }

    public function test_session_only_role_cannot_access_admin_only_routes(): void
    {
        $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('user-management.index'))
            ->assertRedirect(route('login'));
    }

    public function test_logout_route_is_post_not_get(): void
    {
        $route = Route::getRoutes()->getByName('logout');
        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());

        $this->get(route('logout'))->assertStatus(405);
    }

    public function test_demo_login_does_not_grant_protected_module_access_without_auth(): void
    {
        config([
            'demo.staff_accounts' => [
                [
                    'email' => 'demo.admin@example.test',
                    'password' => 'demo-admin-pass',
                    'shell_role' => 'admin',
                    'display_name' => 'Demo Admin',
                    'identities' => ['demo.admin@example.test'],
                ],
            ],
        ]);

        $this->post(route('login.store'), [
            'email' => 'demo.admin@example.test',
            'password' => 'demo-admin-pass',
        ])->assertRedirect(route('dashboard'));

        $this->assertGuest();
        $this->assertSame('admin', session(UiRole::SESSION_KEY));
        $this->assertSame('Demo Admin', session(DemoStaffLogin::SESSION_DISPLAY_NAME));
        $this->assertTrue(session(DemoStaffLogin::SESSION_LOGIN_ESTABLISHED));

        $this->get(route('user-management.index'))->assertRedirect(route('login'));
    }

    public function test_dashboard_logout_controls_use_post_forms(): void
    {
        $this->seedAdmin();

        $this->post(route('login.store'), [
            'email' => 'admin.auth@example.test',
            'password' => 'AdminPass!123',
        ]);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('action="'.e(route('logout')).'"', $html);
        $this->assertStringContainsString('method="post"', strtolower($html));
        $this->assertStringNotContainsString('href="'.e(route('login')).'" class="lml-topbar__user-logout"', $html);
        $this->assertStringNotContainsString('href="'.e(route('login')).'" class="lml-sidebar__logout', $html);
    }
}
