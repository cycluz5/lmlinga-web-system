<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\Resident;
use App\Services\SpotMappingService;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpotMappingPendingHouseholdUxTest extends TestCase
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
            'household_no' => 'HH-801',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => null,
            'longitude' => null,
        ], $overrides);
    }

    public function test_unplotted_household_appears_as_pending_candidate(): void
    {
        Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-801',
        ]));

        $candidates = app(SpotMappingService::class)->pendingPlotCandidates();

        $this->assertCount(1, $candidates);
        $this->assertSame('HH-801', $candidates[0]['householdNo']);
    }

    public function test_plotted_household_is_not_pending_candidate(): void
    {
        Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-802',
            'latitude' => 13.38110000,
            'longitude' => 123.43060000,
        ]));

        $candidates = app(SpotMappingService::class)->pendingPlotCandidates();

        $this->assertSame([], $candidates);
    }

    public function test_spot_mapping_index_exposes_pending_candidate_in_picker(): void
    {
        $household = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-803',
            'latitude' => 0,
            'longitude' => 0,
        ]));
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Head',
            'first_name' => 'Sarah',
            'middle_name' => 'Cruz',
            'last_name' => 'Limre',
        ]);

        $response = $this->get(route('spot-mapping.index'));

        $response->assertOk();
        $response->assertSee('data-pending-candidates', false);
        $response->assertSee('data-spot-map-pending', false);
        $response->assertSee('data-spot-map-pending-list', false);
        $response->assertSee('data-spot-map-plot-existing', false);
        $response->assertSee('Pending Households', false);
        $response->assertSee('Plot Selected Household', false);
        $response->assertSee('HH-803', false);
        $response->assertSee('Sarah Cruz Limre', false);
        $response->assertSee('data-plot-url', false);
        $response->assertSee('spot-mapping/plot', false);
        $response->assertDontSee('Select a registered household', false);
    }

    public function test_zero_zero_pending_household_is_exposed_for_existing_plot(): void
    {
        Household::factory()->create($this->householdAttrs([
            'household_no' => '001',
            'latitude' => 0,
            'longitude' => 0,
            'zone' => 'Zone 1',
        ]));

        $response = $this->get(route('spot-mapping.index'));

        $response->assertOk();
        $response->assertSee('data-stat="pending">1</p>', false);
        $response->assertSee('"householdNo":"001"', false);
        $response->assertSee('data-spot-map-plot-existing', false);
    }

    public function test_plot_existing_pending_zero_zero_updates_same_row_without_create(): void
    {
        $household = Household::factory()->create($this->householdAttrs([
            'household_no' => '001',
            'latitude' => 0,
            'longitude' => 0,
        ]));
        $before = Household::query()->count();

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => '001',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ])->assertOk()
            ->assertJsonPath('household_no', '001')
            ->assertJsonPath('stats.pending', 0)
            ->assertJsonPath('stats.plotted', 1);

        $this->assertSame($before, Household::query()->count());
        $household->refresh();
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);

        $candidates = app(SpotMappingService::class)->pendingPlotCandidates();
        $this->assertSame([], $candidates);
    }

    public function test_zero_unplotted_households_does_not_show_register_household_empty_state(): void
    {
        Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-804',
            'latitude' => 13.38110000,
            'longitude' => 123.43060000,
        ]));

        $response = $this->get(route('spot-mapping.index'));

        $response->assertOk();
        $response->assertSee('Plot New Household', false);
        $response->assertSee('Household Head', false);
        $headPos = strpos((string) $response->getContent(), 'Household Head');
        $firstPos = strpos((string) $response->getContent(), 'First Name');
        $this->assertNotFalse($headPos);
        $this->assertNotFalse($firstPos);
        $this->assertLessThan($firstPos, $headPos);
        $response->assertDontSee('All registered households are already plotted', false);
        $response->assertDontSee('Register a new household in Household Profiling before plotting', false);
        $response->assertDontSee('Register Household', false);
        $response->assertDontSee(route('household-profiling.create', ['from' => 'spot-mapping']), false);
    }

    public function test_unknown_household_still_returns_controlled_plot_failure(): void
    {
        $before = Household::query()->count();

        $response = $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-999',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', SpotMappingService::GENERIC_PLOT_FAILURE);
        $this->assertSame($before, Household::query()->count());
    }

    public function test_plot_updates_coordinates_without_inserting_household(): void
    {
        $household = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-805',
        ]));
        $before = Household::query()->count();

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-805',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ])->assertOk();

        $this->assertSame($before, Household::query()->count());
        $household->refresh();
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);
    }

    public function test_successful_plot_handoff_still_redirects_to_environmental_health(): void
    {
        Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-806',
        ]));

        $response = $this->postJson(route('spot-mapping.plot-handoff'), [
            'household_no' => 'HH-806',
            'house_head' => 'Sarah Cruz Limre',
            'household_type' => 'NHTS',
            'zone' => '2',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['handoff_token', 'redirect_url']);
        $this->assertStringContainsString(
            'environmental-health/household-water-supply',
            (string) $response->json('redirect_url')
        );
    }

    public function test_existing_plotted_markers_remain_on_index(): void
    {
        Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-807',
            'latitude' => 13.38110000,
            'longitude' => 123.43060000,
        ]));

        $response = $this->get(route('spot-mapping.index'));

        $response->assertOk();
        $response->assertSee('HH-807', false);
        $response->assertSee('data-stat="plotted">1</p>', false);
        $response->assertSee('data-stat="pending">0</p>', false);
    }
}
