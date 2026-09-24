<?php

namespace Tests\Feature;

use App\Support\DashboardUiData;
use App\Support\UiRole;
use Tests\TestCase;

/**
 * Dashboard home UI — fixture totals only (not database aggregation).
 */
class DashboardSummaryUiTest extends TestCase
{
    public function test_dashboard_renders_fixture_summary_labels_and_counts(): void
    {
        $counts = DashboardUiData::summaryCounts();

        $response = $this->withSession([UiRole::SESSION_KEY => 'bns'])
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Total Household', false);
        $response->assertSee('Total Residents', false);
        $response->assertSee('NHTS', false);
        $response->assertSee('Non NHTS', false);
        $response->assertSee('Non NHTS Poor', false);
        $response->assertSee('Health Indicators', false);
        $response->assertSee('Teenage Pregnant', false);
        $response->assertSee('Pregnant', false);
        $response->assertSee('Lactating', false);
        $response->assertSee('FP Current User', false);
        $response->assertSee('FP Unmet Needs', false);
        $response->assertSee('Normal Weight Children', false);
        $response->assertSee('Underweight Children', false);
        $response->assertSee('Overweight Children', false);
        $response->assertSee('Exclusively Breastfed Infants', false);
        $response->assertSee('Infants 0–11 Months', false);
        $response->assertSee('HH With Large Family Size', false);
        $response->assertSee('HH With Potable Water Source', false);
        $response->assertSee('HH With Sanitary Toilet', false);
        $response->assertSee('Household snapshot', false);
        $response->assertSee('La Medalla, Iriga City', false);
        $response->assertSee('temporary UI demo values', false);
        $response->assertSee(number_format($counts['totalHouseholds']), false);
        $response->assertSee(number_format($counts['totalResidents']), false);
        $response->assertSee(number_format($counts['nhts']), false);
        $response->assertSee(number_format($counts['nonNhts']), false);
        $response->assertSee(number_format($counts['nonNhtsPoor']), false);

        $response->assertDontSee('Total Health Records', false);
        $response->assertDontSee('Related Records', false);
        $response->assertDontSee('Dashboard content coming soon', false);
        $response->assertDontSee('Senior Citizens', false);
        $response->assertDontSee('Portable Water Source', false);
        $response->assertDontSee('Infants Given Complementary Food', false);
        $response->assertDontSee('Complimentary Food', false);
        $response->assertDontSee('Operation Timbang', false);
        $response->assertDontSee('Vitamin A', false);
        $response->assertDontSee('Deworming', false);

        $html = $response->getContent();
        $this->assertDoesNotMatchRegularExpression(
            '/<h[12][^>]*>\s*Health Records\s*<\/h[12]>/',
            $html
        );
        $this->assertSame(1, substr_count($html, 'data-dash-count="households"'));
        $this->assertSame(1, substr_count($html, 'data-dash-count="nhts"'));
        $this->assertSame(1, substr_count($html, 'data-dash-count="non-nhts-poor"'));
        $this->assertSame(0, substr_count($html, 'data-dash-count="child-care"'));
        $this->assertSame(0, substr_count($html, 'data-dash-count="health-records"'));
        $this->assertSame(1, substr_count($html, 'data-dash-panel="map"'));
        $this->assertSame(1, substr_count($html, 'data-dash-panel="household"'));
        $this->assertSame(13, substr_count($html, 'data-dash-indicator='));
        $this->assertSame(0, substr_count($html, 'data-dash-indicator="complementary-food"'));
        $this->assertSame(1, substr_count($html, 'data-dash-indicator="hh-sanitary-toilet"'));
        $this->assertSame(0, substr_count($html, 'lml-dash-count__icon'));
        $this->assertSame(0, substr_count($html, 'bi-badge-wc'));
        $this->assertSame(13, substr_count($html, 'lml-dash-indicator__pictogram'));
        $this->assertStringContainsString('lml-sidebar__link--active', $html);
        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/dashboard"[^>]*class="[^"]*lml-sidebar__link--active/',
            $html
        );
    }

    public function test_dashboard_does_not_duplicate_summary_cards(): void
    {
        $html = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('dashboard'))
            ->getContent();

        preg_match_all('/data-dash-count="([^"]+)"/', $html, $matches);
        $keys = $matches[1] ?? [];

        $this->assertCount(5, $keys);
        $this->assertSame(array_unique($keys), $keys);
        $this->assertSame(
            ['households', 'residents', 'nhts', 'non-nhts', 'non-nhts-poor'],
            $keys
        );
        $this->assertStringContainsString('data-dash-panel="map"', $html);
        $this->assertStringContainsString('data-dash-panel="household"', $html);
        $this->assertTrue(
            strpos($html, 'data-dash-panel="map"') < strpos($html, 'data-dash-panel="household"')
        );
    }
}
