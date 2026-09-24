<?php

namespace Tests\Feature;

use App\Support\HealthRecordsChildCare;
use App\Support\UiRole;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Health Records → Child Care barangay-wide summary.
 */
class HealthRecordsChildCareSummaryTest extends TestCase
{
    public function test_child_care_summary_page_renders(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bns'])
            ->get(route('health-records.child-care.index'));

        $response->assertOk();
        $response->assertSee('data-lml-hr-child-care', false);
        $response->assertSee('Total Infants', false);
        $response->assertSee('Female', false);
        $response->assertSee('Male', false);
    }

    public function test_health_records_child_care_sidebar_is_active(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame('child-care', UiRole::sidebarActiveKey());
        $this->assertMatchesRegularExpression(
            '/id="lml-sidebar-collapse-health-records"[^>]*\bis-open\b/u',
            $html
        );
        $this->assertStringContainsString('>Child Care</span>', $html);
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink[^>]*aria-current="page"[^>]*>[\s\S]*>Child Care</u',
            $html
        );
    }

    public function test_summary_counts_match_demo_catalog_rows(): void
    {
        $rows = HealthRecordsChildCare::rows();
        $summary = HealthRecordsChildCare::summaryCounts($rows);

        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(
            (string) $summary['total'],
            $this->extractStatValue($html, 'total')
        );
        $this->assertSame(
            (string) $summary['female'],
            $this->extractStatValue($html, 'female')
        );
        $this->assertSame(
            (string) $summary['male'],
            $this->extractStatValue($html, 'male')
        );

        $this->assertSame($summary['female'] + $summary['male'], $summary['total']);
    }

    public function test_vitamin_a_and_deworming_routes_exist_and_link_from_summary(): void
    {
        $this->assertTrue(Route::has('health-records.child-care.vitamin-a'));
        $this->assertTrue(Route::has('health-records.child-care.deworming'));
        $this->assertTrue(Route::has('health-records.child-care.operation-timbang'));

        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.index'));

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
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.operation-timbang')).'"',
            $html
        );

        $this->assertSame(1, preg_match_all('/>\s*Operation Timbang\s*<\/a>/u', $html));
        $this->assertSame(1, preg_match_all('/>\s*Vitamin A\s*<\/a>/u', $html));
        $this->assertSame(1, preg_match_all('/>\s*Deworming\s*<\/a>/u', $html));

        $this->assertMatchesRegularExpression(
            '/>\s*Vitamin A\s*<\/a>[\s\S]*>\s*Deworming\s*<\/a>[\s\S]*>\s*Operation Timbang\s*<\/a>/u',
            $html
        );

        $this->assertStringContainsString('data-hr-cc-add', $html);
        $this->assertMatchesRegularExpression('/>\s*Add\s*<\/span>/u', $html);
        $this->assertStringContainsString('data-hr-cc-export', $html);
        $this->assertMatchesRegularExpression('/>\s*Export Data\s*<\/span>/u', $html);

        $this->assertMatchesRegularExpression(
            '/data-hr-cc-add[\s\S]*data-hr-cc-export/u',
            $html
        );

        $this->get(route('health-records.child-care.vitamin-a'))->assertOk();
        $this->get(route('health-records.child-care.deworming'))->assertOk();
        $this->get(route('health-records.child-care.operation-timbang'))->assertOk();
    }

    public function test_non_residents_entry_point_is_absent_from_child_care_ui(): void
    {
        $this->assertTrue(Route::has('health-records.child-care.non-residents.index'));

        $nonResidentsUrl = route('health-records.child-care.non-residents.index');

        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(0, preg_match_all('/>\s*Non-Residents\s*<\/span>/u', $html));
        $this->assertSame(0, substr_count($html, 'data-hr-cc-non-residents'));
        $this->assertStringNotContainsString('href="'.e($nonResidentsUrl).'"', $html);

        $this->assertSame(1, preg_match_all('/>\s*Vitamin A\s*<\/a>/u', $html));
        $this->assertSame(1, preg_match_all('/>\s*Deworming\s*<\/a>/u', $html));
        $this->assertSame(1, preg_match_all('/>\s*Operation Timbang\s*<\/a>/u', $html));
        $this->assertStringContainsString('data-hr-cc-add', $html);
        $this->assertStringContainsString('data-hr-cc-export', $html);

        $this->assertSame('child-care', UiRole::sidebarActiveKey());
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink[^>]*aria-current="page"[^>]*>[\s\S]*>Child Care</u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink[^>]*>\s*(?:<[^>]+>\s*)*Non-Residents\s*</u',
            $html
        );
    }

    public function test_operation_timbang_keeps_child_care_sidebar_active(): void
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
        $this->assertStringContainsString('Operation Timbang', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink[^>]*>\s*(?:<[^>]+>\s*)*Operation Timbang\s*</u',
            $html
        );
    }

    public function test_filter_controls_are_present(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('placeholder="Search Infant"', $html);
        $this->assertStringContainsString('>All Zones</option>', $html);
        $this->assertStringContainsString('data-hr-cc-age', $html);
        $this->assertStringContainsString('data-hr-cc-sex', $html);
    }

    public function test_table_headings_appear_once(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.index'));

        $response->assertOk();
        $html = $response->getContent();

        foreach (['Full Name', 'Birth Status', 'Age', 'Health Status', 'Action'] as $heading) {
            $this->assertSame(1, substr_count($html, '>'.$heading.'</th>'), "Heading {$heading} must appear once in thead.");
        }
    }

    public function test_view_links_target_member_show_route(): void
    {
        $rows = HealthRecordsChildCare::rows();
        $this->assertNotEmpty($rows);

        $sample = $rows[0];
        $expected = route('household-profiling.members.show', [
            'householdNo' => $sample['household_no'],
            'memberId' => $sample['member_id'],
        ]);

        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.index'));

        $response->assertOk();
        $this->assertStringContainsString('href="'.e($expected).'"', $response->getContent());
    }

    public function test_frozen_child_care_module_routes_remain_reachable(): void
    {
        $params = ['householdNo' => 'HH-151', 'memberId' => 'MB-009'];

        $this->get(route('household-profiling.members.child-immunization', $params))->assertOk();
        $this->get(route('household-profiling.members.school-based-immunization', $params))->assertOk();
        $this->get(route('household-profiling.members.child-nutrition', $params))->assertOk();
        $this->get(route('household-profiling.members.show', $params))->assertOk();
    }

    private function extractStatValue(string $html, string $key): string
    {
        if (! preg_match('/data-stat="'.$key.'"[^>]*>(\d+)</', $html, $match)) {
            $this->fail("Missing data-stat=\"{$key}\" counter.");
        }

        return $match[1];
    }
}
