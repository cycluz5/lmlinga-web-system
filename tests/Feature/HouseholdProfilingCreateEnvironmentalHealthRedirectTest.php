<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\EnvironmentalHealthReturnContext;
use App\Support\Offline\OfflineOperationType;
use App\Support\StaffRole;
use App\Services\SpotMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

/**
 * Normal online Household Profiling create → EH Step 1 (handoff) → Step 4 → household show.
 */
class HouseholdProfilingCreateEnvironmentalHealthRedirectTest extends TestCase
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
            'latitude' => '',
            'longitude' => '',
        ], $overrides);
    }

    /**
     * @return array{0: \Illuminate\Testing\TestResponse, 1: string}
     */
    private function assertCreateRedirectsToEhHandoff(
        \Illuminate\Testing\TestResponse $response,
        string $householdNo,
    ): array {
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/environmental-health/household-water-supply', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('handoff', $query);
        $token = (string) $query['handoff'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame($householdNo, session(EnvironmentalHealthReturnContext::SESSION_KEY));
        $response->assertSessionHas('status', 'Household registered successfully.');

        return [$response, $token];
    }

    private function completeWizardThroughStep3ViaHandoff(string $token, string $householdNo): void
    {
        $this->get(route('environmental-health.household-water-supply', [
            'handoff' => $token,
        ]))->assertRedirect(route('environmental-health.household-water-supply', [
            'household' => $householdNo,
        ]));

        $this->get(route('environmental-health.household-water-supply', [
            'household' => $householdNo,
        ]))->assertOk();

        $this->post(route('environmental-health.household-water-supply.store'), [
            'household_no' => $householdNo,
            'water_supply_status' => 'level_i',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
        ])->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]));

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
        ])->assertRedirect(route('environmental-health.household-water-supply.step3', [
            'householdNo' => $householdNo,
        ]));

        $this->post(route('environmental-health.household-water-supply.step3.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'on_site_safely_managed',
        ])->assertRedirect(route('environmental-health.household-water-supply.step4', [
            'householdNo' => $householdNo,
        ]));
    }

    public function test_normal_online_create_redirects_to_eh_with_handoff(): void
    {
        $response = $this->post(route('household-profiling.store'), $this->shellPayload([
            'household_no' => '088',
        ]));

        [, $token] = $this->assertCreateRedirectsToEhHandoff($response, '088');

        $this->assertNotSame('', $token);
        $this->assertDatabaseHas('households', ['household_no' => '088']);
        $this->assertSame(0, Resident::query()->count());
    }

    public function test_shell_household_zero_coords_opens_eh_step1_after_handoff(): void
    {
        $response = $this->post(route('household-profiling.store'), $this->shellPayload([
            'household_no' => '077',
        ]));

        [, $token] = $this->assertCreateRedirectsToEhHandoff($response, '077');

        $household = Household::query()->where('household_no', '077')->firstOrFail();
        $this->assertEquals(0.0, (float) $household->latitude);
        $this->assertEquals(0.0, (float) $household->longitude);
        $this->assertSame(0, $household->residents()->count());

        $this->get(route('environmental-health.household-water-supply', [
            'handoff' => $token,
        ]))->assertRedirect(route('environmental-health.household-water-supply', [
            'household' => '077',
        ]));

        $this->get(route('environmental-health.household-water-supply', [
            'household' => '077',
        ]))
            ->assertOk()
            ->assertSee('Household Water Supply', false);
    }

    public function test_eh_step4_with_hp_create_return_context_goes_to_household_view_and_clears_context(): void
    {
        $response = $this->post(route('household-profiling.store'), $this->shellPayload([
            'household_no' => '066',
        ]));
        [, $token] = $this->assertCreateRedirectsToEhHandoff($response, '066');

        $this->completeWizardThroughStep3ViaHandoff($token, '066');
        $this->assertSame('066', session(EnvironmentalHealthReturnContext::SESSION_KEY));

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => '066',
        ]), [
            'household_no' => '066',
            'solid_waste_practices' => ['waste_segregation'],
        ])
            ->assertRedirect(route('household-profiling.view', ['householdNo' => '066']))
            ->assertSessionHas('status', 'Environmental health information saved.');

        $this->assertNull(session(EnvironmentalHealthReturnContext::SESSION_KEY));
    }

    public function test_eh_step4_without_return_context_still_goes_to_spot_mapping(): void
    {
        Household::factory()->create([
            'household_no' => 'HH-801',
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => 0,
            'longitude' => 0,
        ]);

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), [
            'household_no' => 'HH-801',
            'house_head' => 'Juan Dela Cruz',
            'household_type' => 'HHTS',
            'zone' => '1',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
            'client_marker_id' => 'client-marker-no-return',
        ])->assertOk();

        $token = (string) $issue->json('handoff_token');
        $this->assertNull(session(EnvironmentalHealthReturnContext::SESSION_KEY));

        $this->completeWizardThroughStep3ViaHandoff($token, 'HH-801');

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => 'HH-801',
        ]), [
            'household_no' => 'HH-801',
            'solid_waste_practices' => ['waste_segregation'],
        ])
            ->assertRedirect(route('spot-mapping.index'))
            ->assertSessionHas(
                'status',
                'Household plotted successfully with environmental health information saved.'
            );
    }

    public function test_plot_new_style_handoff_without_return_context_ends_on_spot_mapping(): void
    {
        $household = Household::factory()->create([
            'household_no' => '055',
            'zone' => 'Zone 2',
            'street' => 'Plot New St.',
            'date_registered' => '2026-01-15',
            'latitude' => 13.3811,
            'longitude' => 123.4306,
        ]);

        $token = app(\App\Services\SpotMappingHandoffService::class)->issueForHousehold(
            $household,
            'Non-HHTS'
        );

        $this->assertNull(session(EnvironmentalHealthReturnContext::SESSION_KEY));
        $this->assertStringContainsString(
            'handoff='.$token,
            route('environmental-health.household-water-supply', ['handoff' => $token])
        );

        $this->completeWizardThroughStep3ViaHandoff($token, '055');

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => '055',
        ]), [
            'household_no' => '055',
            'solid_waste_practices' => ['municipal_collection'],
        ])->assertRedirect(route('spot-mapping.index'));

        $this->assertNull(session(EnvironmentalHealthReturnContext::SESSION_KEY));
    }

    public function test_from_spot_mapping_unplotted_still_uses_plot_household(): void
    {
        $response = $this->post(route('household-profiling.store'), $this->shellPayload([
            'household_no' => '044',
            'from' => 'spot-mapping',
            'latitude' => '',
            'longitude' => '',
        ]));

        $response->assertRedirect(route('spot-mapping.index', [
            'plot_household' => '044',
        ]));
        $this->assertNull(session(EnvironmentalHealthReturnContext::SESSION_KEY));
    }

    public function test_from_spot_mapping_plotted_still_goes_to_spot_mapping(): void
    {
        $response = $this->post(route('household-profiling.store'), $this->shellPayload([
            'household_no' => '043',
            'from' => 'spot-mapping',
            'latitude' => '13.38110000',
            'longitude' => '123.43060000',
        ]));

        $response->assertRedirect(route('spot-mapping.index'));
        $this->assertNull(session(EnvironmentalHealthReturnContext::SESSION_KEY));
    }

    public function test_mismatched_return_context_keeps_spot_mapping_redirect(): void
    {
        Household::factory()->create([
            'household_no' => 'HH-900',
            'zone' => 'Zone 1',
            'street' => 'Mismatch St.',
            'latitude' => 0,
            'longitude' => 0,
        ]);

        session([EnvironmentalHealthReturnContext::SESSION_KEY => '088']);

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), [
            'household_no' => 'HH-900',
            'house_head' => 'Other',
            'household_type' => 'HHTS',
            'zone' => '1',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
            'client_marker_id' => 'client-mismatch',
        ])->assertOk();

        $this->completeWizardThroughStep3ViaHandoff(
            (string) $issue->json('handoff_token'),
            'HH-900'
        );

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => 'HH-900',
        ]), [
            'household_no' => 'HH-900',
            'solid_waste_practices' => ['recycling_reuse'],
        ])->assertRedirect(route('spot-mapping.index'));

        $this->assertSame('088', session(EnvironmentalHealthReturnContext::SESSION_KEY));
    }

    public function test_failed_validation_does_not_set_return_context(): void
    {
        Household::factory()->create(['household_no' => '001']);

        $this->from(route('household-profiling.create'))
            ->post(route('household-profiling.store'), $this->shellPayload([
                'household_no' => '001',
            ]))
            ->assertRedirect(route('household-profiling.create'))
            ->assertSessionHasErrors('household_no');

        $this->assertNull(session(EnvironmentalHealthReturnContext::SESSION_KEY));
    }

    public function test_offline_household_create_unchanged_no_return_context(): void
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
        $this->assertNull(session(EnvironmentalHealthReturnContext::SESSION_KEY));
        $this->assertSame(['091'], array_column(
            app(SpotMappingService::class)->pendingPlotCandidates(),
            'householdNo'
        ));
    }

    public function test_pending_plot_after_normal_create_still_works(): void
    {
        $response = $this->post(route('household-profiling.store'), $this->shellPayload([
            'household_no' => '089',
        ]));
        $this->assertCreateRedirectsToEhHandoff($response, '089');

        $this->assertSame(['089'], array_column(
            app(SpotMappingService::class)->pendingPlotCandidates(),
            'householdNo'
        ));

        $before = Household::query()->count();

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => '089',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ])->assertOk()
            ->assertJsonPath('household_no', '089');

        $this->assertSame($before, Household::query()->count());
        $this->assertSame([], app(SpotMappingService::class)->pendingPlotCandidates());
    }
}
