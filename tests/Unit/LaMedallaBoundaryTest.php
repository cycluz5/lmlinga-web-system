<?php

namespace Tests\Unit;

use App\Http\Requests\IssueSpotMappingHandoffRequest;
use App\Http\Requests\StoreSpotMappingHouseholdRequest;
use App\Http\Requests\UpdateSpotMappingCoordinatesRequest;
use App\Rules\WithinLaMedallaBoundary;
use App\Support\Geo\LaMedallaBoundary;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class LaMedallaBoundaryTest extends TestCase
{
    protected function tearDown(): void
    {
        LaMedallaBoundary::forgetCache();
        parent::tearDown();
    }

    public function test_map_centre_and_known_inside_points_are_inside(): void
    {
        $this->assertTrue(LaMedallaBoundary::contains(13.3806, 123.4312));
        $this->assertTrue(LaMedallaBoundary::contains(13.3790, 123.4330));
        $this->assertTrue(LaMedallaBoundary::contains(13.3811, 123.4306));
    }

    public function test_points_outside_the_barangay_are_outside(): void
    {
        $this->assertFalse(LaMedallaBoundary::contains(13.3900, 123.4400));
        $this->assertFalse(LaMedallaBoundary::contains(13.3855, 123.4345));
        $this->assertFalse(LaMedallaBoundary::contains(14.5995, 120.9842));
    }

    public function test_points_just_outside_the_edge_are_accepted_within_tolerance(): void
    {
        // Southernmost vertex of the polygon; moving south moves straight away from it.
        $southLat = 13.361063343000067;
        $southLng = 123.43145186800005;

        $this->assertFalse(LaMedallaBoundary::contains($southLat - 0.0009, $southLng));
        $this->assertTrue(LaMedallaBoundary::containsWithTolerance($southLat - 0.0009, $southLng));
        $this->assertFalse(LaMedallaBoundary::containsWithTolerance($southLat - 0.002, $southLng));
        $this->assertFalse(LaMedallaBoundary::containsWithTolerance(13.3900, 123.4400));
        $this->assertFalse(LaMedallaBoundary::containsWithTolerance(14.5995, 120.9842));
        $this->assertTrue(LaMedallaBoundary::containsWithTolerance(13.3806, 123.4312));
    }

    public function test_tolerance_is_null_when_boundary_is_unreadable(): void
    {
        $this->assertNull(LaMedallaBoundary::containsWithTolerance(13.3806, 123.4312, null, base_path('does-not-exist.json')));
    }

    public function test_php_and_js_tolerance_match(): void
    {
        $js = file_get_contents(resource_path('js/maps/la-medalla-boundary.js'));
        preg_match('/BOUNDARY_TOLERANCE_METERS = (\d+)/', $js, $m);

        $this->assertSame(LaMedallaBoundary::TOLERANCE_METERS, (int) $m[1]);
    }

    public function test_missing_or_invalid_boundary_file_returns_null(): void
    {
        $this->assertNull(LaMedallaBoundary::contains(13.3806, 123.4312, base_path('does-not-exist.json')));

        $bad = tempnam(sys_get_temp_dir(), 'bnd');
        file_put_contents($bad, '{"type":"Feature","geometry":{"type":"Point","coordinates":[1,2]}}');
        try {
            $this->assertNull(LaMedallaBoundary::contains(13.3806, 123.4312, $bad));
        } finally {
            @unlink($bad);
        }
    }

    public function test_rule_rejects_outside_and_accepts_inside(): void
    {
        $rules = ['lat' => ['required', 'numeric', new WithinLaMedallaBoundary], 'lng' => ['required', 'numeric']];

        $outside = Validator::make(['lat' => 13.3900, 'lng' => 123.4400], $rules);
        $this->assertTrue($outside->fails());
        $this->assertSame(WithinLaMedallaBoundary::MESSAGE, $outside->errors()->first('lat'));

        $this->assertFalse(Validator::make(['lat' => 13.3806, 'lng' => 123.4312], $rules)->fails());
    }

    public function test_rule_leaves_non_numeric_coordinates_to_other_rules(): void
    {
        $validator = Validator::make(['lat' => 'abc', 'lng' => 123.4312], [
            'lat' => [new WithinLaMedallaBoundary],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_all_spot_mapping_plot_requests_enforce_the_boundary(): void
    {
        foreach ([
            StoreSpotMappingHouseholdRequest::class,
            UpdateSpotMappingCoordinatesRequest::class,
            IssueSpotMappingHandoffRequest::class,
        ] as $requestClass) {
            $latRules = (new $requestClass)->rules()['lat'];
            $hasRule = collect($latRules)->contains(fn ($rule) => $rule instanceof WithinLaMedallaBoundary);
            $this->assertTrue($hasRule, $requestClass.' lat rules must include WithinLaMedallaBoundary');
        }
    }
}
