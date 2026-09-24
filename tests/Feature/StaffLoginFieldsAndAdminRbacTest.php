<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffLoginFieldsAndAdminRbacTest extends TestCase
{
    use RefreshDatabase;

    private const WORKER_PASSWORD = 'WorkerPass!123';

    /**
     * @return list<list<string>>
     */
    public static function workerRoles(): array
    {
        return [
            [StaffRole::BHW],
            [StaffRole::BNS],
            [StaffRole::BSPO],
        ];
    }

    public function test_get_login_contains_no_previous_identity_from_laravel(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/id="email"[^>]*value="(?!")[^"]+/', $html);
        $this->assertStringNotContainsString("old('email')", $html);
        $this->assertStringNotContainsString('old(\'email\')', $html);
        $this->assertStringNotContainsString("old('username')", $html);
        $this->assertMatchesRegularExpression('/id="email"[^>]*value=""/', $html);
        $this->assertDoesNotMatchRegularExpression('/autocomplete="current-password"/', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*autocomplete="off"/', $html);
        $this->assertStringNotContainsString('maria.santos', $html);
    }

    public function test_get_login_contains_no_password_value(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/id="password"[^>]*value="(?!")[^"]+/', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringNotContainsString("old('password')", $html);
        $this->assertDoesNotMatchRegularExpression('/id="password"[^>]*autocomplete="current-password"/', $html);
    }

    public function test_staff_login_js_clears_visible_fields_without_storing_credentials(): void
    {
        $js = file_get_contents(resource_path('js/pages/staff-login.js'));
        $this->assertNotFalse($js);
        $this->assertStringContainsString("addEventListener('pageshow'", $js);
        $this->assertStringContainsString("addEventListener('load'", $js);
        $this->assertStringContainsString('DOMContentLoaded', $js);
        $this->assertStringContainsString('userHasInteracted', $js);
        $this->assertStringNotContainsString('localStorage', $js);
        $this->assertStringNotContainsString('sessionStorage', $js);
        $this->assertStringNotContainsString('document.cookie', $js);
        $this->assertDoesNotMatchRegularExpression('/setInterval\s*\(/', $js);
    }

    public function test_failed_login_does_not_flash_identity(): void
    {
        $this->from(route('login'))->post(route('login.store'), [
            'email' => 'magnus',
            'password' => 'wrong-password',
        ])->assertRedirect(route('login'));

        $this->assertNull(session()->getOldInput('email'));
        $this->assertNull(session()->getOldInput('username'));
        $this->assertNull(session('_old_input.email') ?? null);

        $html = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringNotContainsString('value="magnus"', $html);
        $this->assertDoesNotMatchRegularExpression('/id="email"[^>]*value="(?!")[^"]+/', $html);
    }

    public function test_failed_login_does_not_flash_password(): void
    {
        $this->from(route('login'))->post(route('login.store'), [
            'email' => 'magnus',
            'password' => 'wrong-password',
        ])->assertRedirect(route('login'));

        $this->assertNull(session()->getOldInput('password'));
        $this->assertFalse(session()->has('_old_input.password'));

        $html = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringNotContainsString('wrong-password', $html);
        $this->assertDoesNotMatchRegularExpression('/id="password"[^>]*value="(?!")[^"]+/', $html);
    }

    public function test_logout_returns_clean_login_application_state(): void
    {
        $worker = $this->seedStaff(StaffRole::BHW, [
            'email' => 'magnus.bhw@example.test',
            'username' => 'magnus',
        ]);

        $this->post(route('login.store'), [
            'email' => 'magnus',
            'password' => self::WORKER_PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($worker);

        $html = $this->followingRedirects()
            ->post(route('logout'))
            ->assertOk()
            ->getContent();
        $this->assertGuest();
        $this->assertNull(session()->getOldInput('email'));
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session(DemoStaffLogin::SESSION_EMAIL));
        $this->assertStringContainsString('data-lml-staff-login', $html);
        $this->assertStringNotContainsString('value="magnus"', $html);
        $this->assertStringNotContainsString('magnus.bhw@example.test', $html);
        $this->assertDoesNotMatchRegularExpression('/id="email"[^>]*value="(?!")[^"]+/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="password"[^>]*value="(?!")[^"]+/', $html);
        $this->assertStringNotContainsString(self::WORKER_PASSWORD, $html);

        $html = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringNotContainsString('value="magnus"', $html);
        $this->assertStringNotContainsString('magnus.bhw@example.test', $html);
        $this->assertDoesNotMatchRegularExpression('/id="email"[^>]*value="(?!")[^"]+/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="password"[^>]*value="(?!")[^"]+/', $html);
        $this->assertStringNotContainsString(self::WORKER_PASSWORD, $html);
    }

    public function test_authenticated_user_password_is_not_exposed(): void
    {
        $worker = $this->seedStaff(StaffRole::BHW, [
            'email' => 'secret.worker@example.test',
            'username' => 'secret.worker',
        ]);

        $this->post(route('login.store'), [
            'email' => 'secret.worker',
            'password' => self::WORKER_PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $dashboard = $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString(self::WORKER_PASSWORD, $dashboard);
        $this->assertStringNotContainsString((string) $worker->getAuthPassword(), $dashboard);

        $loginWhileAuthenticated = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringNotContainsString(self::WORKER_PASSWORD, $loginWhileAuthenticated);
        $this->assertDoesNotMatchRegularExpression('/id="password"[^>]*value="(?!")[^"]+/', $loginWhileAuthenticated);
        $this->assertStringNotContainsString((string) $worker->getAuthPassword(), $loginWhileAuthenticated);
    }

    #[DataProvider('workerRoles')]
    public function test_authenticated_worker_direct_user_management_returns_403(string $role): void
    {
        $worker = $this->seedStaff($role, [
            'email' => $role.'.direct@example.test',
            'username' => $role.'.direct',
        ]);

        $this->post(route('login.store'), [
            'email' => $role.'.direct',
            'password' => self::WORKER_PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($worker);

        $this->get(route('dashboard'))->assertOk();

        $this->get(route('user-management.index'))
            ->assertForbidden()
            ->assertSee('Administrator access is required', false);

        $this->assertAuthenticatedAs($worker);
    }

    public function test_authenticated_admin_user_management_returns_200(): void
    {
        $admin = $this->seedStaff(StaffRole::ADMIN, [
            'email' => 'admin.direct@example.test',
            'username' => 'admin.direct',
        ]);

        $this->post(route('login.store'), [
            'email' => 'admin.direct',
            'password' => self::WORKER_PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
        $this->get(route('user-management.index'))->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_unauthenticated_user_management_redirects_to_login(): void
    {
        $this->get(route('user-management.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedStaff(string $role, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'password' => self::WORKER_PASSWORD,
            'must_change_password' => false,
            'status' => StaffAccountStatus::ACTIVE,
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
