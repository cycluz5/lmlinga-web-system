<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R01-C — Spot Mapping Plot New Household → canonical household create/store reconciliation.
 */
class SpotMappingNewHouseholdCreationFlowTest extends TestCase
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
    private function validHouseholdPayload(array $overrides = []): array
    {
        return array_merge([
            'household_no' => '121',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'address' => '123 Layuan St., Brgy. La Medalla',
            'accomplished_by' => 'Maria BHW',
        ], $overrides);
    }

    public function test_household_profiling_index_exposes_add_household_information_cta(): void
    {
        $response = $this->get(route('household-profiling.index'));
        $response->assertOk();
        $response->assertSee('Add Household Information', false);
        $response->assertSee(route('household-profiling.create'), false);
        $response->assertSee('data-hh-add-information', false);
        $response->assertSee('plotted later from Pending Households', false);
        $response->assertSee('Export Data', false);
    }

    public function test_household_profiling_index_has_no_register_household_label(): void
    {
        $response = $this->get(route('household-profiling.index'));
        $response->assertOk();
        $response->assertDontSee('Register Household', false);
    }

    public function test_spot_mapping_plot_new_household_opens_in_page_panel(): void
    {
        Household::factory()->create([
            'household_no' => 'HH-640',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => null,
            'longitude' => null,
        ]);

        $response = $this->get(route('spot-mapping.index'));
        $response->assertOk();
        $response->assertSee('Plot New Household', false);
        $response->assertSee('data-spot-map-panel', false);
        $response->assertSee('type="button"', false);
        $response->assertSee('data-spot-map-plot', false);
        $response->assertSee('name="first_name"', false);
        $response->assertSee('name="middle_name"', false);
        $response->assertSee('name="last_name"', false);
        $response->assertDontSee('Select a registered household', false);
    }

    public function test_spot_mapping_household_type_select_uses_nhts_vocabulary(): void
    {
        $response = $this->get(route('spot-mapping.index'));
        $response->assertOk();

        $html = (string) $response->getContent();
        $this->assertStringContainsString('value="NHTS">NHTS</option>', $html);
        $this->assertStringContainsString('value="Non-NHTS">Non-NHTS</option>', $html);
        $this->assertStringNotContainsString('value="HHTS"', $html);
        $this->assertStringNotContainsString('value="Non-HHTS"', $html);
        $this->assertStringNotContainsString('>HHTS</option>', $html);
        $this->assertStringNotContainsString('>Non-HHTS</option>', $html);
    }

    public function test_spot_mapping_with_all_plotted_does_not_show_register_household_guidance(): void
    {
        Household::factory()->create([
            'household_no' => 'HH-641',
            'zone' => 'Zone 1',
            'street' => 'Dalipay St.',
            'date_registered' => '2026-01-15',
            'latitude' => 13.38110000,
            'longitude' => 123.43060000,
        ]);

        $response = $this->get(route('spot-mapping.index'));
        $response->assertOk();
        $response->assertSee('Plot New Household', false);
        $response->assertSee('data-spot-map-plot', false);
        $response->assertDontSee('All registered households are already plotted', false);
        $response->assertDontSee('Register a new household in Household Profiling before plotting', false);
        $response->assertDontSee('Register Household', false);
        $response->assertDontSee(
            route('household-profiling.create', ['from' => 'spot-mapping']),
            false
        );
    }

    public function test_create_from_spot_mapping_persists_pending_household_and_returns_to_plot(): void
    {
        $before = Household::query()->count();

        $response = $this->post(route('household-profiling.store'), $this->validHouseholdPayload([
            'from' => 'spot-mapping',
            'latitude' => null,
            'longitude' => null,
        ]));

        $household = Household::query()->latest('id')->first();
        $this->assertNotNull($household);
        $this->assertSame($before + 1, Household::query()->count());
        $this->assertEquals(0.0, (float) $household->latitude);
        $this->assertEquals(0.0, (float) $household->longitude);

        $response->assertRedirect(route('spot-mapping.index', [
            'plot_household' => $household->household_no,
        ]));

        $index = $this->get(route('household-profiling.index'));
        $index->assertOk();
        $index->assertSee($household->household_no, false);

        $spot = $this->get(route('spot-mapping.index'));
        $spot->assertOk();
        $spot->assertSee('data-stat="pending">1</p>', false);
        $spot->assertSee('data-stat="plotted">0</p>', false);
    }

    public function test_create_from_spot_mapping_with_coordinates_is_plotted(): void
    {
        $response = $this->post(route('household-profiling.store'), $this->validHouseholdPayload([
            'from' => 'spot-mapping',
            'latitude' => '13.38110000',
            'longitude' => '123.43060000',
        ]));

        $household = Household::query()->firstOrFail();
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);

        $response->assertRedirect(route('spot-mapping.index'));

        $spot = $this->get(route('spot-mapping.index'));
        $spot->assertOk();
        $spot->assertSee($household->household_no, false);
        $spot->assertSee('data-stat="plotted">1</p>', false);
        $spot->assertSee('data-stat="pending">0</p>', false);
    }

    public function test_created_household_can_receive_coordinates_via_existing_plot_path(): void
    {
        $this->post(route('household-profiling.store'), $this->validHouseholdPayload([
            'from' => 'spot-mapping',
        ]))->assertRedirect();

        $household = Household::query()->firstOrFail();

        $plot = $this->postJson(route('spot-mapping.plot'), [
            'household_no' => $household->household_no,
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ]);

        $plot->assertOk();
        $household->refresh();
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);

        $spot = $this->get(route('spot-mapping.index'));
        $spot->assertSee('data-stat="plotted">1</p>', false);
        $spot->assertSee('data-stat="pending">0</p>', false);
    }

    public function test_existing_household_can_still_be_plotted(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-640',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->postJson(route('spot-mapping.plot-handoff'), [
            'household_no' => 'HH-640',
            'house_head' => 'Existing Head',
            'household_type' => 'NHTS',
            'zone' => '2',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ])->assertOk();

        $household->refresh();
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);
    }

    public function test_soft_deleted_household_still_rejected_by_plot(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-641',
            'zone' => 'Zone 1',
            'street' => 'Dalipay St.',
            'date_registered' => '2026-01-15',
            'latitude' => null,
            'longitude' => null,
        ]);
        $household->delete();

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-641',
            'lat' => 13.3811,
            'lng' => 123.4306,
        ])->assertStatus(422);

        $this->assertSame(0, Household::query()->where('household_no', 'HH-641')->count());
    }

    public function test_spot_mapping_create_form_prefills_and_links_plot_existing(): void
    {
        $response = $this->get(route('household-profiling.create', [
            'from' => 'spot-mapping',
            'latitude' => '13.3811',
            'longitude' => '123.4306',
            'zone' => '2',
        ]));

        $response->assertOk();
        $response->assertSee('Register Household', false);
        $response->assertSee('Back to Spot Mapping', false);
        $response->assertSee('Plot an existing household on Spot Mapping', false);
        $response->assertSee(route('spot-mapping.index', ['plot' => 1]), false);
        $response->assertSee('name="from"', false);
        $response->assertSee('value="spot-mapping"', false);
        $response->assertSee('value="13.3811"', false);
        $response->assertSee('value="123.4306"', false);
        $response->assertSee('Zone 2', false);
    }

    public function test_default_store_without_spot_mapping_from_redirects_to_eh_handoff(): void
    {
        $response = $this->post(route('household-profiling.store'), $this->validHouseholdPayload());

        $household = Household::query()->firstOrFail();
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/environmental-health/household-water-supply', $location);
        $this->assertStringContainsString('handoff=', $location);
        $this->assertSame(
            (string) $household->household_no,
            session(\App\Support\EnvironmentalHealthReturnContext::SESSION_KEY)
        );
    }

    public function test_no_demo_session_household_becomes_authority(): void
    {
        $this->post(route('household-profiling.store'), $this->validHouseholdPayload([
            'from' => 'spot-mapping',
            'street' => 'Canonical Persist St.',
        ]))->assertRedirect();

        $household = Household::query()->where('street', 'Canonical Persist St.')->firstOrFail();
        $this->assertDatabaseHas('households', [
            'id' => $household->id,
            'household_no' => $household->household_no,
            'street' => 'Canonical Persist St.',
        ]);
        $this->assertFalse(session()->has('demo_households'));
        $this->assertFalse(session()->has('lml.demo_household'));
    }
}
