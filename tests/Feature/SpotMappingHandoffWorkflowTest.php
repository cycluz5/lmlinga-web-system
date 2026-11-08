<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Services\SpotMappingHandoffService;
use App\Services\SpotMappingService;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DB18-D — real Household handoff from Spot Mapping to Environmental Health.
 */
class SpotMappingHandoffWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(StaffRole::BHW);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPlotPayload(string $householdNo = 'HH-501'): array
    {
        return [
            'household_no' => $householdNo,
            'house_head' => 'Maria Santos',
            'household_type' => 'NHTS',
            'zone' => '2',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
            'client_marker_id' => 'client-marker-demo',
        ];
    }

    private function createHousehold(string $householdNo = 'HH-501'): Household
    {
        return Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => null,
            'longitude' => null,
        ]);
    }

    #[DataProvider('shellRolesProvider')]
    public function test_legitimate_plot_handoff_workflow_per_role(string $role): void
    {
        $this->actingAsStaff($role);

        $household = $this->createHousehold('HH-501');

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), $this->validPlotPayload());
        $issue->assertOk();
        $issue->assertJsonStructure([
            'handoff_token',
            'redirect_url',
            'marker',
            'stats',
            'household_no',
            'expires_in_seconds',
        ]);

        $token = (string) $issue->json('handoff_token');
        $this->assertSame(64, strlen($token));

        $household->refresh();
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);

        $step1 = $this->get(route('environmental-health.household-water-supply', [
            'handoff' => $token,
        ]));

        $step1->assertRedirect(route('environmental-health.household-water-supply', [
            'household' => 'HH-501',
        ]));

        $follow = $this->followRedirects($step1);
        $follow->assertOk();
        $follow->assertSee('Household Water Supply Information', false);
        $follow->assertSee('value="HH-501"', false);

        $this->assertTrue(DemoHouseholdWaterSupply::isLinkedForActor('HH-501'));
        $linked = DemoHouseholdWaterSupply::resolveLinkedHousehold('HH-501');
        $this->assertNotNull($linked);
        $this->assertSame($household->id, $linked->id);
        $this->assertSame('13.38110000', (string) $linked->latitude);
    }

    public function test_forged_handoff_token_is_rejected(): void
    {
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $this->createHousehold('HH-502');

        $response = $this->get(route('environmental-health.household-water-supply', [
            'handoff' => str_repeat('a', 64),
        ]));

        $response->assertRedirect(route('spot-mapping.index'));
    }

    public function test_direct_household_query_without_linkage_is_rejected(): void
    {
        $this->createHousehold('HH-999');

        $response = $this->get(route('environmental-health.household-water-supply', [
            'household' => 'HH-999',
        ]));

        $response->assertRedirect(route('spot-mapping.index'));
    }

    public function test_handoff_rejects_unknown_household_without_creating(): void
    {
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $before = Household::query()->count();

        $response = $this->postJson(route('spot-mapping.plot-handoff'), $this->validPlotPayload('HH-888'));

        $response->assertStatus(422);
        $response->assertJsonPath('message', SpotMappingService::GENERIC_PLOT_FAILURE);
        $this->assertSame($before, Household::query()->count());
        $this->assertDatabaseMissing('households', ['household_no' => 'HH-888']);
    }

    public function test_soft_deleted_household_cannot_plot_or_handoff(): void
    {
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $household = $this->createHousehold('HH-503');
        $household->delete();

        $response = $this->postJson(route('spot-mapping.plot-handoff'), $this->validPlotPayload('HH-503'));
        $response->assertStatus(422);
    }

    public function test_stale_linked_session_rejects_soft_deleted_household(): void
    {
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $household = $this->createHousehold('HH-504');

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), $this->validPlotPayload('HH-504'));
        $issue->assertOk();

        $token = (string) $issue->json('handoff_token');
        $this->get(route('environmental-health.household-water-supply', [
            'handoff' => $token,
        ]))->assertRedirect(route('environmental-health.household-water-supply', [
            'household' => 'HH-504',
        ]));

        $household->delete();

        $response = $this->get(route('environmental-health.household-water-supply', [
            'household' => 'HH-504',
        ]));

        $response->assertRedirect(route('spot-mapping.index'));
    }

    public function test_forged_browser_household_id_cannot_retarget_handoff(): void
    {
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $target = $this->createHousehold('HH-510');
        $other = $this->createHousehold('HH-511');

        $response = $this->postJson(route('spot-mapping.plot-handoff'), array_merge(
            $this->validPlotPayload('HH-510'),
            [
                'id' => $other->id,
                'household_id' => $other->id,
            ]
        ));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['id', 'household_id']);

        $target->refresh();
        $other->refresh();
        $this->assertNull($target->latitude);
        $this->assertNull($other->latitude);
    }

    public function test_single_write_updates_coordinates_once(): void
    {
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $household = $this->createHousehold('HH-520');
        $beforeCount = Household::query()->count();

        $this->postJson(route('spot-mapping.plot-handoff'), $this->validPlotPayload('HH-520'))
            ->assertOk();

        $this->assertSame($beforeCount, Household::query()->count());
        $household->refresh();
        $this->assertSame('13.38110000', (string) $household->latitude);
    }

    public function test_replot_overwrites_coordinates_and_handoff_resolves_same_household(): void
    {
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $household = $this->createHousehold('HH-530');

        $this->postJson(route('spot-mapping.plot-handoff'), $this->validPlotPayload('HH-530'))
            ->assertOk();

        $this->postJson(route('spot-mapping.plot-handoff'), array_merge(
            $this->validPlotPayload('HH-530'),
            ['lat' => 13.3820, 'lng' => 123.4310, 'confirm_replot' => true]
        ))->assertOk();

        $household->refresh();
        $this->assertSame('13.38200000', (string) $household->latitude);
        $this->assertSame('123.43100000', (string) $household->longitude);
        $this->assertSame(1, Household::query()->where('household_no', 'HH-530')->count());

        $linked = DemoHouseholdWaterSupply::resolveLinkedHousehold('HH-530');
        $this->assertNull($linked);

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), array_merge(
            $this->validPlotPayload('HH-530'),
            ['confirm_replot' => true]
        ));
        $token = (string) $issue->json('handoff_token');
        $this->get(route('environmental-health.household-water-supply', ['handoff' => $token]))
            ->assertRedirect(route('environmental-health.household-water-supply', ['household' => 'HH-530']));

        $linked = DemoHouseholdWaterSupply::resolveLinkedHousehold('HH-530');
        $this->assertNotNull($linked);
        $this->assertSame($household->id, $linked->id);
    }

    public function test_linked_session_stores_identity_and_transient_type_without_coordinates(): void
    {
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $this->createHousehold('HH-540');

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), $this->validPlotPayload('HH-540'));
        $issue->assertOk();

        $token = (string) $issue->json('handoff_token');
        $this->get(route('environmental-health.household-water-supply', ['handoff' => $token]))
            ->assertRedirect(route('environmental-health.household-water-supply', ['household' => 'HH-540']));

        $linked = DemoHouseholdWaterSupply::findLinkedForActor('HH-540');
        $this->assertIsArray($linked);
        $this->assertArrayHasKey('household_id', $linked);
        $this->assertArrayNotHasKey('lat', $linked);
        $this->assertArrayNotHasKey('lng', $linked);
        // DB19-C: transient canonical type may travel with link context.
        $this->assertSame(DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS, $linked['household_type'] ?? null);

        $resolved = DemoHouseholdWaterSupply::resolveLinkedHousehold('HH-540');
        $this->assertNotNull($resolved);
        $this->assertSame('13.38110000', (string) $resolved->latitude);
        $this->assertSame('123.43060000', (string) $resolved->longitude);

        // Handoff alone must not create an environmental profile.
        $this->assertDatabaseMissing('household_environmental_profiles', [
            'household_id' => $resolved->id,
        ]);
    }

    public function test_legacy_hhts_handoff_canonicalizes_to_nhts_transient_context(): void
    {
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $this->createHousehold('HH-542');
        $payload = $this->validPlotPayload('HH-542');
        $payload['household_type'] = 'HHTS';

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), $payload)->assertOk();
        $token = (string) $issue->json('handoff_token');
        $this->get(route('environmental-health.household-water-supply', ['handoff' => $token]))
            ->assertRedirect();

        $linked = DemoHouseholdWaterSupply::findLinkedForActor('HH-542');
        $this->assertSame(DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS, $linked['household_type'] ?? null);
        $this->assertNotSame('HHTS', $linked['household_type'] ?? null);
    }

    public function test_legacy_non_hhts_handoff_canonicalizes_to_non_nhts_transient_context(): void
    {
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $household = $this->createHousehold('HH-541');
        $payload = $this->validPlotPayload('HH-541');
        $payload['household_type'] = 'Non-HHTS';

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), $payload)->assertOk();
        $token = (string) $issue->json('handoff_token');
        $this->get(route('environmental-health.household-water-supply', ['handoff' => $token]))
            ->assertRedirect();

        $linked = DemoHouseholdWaterSupply::findLinkedForActor('HH-541');
        $this->assertSame(DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NON_NHTS, $linked['household_type'] ?? null);
        $this->assertDatabaseMissing('household_environmental_profiles', [
            'household_id' => $household->id,
        ]);
    }

    public function test_successful_plot_handoff_returns_canonical_environmental_health_redirect(): void
    {
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $this->createHousehold('HH-550');

        $response = $this->postJson(route('spot-mapping.plot-handoff'), $this->validPlotPayload('HH-550'));
        $response->assertOk();
        $response->assertJsonPath('household_no', 'HH-550');

        $token = (string) $response->json('handoff_token');
        $redirectUrl = (string) $response->json('redirect_url');

        $this->assertSame(64, strlen($token));
        $this->assertSame(
            route('environmental-health.household-water-supply', ['handoff' => $token]),
            $redirectUrl
        );

        $step1 = $this->get($redirectUrl);
        $step1->assertRedirect(route('environmental-health.household-water-supply', [
            'household' => 'HH-550',
        ]));

        $this->followRedirects($step1)
            ->assertOk()
            ->assertSee('Household Water Supply Information', false)
            ->assertSee('value="HH-550"', false);
    }

    public function test_plot_handoff_does_not_write_environmental_sanitation_rows(): void
    {
        Schema::dropIfExists('environmental_sanitation');
        Schema::create('environmental_sanitation', function ($table): void {
            $table->id('env_assessment_id');
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('water_supply_status');
            $table->string('water_source_location');
            $table->unsignedTinyInteger('water_availability');
            $table->timestamps();
        });

        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $household = $this->createHousehold('HH-551');
        $before = \Illuminate\Support\Facades\DB::table('environmental_sanitation')->count();

        $this->postJson(route('spot-mapping.plot-handoff'), $this->validPlotPayload('HH-551'))
            ->assertOk();

        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('environmental_sanitation')->count());
        $this->assertDatabaseMissing('environmental_sanitation', [
            'household_id' => $household->getKey(),
        ]);
    }

    public function test_failed_plot_handoff_does_not_return_environmental_health_redirect(): void
    {
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $response = $this->postJson(route('spot-mapping.plot-handoff'), $this->validPlotPayload('HH-404'));

        $response->assertStatus(422);
        $response->assertJsonPath('message', SpotMappingService::GENERIC_PLOT_FAILURE);
        $response->assertJsonMissing(['redirect_url']);
        $response->assertJsonMissing(['handoff_token']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function shellRolesProvider(): array
    {
        return [
            'bns' => ['bns'],
            'bhw' => ['bhw'],
            'bspo' => ['bspo'],
            'admin' => ['admin'],
        ];
    }
}
