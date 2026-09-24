<?php

namespace Tests\Feature;

use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAuthLogoutCacheHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_dashboard_html_is_not_cacheable(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $response = $this->get(route('dashboard'))->assertOk();
        $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('0', $response->headers->get('Expires'));
    }

    public function test_logout_invalidates_session_and_rejects_protected_urls(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $this->get(route('dashboard'))->assertOk();
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('household-profiling.index'))->assertRedirect(route('login'));
    }

    public function test_login_still_works_after_logout_and_public_pages_remain(): void
    {
        $this->actingAsStaff(StaffRole::BHW, [
            'email' => 'relogin.worker@example.test',
            'username' => 'relogin.worker',
            'password' => 'ReloginPass!1',
        ]);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->get(route('login'))->assertOk();
        $this->get(route('landing'))->assertOk();

        $login = $this->get(route('login'));
        $loginCache = strtolower((string) $login->headers->get('Cache-Control', ''));
        $this->assertStringNotContainsString('no-store', $loginCache);

        $this->post(route('login.store'), [
            'email' => 'relogin.worker@example.test',
            'password' => 'ReloginPass!1',
        ])->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertOk();
    }
}
