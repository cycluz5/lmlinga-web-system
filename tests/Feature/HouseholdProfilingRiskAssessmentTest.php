<?php

namespace Tests\Feature;

use App\Support\DemoRiskAssessment;
use App\Support\StaffRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\PersistCatalogHousehold;
use Tests\TestCase;

/**
 * Feature coverage for Household Profiling → member → Risk Assessment.
 */
class HouseholdProfilingRiskAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
        PersistCatalogHousehold::persist('HH-151');
        PersistCatalogHousehold::persistRiskAssessments('HH-151');
    }
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{householdNo: string, memberId: string}
     */
    private function memberWithHistory(): array
    {
        return [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ];
    }

    /**
     * @return array{householdNo: string, memberId: string}
     */
    private function memberWithoutHistory(): array
    {
        return [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-002',
        ];
    }

    public function test_named_routes_resolve_under_household_profiling_member(): void
    {
        $params = $this->memberWithHistory();

        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/risk-assessment'),
            route('household-profiling.members.risk-assessment', $params)
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/risk-assessment/create'),
            route('household-profiling.members.risk-assessment.create', $params)
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/risk-assessment/RA-001'),
            route('household-profiling.members.risk-assessment.show', $params + ['assessmentId' => 'RA-001'])
        );
    }

    public function test_routes_are_protected_by_ui_role_middleware(): void
    {
        foreach ([
            'household-profiling.members.risk-assessment',
            'household-profiling.members.risk-assessment.create',
            'household-profiling.members.risk-assessment.show',
            'household-profiling.members.risk-assessment.section',
            'household-profiling.members.risk-assessment.section.edit',
            'household-profiling.members.risk-assessment.section.update',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('ui.role', $route->gatherMiddleware());
        }
    }

    public function test_history_screen_renders_for_resident_with_fixture_rows(): void
    {
        $response = $this->get(route(
            'household-profiling.members.risk-assessment',
            $this->memberWithHistory()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-risk-assess-mode="history"', $html);
        $this->assertStringContainsString('data-household-no="HH-151"', $html);
        $this->assertStringContainsString('data-member-id="MB-001"', $html);
        $this->assertStringContainsString('Kristine Reyes', $html);
        $this->assertStringContainsString('RISK ASSESSMENT HISTORY', $html);
        $this->assertStringContainsString('data-risk-assess-history-icon', $html);
        $this->assertStringContainsString(
            'View previous risk assessments conducted for this individual',
            $html
        );
        $this->assertStringContainsString('Date Conducted', $html);
        $this->assertStringContainsString('BP Reading', $html);
        $this->assertStringContainsString('>BMI</th>', $html);
        $this->assertStringContainsString('06/08/2026', $html);
        $this->assertStringContainsString('View Full Details', $html);
        $this->assertStringContainsString('data-risk-assess-view', $html);
        $this->assertStringContainsString('120/80', $html);
        $this->assertStringContainsString('Normal', $html);
        $this->assertStringContainsString('data-risk-assess-add', $html);
        $this->assertStringContainsString('Filter risk assessments by date conducted', $html);
        $this->assertStringContainsString('>Date</option>', $html);
        $this->assertStringContainsString('>All Dates</option>', $html);
        $this->assertStringContainsString('>This Month</option>', $html);
        $this->assertStringContainsString('>Last 3 Months</option>', $html);
        $this->assertStringContainsString('>This Year</option>', $html);
        $this->assertStringContainsString('>Custom range</option>', $html);
        $this->assertStringContainsString('data-risk-assess-custom-range', $html);
        $this->assertStringContainsString('for="lml-risk-assess-date-from"', $html);
        $this->assertStringContainsString('for="lml-risk-assess-date-to"', $html);
        $this->assertStringNotContainsString('lml-risk-assess__date-clear', $html);
        $this->assertStringNotContainsString('No risk assessments recorded for this resident.', $html);
        $this->assertSame(3, substr_count($html, 'data-risk-assess-row'));
    }

    public function test_empty_history_state_for_resident_without_assessments(): void
    {
        $response = $this->get(route(
            'household-profiling.members.risk-assessment',
            $this->memberWithoutHistory()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-member-id="MB-002"', $html);
        $this->assertStringContainsString('No risk assessments recorded for this resident.', $html);
        $this->assertStringContainsString('data-risk-assess-add', $html);
        $this->assertStringContainsString('Risk Assessment is optional', $html);
        $this->assertStringNotContainsString('06/08/2026', $html);
        $this->assertDoesNotMatchRegularExpression('/class="lml-risk-assess__table"/', $html);
    }

    public function test_date_filter_this_month_narrows_history_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        $response = $this->get(route(
            'household-profiling.members.risk-assessment',
            $this->memberWithHistory() + ['date' => 'this_month']
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'data-risk-assess-row'));
        $this->assertStringContainsString('data-conducted-at="2026-06-08"', $html);
        $this->assertStringNotContainsString('data-conducted-at="2026-05-01"', $html);
        $this->assertStringNotContainsString('data-conducted-at="2025-10-08"', $html);
        $this->assertMatchesRegularExpression(
            '/<option[^>]*value="this_month"[^>]*selected/u',
            $html
        );
    }

    public function test_date_filter_last_3_months_uses_rolling_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        $response = $this->get(route(
            'household-profiling.members.risk-assessment',
            $this->memberWithHistory() + ['date' => 'last_3_months']
        ));

        $response->assertOk();
        $html = $response->getContent();

        // Window: 2026-03-15 .. 2026-06-15 → June + May in, Oct 2025 out.
        $this->assertSame(2, substr_count($html, 'data-risk-assess-row'));
        $this->assertStringContainsString('data-conducted-at="2026-06-08"', $html);
        $this->assertStringContainsString('data-conducted-at="2026-05-01"', $html);
        $this->assertStringNotContainsString('data-conducted-at="2025-10-08"', $html);
    }

    public function test_date_filter_this_year_narrows_history_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09'));

        $response = $this->get(route(
            'household-profiling.members.risk-assessment',
            $this->memberWithHistory() + ['date' => 'this_year']
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(2, substr_count($html, 'data-risk-assess-row'));
        $this->assertStringContainsString('data-conducted-at="2026-06-08"', $html);
        $this->assertStringContainsString('data-conducted-at="2026-05-01"', $html);
        $this->assertStringNotContainsString('data-conducted-at="2025-10-08"', $html);
    }

    public function test_date_filter_custom_range_inclusive_bounds(): void
    {
        $response = $this->get(route(
            'household-profiling.members.risk-assessment',
            $this->memberWithHistory() + [
                'date' => 'custom',
                'from' => '2026-05-01',
                'to' => '2026-06-08',
            ]
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(2, substr_count($html, 'data-risk-assess-row'));
        $this->assertStringContainsString('data-conducted-at="2026-06-08"', $html);
        $this->assertStringContainsString('data-conducted-at="2026-05-01"', $html);
        $this->assertStringNotContainsString('data-conducted-at="2025-10-08"', $html);
        $this->assertStringContainsString('data-risk-assess-custom-range', $html);
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="lml-risk-assess-date-from"[^>]*value="2026-05-01"/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="lml-risk-assess-date-to"[^>]*value="2026-06-08"/u',
            $html
        );
    }

    public function test_date_filter_custom_range_incomplete_does_not_hide_history(): void
    {
        $response = $this->get(route(
            'household-profiling.members.risk-assessment',
            $this->memberWithHistory() + [
                'date' => 'custom',
                'from' => '2026-05-01',
            ]
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(3, substr_count($html, 'data-risk-assess-row'));
        $this->assertStringContainsString('value="custom"', $html);
    }

    public function test_date_filter_custom_range_invalid_from_after_to_is_not_applied(): void
    {
        $response = $this->get(route(
            'household-profiling.members.risk-assessment',
            $this->memberWithHistory() + [
                'date' => 'custom',
                'from' => '2026-06-30',
                'to' => '2026-06-01',
            ]
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(3, substr_count($html, 'data-risk-assess-row'));
        $this->assertStringNotContainsString('No risk assessments match the selected date.', $html);
    }

    public function test_date_filter_all_dates_restores_full_list_and_has_no_clear_control(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        $filtered = $this->get(route(
            'household-profiling.members.risk-assessment',
            $this->memberWithHistory() + ['date' => 'this_month']
        ));
        $filtered->assertOk();
        $this->assertSame(1, substr_count($filtered->getContent(), 'data-risk-assess-row'));

        $all = $this->get(route(
            'household-profiling.members.risk-assessment',
            $this->memberWithHistory() + ['date' => 'all']
        ));
        $all->assertOk();
        $html = $all->getContent();

        $this->assertSame(3, substr_count($html, 'data-risk-assess-row'));
        $this->assertStringContainsString('>All Dates</option>', $html);
        $this->assertStringNotContainsString('lml-risk-assess__date-clear', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/>\s*Clear\s*<\/a>/u',
            $html
        );
    }

    public function test_date_filter_switching_presets_updates_visible_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15'));
        $params = $this->memberWithHistory();

        $month = $this->get(route(
            'household-profiling.members.risk-assessment',
            $params + ['date' => 'this_month']
        ));
        $month->assertOk();
        $this->assertSame(1, substr_count($month->getContent(), 'data-risk-assess-row'));

        $year = $this->get(route(
            'household-profiling.members.risk-assessment',
            $params + ['date' => 'this_year']
        ));
        $year->assertOk();
        $this->assertSame(2, substr_count($year->getContent(), 'data-risk-assess-row'));

        $all = $this->get(route(
            'household-profiling.members.risk-assessment',
            $params + ['date' => 'all']
        ));
        $all->assertOk();
        $this->assertSame(3, substr_count($all->getContent(), 'data-risk-assess-row'));
    }

    public function test_date_filter_empty_filtered_state_without_clear_control(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09'));

        $response = $this->get(route(
            'household-profiling.members.risk-assessment',
            $this->memberWithHistory() + ['date' => 'this_month']
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('No risk assessments match the selected date.', $html);
        $this->assertStringContainsString('Select All Dates to see all assessments', $html);
        $this->assertStringNotContainsString('lml-risk-assess__date-clear', $html);
        $this->assertSame(0, substr_count($html, 'data-risk-assess-row'));
    }

    public function test_date_filter_does_not_modify_assessment_catalog(): void
    {
        $before = DemoRiskAssessment::forMember('HH-151', 'MB-001');

        $this->get(route(
            'household-profiling.members.risk-assessment',
            $this->memberWithHistory() + [
                'date' => 'custom',
                'from' => '2026-05-01',
                'to' => '2026-05-01',
            ]
        ))->assertOk();

        $after = DemoRiskAssessment::forMember('HH-151', 'MB-001');
        $this->assertSame($before, $after);
        $this->assertCount(3, $after);
    }

    public function test_add_destination_renders_five_wizard_steps(): void
    {
        $response = $this->get(route(
            'household-profiling.members.risk-assessment.create',
            $this->memberWithHistory()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-risk-assess-mode="create"', $html);
        $this->assertStringContainsString('RISK ASSESSMENT', $html);
        $this->assertStringContainsString(
            'Record and monitor health risk factors for preventive healthcare.',
            $html
        );
        $this->assertStringContainsString('Red Flag Assessment', $html);
        $this->assertStringContainsString('Past Medical History', $html);
        $this->assertStringContainsString('Family History', $html);
        $this->assertStringContainsString('Lifestyle &amp; Risk Factor', $html);
        $this->assertStringContainsString('Physical Measurements &amp; Clinical Screening', $html);
        $this->assertStringContainsString('data-risk-assess-step="1"', $html);
        $this->assertStringContainsString('data-risk-assess-step="5"', $html);
        $this->assertStringContainsString('data-risk-assess-next', $html);
        $this->assertStringContainsString('data-risk-assess-save', $html);
        $this->assertStringContainsString('Kristine Reyes', $html);
        $this->assertStringContainsString('data-member-id="MB-001"', $html);
    }

    public function test_wizard_fields_are_optional_and_include_none_options(): void
    {
        $response = $this->get(route(
            'household-profiling.members.risk-assessment.create',
            $this->memberWithHistory()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString(' required', $html);
        $this->assertStringNotContainsString('required=', $html);
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($html, 'None of the listed conditions were experienced')
        );
        $this->assertStringContainsString('Chest Pain', $html);
        $this->assertStringContainsString('Hypertension', $html);
        $this->assertStringContainsString('Isch. Heart Disease', $html);
        $this->assertStringContainsString('type="radio"', $html);
        $this->assertStringContainsString('name="tobacco"', $html);
        $this->assertStringContainsString('name="alcohol"', $html);
        $this->assertStringContainsString('name="physical_activity"', $html);
        $this->assertStringContainsString('name="dietary"', $html);
        $this->assertStringNotContainsString('name="dietary[]"', $html);
        $this->assertStringContainsString(DemoRiskAssessment::dietaryQuestion(), $html);
        $this->assertStringContainsString(DemoRiskAssessment::physicalActivityQuestion(), $html);
        $this->assertStringContainsString('value="yes"', $html);
        $this->assertStringContainsString('value="no"', $html);
        $this->assertStringNotContainsString('value="balanced"', $html);
        $this->assertStringNotContainsString('value="high_salt"', $html);
        $this->assertStringNotContainsString('value="low_fruits"', $html);
        $this->assertStringNotContainsString('value="meets"', $html);
        $this->assertStringNotContainsString('value="below"', $html);
        // BMI and BMI Status render as one combined field ("20 (Normal)"),
        // not two separate labeled inputs.
        $this->assertStringNotContainsString('BMI Status', $html);
        $this->assertStringContainsString('lml-risk-assess__bmi-display', $html);
        $this->assertStringContainsString('data-risk-assess-bmi-value', $html);
        $this->assertStringContainsString('data-risk-assess-bmi-status', $html);
        $this->assertDoesNotMatchRegularExpression('/\bname="bmi_status"/', $html);
        $this->assertStringContainsString('Height (cm)', $html);
        $this->assertStringContainsString('Systolic (mmHg)', $html);
        $this->assertStringContainsString('Blurred Vision', $html);
        $this->assertStringNotContainsString('no clinical BMI algorithm', $html);
        $this->assertStringNotContainsString('Demo wizard only', $html);
        $this->assertStringNotContainsString('UI phase', $html);
    }

    public function test_view_existing_assessment_is_read_only_and_does_not_create(): void
    {
        $before = DemoRiskAssessment::forMember('HH-151', 'MB-001');
        $beforeCount = count($before);

        $response = $this->get(route(
            'household-profiling.members.risk-assessment.show',
            $this->memberWithHistory() + ['assessmentId' => 'RA-002']
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-risk-assess-mode="history-show"', $html);
        $this->assertStringContainsString('data-assessment-id="RA-002"', $html);
        $this->assertStringContainsString('RISK ASSESSMENT HISTORY', $html);
        $this->assertStringContainsString('data-risk-assess-section-card="red-flags"', $html);
        $this->assertStringContainsString('data-risk-assess-section-card="past-medical"', $html);
        $this->assertStringContainsString('data-risk-assess-section-card="family-history"', $html);
        $this->assertStringContainsString('data-risk-assess-section-card="lifestyle"', $html);
        $this->assertStringContainsString('data-risk-assess-section-card="physical"', $html);
        $this->assertStringContainsString('Date Conducted:', $html);
        $this->assertStringContainsString('05/01/2026', $html);
        $this->assertStringNotContainsString('data-risk-assess-save', $html);
        $this->assertStringNotContainsString('data-risk-assess-next', $html);
        $this->assertStringNotContainsString('data-lml-risk-assess-mode="view"', $html);

        $after = DemoRiskAssessment::forMember('HH-151', 'MB-001');
        $this->assertCount($beforeCount, $after);
        $this->assertSame($before, $after);
    }

    public function test_view_unknown_assessment_does_not_fabricate_record(): void
    {
        $response = $this->get(route(
            'household-profiling.members.risk-assessment.show',
            $this->memberWithHistory() + ['assessmentId' => 'RA-999']
        ));

        $response->assertNotFound();
    }

    public function test_demo_filter_helper_preserves_optional_empty_catalog(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        $this->assertSame([], DemoRiskAssessment::forMember('HH-151', 'MB-002'));
        $this->assertCount(3, DemoRiskAssessment::forMember('HH-151', 'MB-001'));

        $rows = DemoRiskAssessment::forMember('HH-151', 'MB-001');

        $this->assertCount(3, DemoRiskAssessment::filterByDate($rows, null));
        $this->assertCount(3, DemoRiskAssessment::filterByDate($rows, 'all'));
        $this->assertCount(1, DemoRiskAssessment::filterByDate($rows, 'this_month'));
        $this->assertCount(2, DemoRiskAssessment::filterByDate($rows, 'last_3_months'));
        $this->assertCount(2, DemoRiskAssessment::filterByDate($rows, 'this_year'));

        $custom = DemoRiskAssessment::filterByDate($rows, 'custom', '2026-06-08', '2026-06-08');
        $this->assertCount(1, $custom);
        $this->assertSame('RA-001', $custom[0]['id']);

        $this->assertCount(3, DemoRiskAssessment::filterByDate($rows, 'custom', '2026-06-30', '2026-06-01'));
        $this->assertCount(3, DemoRiskAssessment::filterByDate($rows, 'custom', '2026-05-01', null));
    }

    public function test_member_view_links_to_resident_risk_assessment_history(): void
    {
        $params = $this->memberWithHistory();
        $response = $this->get(route('household-profiling.members.show', $params));

        $response->assertOk();
        $response->assertSee(
            'href="'.e(route('household-profiling.members.risk-assessment', $params)).'"',
            false
        );
        $response->assertSee('data-hh-member-risk-assessment', false);
    }
}
