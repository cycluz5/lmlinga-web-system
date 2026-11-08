<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spot Mapping — Save Map export UI (client-side export; no backend route).
 */
class SpotMappingExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(StaffRole::BHW);
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);
    }

    public function test_save_map_control_renders_on_index(): void
    {
        Household::factory()->create([
            'household_no' => 'HH-801',
            'zone' => 'Zone 1',
            'latitude' => 13.38100000,
            'longitude' => 123.43100000,
        ]);

        $html = $this->get(route('spot-mapping.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Save Map', $html);
        $this->assertStringContainsString('data-spot-map-export-toggle', $html);
        $this->assertStringContainsString('data-spot-map-export-menu-panel', $html);
    }

    public function test_overall_export_actions_exist(): void
    {
        $html = $this->get(route('spot-mapping.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-spot-map-export[^>]*data-scope="overall"[^>]*data-format="png"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-spot-map-export[^>]*data-scope="overall"[^>]*data-format="pdf"/',
            $html
        );
    }

    public function test_compact_per_zone_export_controls_exist(): void
    {
        $html = $this->get(route('spot-mapping.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-spot-map-export-zone-select', $html);
        $this->assertStringContainsString('data-spot-map-export-zone-image', $html);
        $this->assertStringContainsString('data-spot-map-export-zone-pdf', $html);
        $this->assertStringContainsString('id="lml-spot-map-export-zone-select"', $html);

        foreach ([1, 2, 3, 4, 5] as $zone) {
            $this->assertStringContainsString('>Zone '.$zone.'</option>', $html);
        }

        $this->assertSame(
            0,
            preg_match_all('/data-spot-map-export[^>]*data-scope="zone"/', $html),
            'Per-zone export should use the compact zone selector, not repeated zone actions.'
        );
    }

    public function test_no_backend_spot_mapping_export_route_is_registered(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('spot-mapping.export'),
            'Spot Mapping export should remain client-side in Phase B1.'
        );
    }
}
