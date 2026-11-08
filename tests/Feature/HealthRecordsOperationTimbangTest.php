<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\TimbangRecord;
use App\Models\Resident;
use App\Support\UiRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Health Records → Child Care → Operation Timbang monitoring summary (DB-16).
 */
class HealthRecordsOperationTimbangTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedSessionChild(): Resident
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-700',
            'zone' => 'Zone 1',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-700',
            'first_name' => 'Maya',
            'last_name' => 'Session',
            'birthday' => Carbon::parse('2026-08-15')->subMonths(9)->format('Y-m-d'),
            'sex' => 'Female',
        ]);

        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-08-10',
            'weight_kg' => 8.2,
            'height_cm' => 69.0,
            'muac_cm' => 13.5,
        ]);

        return $resident;
    }

    public function test_operation_timbang_route_resolves(): void
    {
        $this->assertTrue(Route::has('health-records.child-care.operation-timbang'));

        $route = Route::getRoutes()->getByName('health-records.child-care.operation-timbang');
        $this->assertNotNull($route);
        $this->assertSame('health-records/child-care/operation-timbang', $route->uri());
    }

    public function test_operation_timbang_page_renders_successfully(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $this->actingAsStaff(StaffRole::BNS);
        $response = $this->get(route('health-records.child-care.operation-timbang'));

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
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.operation-timbang'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Operation Timbang\s*</u',
            $html
        );
    }

    public function test_vitamin_a_and_deworming_pills_remain_present(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.operation-timbang'));

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
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2026,
                'month' => 8,
            ]));

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

        // Month pills show month name only — year lives in the Year selector.
        foreach (['January', 'February', 'August', 'December'] as $monthName) {
            $this->assertMatchesRegularExpression(
                '/lml-hr-ot-month-pill__text[^>]*>\s*'.preg_quote($monthName, '/').'\s*</u',
                $html
            );
            $this->assertDoesNotMatchRegularExpression(
                '/lml-hr-ot-month-pill__text[^>]*>\s*'.preg_quote($monthName, '/').'\s+\d{4}\s*</u',
                $html
            );
        }

        // Summary heading still uses the full calendar period (year from selector).
        $this->assertMatchesRegularExpression(
            '/data-hr-ot-summary-label[^>]*>\s*August 2026\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-hr-ot-month-pill--active[^>]*aria-selected="true"[^>]*aria-current="true"/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-hr-ot-year[\s\S]*>\s*2026\s*</u',
            $html
        );

        // Future months in the selected year are marked unavailable.
        $this->assertMatchesRegularExpression(
            '/data-month="9"[^>]*data-ot-future="1"[^>]*disabled/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-month="8"[^>]*data-ot-future="1"/u',
            $html
        );
    }

    public function test_selected_year_and_month_determine_monitoring_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $household = Household::factory()->create([
            'household_no' => 'HH-710',
            'zone' => 'Zone 1',
        ]);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-710',
            'first_name' => 'Period',
            'last_name' => 'Child',
            'birthday' => Carbon::parse('2026-08-15')->subMonths(11)->format('Y-m-d'),
            'sex' => 'Male',
        ]);

        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2025-08-10',
            'weight_kg' => 7.1,
            'height_cm' => 65.0,
            'muac_cm' => 12.5,
        ]);
        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-08-10',
            'weight_kg' => 9.2,
            'height_cm' => 71.0,
            'muac_cm' => 14.0,
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $year2025 = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2025,
                'month' => 8,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('7.1 kg', $year2025);
        $this->assertStringNotContainsString('9.2 kg', $year2025);
        $this->assertMatchesRegularExpression(
            '/data-hr-ot-summary-label[^>]*>\s*August 2025\s*</u',
            $year2025
        );
        // Same August pill label; year selector owns the calendar year.
        $this->assertMatchesRegularExpression(
            '/lml-hr-ot-month-pill__text[^>]*>\s*August\s*</u',
            $year2025
        );
        // Historical measurement year must appear beside the current year.
        $this->assertMatchesRegularExpression(
            '/id="lml-hr-ot-year"[\s\S]*?<option[^>]*value="2025"[^>]*>\s*2025\s*<\/option>/u',
            $year2025
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-hr-ot-year"[\s\S]*?<option[^>]*value="2026"[^>]*>\s*2026\s*<\/option>/u',
            $year2025
        );
        // In a historical year, every month pill is selectable.
        $this->assertDoesNotMatchRegularExpression(
            '/data-hr-ot-month[^>]*data-ot-future="1"/u',
            $year2025
        );

        $this->actingAsStaff(StaffRole::BHW);
        $year2026 = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2026,
                'month' => 8,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('9.2 kg', $year2026);
        $this->assertStringNotContainsString('7.1 kg', $year2026);
        $this->assertMatchesRegularExpression(
            '/data-hr-ot-summary-label[^>]*>\s*August 2026\s*</u',
            $year2026
        );
    }

    public function test_future_month_pills_are_disabled_in_ui(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2026,
                'month' => 3,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/data-month="3"[^>]*data-ot-future="1"/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-month="4"[^>]*data-ot-future="1"[^>]*disabled/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-month="12"[^>]*data-ot-future="1"[^>]*disabled/u',
            $html
        );
    }

    public function test_summary_metrics_render(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        $this->seedSessionChild();

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2026,
                'month' => 8,
            ]));

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
            '/data-ot-stat="measured-0-23"[^>]*>\s*1\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-ot-stat="total-female"[^>]*>\s*1\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-ot-stat="total-male"[^>]*>\s*0\s*</u',
            $html
        );
    }

    public function test_filter_controls_render(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.operation-timbang'));

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

    public function test_table_headings_and_status_filter_options_render(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        $this->seedSessionChild();

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2026,
                'month' => 8,
            ]));

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
        $this->assertStringContainsString('lml-hr-ot-status--unclassified', $html);
        $this->assertStringContainsString('Maya Session', $html);
    }

    public function test_database_backed_rows_render(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        $this->seedSessionChild();

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2026,
                'month' => 8,
            ]));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-ot-data-mode="database"', $html);
        $this->assertStringContainsString('Maya Session', $html);
        $this->assertStringContainsString('8.2 kg', $html);
        $this->assertStringContainsString('69 cm', $html);
        $this->assertStringContainsString('13.5', $html);
        $this->assertStringNotContainsString('Kristine B. Reyes', $html);
    }

    public function test_export_data_control_exists(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.operation-timbang'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-ot-export', $html);
        $this->assertStringContainsString('aria-label="Export Operation Timbang data"', $html);
        $this->assertMatchesRegularExpression('/>\s*Export Data\s*<\/span>/u', $html);
        $this->assertStringNotContainsString('data-hr-cc-add', $html);
    }

    public function test_health_records_expanded_and_child_care_sidebar_active(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.operation-timbang'));

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
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $this->actingAsStaff(StaffRole::BHW);
        $vitaminA = $this->get(route('health-records.child-care.vitamin-a'));
        $vitaminA->assertOk();
        $this->assertStringContainsString('data-lml-hr-vitamin-a', $vitaminA->getContent());
        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Vitamin A\s*</u',
            $vitaminA->getContent()
        );

        $this->actingAsStaff(StaffRole::BHW);
        $deworming = $this->get(route('health-records.child-care.deworming'));
        $deworming->assertOk();
        $this->assertStringContainsString('data-lml-hr-deworming', $deworming->getContent());
        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Deworming\s*</u',
            $deworming->getContent()
        );

        $this->actingAsStaff(StaffRole::BHW);
        $summary = $this->get(route('health-records.child-care.index'));
        $summary->assertOk();
        $this->assertStringContainsString('data-lml-hr-child-care', $summary->getContent());
    }

    public function test_non_residents_scope_pill_is_absent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.operation-timbang'))
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
