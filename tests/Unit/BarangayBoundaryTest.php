<?php

namespace Tests\Unit;

use App\Support\Geo\BarangayBoundary;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * SM-01 boundary loading/validation. All geometries are FICTIONAL test shapes (longitude 100–106,
 * latitude 10–12) — not La Medalla. GeoJSON positions are [longitude, latitude].
 */
class BarangayBoundaryTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        BarangayBoundary::flushCache();
        parent::tearDown();
    }

    /** lng 100..101, lat 10..12 */
    private const RECT = [[100.0, 10.0], [101.0, 10.0], [101.0, 12.0], [100.0, 12.0], [100.0, 10.0]];

    private static function feature(array $geometry, array $properties = ['source' => 'Test fixture (fictional)']): string
    {
        return json_encode(['type' => 'Feature', 'properties' => $properties, 'geometry' => $geometry]);
    }

    private static function polygon(array ...$rings): array
    {
        return ['type' => 'Polygon', 'coordinates' => $rings];
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lml-boundary-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_valid_polygon_contains_inside_points_edges_and_vertices(): void
    {
        $b = BarangayBoundary::fromJson(self::feature(self::polygon(self::RECT)));

        $this->assertTrue($b->isValid());
        $this->assertSame(BarangayBoundary::STATE_VALID, $b->state());
        $this->assertTrue($b->contains(11.0, 100.5), 'inside');
        $this->assertTrue($b->contains(10.0, 100.5), 'on bottom edge');
        $this->assertTrue($b->contains(12.0, 101.0), 'on a vertex');
        $this->assertFalse($b->contains(12.5, 100.5), 'north of the rectangle');
        $this->assertFalse($b->contains(11.0, 101.5), 'east of the rectangle');
    }

    public function test_geojson_order_is_longitude_then_latitude(): void
    {
        $b = BarangayBoundary::fromJson(self::feature(self::polygon(self::RECT)));

        // contains(latitude, longitude): lat 11 / lng 100.5 is inside; the swapped pair is not.
        $this->assertTrue($b->contains(11.0, 100.5));
        $this->assertFalse($b->contains(100.5, 11.0));
        $this->assertSame([100.0, 10.0], $b->toGeoJson()['geometry']['coordinates'][0][0][0]);
    }

    public function test_swapped_latitude_longitude_is_rejected_when_out_of_range(): void
    {
        $swapped = [[10.0, 100.0], [10.0, 101.0], [12.0, 101.0], [12.0, 100.0], [10.0, 100.0]];
        $b = BarangayBoundary::fromJson(self::feature(self::polygon($swapped)));

        $this->assertSame(BarangayBoundary::STATE_INVALID, $b->state());
        $this->assertStringContainsString('longitude, latitude', $b->reason());
    }

    public function test_multipolygon_and_holes(): void
    {
        $second = [[105.0, 10.0], [106.0, 10.0], [106.0, 11.0], [105.0, 11.0], [105.0, 10.0]];
        $hole = [[100.4, 10.8], [100.6, 10.8], [100.6, 11.2], [100.4, 11.2], [100.4, 10.8]];
        $b = BarangayBoundary::fromJson(self::feature([
            'type' => 'MultiPolygon',
            'coordinates' => [[self::RECT, $hole], [$second]],
        ]));

        $this->assertTrue($b->isValid());
        $this->assertTrue($b->contains(10.5, 100.2), 'first polygon');
        $this->assertTrue($b->contains(10.5, 105.5), 'second polygon');
        $this->assertFalse($b->contains(10.5, 103.0), 'between the polygons');
        $this->assertFalse($b->contains(11.0, 100.5), 'inside the hole');
        $this->assertTrue($b->contains(10.8, 100.5), 'on the hole edge counts as inside');
        $this->assertSame('MultiPolygon', $b->toGeoJson()['geometry']['type']);
    }

    public function test_feature_collection_with_exactly_one_feature_is_accepted(): void
    {
        $one = json_encode(['type' => 'FeatureCollection', 'features' => [json_decode(self::feature(self::polygon(self::RECT)), true)]]);
        $two = json_encode(['type' => 'FeatureCollection', 'features' => [json_decode(self::feature(self::polygon(self::RECT)), true), json_decode(self::feature(self::polygon(self::RECT)), true)]]);

        $this->assertTrue(BarangayBoundary::fromJson($one)->isValid());
        $this->assertFalse(BarangayBoundary::fromJson($two)->isValid());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidBoundaries(): array
    {
        $rect = self::RECT;
        $open = array_slice($rect, 0, 4);
        $flat = [[100.0, 10.0], [101.0, 10.0], [102.0, 10.0], [100.0, 10.0]];

        return [
            'not json' => ['{not json', 'not valid JSON'],
            'bare geometry, no Feature' => [json_encode(self::polygon($rect)), 'GeoJSON Feature'],
            'missing source' => [self::feature(self::polygon($rect), ['name' => 'x']), 'properties.source'],
            'blank source' => [self::feature(self::polygon($rect), ['source' => '  ']), 'properties.source'],
            'point geometry' => [self::feature(['type' => 'Point', 'coordinates' => [100.5, 11.0]]), 'Polygon or MultiPolygon'],
            'empty polygon' => [self::feature(['type' => 'Polygon', 'coordinates' => []]), 'no rings'],
            'empty multipolygon' => [self::feature(['type' => 'MultiPolygon', 'coordinates' => []]), 'no coordinates'],
            'ring too short' => [self::feature(self::polygon([[100.0, 10.0], [101.0, 10.0], [100.0, 10.0]])), 'at least 4 positions'],
            'ring not closed' => [self::feature(self::polygon(array_merge($open, [[100.0, 11.0]]))), 'not closed'],
            'string numbers' => [self::feature(self::polygon([['100', '10'], [101.0, 10.0], [101.0, 12.0], ['100', '10']])), 'pair of numbers'],
            'longitude out of range' => [self::feature(self::polygon([[200.0, 10.0], [201.0, 10.0], [201.0, 12.0], [200.0, 10.0]])), 'out of range'],
            'zero area' => [self::feature(self::polygon($flat)), 'no area'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBoundaries')]
    public function test_invalid_boundaries_fail_closed(string $json, string $reasonFragment): void
    {
        $b = BarangayBoundary::fromJson($json);

        $this->assertSame(BarangayBoundary::STATE_INVALID, $b->state());
        $this->assertStringContainsString($reasonFragment, $b->reason());
        $this->assertNull($b->toGeoJson());
        $this->assertFalse($b->contains(11.0, 100.5), 'fail closed: nothing is inside');
    }

    public function test_missing_file_fails_closed_with_a_clear_reason(): void
    {
        $b = BarangayBoundary::load(sys_get_temp_dir().DIRECTORY_SEPARATOR.'lml-no-such-boundary-'.bin2hex(random_bytes(4)).'.geojson');

        $this->assertSame(BarangayBoundary::STATE_MISSING, $b->state());
        $this->assertStringContainsString('has not been added yet', $b->reason());
        $this->assertNull($b->toGeoJson());
        $this->assertFalse($b->contains(11.0, 100.5));
    }

    public function test_the_shipped_indicative_boundary_is_valid_attributed_and_unchanged(): void
    {
        // SR-10 Phase B: the default file is the INDICATIVE NAMRIA/PSA COD-AB polygon (PSGC 0501716006), copied
        // unchanged from a checksum-verified release — not an invented or approximated shape.
        $path = base_path('resources/geo/la-medalla-boundary.geojson');
        $this->assertStringEndsWith('la-medalla-boundary.geojson', str_replace('\\', '/', BarangayBoundary::configuredPath()));
        $raw = json_decode((string) file_get_contents($path), true);
        $this->assertSame('0501716006', $raw['properties']['psgc']);
        $this->assertStringContainsString('INDICATIVE', $raw['properties']['source']);
        $this->assertStringContainsString('CC BY-IGO', $raw['properties']['license']);
        $this->assertSame('Polygon', $raw['geometry']['type']);
        $ring = $raw['geometry']['coordinates'][0];
        $this->assertCount(12, $ring);
        $this->assertSame($ring[0], end($ring), 'closed ring');
        foreach ($ring as [$lng, $lat]) {
            $this->assertTrue($lng > 123.4 && $lng < 123.45 && $lat > 13.36 && $lat < 13.39, 'GeoJSON [lng, lat] order within Iriga');
        }

        $b = BarangayBoundary::load();
        $this->assertTrue($b->isValid());
        $this->assertStringContainsString('indicative', $b->meta()['name']);

        // Sourced reference points (HDX COD-AB centroids, OSM place node, app map centre) — none invented here.
        $this->assertTrue($b->contains(13.37722053, 123.43101052), 'HDX La Medalla (Iriga) centroid');
        $this->assertTrue($b->contains(13.3739011, 123.4325556), 'OSM node 6784107193');
        $this->assertTrue($b->contains(13.3806, 123.4312), 'LA_MEDALLA_CENTER');
        $this->assertFalse($b->contains(13.47453212, 123.37216972), 'HDX La Medalla, Baao centroid (other LGU)');
        $this->assertFalse($b->contains(13.44797179, 123.42358085), 'HDX Iriga City centroid');
    }

    public function test_loads_a_valid_file_and_keeps_metadata(): void
    {
        $path = $this->tempFile(self::feature(self::polygon(self::RECT), [
            'source' => 'Test fixture (fictional)', 'name' => 'Test area', 'version' => 'v1', 'retrieved_at' => '2026-10-30', 'extra' => 'ignored',
        ]));

        $b = BarangayBoundary::load($path);

        $this->assertTrue($b->isValid());
        $this->assertSame(['source' => 'Test fixture (fictional)', 'name' => 'Test area', 'version' => 'v1', 'retrieved_at' => '2026-10-30'], $b->meta());
        $this->assertSame($b->meta(), $b->toGeoJson()['properties']);
    }

    public function test_empty_and_oversized_files_are_invalid_and_logged(): void
    {
        Log::spy();

        $this->assertSame(BarangayBoundary::STATE_INVALID, BarangayBoundary::load($this->tempFile(''))->state());
        $this->assertSame(BarangayBoundary::STATE_INVALID, BarangayBoundary::load($this->tempFile(str_repeat(' ', BarangayBoundary::MAX_BYTES + 1)))->state());

        Log::shouldHaveReceived('warning')->with('geo.boundary.invalid', \Mockery::type('array'))->twice();
    }
}
