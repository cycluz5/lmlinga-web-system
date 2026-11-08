<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\Resident;
use App\Models\TimbangRecord;
use App\Support\HealthRecordsOperationTimbang;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Health Records → Child Care → Operation Timbang Report Builder —
 * choices before export, same pattern as Environmental Health's,
 * Household Profiling's, Death's, Maternal Care's, Family Planning's,
 * Risk Assessment's, and Child Care's own Report Builder pages. Session
 * (year/month) reloads server-side; zone/sex/status filter that
 * session's rows client-side.
 */
class HealthRecordsOperationTimbangReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_report_builder_page_loads_with_filters_and_preview(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        $this->createWeighedChild('Grace Ramos', 'Zone 8', 'Female', '2026-08-10');

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.operation-timbang.report-builder'));

        $response->assertOk();
        $response->assertSee('Operation Timbang Report', false);
        $response->assertSee('data-hrot-report', false);
        $response->assertSee('data-hrot-report-year', false);
        $response->assertSee('data-hrot-report-month', false);
        $response->assertSee('data-hrot-report-zone', false);
        $response->assertSee('data-hrot-report-sex', false);
        $response->assertSee('data-hrot-report-status', false);
        $response->assertSee('data-hrot-export', false);
        $response->assertSee('Export PDF', false);
        $response->assertSee('Grace Ramos', false);
    }

    public function test_dashboard_export_button_lands_on_report_builder_first(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.operation-timbang'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString(
            'data-export-url="'.e(route('health-records.child-care.operation-timbang.report-builder')).'"',
            $html
        );
        $this->assertStringNotContainsString(
            'data-export-url="'.e(route('health-records.child-care.operation-timbang.export')).'"',
            $html
        );
    }

    public function test_export_rows_respects_zone_and_sex_filters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $this->createWeighedChild('Zone A Person', 'Zone A', 'Female', '2026-08-10');
        $this->createWeighedChild('Zone B Person', 'Zone B', 'Male', '2026-08-11');

        $this->assertSame(
            ['Zone A Person'],
            array_column(HealthRecordsOperationTimbang::exportRows(2026, 8, 'Zone A'), 'full_name')
        );
        $this->assertSame(
            ['Zone A Person'],
            array_column(HealthRecordsOperationTimbang::exportRows(2026, 8, null, 'female'), 'full_name')
        );
        $this->assertCount(2, HealthRecordsOperationTimbang::exportRows(2026, 8));
    }

    public function test_export_rows_reflect_session_specific_measurements(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        // Eligible (under-5) residents always appear in every session's rows
        // — the population denominator is age-based, not measurement-date
        // filtered — but only carry a measurement value for the session
        // they were actually weighed in.
        $this->createWeighedChild('March Person', 'Zone A', 'Male', '2026-03-05');

        $august = collect(HealthRecordsOperationTimbang::exportRows(2026, 8))->firstWhere('full_name', 'March Person');
        $march = collect(HealthRecordsOperationTimbang::exportRows(2026, 3))->firstWhere('full_name', 'March Person');

        $this->assertNotNull($august);
        $this->assertNotNull($march);
        $this->assertFalse($august['has_measurement']);
        $this->assertTrue($march['has_measurement']);
    }

    public function test_export_control_downloads_pdf_reflecting_filters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $this->createWeighedChild('Adrian Corporal', 'Zone 2', 'Male', '2026-08-10');
        $this->createWeighedChild('Haziel Santos', 'Zone 3', 'Female', '2026-08-11');

        $this->actingAsStaff(StaffRole::BHW);
        $all = $this->get(route('health-records.child-care.operation-timbang.export', ['year' => 2026, 'month' => 8]));
        $all->assertOk();
        $all->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $all->getContent());
        $this->assertStringContainsString('Adrian Corporal', $all->getContent());
        $this->assertStringContainsString('Haziel Santos', $all->getContent());
        $this->assertStringContainsString('filename=', (string) $all->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', strtolower((string) $all->headers->get('content-disposition')));

        $this->actingAsStaff(StaffRole::BHW);
        $filtered = $this->get(route('health-records.child-care.operation-timbang.export', [
            'year' => 2026,
            'month' => 8,
            'zone' => 'Zone 2',
        ]));
        $filtered->assertOk();
        $filtered->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $filtered->getContent());
        $this->assertStringContainsString('Adrian Corporal', $filtered->getContent());
        $this->assertStringNotContainsString('Haziel Santos', $filtered->getContent());
    }

    public function test_report_builder_shows_the_monthly_summary_panel(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        $this->createWeighedChild('Grace Ramos', 'Zone 8', 'Female', '2026-08-10');

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.operation-timbang.report-builder', [
            'year' => 2026,
            'month' => 8,
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Summary:\s*<span[^>]*>\s*August 2026\s*</u', $html);
        $this->assertStringContainsString('No. of 0–23 Months PS', $html);
        $this->assertStringContainsString('No. of 0–23 Months Old Measured', $html);
        $this->assertStringContainsString('No. of Over age', $html);
        $this->assertStringContainsString('No. of Transferred/ Moveout', $html);
        $this->assertStringContainsString('No. of Dead', $html);
        $this->assertStringContainsString('No. Not Available', $html);
        $this->assertStringContainsString('No. of New Cases', $html);
        $this->assertStringContainsString('Total Number of 0–23 Months', $html);
        $this->assertMatchesRegularExpression(
            '/lml-hr-ot-metric__value">\s*1\s*</u',
            $html
        );
    }

    public function test_export_pdf_includes_the_monthly_summary_page(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        $this->createWeighedChild('Grace Ramos', 'Zone 8', 'Female', '2026-08-10');

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.operation-timbang.export', [
            'year' => 2026,
            'month' => 8,
        ]));

        $response->assertOk();
        $pdf = $response->getContent();

        $this->assertStringContainsString('SUMMARY: AUGUST 2026', $pdf);
        $this->assertStringContainsString('0-23 Months PS', $pdf);
        $this->assertStringContainsString('0-23 Months Old Measured', $pdf);
        $this->assertStringContainsString('Total Number of 0-23 Months', $pdf);
    }

    private function createWeighedChild(string $name, string $zone, string $sex, string $measurementDate): Resident
    {
        $household = Household::factory()->create(['zone' => $zone]);
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => $first,
            'last_name' => $last,
            'birthday' => Carbon::parse($measurementDate)->subMonths(9)->format('Y-m-d'),
            'sex' => $sex,
        ]);

        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => $measurementDate,
            'weight_kg' => 8.2,
            'height_cm' => 69.0,
            'muac_cm' => 13.5,
        ]);

        return $resident;
    }
}
