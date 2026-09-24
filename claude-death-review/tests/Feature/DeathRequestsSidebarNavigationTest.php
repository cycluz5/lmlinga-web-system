<?php

namespace Tests\Feature;

use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DeathRequestsSidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_requests_contains_household_and_death_requests(): void
    {
        $this->assertTrue(Route::has('household-requests.index'));
        $this->assertTrue(Route::has('death-requests.index'));

        $response = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('dashboard'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('>Requests</span>', $html);
        $this->assertStringContainsString('id="lml-sidebar-collapse-requests"', $html);
        $this->assertStringContainsString('>Household Requests</span>', $html);
        $this->assertStringContainsString('>Death Requests</span>', $html);
        $this->assertStringContainsString('href="'.e(route('household-requests.index')).'"', $html);
        $this->assertStringContainsString('href="'.e(route('death-requests.index')).'"', $html);
        $this->assertStringContainsString('>Health Records</span>', $html);
        $this->assertStringContainsString('>Death</span>', $html);
    }

    public function test_bhw_does_not_see_death_requests_nav(): void
    {
        $html = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('dashboard'))
            ->getContent();

        $this->assertStringNotContainsString('id="lml-sidebar-collapse-requests"', $html);
        $this->assertStringNotContainsString('>Death Requests</span>', $html);
        $this->assertStringNotContainsString('>Household Requests</span>', $html);
        $this->assertStringContainsString('>Health Records</span>', $html);
        $this->assertStringContainsString('>Death</span>', $html);
    }

    public function test_death_requests_active_state_does_not_activate_household_or_health_records_death(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('death-requests.index'));

        $response->assertOk();
        $this->assertSame('death-requests', UiRole::sidebarActiveKey());
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/id="lml-sidebar-collapse-requests"[^>]*\bis-open\b/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink--active"[\s\S]{0,80}aria-current="page"[\s\S]{0,40}>\s*<i[^>]*><\/i>\s*<span>Death Requests<\/span>/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink--active"[\s\S]{0,80}aria-current="page"[\s\S]{0,40}>\s*<i[^>]*><\/i>\s*<span>Household Requests<\/span>/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-sidebar-collapse-health-records"[^>]*\bis-open\b/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink--active"[\s\S]{0,80}aria-current="page"[\s\S]{0,40}>\s*<i[^>]*><\/i>\s*<span>Death<\/span>/u',
            $html
        );
    }

    public function test_household_requests_page_still_renders_and_is_active_child(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('household-requests.index'));

        $response->assertOk();
        $this->assertSame('household-requests', UiRole::sidebarActiveKey());
        $html = $response->getContent();
        $this->assertStringContainsString('data-lml-household-requests', $html);
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink--active"[\s\S]{0,80}aria-current="page"[\s\S]{0,40}>\s*<i[^>]*><\/i>\s*<span>Household Requests<\/span>/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink--active"[\s\S]{0,80}aria-current="page"[\s\S]{0,40}>\s*<i[^>]*><\/i>\s*<span>Death Requests<\/span>/u',
            $html
        );
    }

    public function test_health_records_death_does_not_activate_death_requests(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('health-records.death.index'));

        $response->assertOk();
        $this->assertSame('death', UiRole::sidebarActiveKey());
        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink--active"[\s\S]{0,80}aria-current="page"[\s\S]{0,40}>\s*<i[^>]*><\/i>\s*<span>Death<\/span>/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-sidebar-collapse-requests"[^>]*\bis-open\b/u',
            $html
        );
    }
}
