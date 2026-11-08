<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Support\HealthRecordsRiskAssessment;
use App\Support\UiRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Health Records → Risk Assessment barangay-wide summary.
 */
class HealthRecordsRiskAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_risk_assessment_route_resolves(): void
    {
        $this->assertTrue(Route::has('health-records.risk-assessment.index'));

        $route = Route::getRoutes()->getByName('health-records.risk-assessment.index');
        $this->assertNotNull($route);
        $this->assertSame('health-records/risk-assessment', $route->uri());
    }

    public function test_risk_assessment_page_renders_successfully(): void
    {
        $this->actingAsStaff(StaffRole::BNS);
        $response = $this->get(route('health-records.risk-assessment.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-risk', $html);
        $this->assertMatchesRegularExpression(
            '/id="lml-hr-risk-heading"[^>]*>\s*Risk Assessment\s*</u',
            $html
        );
        $this->assertStringContainsString('lml-hr-risk__action-row', $html);
        $this->assertStringNotContainsString('lml-hr-risk__title', $html);
        $this->assertStringNotContainsString('lml-hr-risk__description', $html);
        $this->assertStringContainsString(
            'Record and management of risk assessment details for monitoring and tracking health risks.',
            $html
        );
    }

    public function test_summary_cards_render_from_database_counts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16')->startOfDay());

        $summary = HealthRecordsRiskAssessment::summaryCounts();

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.risk-assessment.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(0, $summary['this_year']);
        $this->assertSame(0, $summary['this_month']);
        $this->assertSame('2026', $summary['this_year_label']);
        $this->assertSame('September 2026', $summary['this_month_label']);
        $this->assertStringContainsString('Total Assessed This Year', $html);
        $this->assertStringContainsString('Total Assessed This Month', $html);
        $this->assertStringNotContainsString('Total Assessed Clients', $html);
        $this->assertMatchesRegularExpression(
            '/data-ra-stat="this-year"[^>]*>\s*0\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-ra-stat="this-month"[^>]*>\s*0\s*</u',
            $html
        );
    }

    public function test_filter_controls_render(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.risk-assessment.index'));

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
        $this->assertStringContainsString('data-hr-ra-month', $html);
        $this->assertStringContainsString('>Month</option>', $html);
        $this->assertStringContainsString('for="lml-hr-ra-month"', $html);
        $this->assertStringContainsString('>January</option>', $html);
        $this->assertStringContainsString('>September</option>', $html);
    }

    public function test_empty_database_shows_empty_state_without_demo_names(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.risk-assessment.index'));

        $response->assertOk();
        $html = $response->getContent();

        preg_match_all('/<th scope="col">([^<]+)<\/th>/u', $html, $headerMatches);
        $this->assertSame([
            'Name',
            'Age',
            'Birthday',
            'Date Assessed',
            'BMI',
            'BP',
        ], $headerMatches[1]);

        $this->assertSame([], HealthRecordsRiskAssessment::rows());
        $this->assertStringContainsString('data-hr-ra-empty', $html);
        $this->assertStringContainsString('No risk assessment records are available.', $html);
        $this->assertStringNotContainsString('Kristine Reyes', $html);
        $this->assertStringNotContainsString('Liza M. Evangelista', $html);
        $this->assertStringNotContainsString('Jacob A. Magistrado', $html);
        $this->assertStringNotContainsString('data-hr-ra-row', $html);
    }

    public function test_listing_shows_persisted_risk_assessment_record(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16')->startOfDay());

        $household = Household::factory()->create(['zone' => 'Zone 2']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-501',
            'first_name' => 'Persisted',
            'last_name' => 'Assessed',
            'birthday' => '1990-01-01',
        ]);

        RiskAssessment::factory()->create([
            'resident_id' => $resident->id,
            'conducted_at' => '2026-09-10',
            'tobacco' => 'never',
            'alcohol' => 'never',
            'physical_activity' => 'meets',
            'bp_status' => 'Normal',
            'bmi_label' => 'Normal',
            'family_history' => ['none'],
            'past_medical' => ['none'],
        ]);

        $rows = HealthRecordsRiskAssessment::rows();
        $this->assertCount(1, $rows);
        $this->assertSame('Persisted Assessed', $rows[0]['full_name']);
        $this->assertSame('Normal', $rows[0]['bmi_status']);
        $this->assertSame('2026', $rows[0]['year']);
        $this->assertSame('09', $rows[0]['month']);
        $this->assertSame('Zone 2', $rows[0]['zone']);
        $this->assertNotSame('', (string) ($rows[0]['view_url'] ?? ''));
        $this->assertNotSame('', (string) ($rows[0]['birthday'] ?? ''));
        $this->assertNotSame('', (string) ($rows[0]['date_assessed'] ?? ''));

        $summary = HealthRecordsRiskAssessment::summaryCounts($rows);
        $this->assertSame(1, $summary['this_year']);
        $this->assertSame(1, $summary['this_month']);

        $html = $this->riskAssessmentHtml();
        $this->assertStringContainsString('Persisted Assessed', $html);
        $this->assertStringContainsString('data-hr-ra-row', $html);
        $this->assertStringContainsString('data-has-records="1"', $html);
        $this->assertStringContainsString('lml-hr-risk__name-link', $html);
        $this->assertStringContainsString((string) $rows[0]['view_url'], $html);
        $this->assertStringContainsString((string) $rows[0]['birthday'], $html);
        $this->assertStringContainsString((string) $rows[0]['date_assessed'], $html);
        $this->assertMatchesRegularExpression(
            '/data-ra-stat="this-year"[^>]*>\s*1\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-ra-stat="this-month"[^>]*>\s*1\s*</u',
            $html
        );
    }

    public function test_summary_counts_only_include_assessments_from_current_year_and_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16')->startOfDay());

        $household = Household::factory()->create(['zone' => 'Zone 1']);

        $thisYearResident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'ThisYear',
            'last_name' => 'Client',
            'birthday' => '1988-05-01',
        ]);
        $thisMonthResident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'ThisMonth',
            'last_name' => 'Client',
            'birthday' => '1987-04-01',
        ]);
        $priorYearResident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'PriorYear',
            'last_name' => 'Client',
            'birthday' => '1985-03-01',
        ]);

        RiskAssessment::factory()->create([
            'resident_id' => $thisYearResident->id,
            'conducted_at' => '2026-03-15',
            'bp_status' => 'Normal',
            'bmi_label' => 'Normal',
        ]);
        RiskAssessment::factory()->create([
            'resident_id' => $thisMonthResident->id,
            'conducted_at' => '2026-09-05',
            'bp_status' => 'Normal',
            'bmi_label' => 'Normal',
        ]);
        RiskAssessment::factory()->create([
            'resident_id' => $priorYearResident->id,
            'conducted_at' => '2025-09-05',
            'bp_status' => 'Normal',
            'bmi_label' => 'Normal',
        ]);

        $summary = HealthRecordsRiskAssessment::summaryCounts();

        $this->assertSame(2, $summary['this_year']);
        $this->assertSame(1, $summary['this_month']);
        $this->assertSame('2026', $summary['this_year_label']);
        $this->assertSame('September 2026', $summary['this_month_label']);
    }

    public function test_export_control_exists_and_add_button_is_absent(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.risk-assessment.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('data-hr-ra-add', $html);
        $this->assertStringContainsString('data-hr-ra-export', $html);
        $this->assertStringContainsString('data-hr-ra-toast', $html);
    }

    public function test_sidebar_risk_assessment_is_real_link_and_active(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.risk-assessment.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame('risk-assessment', UiRole::sidebarActiveKey());
        $this->assertStringContainsString(
            'href="'.e(route('health-records.risk-assessment.index')).'"',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink--active[^>]*aria-current="page"[^>]*>[\s\S]*>Risk Assessment</u',
            $html
        );
    }

    public function test_summary_counts_match_database_rows(): void
    {
        $rows = HealthRecordsRiskAssessment::rows();
        $summary = HealthRecordsRiskAssessment::summaryCounts($rows);

        $this->assertSame(0, $summary['this_year']);
        $this->assertSame(0, $summary['this_month']);
        $this->assertSame([], HealthRecordsRiskAssessment::years($rows));
    }

    public function test_eligibility_uses_birthday_not_stored_age_field(): void
    {
        $asOf = Carbon::parse('2026-08-11')->startOfDay();

        $this->assertTrue(HealthRecordsRiskAssessment::isEligibleResident([
            'id' => 'SYN-19',
            'birthday' => '2007-08-11',
            'age' => 10,
        ], $asOf));
        $this->assertSame(
            19,
            HealthRecordsRiskAssessment::ageInYears([
                'birthday' => '2007-08-11',
                'age' => 10,
            ], $asOf)
        );

        $this->assertFalse(HealthRecordsRiskAssessment::isEligibleResident([
            'id' => 'SYN-NO-DOB',
            'age' => 40,
        ], $asOf));
        $this->assertNull(HealthRecordsRiskAssessment::ageInYears(['age' => 40], $asOf));
    }

    public function test_dob_boundary_includes_nineteenth_birthday_and_excludes_day_before(): void
    {
        $asOf = Carbon::parse('2026-08-11')->startOfDay();

        $this->assertSame(
            19,
            HealthRecordsRiskAssessment::ageInYears(['birthday' => '2007-08-11'], $asOf)
        );
        $this->assertTrue(HealthRecordsRiskAssessment::isEligibleResident([
            'birthday' => '2007-08-11',
        ], $asOf));

        $this->assertSame(
            18,
            HealthRecordsRiskAssessment::ageInYears(['birthday' => '2007-08-12'], $asOf)
        );
        $this->assertFalse(HealthRecordsRiskAssessment::isEligibleResident([
            'birthday' => '2007-08-12',
        ], $asOf));
    }

    private function riskAssessmentHtml(): string
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.risk-assessment.index'));

        $response->assertOk();

        return $response->getContent();
    }
}
