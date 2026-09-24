<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Services\SpotMappingService;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpotMappingOverlapPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_spot_mapping(): void
    {
        $this->get(route('spot-mapping.index'))->assertRedirect(route('login'));
    }

    public function test_spot_mapping_page_renders_overlap_selector_markup(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        Household::factory()->create([
            'household_no' => 'HH-901',
            'zone' => 'Zone 1',
            'latitude' => 13.38110000,
            'longitude' => 123.43060000,
        ]);

        $html = $this->get(route('spot-mapping.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-spot-map-group-selector', $html);
        $this->assertStringContainsString('data-spot-map-group-list', $html);
        $this->assertStringContainsString('data-spot-map-details-body', $html);
        $this->assertStringContainsString('Multiple households at this location', $html);
        $this->assertStringContainsString('data-spot-map-view-hh', $html);
        $this->assertStringContainsString('"householdNo":"HH-901"', $html);
        $this->assertStringContainsString('"lat":', $html);
        $this->assertStringContainsString('"lng":', $html);
    }

    public function test_mapped_marker_payload_still_includes_identity_and_coordinates(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        Household::factory()->create([
            'household_no' => 'HH-902',
            'zone' => 'Zone 2',
            'latitude' => 13.38220000,
            'longitude' => 123.43170000,
        ]);

        $marker = collect(app(SpotMappingService::class)->mappedMarkers())
            ->firstWhere('householdNo', 'HH-902');

        $this->assertNotNull($marker);
        $this->assertSame('HH-902', $marker['householdNo']);
        $this->assertArrayHasKey('lat', $marker);
        $this->assertArrayHasKey('lng', $marker);
        $this->assertEqualsWithDelta(13.3822, (float) $marker['lat'], 0.0000001);
        $this->assertEqualsWithDelta(123.4317, (float) $marker['lng'], 0.0000001);
        $this->assertArrayHasKey('houseHead', $marker);
        $this->assertArrayHasKey('zone', $marker);
    }
}
