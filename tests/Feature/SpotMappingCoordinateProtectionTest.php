<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Services\SpotMappingService;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Server-side protection: already-plotted households require confirm_replot.
 */
class SpotMappingCoordinateProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);
        $this->actingAsStaff(StaffRole::BHW);
    }

    /**
     * @return array<string, mixed>
     */
    private function householdAttrs(array $overrides = []): array
    {
        return array_merge([
            'household_no' => 'HH-901',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => null,
            'longitude' => null,
        ], $overrides);
    }

    public function test_unplotted_null_coords_plot_succeeds_without_confirm_replot(): void
    {
        $household = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-901',
        ]));

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-901',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ])
            ->assertOk()
            ->assertJsonPath('household_no', 'HH-901');

        $household->refresh();
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);
    }

    public function test_zero_zero_unplotted_semantics_plot_succeeds_without_confirm_replot(): void
    {
        $household = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-902',
            'latitude' => 0,
            'longitude' => 0,
        ]));

        $this->assertFalse(
            app(SpotMappingService::class)->householdHasPlottedCoordinates($household)
        );

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-902',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ])->assertOk();

        $household->refresh();
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);
    }

    public function test_already_plotted_without_confirm_replot_is_rejected(): void
    {
        $household = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-903',
            'latitude' => 13.38000000,
            'longitude' => 123.43000000,
        ]));

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-903',
            'lat' => 13.3815,
            'lng' => 123.4315,
            'consent' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['household_no']);

        $household->refresh();
        $this->assertSame('13.38000000', (string) $household->latitude);
        $this->assertSame('123.43000000', (string) $household->longitude);
    }

    public function test_already_plotted_with_confirm_replot_false_is_rejected(): void
    {
        $household = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-904',
            'latitude' => 13.38000000,
            'longitude' => 123.43000000,
        ]));

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-904',
            'lat' => 13.3815,
            'lng' => 123.4315,
            'consent' => true,
            'confirm_replot' => false,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['household_no']);

        $household->refresh();
        $this->assertSame('13.38000000', (string) $household->latitude);
        $this->assertSame('123.43000000', (string) $household->longitude);
    }

    public function test_already_plotted_with_confirm_replot_true_succeeds(): void
    {
        $household = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-905',
            'latitude' => 13.38000000,
            'longitude' => 123.43000000,
        ]));

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-905',
            'lat' => 13.3815,
            'lng' => 123.4315,
            'consent' => true,
            'confirm_replot' => true,
        ])->assertOk();

        $household->refresh();
        $this->assertSame('13.38150000', (string) $household->latitude);
        $this->assertSame('123.43150000', (string) $household->longitude);
    }

    public function test_plot_handoff_first_plot_still_works_without_confirm_replot(): void
    {
        $household = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-906',
        ]));

        $this->postJson(route('spot-mapping.plot-handoff'), [
            'household_no' => 'HH-906',
            'house_head' => 'Maria Santos',
            'household_type' => 'NHTS',
            'zone' => '2',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ])
            ->assertOk()
            ->assertJsonStructure(['handoff_token', 'redirect_url']);

        $household->refresh();
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);
    }

    public function test_plot_handoff_rejects_replot_without_confirm(): void
    {
        $household = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-907',
            'latitude' => 13.38000000,
            'longitude' => 123.43000000,
        ]));

        $this->postJson(route('spot-mapping.plot-handoff'), [
            'household_no' => 'HH-907',
            'house_head' => 'Maria Santos',
            'household_type' => 'NHTS',
            'zone' => '2',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['household_no']);

        $household->refresh();
        $this->assertSame('13.38000000', (string) $household->latitude);
        $this->assertSame('123.43000000', (string) $household->longitude);
    }

    public function test_plot_new_path_does_not_call_update_coordinates(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(\App\Http\Controllers\SpotMappingController::class))->getFileName());
        $start = strpos($source, 'function plotNewHousehold');
        $end = strpos($source, 'function plotAndHandoff');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $plotNewBlock = substr($source, $start, $end - $start);

        $this->assertStringContainsString('createWithHead', $plotNewBlock);
        $this->assertStringNotContainsString('updateCoordinates', $plotNewBlock);
    }
}