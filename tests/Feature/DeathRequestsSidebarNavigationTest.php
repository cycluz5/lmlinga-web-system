<?php

namespace Tests\Feature;

use App\Support\StaffRole;

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

        $this->actingAsStaff(StaffRole::ADMIN);
        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('>Requests</span>', $html);
        $this->assertStringContainsString('id="lml-sidebar-collapse-requests"', $html);
        $this->assertStringContainsString('>Household Requests</span>', $html);
        $this->assertStringContainsString('>Mortality</span>', $html);
        $this->assertStringContainsString('href="'.e(route('household-requests.index')).'"', $html);
        $this->assertStringContainsString('href="'.e(route('death-requests.index')).'"', $html);
        $this->assertStringContainsString('>Health Records</span>', $html);
        $this->assertStringContainsString('>Death</span>', $html);
    }

    public function test_bhw_sees_disabled_death_and_household_requests_nav(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('dashboard'))
            ->getContent();

        $this->assertStringContainsString('id="lml-sidebar-collapse-requests"', $html);
        $this->assertStringContainsString('>Mortality</span>', $html);
        $this->assertStringContainsString('>Household Requests</span>', $html);
        $this->assertStringContainsString('data-lml-admin-only', $html);
        $this->assertStringNotContainsString('href="'.e(route('death-requests.index')).'"', $html);
        $this->assertStringNotContainsString('href="'.e(route('household-requests.index')).'"', $html);
        $this->assertStringContainsString('>Health Records</span>', $html);
        $this->assertStringContainsString('>Death</span>', $html);
    }

    public function test_death_requests_active_state_does_not_activate_household_or_health_records_death(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $response = $this->get(route('death-requests.index'));

        $response->assertOk();
        $this->assertSame('death-requests', UiRole::sidebarActiveKey());
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/id="lml-sidebar-collapse-requests"[^>]*\bis-open\b/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink--active"[\s\S]{0,80}aria-current="page"[\s\S]{0,40}>\s*<i[^>]*><\/i>\s*<span>Mortality<\/span>/u',
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
        $this->actingAsStaff(StaffRole::ADMIN);
        $response = $this->get(route('household-requests.index'));

        $response->assertOk();
        $this->assertSame('household-requests', UiRole::sidebarActiveKey());
        $html = $response->getContent();
        $this->assertStringContainsString('data-lml-household-requests', $html);
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink--active"[\s\S]{0,80}aria-current="page"[\s\S]{0,40}>\s*<i[^>]*><\/i>\s*<span>Household Requests<\/span>/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink--active"[\s\S]{0,80}aria-current="page"[\s\S]{0,40}>\s*<i[^>]*><\/i>\s*<span>Mortality<\/span>/u',
            $html
        );
    }

    public function test_health_records_death_does_not_activate_death_requests(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $response = $this->get(route('health-records.death.index'));

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
