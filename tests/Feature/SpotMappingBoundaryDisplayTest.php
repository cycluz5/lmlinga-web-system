<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Support\Geo\BarangayBoundary;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SM-01: Spot Mapping shows the official boundary only when valid data exists, and a clear warning otherwise.
 * Display only — no coordinate is checked or changed. Test geometry is FICTIONAL (not La Medalla).
 */
class SpotMappingBoundaryDisplayTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tempFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        BarangayBoundary::flushCache();
        $this->actingAsStaff(StaffRole::BHW);
    }

    protected function tearDown(): void
    {
        if ($this->tempFile !== null) {
            @unlink($this->tempFile);
        }
        BarangayBoundary::flushCache();
        parent::tearDown();
    }

    private function useBoundaryFile(string $contents): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'lml-boundary-');
        file_put_contents($this->tempFile, $contents);
        config(['lmlinga.geofence.boundary_path' => $this->tempFile]);
    }

    private static function fictionalFeature(): string
    {
        return json_encode([
            'type' => 'Feature',
            'properties' => ['source' => 'Test fixture (fictional)'],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[100.0, 10.0], [101.0, 10.0], [101.0, 12.0], [100.0, 12.0], [100.0, 10.0]]]],
        ]);
    }

    public function test_missing_boundary_shows_a_warning_and_no_outline_data(): void
    {
        config(['lmlinga.geofence.boundary_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'lml-missing-'.bin2hex(random_bytes(4)).'.geojson']);

        $this->get(route('spot-mapping.index'))
            ->assertOk()
            ->assertSee('data-boundary-state="missing"', false)
            ->assertDontSee("data-boundary='", false)
            ->assertSee('data-spot-map-boundary-warning', false)
            ->assertSee('the official Barangay La Medalla boundary has not been added yet')
            ->assertSee('No location is treated as inside the barangay boundary');
    }

    public function test_default_configuration_shows_the_indicative_boundary(): void
    {
        // SR-10 Phase B: the shipped default is the INDICATIVE NAMRIA/PSA polygon, labelled as such.
        $html = $this->get(route('spot-mapping.index'))
            ->assertOk()
            ->assertSee('data-boundary-state="valid"', false)
            ->assertSee('data-spot-map-boundary-indicative', false)
            ->assertSee('Indicative only')
            ->assertDontSee('data-spot-map-boundary-warning', false)
            ->getContent();
        $this->assertStringContainsString('PSGC 0501716006', $html);
    }

    public function test_invalid_boundary_shows_the_reason_and_no_outline_data(): void
    {
        $this->useBoundaryFile(json_encode(['type' => 'Feature', 'properties' => [], 'geometry' => ['type' => 'Polygon', 'coordinates' => []]]));

        $this->get(route('spot-mapping.index'))
            ->assertOk()
            ->assertSee('data-boundary-state="invalid"', false)
            ->assertDontSee("data-boundary='", false)
            ->assertSee('the boundary file is invalid')
            ->assertSee('properties.source');
    }

    public function test_valid_boundary_is_sent_to_the_map_without_a_warning(): void
    {
        $this->useBoundaryFile(self::fictionalFeature());

        $html = $this->get(route('spot-mapping.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-boundary-state="valid"', $html);
        $this->assertStringNotContainsString('data-spot-map-boundary-warning', $html);
        $this->assertSame(1, preg_match("/data-boundary='([^']+)'/", $html, $m));
        $feature = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
        $this->assertSame('MultiPolygon', $feature['geometry']['type']);
        $this->assertSame([100, 10], $feature['geometry']['coordinates'][0][0][0], 'GeoJSON [longitude, latitude] order preserved');
        $this->assertSame('Test fixture (fictional)', $feature['properties']['source']);
    }

    public function test_showing_the_page_never_changes_household_coordinates(): void
    {
        $inside = Household::factory()->create(['household_no' => 'HH-901', 'latitude' => 11.0, 'longitude' => 100.5]);
        $outside = Household::factory()->create(['household_no' => 'HH-902', 'latitude' => 13.3811, 'longitude' => 123.4306]);
        $before = DB::table('households')->orderBy('household_no')->get(['household_no', 'latitude', 'longitude'])->toArray();

        foreach ([null, self::fictionalFeature(), '{invalid'] as $contents) {
            if ($contents === null) {
                config(['lmlinga.geofence.boundary_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'lml-missing.geojson']);
            } else {
                $this->useBoundaryFile($contents);
            }
            BarangayBoundary::flushCache();
            $this->get(route('spot-mapping.index'))->assertOk();
        }

        $this->assertEquals($before, DB::table('households')->orderBy('household_no')->get(['household_no', 'latitude', 'longitude'])->toArray());
        $this->assertNotNull($inside->fresh());
        $this->assertNotNull($outside->fresh());
    }
}
