<?php

namespace Tests\Feature;

use App\Support\UiRole;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Health Records → Child Care → Operation Timbang monitoring summary.
 */
class HealthRecordsOperationTimbangTest extends TestCase
{
    public function test_operation_timbang_route_resolves(): void
    {
        $this->assertTrue(Route::has('health-records.child-care.operation-timbang'));

        $route = Route::getRoutes()->getByName('health-records.child-care.operation-timbang');
        $this->assertNotNull($route);
        $this->assertSame('health-records/child-care/operation-timbang', $route->uri());
    }

    public function test_operation_timbang_page_renders_successfully(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bns'])
            ->get(route('health-records.child-care.operation-timbang'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-operation-timbang', $html);
        $this->assertStringContainsString('lml-hr-child-care--operation-timbang', $html);
        $this->assertStringContainsString(
            'Record and management of Operation Timbang weigh-in details for monitoring and tracking nutritional status.',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-hr-ot-heading"[^>]*>\s*Child Care\s*</u',
            $html
        );
        $this->assertStringNotContainsString(
            'Record and management of deworming details for monitoring and tracking treatment status.',
            $html
        );
    }

    public function test_operation_timbang_pill_is_active_current(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.operation-timbang'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Operation Timbang\s*</u',
            $html
        );
    }

    public function test_vitamin_a_and_deworming_pills_remain_present(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.operation-timbang'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.vitamin-a')).'"',
            $html
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.deworming')).'"',
            $html
        );
        $this->assertSame(1, preg_match_all('/>\s*Vitamin A\s*<\/a>/u', $html));
        $this->assertSame(1, preg_match_all('/>\s*Deworming\s*<\/a>/u', $html));

        $this->assertMatchesRegularExpression(
            '/>\s*Vitamin A\s*<\/a>[\s\S]*>\s*Deworming\s*<\/a>[\s\S]*>\s*Operation Timbang\s*<\/a>/u',
            $html
        );
    }

    public function test_month_and_year_session_controls_exist(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.operation-timbang'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Month:', $html);
        $this->assertStringContainsString(
            'Each tab is a monthly weigh-in session — tap to switch.',
            $html
        );
        $this->assertStringContainsString('data-hr-ot-month-list', $html);
        $this->assertStringContainsString('data-hr-ot-year', $html);
        $this->assertStringContainsString('for="lml-hr-ot-year"', $html);
        $this->assertStringContainsString('January 2026', $html);
        $this->assertStringContainsString('December 2026', $html);
        $this->assertMatchesRegularExpression(
            '/lml-hr-ot-month-pill--active[^>]*aria-selected="true"[^>]*aria-current="true"/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-hr-ot-year[\s\S]*>\s*2026\s*</u',
            $html
        );
    }

    public function test_summary_metrics_render(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.operation-timbang'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Summary:', $html);
        $this->assertStringContainsString('No. of 0–23 Months PS', $html);
        $this->assertStringContainsString('No. of 0–23 Months Old Measured', $html);
        $this->assertStringContainsString('No. of Over age', $html);
        $this->assertStringContainsString('No. of Transferred/ Moveout', $html);
        $this->assertStringContainsString('No. of Dead', $html);
        $this->assertStringContainsString('No. Not Available', $html);
        $this->assertStringContainsString('No. of New Cases', $html);
        $this->assertStringContainsString('Total Number of 0–23 Months', $html);
        $this->assertStringContainsString('Male', $html);
        $this->assertStringContainsString('Female', $html);

        $this->assertMatchesRegularExpression(
            '/data-ot-stat="ps-0-23"[^>]*>\s*33\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-ot-stat="measured-0-23"[^>]*>\s*33\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-ot-stat="total-male"[^>]*>\s*17\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-ot-stat="total-female"[^>]*>\s*16\s*</u',
            $html
        );
    }

    public function test_filter_controls_render(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.operation-timbang'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-ot-search', $html);
        $this->assertStringContainsString('placeholder="Name of Child"', $html);
        $this->assertStringContainsString('for="lml-hr-ot-search"', $html);
        $this->assertStringContainsString('data-hr-ot-zone', $html);
        $this->assertStringContainsString('>All Zones</option>', $html);
        $this->assertStringContainsString('for="lml-hr-ot-zone"', $html);
        $this->assertStringContainsString('data-hr-ot-sex', $html);
        $this->assertStringContainsString('for="lml-hr-ot-sex"', $html);
        $this->assertStringContainsString('data-hr-ot-status', $html);
        $this->assertStringContainsString('for="lml-hr-ot-status"', $html);
        $this->assertMatchesRegularExpression('/>\s*Sex\s*<\/option>/u', $html);
        $this->assertMatchesRegularExpression('/>\s*Status\s*<\/option>/u', $html);
    }

    public function test_table_headings_and_status_text_render(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.operation-timbang'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/<th scope="col">\s*Full Name\s*<\/th>/u', $html);
        $this->assertMatchesRegularExpression('/<th scope="col">\s*Age\s*<\/th>/u', $html);
        $this->assertMatchesRegularExpression('/<th scope="col">\s*Weight\s*<\/th>/u', $html);
        $this->assertMatchesRegularExpression('/<th scope="col">\s*Height\s*<\/th>/u', $html);
        $this->assertMatchesRegularExpression('/<th scope="col">\s*MUAC\s*<\/th>/u', $html);
        $this->assertMatchesRegularExpression('/<th scope="col">\s*Status\s*<\/th>/u', $html);

        $this->assertStringContainsString('Below Normal', $html);
        $this->assertStringContainsString('Normal', $html);
        $this->assertStringContainsString('Above Normal', $html);
        $this->assertStringContainsString('lml-hr-ot-status--below-normal', $html);
        $this->assertStringContainsString('lml-hr-ot-status--normal', $html);
        $this->assertStringContainsString('lml-hr-ot-status--above-normal', $html);
    }

    public function test_figma_preview_rows_render(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.operation-timbang'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-ot-data-mode="figma-preview"', $html);

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

        $this->assertStringContainsString('2 months', $html);
        $this->assertStringContainsString('18 months', $html);
        $this->assertStringContainsString('3.4 kg', $html);
        $this->assertStringContainsString('12.3 kg', $html);
        $this->assertStringContainsString('43.5 cm', $html);
        $this->assertStringContainsString('86.7 cm', $html);
        $this->assertStringContainsString('14.5', $html);
        $this->assertStringContainsString('15.5', $html);
    }

    public function test_export_data_control_exists(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.operation-timbang'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-ot-export', $html);
        $this->assertStringContainsString('aria-label="Export Operation Timbang data"', $html);
        $this->assertMatchesRegularExpression('/>\s*Export Data\s*<\/span>/u', $html);
        $this->assertStringNotContainsString('data-hr-cc-add', $html);
    }

    public function test_health_records_expanded_and_child_care_sidebar_active(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.operation-timbang'));

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
            '/lml-sidebar__sublink[^>]*>\s*(?:<[^>]+>\s*)*Operation Timbang\s*</u',
            $html
        );
    }

    public function test_frozen_adjacent_routes_remain_reachable(): void
    {
        $vitaminA = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.vitamin-a'));
        $vitaminA->assertOk();
        $this->assertStringContainsString('data-lml-hr-vitamin-a', $vitaminA->getContent());
        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Vitamin A\s*</u',
            $vitaminA->getContent()
        );

        $deworming = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.deworming'));
        $deworming->assertOk();
        $this->assertStringContainsString('data-lml-hr-deworming', $deworming->getContent());
        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Deworming\s*</u',
            $deworming->getContent()
        );

        $summary = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.index'));
        $summary->assertOk();
        $this->assertStringContainsString('data-lml-hr-child-care', $summary->getContent());
    }

    public function test_non_residents_scope_pill_is_absent(): void
    {
        $html = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.operation-timbang'))
            ->assertOk()
            ->getContent();

        $this->assertSame(0, preg_match_all('/>\s*Non-Residents\s*<\/span>/u', $html));
        $this->assertSame(0, substr_count($html, 'data-hr-cc-non-residents'));
        $this->assertStringNotContainsString(
            'href="'.e(route('health-records.child-care.non-residents.index')).'"',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Operation Timbang\s*</u',
            $html
        );
        $this->assertSame('child-care', UiRole::sidebarActiveKey());
        $this->assertStringContainsString('data-lml-hr-operation-timbang', $html);
    }
}
