<?php

namespace Tests\Feature;

use App\Support\HealthRecordsRiskAssessment;
use App\Support\UiRole;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Health Records → Risk Assessment barangay-wide summary.
 */
class HealthRecordsRiskAssessmentTest extends TestCase
{
    public function test_risk_assessment_route_resolves(): void
    {
        $this->assertTrue(Route::has('health-records.risk-assessment.index'));

        $route = Route::getRoutes()->getByName('health-records.risk-assessment.index');
        $this->assertNotNull($route);
        $this->assertSame('health-records/risk-assessment', $route->uri());
    }

    public function test_risk_assessment_page_renders_successfully(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bns'])
            ->get(route('health-records.risk-assessment.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-risk', $html);
        $this->assertMatchesRegularExpression(
            '/id="lml-hr-risk-heading"[^>]*>\s*Risk Assessment\s*</u',
            $html
        );
        $this->assertStringContainsString(
            'Record and management of risk assessment details for monitoring and tracking health risks.',
            $html
        );
    }

    public function test_summary_cards_render_from_fixture(): void
    {
        $summary = HealthRecordsRiskAssessment::summaryCounts();

        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.risk-assessment.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Total Assessed Clients', $html);
        $this->assertMatchesRegularExpression(
            '/data-ra-stat="total"[^>]*>\s*'.preg_quote((string) $summary['total'], '/').'\s*</u',
            $html
        );

        foreach (HealthRecordsRiskAssessment::zones() as $zone) {
            $this->assertStringContainsString('>'.$zone.'</p>', $html);
            $count = (int) ($summary['zones'][$zone] ?? 0);
            $slug = \Illuminate\Support\Str::slug($zone);
            $this->assertMatchesRegularExpression(
                '/data-ra-stat="'.preg_quote($slug, '/').'"[^>]*>[\s\S]*?>\s*'.$count.'\s*</u',
                $html
            );
        }
    }

    public function test_filter_controls_render(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.risk-assessment.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-ra-search', $html);
        $this->assertStringContainsString('placeholder="Search Name"', $html);
        $this->assertStringContainsString('for="lml-hr-ra-search"', $html);
        $this->assertStringContainsString('data-hr-ra-zone', $html);
        $this->assertStringContainsString('>All Zones</option>', $html);
        $this->assertStringContainsString('for="lml-hr-ra-zone"', $html);
        $this->assertStringContainsString('data-hr-ra-year', $html);
        $this->assertStringContainsString('>All Years</option>', $html);
        $this->assertStringContainsString('for="lml-hr-ra-year"', $html);

        foreach (HealthRecordsRiskAssessment::years() as $year) {
            $this->assertStringContainsString('>'.$year.'</option>', $html);
        }
    }

    public function test_table_headers_and_fixture_rows_render(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.risk-assessment.index'));

        $response->assertOk();
        $html = $response->getContent();

        foreach ([
            'Full Name',
            'BMI Status',
            'BP Status',
            'Smoking Status',
            'Alcohol Status',
            'Physical Activity Risk',
            'Family History Risk',
            'Chronic Disease',
        ] as $header) {
            $this->assertStringContainsString($header, $html);
        }

        $this->assertStringContainsString('(Underweight/', $html);
        $this->assertStringContainsString('Normal/', $html);
        $this->assertStringContainsString('Overweight/', $html);
        $this->assertStringContainsString('Obese)', $html);
        $this->assertStringContainsString('(Never/', $html);
        $this->assertStringContainsString('Current/', $html);
        $this->assertStringContainsString('Quit)', $html);

        foreach (HealthRecordsRiskAssessment::rows() as $row) {
            $this->assertStringContainsString($row['full_name'], $html);
            $this->assertStringContainsString($row['bmi_status'], $html);
        }

        $this->assertStringContainsString('<caption class="visually-hidden">', $html);
        $this->assertStringContainsString('data-hr-ra-empty', $html);
        $this->assertStringContainsString(
            'No risk assessment records match the selected filters.',
            $html
        );
    }

    public function test_add_and_export_controls_exist(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.risk-assessment.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-ra-add', $html);
        $this->assertStringContainsString('aria-label="Add risk assessment record"', $html);
        $this->assertMatchesRegularExpression('/>\s*Add\s*<\/span>/u', $html);
        $this->assertStringContainsString('data-hr-ra-export', $html);
        $this->assertStringContainsString('aria-label="Export Risk Assessment data"', $html);
        $this->assertMatchesRegularExpression('/>\s*Export Data\s*<\/span>/u', $html);
        $this->assertStringContainsString('data-hr-ra-toast', $html);
        $this->assertStringNotContainsString('href="#"', $html);
    }

    public function test_sidebar_risk_assessment_is_real_link_and_active(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.risk-assessment.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame('risk-assessment', UiRole::sidebarActiveKey());
        $this->assertMatchesRegularExpression(
            '/id="lml-sidebar-collapse-health-records"[^>]*\bis-open\b/u',
            $html
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.risk-assessment.index')).'"',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink--active[^>]*aria-current="page"[^>]*>[\s\S]*>Risk Assessment</u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink--active[^>]*>[\s\S]*>Child Care</u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<span[^>]*lml-sidebar__sublink--unavailable[^>]*>[\s\S]*?<span>\s*Risk Assessment\s*<\/span>/u',
            $html
        );
    }

    public function test_summary_counts_match_fixture_rows(): void
    {
        $rows = HealthRecordsRiskAssessment::rows();
        $summary = HealthRecordsRiskAssessment::summaryCounts($rows);

        $this->assertSame(count($rows), $summary['total']);
        $this->assertSame(8, $summary['total']);

        $this->assertSame(2, $summary['zones']['Zone 1']);
        $this->assertSame(2, $summary['zones']['Zone 2']);
        $this->assertSame(2, $summary['zones']['Zone 3']);
        $this->assertSame(1, $summary['zones']['Zone 4']);
        $this->assertSame(1, $summary['zones']['Zone 5']);

        $this->assertSame(['2026', '2025', '2024'], HealthRecordsRiskAssessment::years($rows));
    }
}
