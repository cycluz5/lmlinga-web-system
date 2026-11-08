<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\ChildNutrition;
use App\Models\Household;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Health Records → Child Care → Vitamin A Report Builder — choices
 * before export, same pattern as Environmental Health's, Household
 * Profiling's, Death's, Maternal Care's, Family Planning's, Risk
 * Assessment's, and Child Care's own Report Builder pages. Unlike those,
 * this page reloads server-side on filter change (Vitamin A rows are
 * pre-aggregated per zone/year/month, not per-resident), so there is no
 * client-side JSON preview payload to assert on — the rendered table and
 * letterhead are the preview.
 */
class HealthRecordsVitaminAReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_builder_page_loads_with_filters_and_preview(): void
    {
        $this->createChildWithDose('Zone 1', '2026-09-15');

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.vitamin-a.report-builder'));

        $response->assertOk();
        $response->assertSee('Vitamin A Monitoring Report', false);
        $response->assertSee('data-hrva-report', false);
        $response->assertSee('data-hrva-report-zone', false);
        $response->assertSee('data-hrva-report-year', false);
        $response->assertSee('data-hrva-report-month', false);
        $response->assertSee('data-hrva-export', false);
        $response->assertSee('Export PDF', false);
        $response->assertSee('HEALTH RECORDS — VITAMIN A MONITORING', false);
        $response->assertSee('La Medalla Iriga City', false);
        $response->assertSee('Age Group', false);
    }

    public function test_export_link_carries_the_current_filters(): void
    {
        $this->createChildWithDose('Zone 1', '2026-09-15');

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.vitamin-a.report-builder', [
            'zone' => 'Zone 1',
            'year' => '2026',
            'month' => '09',
        ]))->assertOk()->getContent();

        $expectedExportUrl = route('health-records.child-care.vitamin-a.export', [
            'zone' => 'Zone 1',
            'year' => '2026',
            'month' => '09',
        ]);

        $this->assertStringContainsString('href="'.e($expectedExportUrl).'"', $html);
    }

    public function test_export_link_has_no_query_when_all_filters_are_default(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.vitamin-a.report-builder'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.vitamin-a.export')).'"',
            $html
        );
    }

    public function test_preview_reflects_active_filters(): void
    {
        $this->createChildWithDose('Zone A', '2026-09-15');

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.vitamin-a.report-builder', [
            'zone' => 'Zone A',
            'year' => '2026',
            'month' => '09',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Overall Target Population : 1', $html);
        $this->assertStringContainsString('Zone A', $html);
        $this->assertStringContainsString('September 2026', $html);
    }

    public function test_dashboard_url_points_back_to_vitamin_a_page(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.vitamin-a.report-builder'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.vitamin-a')).'"',
            $html
        );
    }

    private function createChildWithDose(string $zone, string $doseDate): void
    {
        $household = Household::factory()->create(['zone' => $zone]);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'sex' => 'Female',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);

        ChildNutrition::factory()->create([
            'resident_id' => $resident->id,
            'vitamin_a_va_6_11_date' => $doseDate,
        ]);
    }
}
