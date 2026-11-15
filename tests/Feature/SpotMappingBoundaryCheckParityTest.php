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

    /**
     * Batch 5 (panel requirement, SM-03) replaced the Phase 3A "warn only, never reject" policy: every Spot
     * Mapping and household location input must now carry the server-side boundary rule. (This test previously
     * asserted the opposite — that no request referenced the boundary.)
     */
    public function test_every_location_input_enforces_the_boundary_rule_on_the_server(): void
    {
        foreach ([
            'UpdateSpotMappingCoordinatesRequest',
            'StoreSpotMappingHouseholdRequest',
            'IssueSpotMappingHandoffRequest',
            'ValidatesHouseholdShell',
        ] as $request) {
            $source = (string) file_get_contents(app_path("Http/Requests/{$request}.php"));
            $this->assertStringContainsString('new WithinBarangayBoundary(', $source, $request);
        }
    }
}
