<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Services\SpotMappingService;
use App\Support\Offline\OfflineOperationType;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

/**
 * Information-first Household Profiling create (no map/GPS required).
 * Coordinates stay optional; Spot Mapping pending + plot reuse existing routes.
 */
class HouseholdProfilingInformationFirstCreateTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(StaffRole::BHW);
    }

    /**
     * @return array<string, mixed>
     */
    private function shellPayload(array $overrides = []): array
    {
        return array_merge([
            'household_no' => '088',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'address' => '88 Layuan St., Brgy. La Medalla',
            'accomplished_by' => 'Maria BHW',
        ], $overrides);
    }

    public function test_index_cta_links_to_existing_create_route(): void
    {
        $this->get(route('household-profiling.index'))
            ->assertOk()
            ->assertSee('Add Household Information', false)
            ->assertSee('data-hh-add-information', false)
            ->assertSee(route('household-profiling.create'), false);

        $create = $this->get(route('household-profiling.create'))
            ->assertOk()
            ->assertSee('Register Household', false)
            ->assertSee('data-offline-operation="HOUSEHOLD_CREATE"', false)
            ->assertSee('name="latitude"', false)
            ->assertSee('name="longitude"', false)
            ->assertSee('Optional', false)
            ->assertSee(route('household-profiling.store'), false);

        $create->assertDontSee('Enter the three-digit Household No. assigned to this house.', false);
        $create->assertDontSee('Latitude and longitude are optional.', false);
        $create->assertDontSee('Fields marked with', false);
        $create->assertDontSee('Please complete the required fields before registering this household.', false);
    }

    public function test_create_validation_summary_uses_neutral_heading(): void
    {
        Household::factory()->create(['household_no' => '001']);

        $response = $this->from(route('household-profiling.create'))
            ->followingRedirects()
            ->post(route('household-profiling.store'), $this->shellPayload([
                'household_no' => '001',
            ]));

        $response->assertOk()
            ->assertSee('Please review the information below.', false)
            ->assertSee('This household number is already registered.', false)
            ->assertDontSee('Please complete the required fields before registering this household.', false);
    }

    public function test_blank_coordinates_persist_as_unplotted_sentinel_and_appear_as_pending(): void
    {
        $response = $this->post(route('household-profiling.store'), $this->shellPayload([
            'household_no' => '088',
            'latitude' => '',
            'longitude' => '',
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString(
            '/environmental-health/household-water-supply',
            (string) $response->headers->get('Location')
        );
        $this->assertStringContainsString('handoff=', (string) $response->headers->get('Location'));

        $household = Household::query()->where('household_no', '088')->firstOrFail();
        $this->assertEquals(0.0, (float) $household->latitude);
        $this->assertEquals(0.0, (float) $household->longitude);

        $candidates = app(SpotMappingService::class)->pendingPlotCandidates();
        $this->assertSame(['088'], array_column($candidates, 'householdNo'));
        $this->assertSame(1, app(SpotMappingService::class)->stats()['pending']);
    }

    public function test_unused_household_005_with_blank_coords_does_not_false_duplicate(): void
    {
        $response = $this->post(route('household-profiling.store'), $this->shellPayload([
            'household_no' => '005',
            'zone' => 'Zone 1',
            'latitude' => '',
            'longitude' => '',
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString(
            '/environmental-health/household-water-supply',
            (string) $response->headers->get('Location')
        );
        $response->assertSessionDoesntHaveErrors();

        $household = Household::query()->where('household_no', '005')->firstOrFail();
        $this->assertEquals(0.0, (float) $household->latitude);
        $this->assertEquals(0.0, (float) $household->longitude);
        $this->assertContains('005', array_column(
            app(SpotMappingService::class)->pendingPlotCandidates(),
            'householdNo'
        ));
    }

    public function test_later_pending_plot_updates_same_row_without_creating_duplicate(): void
    {
        $this->post(route('household-profiling.store'), $this->shellPayload([
            'household_no' => '089',
            'latitude' => '',
            'longitude' => '',
        ]))->assertRedirect();

        $before = Household::query()->count();

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => '089',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ])->assertOk()
            ->assertJsonPath('household_no', '089')
            ->assertJsonPath('stats.pending', 0);

        $this->assertSame($before, Household::query()->count());

        $household = Household::query()->where('household_no', '089')->firstOrFail();
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);
        $this->assertSame([], app(SpotMappingService::class)->pendingPlotCandidates());
    }

    public function test_duplicate_household_no_remains_rejected(): void
    {
        Household::factory()->create(['household_no' => '090']);

        $this->from(route('household-profiling.create'))
            ->post(route('household-profiling.store'), $this->shellPayload([
                'household_no' => '090',
            ]))
            ->assertRedirect(route('household-profiling.create'))
            ->assertSessionHasErrors('household_no');

        $this->assertSame(1, Household::query()->where('household_no', '090')->count());
    }

    public function test_offline_household_create_with_blank_coordinates_becomes_pending(): void
    {
        $this->actingAsFieldStaff();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload([
                'household_no' => '091',
                'latitude' => null,
                'longitude' => null,
            ]),
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
        $response->assertJsonPath('household.household_no', '091');

        $household = Household::query()->where('household_no', '091')->firstOrFail();
        $this->assertEquals(0.0, (float) $household->latitude);
        $this->assertEquals(0.0, (float) $household->longitude);
        $this->assertSame(['091'], array_column(
            app(SpotMappingService::class)->pendingPlotCandidates(),
            'householdNo'
        ));
    }

    public function test_spot_mapping_plot_new_path_unchanged_and_create_not_embedded(): void
    {
        $this->get(route('spot-mapping.index'))
            ->assertOk()
            ->assertSee('Plot New Household', false)
            ->assertSee('data-plot-new-url', false)
            ->assertSee('data-spot-map-plot-existing', false)
            ->assertDontSee('Add Household Information', false)
            ->assertDontSee(route('household-profiling.create'), false);
    }
}
