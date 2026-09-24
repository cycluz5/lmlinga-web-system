<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Services\SpotMappingService;
use App\Support\DashboardStatistics;
use App\Support\DashboardUiData;
use App\Support\DemoCatalog;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Refinement 2.0-3 — Dashboard map uses SpotMappingService::mappedMarkers().
 */
class DashboardSpotMapSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(StaffRole::BHW);
    }

    public function test_dashboard_marker_dataset_contains_legacy_and_three_digit_households(): void
    {
        $this->seedPlottedHousehold('HH-001', 'Zone 1', 'NHTS', 'Legacy', 'Mae', 'Head', 13.3811, 123.4306);
        $this->seedPlottedHousehold('121', 'Zone 2', 'Non-NHTS', 'Doi', null, 'Chipi', 13.378472, 123.430925);

        $markers = $this->markersFromHtml(
            $this->get(route('dashboard'))->assertOk()->getContent()
        );
        $byNo = collect($markers)->keyBy('householdNo');

        $this->assertArrayHasKey('HH-001', $byNo->all());
        $this->assertArrayHasKey('121', $byNo->all());

        $legacy = $byNo['HH-001'];
        $current = $byNo['121'];

        $this->assertEqualsWithDelta(13.3811, (float) $legacy['lat'], 0.000001);
        $this->assertEqualsWithDelta(123.4306, (float) $legacy['lng'], 0.000001);
        $this->assertEqualsWithDelta(13.378472, (float) $current['lat'], 0.000001);
        $this->assertEqualsWithDelta(123.430925, (float) $current['lng'], 0.000001);

        if (Schema::hasColumn('households', 'household_type')) {
            $this->assertSame('NHTS', $legacy['householdType']);
            $this->assertSame('Non-NHTS', $current['householdType']);
        }

        $this->assertSame('Legacy', $legacy['headFirstName']);
        $this->assertSame('Mae', $legacy['headMiddleName']);
        $this->assertSame('Head', $legacy['headLastName']);
        $this->assertSame('Doi', $current['headFirstName']);
        $this->assertSame('', $current['headMiddleName']);
        $this->assertSame('Chipi', $current['headLastName']);
        $this->assertSame('Doi Chipi', $current['houseHead']);
        $this->assertSame('1', (string) $legacy['zone']);
        $this->assertSame('Zone 1', $legacy['zoneLabel']);
        $this->assertSame('2', (string) $current['zone']);
        $this->assertSame('Zone 2', $current['zoneLabel']);
        $this->assertSame(1, $legacy['members']);
        $this->assertSame(1, $current['members']);
        $this->assertSame('plotted', $current['status']);
        $this->assertArrayNotHasKey('purok', $current);
    }

    public function test_dashboard_and_spot_mapping_share_the_same_mapped_household_numbers(): void
    {
        $this->seedPlottedHousehold('HH-001', 'Zone 1', 'NHTS', 'One', 'A', 'Head', 13.3811, 123.4306);
        $this->seedPlottedHousehold('HH-002', 'Zone 2', 'Non-NHTS', 'Two', null, 'Head', 13.3812, 123.4307);
        $this->seedPlottedHousehold('121', 'Zone 2', 'Non-NHTS', 'Doi', null, 'Chipi', 13.378472, 123.430925);
        Household::factory()->create([
            'household_no' => 'HH-999',
            'zone' => 'Zone 3',
            'latitude' => null,
            'longitude' => null,
        ]);

        $serviceNos = $this->sortedHouseholdNos(app(SpotMappingService::class)->mappedMarkers());
        $dashboardNos = $this->sortedHouseholdNos(
            $this->markersFromHtml($this->get(route('dashboard'))->assertOk()->getContent())
        );
        $spotNos = $this->sortedHouseholdNos(
            $this->markersFromHtml($this->get(route('spot-mapping.index'))->assertOk()->getContent())
        );

        $this->assertSame(['121', 'HH-001', 'HH-002'], $serviceNos);
        $this->assertSame($serviceNos, $dashboardNos);
        $this->assertSame($serviceNos, $spotNos);
        $this->assertCount(3, $dashboardNos);
    }

    public function test_unplotted_and_invalid_coordinates_are_not_mapped(): void
    {
        $this->seedPlottedHousehold('HH-001', 'Zone 1', 'NHTS', 'Ok', null, 'Head', 13.3811, 123.4306);
        Household::factory()->create([
            'household_no' => 'HH-888',
            'zone' => 'Zone 1',
            'latitude' => null,
            'longitude' => null,
        ]);
        Household::factory()->create([
            'household_no' => '000',
            'zone' => 'Zone 2',
            'latitude' => 0,
            'longitude' => 0,
        ]);

        $nos = $this->sortedHouseholdNos(app(SpotMappingService::class)->mappedMarkers());

        $this->assertSame(['HH-001'], $nos);
        $this->assertSame(
            $nos,
            $this->sortedHouseholdNos(
                $this->markersFromHtml($this->get(route('dashboard'))->assertOk()->getContent())
            )
        );
    }

    public function test_leading_zero_household_number_is_preserved(): void
    {
        $this->seedPlottedHousehold('001', 'Zone 3', 'NHTS', 'Zero', null, 'Lead', 13.38, 123.43);

        $marker = collect(app(SpotMappingService::class)->mappedMarkers())
            ->firstWhere('householdNo', '001');

        $this->assertNotNull($marker);
        $this->assertSame('001', $marker['householdNo']);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('"householdNo":"001"', $html);
        $this->assertStringNotContainsString('"householdNo":"1"', $html);
        $this->assertStringNotContainsString('"householdNo":"HH-001"', $html);
    }

    public function test_dashboard_does_not_leak_demo_catalog_markers(): void
    {
        $this->assertNotNull(DemoCatalog::findHousehold('HH-151'));

        $this->seedPlottedHousehold('121', 'Zone 2', 'Non-NHTS', 'Doi', null, 'Chipi', 13.38, 123.43);

        $nos = array_column(app(SpotMappingService::class)->mappedMarkers(), 'householdNo');
        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(['121'], $nos);
        $this->assertStringNotContainsString('HH-151', $html);
        $this->assertStringNotContainsString('temporary UI demo values', $html);
    }

    public function test_dashboard_route_stays_read_only_and_has_no_plot_controls(): void
    {
        $before = [
            'households' => Household::query()->count(),
            'residents' => Resident::query()->count(),
        ];

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame($before['households'], Household::query()->count());
        $this->assertSame($before['residents'], Resident::query()->count());
        $this->assertStringNotContainsString('Plot New Household', $html);
        $this->assertStringNotContainsString('data-spot-map-plot', $html);
        $this->assertStringNotContainsString('data-plot-new-url', $html);
        $this->assertStringNotContainsString(route('spot-mapping.plot-new', [], false), $html);
        $this->assertStringContainsString('data-lml-dash-map', $html);
        $this->assertStringContainsString('data-markers=', $html);
        $this->assertStringContainsString('data-dash-map-legend', $html);
    }

    public function test_dashboard_js_renders_compact_popup_fields_without_plot_writes(): void
    {
        $js = (string) file_get_contents(base_path('resources/js/pages/dashboard-home.js'));

        $this->assertStringContainsString('SpotMappingService::mappedMarkers', $js);
        $this->assertStringContainsString('Household No.', $js);
        $this->assertStringContainsString('Household Type', $js);
        $this->assertStringContainsString('Household Head', $js);
        $this->assertStringContainsString('No. of Members', $js);
        $this->assertStringContainsString("VIEW_HH_BASE = '/household-profiling/'", $js);
        $this->assertStringContainsString('isPlottableCoordinate', $js);
        $this->assertStringNotContainsString('spot-mapping/plot', $js);
        $this->assertStringNotContainsString('plot-new', $js);
    }

    public function test_zone_cards_count_database_households_including_three_digit_numbers(): void
    {
        $this->seedPlottedHousehold('HH-001', 'Zone 1', 'NHTS', 'One', null, 'Head', 13.3811, 123.4306);
        $this->seedPlottedHousehold('121', 'Zone 2', 'Non-NHTS', 'Doi', null, 'Chipi', 13.378472, 123.430925);
        Household::factory()->create([
            'household_no' => 'HH-UNPLOTTED',
            'zone' => 'Zone 2',
            'latitude' => null,
            'longitude' => null,
        ]);

        $summary = collect(DashboardStatistics::zoneSummary())->keyBy('zone');
        $this->assertSame(1, $summary['Zone 1']['households']);
        $this->assertSame(2, $summary['Zone 2']['households']);
        $this->assertSame(1, $summary['Zone 1']['population']);
        $this->assertSame(1, $summary['Zone 2']['population']);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('Zone-Based Household and Population Map', $html);
        $this->assertSame(5, substr_count($html, 'data-dash-zone="zone-'));

        $zoneTwo = collect(DashboardUiData::zoneSummaryCards())->firstWhere('zone', 'Zone 2');
        $this->assertNotNull($zoneTwo);
        $this->assertSame(2, $zoneTwo['households']);
    }

    public function test_view_household_paths_remain_valid_for_legacy_and_three_digit_numbers(): void
    {
        $this->seedPlottedHousehold('HH-001', 'Zone 1', 'NHTS', 'Legacy', 'Mae', 'Head', 13.3811, 123.4306);
        $this->seedPlottedHousehold('121', 'Zone 2', 'Non-NHTS', 'Doi', null, 'Chipi', 13.378472, 123.430925);

        $this->get(route('household-profiling.view', ['householdNo' => 'HH-001']))->assertOk();
        $this->get(route('household-profiling.view', ['householdNo' => '121']))
            ->assertOk()
            ->assertSee('Doi Chipi', false);
    }

    public function test_dashboard_renders_zone_map_legend_without_plot_chrome(): void
    {
        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-dash-map-legend', $html);
        $this->assertStringContainsString('Map Legend', $html);
        $this->assertStringContainsString('Barangay Zones', $html);
        $this->assertStringContainsString('lml-spot-map__legend-swatch--zone-1', $html);
        $this->assertStringContainsString('lml-spot-map__legend-swatch--zone-2', $html);
        $this->assertStringContainsString('lml-spot-map__legend-swatch--zone-3', $html);
        $this->assertStringContainsString('lml-spot-map__legend-swatch--zone-4', $html);
        $this->assertStringContainsString('lml-spot-map__legend-swatch--zone-5', $html);
        $this->assertStringContainsString('>Zone 1</span>', $html);
        $this->assertStringContainsString('>Zone 2</span>', $html);
        $this->assertStringContainsString('>Zone 3</span>', $html);
        $this->assertStringContainsString('>Zone 4</span>', $html);
        $this->assertStringContainsString('>Zone 5</span>', $html);
        $this->assertStringNotContainsString('Completed / Plotted', $html);
        $this->assertStringNotContainsString('Plot New Household', $html);
        $this->assertStringNotContainsString('Save Map', $html);
        $this->assertStringNotContainsString('data-spot-map-plot', $html);
        $this->assertStringNotContainsString('data-spot-map-confirm', $html);

        $this->assertSame([], $this->markersFromHtml($html));
    }

    public function test_dashboard_legend_remains_when_plotted_markers_exist(): void
    {
        $this->seedPlottedHousehold('HH-001', 'Zone 1', 'NHTS', 'Legacy', 'Mae', 'Head', 13.3811, 123.4306);
        $this->seedPlottedHousehold('121', 'Zone 2', 'Non-NHTS', 'Doi', null, 'Chipi', 13.378472, 123.430925);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $nos = $this->sortedHouseholdNos($this->markersFromHtml($html));

        $this->assertStringContainsString('data-dash-map-legend', $html);
        $this->assertSame(['121', 'HH-001'], $nos);
        $this->assertSame(
            $nos,
            $this->sortedHouseholdNos(app(SpotMappingService::class)->mappedMarkers())
        );
        $this->assertStringNotContainsString('HH-151', $html);
    }

    public function test_dashboard_zone_palette_matches_spot_mapping_css_variables(): void
    {
        $spotCss = (string) file_get_contents(base_path('resources/css/pages/spot-mapping.css'));
        $dashCss = (string) file_get_contents(base_path('resources/css/pages/dashboard.css'));
        $dashJs = (string) file_get_contents(base_path('resources/js/pages/dashboard-home.js'));

        foreach ([
            '--lml-spot-zone-1: #3b82f6',
            '--lml-spot-zone-2: #f97316',
            '--lml-spot-zone-3: #facc15',
            '--lml-spot-zone-4: #14b8a6',
            '--lml-spot-zone-5: #8b5cf6',
        ] as $token) {
            $this->assertStringContainsString($token, $spotCss);
            $this->assertStringContainsString($token, $dashCss);
        }

        $this->assertStringContainsString('lml-spot-map__marker--zone-${zone}', $dashJs);
        $this->assertStringContainsString('lml-spot-map__legend-swatch--zone-', $spotCss);
        $this->assertStringContainsString('lml-dash-map__legend', $dashCss);
        $this->assertStringNotContainsString('spot-mapping/plot', $dashJs);
    }

    /**
     * @param  list<array<string, mixed>>  $markers
     * @return list<string>
     */
    private function sortedHouseholdNos(array $markers): array
    {
        $nos = array_values(array_map(
            static fn (array $marker): string => (string) $marker['householdNo'],
            $markers
        ));
        sort($nos);

        return $nos;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function markersFromHtml(string $html): array
    {
        $this->assertMatchesRegularExpression('/data-markers=\'/', $html);

        if (preg_match('/data-markers=\'(.*?)\'/s', $html, $matches) !== 1) {
            $this->fail('Dashboard/Spot Mapping page is missing data-markers JSON.');
        }

        $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function seedPlottedHousehold(
        string $householdNo,
        string $zone,
        string $householdType,
        string $firstName,
        ?string $middleName,
        string $lastName,
        float $lat,
        float $lng,
    ): Household {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => $zone,
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        if (Schema::hasColumn('households', 'household_type')) {
            DB::table('households')->where($household->getKeyName(), $household->getKey())->update([
                'household_type' => $householdType,
            ]);
        }

        Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'relation' => 'Head',
        ]);

        return $household->refresh();
    }
}
