<?php

namespace Tests\Feature;

use App\Support\UiRole;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Health Records → Child Care → Deworming monitoring summary.
 */
class HealthRecordsDewormingTest extends TestCase
{
    public function test_deworming_route_resolves(): void
    {
        $this->assertTrue(Route::has('health-records.child-care.deworming'));

        $route = Route::getRoutes()->getByName('health-records.child-care.deworming');
        $this->assertNotNull($route);
        $this->assertSame('health-records/child-care/deworming', $route->uri());
    }

    public function test_deworming_page_renders_successfully(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bns'])
            ->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-deworming', $html);
        $this->assertStringContainsString('lml-hr-child-care--deworming', $html);
        $this->assertStringContainsString(
            'Record and management of deworming details for monitoring and tracking treatment status.',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-hr-deworming-heading"[^>]*>\s*Child Care\s*</u',
            $html
        );
    }

    public function test_deworming_pill_is_active_current(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Deworming\s*</u',
            $html
        );
    }

    public function test_vitamin_a_and_operation_timbang_pills_remain_present(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.vitamin-a')).'"',
            $html
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.operation-timbang')).'"',
            $html
        );
        $this->assertSame(1, preg_match_all('/>\s*Vitamin A\s*<\/a>/u', $html));
        $this->assertSame(1, preg_match_all('/>\s*Operation Timbang\s*<\/a>/u', $html));

        $this->assertMatchesRegularExpression(
            '/>\s*Vitamin A\s*<\/a>[\s\S]*>\s*Deworming\s*<\/a>[\s\S]*>\s*Operation Timbang\s*<\/a>/u',
            $html
        );
    }

    public function test_export_data_control_exists(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-dw-export', $html);
        $this->assertStringContainsString('aria-label="Export Deworming data"', $html);
        $this->assertMatchesRegularExpression('/>\s*Export Data\s*<\/span>/u', $html);
        $this->assertStringNotContainsString('data-hr-cc-add', $html);
    }

    public function test_summary_cards_render(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('First Round (July)', $html);
        $this->assertStringContainsString('Second Round (January)', $html);
        $this->assertStringContainsString('Received 1 dose/year', $html);
        $this->assertStringContainsString('Received 2 dose/year', $html);
        $this->assertMatchesRegularExpression(
            '/lml-hr-dw-card__label[^>]*>\s*Status\s*</u',
            $html
        );
    }

    public function test_filter_controls_render(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-dw-search', $html);
        $this->assertStringContainsString('placeholder="Name of Child"', $html);
        $this->assertStringContainsString('for="lml-hr-dw-search"', $html);
        $this->assertStringContainsString('data-hr-dw-zone', $html);
        $this->assertStringContainsString('>All Zones</option>', $html);
        $this->assertStringContainsString('for="lml-hr-dw-zone"', $html);
        $this->assertStringContainsString('data-hr-dw-sex', $html);
        $this->assertStringContainsString('for="lml-hr-dw-sex"', $html);
        $this->assertStringContainsString('data-hr-dw-status', $html);
        $this->assertStringContainsString('for="lml-hr-dw-status"', $html);
        $this->assertMatchesRegularExpression('/>\s*Sex\s*<\/option>/u', $html);
        $this->assertMatchesRegularExpression('/>\s*Status\s*<\/option>/u', $html);
    }

    public function test_table_headings_render_exactly(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/<th scope="col">\s*Full Name\s*<\/th>/u', $html);
        $this->assertMatchesRegularExpression('/<th scope="col">\s*Age\s*<\/th>/u', $html);
        $this->assertMatchesRegularExpression('/<th scope="col">\s*July Round \(Date\)\s*<\/th>/u', $html);
        $this->assertMatchesRegularExpression('/<th scope="col">\s*January Round \(Date\)\s*<\/th>/u', $html);
    }

    public function test_figma_preview_display_values_and_rows_render(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-dw-data-mode="figma-preview"', $html);

        // UI-phase Figma preview/demo display values only — not production aggregates.
        $this->assertMatchesRegularExpression(
            '/data-dw-stat="first-round"[^>]*>\s*60\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-dw-stat="second-round"[^>]*>\s*0\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-dw-stat="received-1-dose"[^>]*>\s*0%\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-dw-stat="received-2-dose"[^>]*>\s*84%\s*</u',
            $html
        );

        foreach ([
            'Kristine B. Reyes',
            'Jacob A. Magistrado',
            'Haziel H. Santos',
            'Andrei B. Malaya',
            'Crisley F. Fernando',
            'Gabriel Allan S. Chua',
        ] as $name) {
            $this->assertStringContainsString($name, $html);
        }

        $this->assertStringContainsString('3 yrs old', $html);
        $this->assertStringContainsString('5 yrs old', $html);
        $this->assertStringContainsString('4 yrs old', $html);
        $this->assertStringContainsString('July 1, 2026', $html);
        $this->assertStringContainsString('January 20, 2026', $html);
    }

    public function test_health_records_expanded_and_child_care_sidebar_active(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame('child-care', UiRole::sidebarActiveKey());
        $this->assertMatchesRegularExpression(
            '/id="lml-sidebar-collapse-health-records"[^>]*\bis-open\b/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink[^>]*aria-current="page"[^>]*>[\s\S]*>Child Care</u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink[^>]*>\s*(?:<[^>]+>\s*)*Deworming\s*</u',
            $html
        );
    }

    public function test_frozen_vitamin_a_route_remains_reachable(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-vitamin-a', $html);
        $this->assertStringContainsString('lml-hr-child-care--vitamin-a', $html);
        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Vitamin A\s*</u',
            $html
        );
    }

    public function test_non_residents_scope_pill_is_absent(): void
    {
        $html = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.deworming'))
            ->assertOk()
            ->getContent();

        $this->assertSame(0, preg_match_all('/>\s*Non-Residents\s*<\/span>/u', $html));
        $this->assertSame(0, substr_count($html, 'data-hr-cc-non-residents'));
        $this->assertStringNotContainsString(
            'href="'.e(route('health-records.child-care.non-residents.index')).'"',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Deworming\s*</u',
            $html
        );
        $this->assertSame('child-care', UiRole::sidebarActiveKey());
        $this->assertStringContainsString('data-lml-hr-deworming', $html);
    }
}
