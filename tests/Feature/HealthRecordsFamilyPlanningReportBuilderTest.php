<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\FamilyPlanningVisit;
use App\Models\Household;
use App\Models\Resident;
use App\Support\HealthRecordsFamilyPlanning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Health Records → Family Planning Report Builder — choices before
 * export, same pattern as Environmental Health's, Household Profiling's,
 * Death's, and Maternal Care's Report Builder pages. Data source: one row
 * per commodity given (family_planning / fp_commodities_given in ERD
 * mode, family_planning_visits.commodities JSON in legacy mode).
 */
class HealthRecordsFamilyPlanningReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_builder_page_loads_with_filters_and_preview(): void
    {
        $this->createVisit('Grace Ramos', 'Zone 8', '2026-09-10', [
            ['name' => 'Pills', 'quantity' => 3],
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.report-builder'));

        $response->assertOk();
        $response->assertSee('Family Planning Report', false);
        $response->assertSee('data-hrfp-report', false);
        $response->assertSee('data-hrfp-report-zone', false);
        $response->assertSee('data-hrfp-report-year', false);
        $response->assertSee('data-hrfp-report-month', false);
        $response->assertSee('data-hrfp-export', false);
        $response->assertSee('Export PDF', false);
        $response->assertSee('data-hrfp-report-rows', false);
        $response->assertSee('Grace Ramos', false);
    }

    public function test_dashboard_export_button_lands_on_report_builder_first(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.index'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString(
            'data-export-url="'.e(route('health-records.family-planning.report-builder')).'"',
            $html
        );
        $this->assertStringNotContainsString(
            'data-export-url="'.e(route('health-records.family-planning.export')).'"',
            $html
        );
    }

    public function test_commodity_export_rows_are_one_row_per_commodity(): void
    {
        $this->createVisit('Ana Bautista', 'Zone 1', '2026-09-11', [
            ['name' => 'Pills', 'quantity' => 3],
            ['name' => 'Condoms', 'quantity' => 10],
        ]);

        $rows = HealthRecordsFamilyPlanning::commodityExportRows();

        $this->assertCount(2, $rows);
        $this->assertSame(['Ana Bautista', 'Ana Bautista'], array_column($rows, 'full_name'));
        $this->assertSame(['Pills', 'Condoms'], array_column($rows, 'commodity'));
        $this->assertSame(['3', '10'], array_column($rows, 'quantity'));
        foreach ($rows as $row) {
            $this->assertNotSame('', $row['visit_date']);
        }
    }

    public function test_commodity_export_rows_respects_zone_year_and_month_filters(): void
    {
        $this->createVisit('Zone A Person', 'Zone A', '2026-03-15', [
            ['name' => 'DMPA', 'quantity' => 1],
        ]);
        $this->createVisit('Zone B Person', 'Zone B', '2026-09-15', [
            ['name' => 'IUD', 'quantity' => 1],
        ]);

        $this->assertSame(['Zone A Person'], array_column(HealthRecordsFamilyPlanning::commodityExportRows('Zone A'), 'full_name'));
        $this->assertSame(['Zone B Person'], array_column(HealthRecordsFamilyPlanning::commodityExportRows(null, '2026', '09'), 'full_name'));
        $this->assertSame(['Zone A Person'], array_column(HealthRecordsFamilyPlanning::commodityExportRows(null, '2026', '03'), 'full_name'));
        $this->assertCount(2, HealthRecordsFamilyPlanning::commodityExportRows(null, '2026'));
    }

    public function test_period_label_reflects_active_filters(): void
    {
        $this->assertSame('All Time', HealthRecordsFamilyPlanning::periodLabel('all', 'all'));
        $this->assertSame('Year 2026', HealthRecordsFamilyPlanning::periodLabel('2026', 'all'));
        $this->assertSame('September 2026', HealthRecordsFamilyPlanning::periodLabel('2026', '09'));
    }

    public function test_export_control_downloads_pdf_reflecting_filters(): void
    {
        $this->createVisit('Adrian Corporal', 'Zone 2', '2026-09-11', [
            ['name' => 'Pills', 'quantity' => 3],
        ]);
        $this->createVisit('Haziel Santos', 'Zone 3', '2026-03-05', [
            ['name' => 'Condoms', 'quantity' => 12],
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $all = $this->get(route('health-records.family-planning.export'));
        $all->assertOk();
        $all->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $all->getContent());
        $this->assertStringContainsString('Adrian Corporal', $all->getContent());
        $this->assertStringContainsString('Haziel Santos', $all->getContent());
        $this->assertStringContainsString('filename=', (string) $all->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', strtolower((string) $all->headers->get('content-disposition')));

        $this->actingAsStaff(StaffRole::BHW);
        $filtered = $this->get(route('health-records.family-planning.export', ['zone' => 'Zone 2']));
        $filtered->assertOk();
        $filtered->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $filtered->getContent());
        $this->assertStringContainsString('Adrian Corporal', $filtered->getContent());
        $this->assertStringNotContainsString('Haziel Santos', $filtered->getContent());
    }

    /**
     * @param  list<array{name: string, quantity: int}>  $commodities
     */
    private function createVisit(string $name, string $zone, string $visitedAt, array $commodities): FamilyPlanningVisit
    {
        $household = Household::factory()->create(['zone' => $zone]);
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => $first,
            'last_name' => $last,
        ]);

        return FamilyPlanningVisit::factory()->create([
            'resident_id' => $resident->id,
            'visited_at' => $visitedAt,
            'commodities' => $commodities,
        ]);
    }
}
