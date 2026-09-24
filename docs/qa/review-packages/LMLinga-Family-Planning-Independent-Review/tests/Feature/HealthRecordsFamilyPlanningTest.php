<?php

namespace Tests\Feature;

use App\Support\HealthRecordsFamilyPlanning;
use App\Support\UiRole;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Health Records → Family Planning barangay-wide summary.
 */
class HealthRecordsFamilyPlanningTest extends TestCase
{
    public function test_family_planning_route_resolves(): void
    {
        $this->assertTrue(Route::has('health-records.family-planning.index'));
        $this->assertFalse(Route::has('health-records.family-planning'));

        $route = Route::getRoutes()->getByName('health-records.family-planning.index');
        $this->assertNotNull($route);
        $this->assertSame('health-records/family-planning', $route->uri());
    }

    public function test_family_planning_page_renders_successfully(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bns'])
            ->get(route('health-records.family-planning.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-fp', $html);
        $this->assertMatchesRegularExpression(
            '/id="lml-hr-fp-heading"[^>]*>\s*Family Planning\s*</u',
            $html
        );
        $this->assertStringContainsString('Non - Residents Client', $html);
        $this->assertStringContainsString(
            'Record and management of family planning details for monitoring and tracking reproductive health services.',
            $html
        );
    }

    public function test_summary_cards_render_from_fixture(): void
    {
        $summary = HealthRecordsFamilyPlanning::summaryCounts();

        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Total FP Patients', $html);
        $this->assertStringContainsString('Due for Follow-ups', $html);
        $this->assertStringContainsString('Missed for Follow-ups', $html);
        $this->assertMatchesRegularExpression(
            '/data-fp-stat="total"[^>]*>\s*'.preg_quote((string) $summary['total'], '/').'\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-fp-stat="due"[^>]*>[\s\S]*?>\s*'.preg_quote((string) $summary['due'], '/').'\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-fp-stat="missed"[^>]*>[\s\S]*?>\s*'.preg_quote((string) $summary['missed'], '/').'\s*</u',
            $html
        );
    }

    public function test_filter_controls_render(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-fp-search', $html);
        $this->assertStringContainsString('placeholder="Search Name"', $html);
        $this->assertStringContainsString('for="lml-hr-fp-search"', $html);
        $this->assertStringContainsString('data-hr-fp-zone', $html);
        $this->assertStringContainsString('>All Zones</option>', $html);
        $this->assertStringContainsString('for="lml-hr-fp-zone"', $html);
        $this->assertStringContainsString('data-hr-fp-year', $html);
        $this->assertStringContainsString('>All Years</option>', $html);
        $this->assertStringContainsString('for="lml-hr-fp-year"', $html);

        foreach (HealthRecordsFamilyPlanning::years() as $year) {
            $this->assertStringContainsString('>'.$year.'</option>', $html);
        }
    }

    public function test_table_headers_and_fixture_rows_render(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.index'));

        $response->assertOk();
        $html = $response->getContent();

        foreach ([
            'Full Name',
            'Age',
            'Method',
            'Start Date',
            'Last Visit',
            'Next Sched',
        ] as $header) {
            $this->assertStringContainsString($header, $html);
        }

        foreach (HealthRecordsFamilyPlanning::rows() as $row) {
            $this->assertStringContainsString($row['full_name'], $html);
            $this->assertStringContainsString($row['method'], $html);
            $this->assertStringContainsString($row['start_date'], $html);
        }

        $this->assertStringContainsString('<caption class="visually-hidden">', $html);
        $this->assertStringContainsString('data-hr-fp-empty', $html);
        $this->assertStringContainsString(
            'No family planning records match the selected filters.',
            $html
        );
    }

    public function test_add_and_export_controls_exist(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-fp-add', $html);
        $this->assertStringContainsString('aria-label="Add family planning record"', $html);
        $this->assertMatchesRegularExpression('/>\s*Add\s*<\/span>/u', $html);
        $this->assertStringContainsString('data-hr-fp-export', $html);
        $this->assertStringContainsString('aria-label="Export Family Planning data"', $html);
        $this->assertMatchesRegularExpression('/>\s*Export Data\s*<\/span>/u', $html);
        $this->assertStringContainsString('data-hr-fp-toast', $html);
        $this->assertStringNotContainsString('href="#"', $html);
    }

    public function test_sidebar_family_planning_is_real_link_and_active(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame('family-planning', UiRole::sidebarActiveKey());
        $this->assertMatchesRegularExpression(
            '/id="lml-sidebar-collapse-health-records"[^>]*\bis-open\b/u',
            $html
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.family-planning.index')).'"',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink--active[^>]*aria-current="page"[^>]*>[\s\S]*>Family Planning</u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink--active[^>]*>[\s\S]*>Risk Assessment</u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<span[^>]*lml-sidebar__sublink--unavailable[^>]*>[\s\S]*?<span>\s*Family Planning\s*<\/span>/u',
            $html
        );
    }

    public function test_summary_counts_match_fixture_rows(): void
    {
        $rows = HealthRecordsFamilyPlanning::rows();
        $summary = HealthRecordsFamilyPlanning::summaryCounts($rows);

        $this->assertSame(count($rows), $summary['total']);
        $this->assertSame(6, $summary['total']);
        $this->assertSame(0, $summary['due']);
        $this->assertSame(0, $summary['missed']);
        $this->assertSame(['2026'], HealthRecordsFamilyPlanning::years($rows));
    }

    public function test_remains_independent_of_household_profiling_family_planning(): void
    {
        $this->assertTrue(Route::has('household-profiling.members.family-planning.index'));
        $this->assertTrue(Route::has('health-records.family-planning.index'));

        $hh = Route::getRoutes()->getByName('household-profiling.members.family-planning.index');
        $hr = Route::getRoutes()->getByName('health-records.family-planning.index');

        $this->assertNotNull($hh);
        $this->assertNotNull($hr);
        $this->assertNotSame($hh->uri(), $hr->uri());
    }
}
