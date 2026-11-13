<?php

namespace Tests\Feature;

use App\Support\Geo\BarangayBoundary;
use Tests\TestCase;

/**
 * Phase 3A: the page's boundary check (resources/js/pages/spot-mapping-location-check.js) is tested against
 * tests/fixtures/spot-mapping/boundary-check-points.json. This test keeps that fixture honest: every expected
 * answer must still equal BarangayBoundary::contains for the shipped indicative boundary. The points are geometry
 * test inputs derived from the outline, not household locations, and nothing is written to the database.
 */
class SpotMappingBoundaryCheckParityTest extends TestCase
{
    public function test_fixture_answers_match_the_server_boundary_check(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/fixtures/spot-mapping/boundary-check-points.json')), true);
        $boundary = BarangayBoundary::fromJson((string) file_get_contents(base_path($fixture['source'])));

        $this->assertTrue($boundary->isValid());
        $this->assertGreaterThanOrEqual(300, count($fixture['points']));

        $mismatches = [];
        foreach ($fixture['points'] as $point) {
            if ($boundary->contains((float) $point['lat'], (float) $point['lng']) !== $point['inside']) {
                $mismatches[] = $point['label'];
            }
        }

        $this->assertSame([], $mismatches);
    }

    public function test_server_still_accepts_any_valid_coordinate_and_has_no_boundary_rule(): void
    {
        foreach (['UpdateSpotMappingCoordinatesRequest', 'StoreSpotMappingHouseholdRequest', 'IssueSpotMappingHandoffRequest'] as $request) {
            $source = (string) file_get_contents(app_path("Http/Requests/{$request}.php"));
            $this->assertStringNotContainsString('BarangayBoundary', $source, $request);
        }
    }
}
