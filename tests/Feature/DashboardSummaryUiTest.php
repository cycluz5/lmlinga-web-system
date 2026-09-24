<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\Resident;
use App\Support\DashboardStatistics;
use App\Support\DashboardUiData;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard home UI — DB-backed totals with frozen layout contract.
 */
class DashboardSummaryUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_summary_labels_structure_and_db_values(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-700',
            'zone' => 'Zone 1',
            'street' => 'Rizal Street',
            'date_registered' => '2026-08-01',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Head',
            'first_name' => 'Ada',
            'middle_name' => null,
            'last_name' => 'Reyes',
            'fp_user' => 'Yes',
        ]);

        $counts = DashboardUiData::summaryCounts();

        $this->actingAsStaff(StaffRole::BNS);
        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Total Household', false);
        $response->assertSee('Total Residents', false);
        $response->assertSee('NHTS', false);
        $response->assertSee('Non NHTS', false);
        $response->assertDontSee('Non NHTS Poor', false);
        $response->assertSee('Health Indicators', false);
        $response->assertSee('Teenage Pregnant', false);
        $response->assertSee('Pregnant', false);
        $response->assertDontSee('Lactating', false);
        $response->assertSee('FP Current User', false);
        $response->assertDontSee('FP Unmet Needs', false);
        $response->assertSee('Normal Weight Children', false);
        $response->assertSee('Underweight Children', false);
        $response->assertSee('Overweight Children', false);
        $response->assertDontSee('Exclusively Breastfed Infants', false);
        $response->assertSee('Infants 0–11 Months', false);
        $response->assertSee('HH With Large Family Size', false);
        $response->assertSee('HH With Potable Water Source', false);
        $response->assertSee('HH With Sanitary Toilet', false);
        $response->assertSee('La Medalla, Iriga City', false);
        $response->assertDontSee('Community health overview', false);
        $response->assertDontSee('Interactive map preview', false);
        $response->assertDontSee('temporary UI demo values', false);
        $response->assertDontSee('Household snapshot', false);
        $response->assertSee((string) $counts['totalHouseholds'], false);
        $response->assertSee((string) $counts['totalResidents'], false);
        $response->assertDontSee('lml-dash-panel--table', false);

        $response->assertDontSee('Total Health Records', false);
        $response->assertDontSee('Related Records', false);
        $response->assertDontSee('Dashboard content coming soon', false);
        $response->assertDontSee('Senior Citizens', false);
        $response->assertDontSee('Portable Water Source', false);
        $response->assertDontSee('Infants Given Complementary Food', false);
        $response->assertDontSee('Complimentary Food', false);
        $response->assertDontSee('Operation Timbang', false);
        $response->assertDontSee('Vitamin A', false);
        $response->assertDontSee('Deworming', false);
        $response->assertDontSee('HH-151', false);
        $response->assertDontSee('635', false);
        $response->assertDontSee('2,103', false);

        $html = $response->getContent();
        $this->assertDoesNotMatchRegularExpression(
            '/<h[12][^>]*>\s*Health Records\s*<\/h[12]>/',
            $html
        );
        $this->assertSame(1, substr_count($html, 'data-dash-count="households"'));
        $this->assertSame(1, substr_count($html, 'data-dash-count="nhts"'));
        $this->assertSame(0, substr_count($html, 'data-dash-count="non-nhts-poor"'));
        $this->assertSame(0, substr_count($html, 'data-dash-count="child-care"'));
        $this->assertSame(0, substr_count($html, 'data-dash-count="health-records"'));
        $this->assertSame(1, substr_count($html, 'data-dash-panel="map"'));
        $this->assertSame(1, substr_count($html, 'data-dash-panel="zones"'));
        $this->assertSame(0, substr_count($html, 'data-dash-panel="household"'));
        $this->assertSame(10, substr_count($html, 'data-dash-indicator='));
        $this->assertSame(0, substr_count($html, 'data-dash-indicator="complementary-food"'));
        $this->assertSame(0, substr_count($html, 'data-dash-indicator="lactating"'));
        $this->assertSame(1, substr_count($html, 'data-dash-indicator="hh-sanitary-toilet"'));
        $this->assertSame(4, substr_count($html, 'lml-dash-count__icon'));
        $this->assertSame(0, substr_count($html, 'bi-badge-wc'));
        $this->assertSame(10, substr_count($html, 'lml-dash-indicator__pictogram'));
        $this->assertStringContainsString('lml-sidebar__link--active', $html);
        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/dashboard"[^>]*class="[^"]*lml-sidebar__link--active/',
            $html
        );
    }

    public function test_dashboard_empty_snapshot_state(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('lml-dash-panel--table', $html);
        $this->assertStringNotContainsString('Household snapshot', $html);
        $this->assertStringNotContainsString('HH-151', $html);
        $this->assertStringContainsString('data-dash-panel="map"', $html);
        $this->assertStringContainsString('data-dash-panel="zones"', $html);
    }

    public function test_dashboard_does_not_duplicate_summary_cards(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('dashboard'))
            ->getContent();

        preg_match_all('/data-dash-count="([^"]+)"/', $html, $matches);
        $keys = $matches[1] ?? [];

        $this->assertCount(4, $keys);
        $this->assertSame(array_unique($keys), $keys);
        $this->assertSame(
            ['households', 'residents', 'nhts', 'non-nhts'],
            $keys
        );
        $this->assertStringContainsString('data-dash-panel="map"', $html);
        $this->assertStringContainsString('data-dash-panel="zones"', $html);
        $this->assertStringNotContainsString('data-dash-panel="household"', $html);
        $this->assertTrue(
            strpos($html, 'data-dash-panel="map"') < strpos($html, 'data-dash-panel="zones"')
        );
    }
}
