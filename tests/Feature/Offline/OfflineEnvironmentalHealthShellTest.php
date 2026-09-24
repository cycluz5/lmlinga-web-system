<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Support\Offline\OfflineEnvironmentalHealthShell;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineEnvironmentalHealthShellTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: Household}
     */
    private function seedLinkedHousehold(string $householdNo = 'HH-601'): array
    {
        $this->actingAsStaff(StaffRole::BHW);
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => null,
            'longitude' => null,
        ]);

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), [
            'household_no' => $householdNo,
            'house_head' => 'Ana Reyes',
            'household_type' => 'HHTS',
            'zone' => '2',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
            'client_marker_id' => 'client-marker-eh-shell',
        ]);
        $issue->assertOk();

        $this->get(route('environmental-health.household-water-supply', [
            'handoff' => (string) $issue->json('handoff_token'),
        ]))->assertRedirect(route('environmental-health.household-water-supply', [
            'household' => $householdNo,
        ]));

        return [$householdNo, $household->fresh()];
    }

    public function test_guest_cannot_read_environmental_health_shells(): void
    {
        $this->get(route('offline.environmental-health-shell', ['step' => 1]))
            ->assertUnauthorized();
    }

    public function test_unknown_step_is_not_found(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $this->get('/offline/environmental-health-shell/5')->assertNotFound();
    }

    public function test_canonical_step_1_shell_renders_production_ui_without_mysql_household(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $before = Household::query()->count();

        $html = $this->get(route('offline.environmental-health-shell', ['step' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('lml-dashboard', $html);
        $this->assertStringContainsString('Household Water Supply Information', $html);
        $this->assertStringContainsString('Environmental Sanitation', $html);
        $this->assertStringContainsString('lml-hws__level-grid', $html);
        $this->assertStringContainsString('lml-hws__level-card', $html);
        $this->assertStringContainsString('Water Supply Status', $html);
        $this->assertStringContainsString('Specify Water Source', $html);
        $this->assertStringContainsString('name="water_supply_status"', $html);
        $this->assertStringContainsString('name="specify_water_source"', $html);
        $this->assertStringContainsString('name="water_source_location"', $html);
        $this->assertStringContainsString('name="water_availability"', $html);
        $this->assertStringContainsString('class="lml-hws__next', $html);
        $this->assertStringContainsString(OfflineEnvironmentalHealthShell::PLACEHOLDER_HOUSEHOLD_NO, $html);
        $this->assertStringNotContainsString('No household was linked from Spot Mapping', $html);
        $this->assertStringNotContainsString('data-hws-household-label', $html);
        $this->assertSame($before, Household::query()->count());
        $this->assertSame(0, Household::query()->where(
            'household_no',
            OfflineEnvironmentalHealthShell::PLACEHOLDER_HOUSEHOLD_NO
        )->count());
    }

    public function test_canonical_steps_2_to_4_render_production_forms_without_wizard_gates(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $step2 = $this->get(route('offline.environmental-health-shell', ['step' => 2]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Validation / Random Sampling / Testing', $step2);
        $this->assertStringContainsString('lml-dashboard', $step2);
        $this->assertStringContainsString('lml-hws__test-grid', $step2);
        $this->assertStringContainsString('data-hws-step2-form', $step2);
        $this->assertStringContainsString('name="microbiological_test_date"', $step2);
        $this->assertStringContainsString('name="microbiological_result"', $step2);
        $this->assertStringContainsString('name="physicochemical_test_date"', $step2);
        $this->assertStringContainsString('name="physicochemical_result"', $step2);
        $this->assertStringContainsString('Physico - Chemical Test', $step2);

        $step3 = $this->get(route('offline.environmental-health-shell', ['step' => 3]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Basic Sanitation Facility', $step3);
        $this->assertStringContainsString('data-hws-step3-form', $step3);
        $this->assertStringContainsString('name="toilet_type"', $step3);
        $this->assertStringContainsString('pour_flush_with_septic_tank', $step3);
        $this->assertStringContainsString('name="open_defecation_practiced"', $step3);
        $this->assertStringContainsString('name="shared_toilet"', $step3);
        $this->assertStringContainsString('name="sewage_disposal_method"', $step3);

        $step4 = $this->get(route('offline.environmental-health-shell', ['step' => 4]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Solid Waste Management', $step4);
        $this->assertStringContainsString('data-hws-step4-form', $step4);
        $this->assertStringContainsString('Waste Management Practices', $step4);
        $this->assertStringContainsString('name="solid_waste_practices[]"', $step4);
        $this->assertStringContainsString('value="waste_segregation"', $step4);
        $this->assertStringContainsString('value="backyard_composting"', $step4);
        $this->assertStringContainsString('value="recycling_reuse"', $step4);
        $this->assertStringContainsString('value="municipal_collection"', $step4);
    }

    public function test_step_1_shell_preserves_the_same_online_form_contract(): void
    {
        [$householdNo] = $this->seedLinkedHousehold('HH-641');

        $online = $this->get(route('environmental-health.household-water-supply', [
            'household' => $householdNo,
        ]))->assertOk()->getContent();

        $shell = $this->get(route('offline.environmental-health-shell', ['step' => 1]))
            ->assertOk()
            ->getContent();

        foreach ([
            'lml-dashboard',
            'lml-hws__level-grid',
            'lml-hws__level-card',
            'lml-hws__program-title',
            'Water Supply Status',
            'Specify Water Source',
            'name="water_supply_status"',
            'value="level_i"',
            'value="level_ii"',
            'value="level_iii"',
            'value="others"',
            'name="specify_water_source"',
            'name="water_source_location"',
            'name="water_availability"',
            'class="lml-hws__next',
            'data-hws-next',
            'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
        ] as $needle) {
            $this->assertStringContainsString($needle, $online, "online missing {$needle}");
            $this->assertStringContainsString($needle, $shell, "shell missing {$needle}");
        }

        $this->assertStringContainsString($householdNo, $online);
        $this->assertStringNotContainsString('No household was linked from Spot Mapping', $online);
    }

    public function test_dashboard_exposes_actor_scoped_shell_base_url(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $html = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $this->assertStringContainsString(
            'data-offline-eh-shell-base="'.e(url('/offline/environmental-health-shell')).'"',
            $html
        );
        $this->assertStringContainsString('data-offline-actor-id', $html);
    }
}
