<?php

namespace Tests\Feature;

use App\Support\UiRole;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Health Records → Child Care → Vitamin A monitoring summary.
 */
class HealthRecordsVitaminATest extends TestCase
{
    public function test_vitamin_a_route_resolves(): void
    {
        $this->assertTrue(Route::has('health-records.child-care.vitamin-a'));

        $route = Route::getRoutes()->getByName('health-records.child-care.vitamin-a');
        $this->assertNotNull($route);
        $this->assertSame('health-records/child-care/vitamin-a', $route->uri());
    }

    public function test_child_care_summary_links_to_vitamin_a_named_route(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.index'));

        $response->assertOk();
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.vitamin-a')).'"',
            $response->getContent()
        );
    }

    public function test_vitamin_a_page_renders_successfully(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bns'])
            ->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-vitamin-a', $html);
        $this->assertStringContainsString('lml-hr-child-care--vitamin-a', $html);
        $this->assertStringContainsString(
            'Record and management of Vitamin A supplementation details for monitoring and tracking nutritional status.',
            $html
        );
    }

    public function test_vitamin_a_pill_is_active_current(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Vitamin A\s*</u',
            $html
        );
    }

    public function test_deworming_and_operation_timbang_pills_remain_present(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.deworming')).'"',
            $html
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.operation-timbang')).'"',
            $html
        );
        $this->assertSame(1, preg_match_all('/>\s*Deworming\s*<\/a>/u', $html));
        $this->assertSame(1, preg_match_all('/>\s*Operation Timbang\s*<\/a>/u', $html));

        $this->assertMatchesRegularExpression(
            '/>\s*Vitamin A\s*<\/a>[\s\S]*>\s*Deworming\s*<\/a>[\s\S]*>\s*Operation Timbang\s*<\/a>/u',
            $html
        );
    }

    public function test_health_records_expanded_and_child_care_sidebar_active(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.vitamin-a'));

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
            '/lml-sidebar__sublink[^>]*>\s*(?:<[^>]+>\s*)*Vitamin A\s*</u',
            $html
        );
    }

    public function test_age_group_labels_and_dose_headers_render(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('6 – 11 mos. old', $html);
        $this->assertStringContainsString('12 – 59 mos. old', $html);
        $this->assertStringContainsString('60 – 71 mos. old', $html);
        $this->assertStringContainsString('Vitamin A 100,000 IU', $html);
        $this->assertStringContainsString('Vitamin A 200,000 IU', $html);
        $this->assertStringContainsString('Percentage', $html);
        $this->assertStringContainsString('Accomplishment', $html);
        $this->assertStringContainsString('Total Number of children given vitamin A', $html);
        $this->assertMatchesRegularExpression('/>\s*Age Group\s*</u', $html);
    }

    public function test_zone_filter_and_export_render_without_add_control(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-va-zone', $html);
        $this->assertStringContainsString('>All Zones</option>', $html);
        $this->assertStringContainsString('for="lml-hr-va-zone"', $html);
        $this->assertStringContainsString('data-hr-va-export', $html);
        $this->assertMatchesRegularExpression('/>\s*Export Data\s*<\/span>/u', $html);

        $this->assertStringNotContainsString('data-hr-cc-add', $html);
        $this->assertStringNotContainsString('data-hr-va-add', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/aria-label="Add child care record"/u',
            $html
        );
    }

    public function test_figma_preview_display_values_render_for_ui_phase(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-va-data-mode="figma-preview"', $html);

        // UI-phase Figma preview/demo display values only — not production aggregates.
        foreach (['32', '15', '14', '29', '248', '122', '128', '250', '70', '40', '33', '73'] as $previewValue) {
            $this->assertMatchesRegularExpression(
                '/>\s*'.preg_quote($previewValue, '/').'\s*</u',
                $html,
                "Expected Figma preview value {$previewValue} in Vitamin A table."
            );
        }

        foreach (['91%', '89%', '90%'] as $previewPct) {
            $this->assertMatchesRegularExpression(
                '/>\s*'.preg_quote($previewPct, '/').'\s*</u',
                $html,
                "Expected Figma preview percentage {$previewPct} (literal display, not derived)."
            );
        }
    }

    public function test_intentionally_empty_figma_cells_are_not_zero_filled(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, preg_match('/data-age-group="total"[\s\S]*?<\/tr>/u', $html, $totalMatch));
        $totalRow = $totalMatch[0];

        // Total row numeric cells must remain empty — no invented sums or placeholders.
        $this->assertDoesNotMatchRegularExpression('/>\s*\d+\s*</u', $totalRow);
        $this->assertDoesNotMatchRegularExpression('/>\s*\d+%\s*</u', $totalRow);
        $this->assertStringNotContainsString('>0</', $totalRow);
        $this->assertStringNotContainsString('>—<', $totalRow);
        $this->assertStringNotContainsString('>N/A<', $totalRow);
        $this->assertStringNotContainsString('>Pending<', $totalRow);

        // 6–11 row should not zero-fill the empty 200k IU band (Figma leaves those blank).
        $this->assertSame(1, preg_match('/data-age-group="6-11"[\s\S]*?<\/tr>/u', $html, $row611Match));
        $row611 = $row611Match[0];
        $this->assertStringContainsString('>32<', preg_replace('/\s+/', '', $row611) ?? $row611);
        $this->assertStringNotContainsString('>0</', $row611);
    }

    public function test_non_residents_scope_pill_is_absent(): void
    {
        $html = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.vitamin-a'))
            ->assertOk()
            ->getContent();

        $this->assertSame(0, preg_match_all('/>\s*Non-Residents\s*<\/span>/u', $html));
        $this->assertSame(0, substr_count($html, 'data-hr-cc-non-residents'));
        $this->assertStringNotContainsString(
            'href="'.e(route('health-records.child-care.non-residents.index')).'"',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Vitamin A\s*</u',
            $html
        );
        $this->assertSame('child-care', UiRole::sidebarActiveKey());
        $this->assertStringContainsString('data-lml-hr-vitamin-a', $html);
    }
}
