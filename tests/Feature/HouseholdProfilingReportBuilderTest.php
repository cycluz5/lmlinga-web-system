<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Household Profiling Report Builder — choices before export, same
 * pattern as Environmental Health's Report Builder page.
 */
class HouseholdProfilingReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_builder_page_loads_with_filters_and_preview(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-960',
            'zone' => 'Zone 5',
            'street' => 'Builder St.',
            'date_registered' => '2026-01-15',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Head',
            'first_name' => 'Grace',
            'last_name' => 'Ramos',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('household-profiling.report-builder'));

        $response->assertOk();
        $response->assertSee('Household Profiling Report', false);
        $response->assertSee('data-hp-report', false);
        $response->assertSee('data-hp-report-zone', false);
        $response->assertDontSee('data-hp-report-street', false);
        $response->assertDontSee('data-hp-report-search', false);
        $response->assertSee('data-hp-export', false);
        $response->assertSee('Export PDF', false);
        $response->assertSee('data-hp-report-rows', false);
        $response->assertSee('data-hp-report-sections', false);
        $response->assertSee('Zone 5', false);
        $response->assertSee('Ramos, Grace', false);
    }

    public function test_report_builder_prefills_filters_from_query_string(): void
    {
        Household::factory()->create([
            'household_no' => 'HH-961',
            'zone' => 'Zone 6',
            'street' => 'Prefill St.',
            'date_registered' => '2026-01-15',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('household-profiling.report-builder', ['zone' => 'Zone 6']));

        $response->assertOk();
        $response->assertSee('<option value="Zone 6" selected>Zone 6</option>', false);
    }

    public function test_export_button_links_to_export_route_with_current_query(): void
    {
        Household::factory()->create([
            'household_no' => 'HH-962',
            'zone' => 'Zone 7',
            'street' => 'Link St.',
            'date_registered' => '2026-01-15',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('household-profiling.report-builder'));

        $response->assertOk();
        $response->assertSee('data-export-base="'.e(route('household-profiling.export')).'"', false);
    }
}
