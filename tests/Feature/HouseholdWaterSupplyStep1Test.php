<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\HouseholdEnvironmentalProfile;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class HouseholdWaterSupplyStep1Test extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: Household}
     */
    private function seedLinkedHousehold(string $householdNo = 'HH-601', string $uiType = 'HHTS'): array
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

        $payload = [
            'household_no' => $householdNo,
            'house_head' => 'Ana Reyes',
            'household_type' => $uiType,
            'zone' => '2',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
            'client_marker_id' => 'client-marker-step1',
        ];

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), $payload);
        $issue->assertOk();

        $token = (string) $issue->json('handoff_token');

        $this->get(route('environmental-health.household-water-supply', [
            'handoff' => $token,
        ]))->assertRedirect(route('environmental-health.household-water-supply', [
            'household' => $householdNo,
        ]));

        return [$householdNo, $household->fresh()];
    }

    /**
     * @return array<string, mixed>
     */
    private function validStep1Payload(string $householdNo, array $overrides = []): array
    {
        return array_merge([
            'household_no' => $householdNo,
            'water_supply_status' => 'level_i',
            'specify_water_source' => null,
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
        ], $overrides);
    }

    public function test_level_i_derives_with_basic_safe_water(): void
    {
        [$householdNo] = $this->seedLinkedHousehold('HH-611');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo, [
            'water_supply_status' => 'level_i',
            'specify_water_source' => 'stale should clear',
        ]))->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]));

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertSame('level_i', $record['water_supply_status'] ?? null);
        $this->assertSame(DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITH, $record['basic_safe_water_status'] ?? null);
        $this->assertArrayHasKey('specify_water_source', $record);
        $this->assertNull($record['specify_water_source']);
    }

    public function test_level_ii_derives_with_basic_safe_water(): void
    {
        [$householdNo] = $this->seedLinkedHousehold('HH-612');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo, [
            'water_supply_status' => 'level_ii',
        ]))->assertRedirect();

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertSame(DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITH, $record['basic_safe_water_status'] ?? null);
    }

    public function test_level_iii_derives_with_basic_safe_water(): void
    {
        [$householdNo] = $this->seedLinkedHousehold('HH-613');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo, [
            'water_supply_status' => 'level_iii',
        ]))->assertRedirect();

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertSame(DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITH, $record['basic_safe_water_status'] ?? null);
    }

    public function test_others_derives_without_basic_safe_water_and_requires_specify(): void
    {
        [$householdNo] = $this->seedLinkedHousehold('HH-614');

        $this->from(route('environmental-health.household-water-supply', [
            'household' => $householdNo,
        ]))->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo, [
            'water_supply_status' => 'others',
            'specify_water_source' => null,
        ]))->assertRedirect(route('environmental-health.household-water-supply', [
            'household' => $householdNo,
        ]))->assertSessionHasErrors('specify_water_source');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo, [
            'water_supply_status' => 'others',
            'specify_water_source' => '  Open Dug Well  ',
        ]))->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]));

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertSame('others', $record['water_supply_status'] ?? null);
        $this->assertSame('Open Dug Well', $record['specify_water_source'] ?? null);
        $this->assertSame(DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITHOUT, $record['basic_safe_water_status'] ?? null);
    }

    public function test_browser_supplied_status_cannot_override_server_derivation(): void
    {
        [$householdNo] = $this->seedLinkedHousehold('HH-615');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo, [
            'water_supply_status' => 'level_ii',
            'basic_safe_water_status' => 'without_basic_safe_water',
            'water_status' => 'without_basic_safe_water',
            'safe_water_status' => 'without_basic_safe_water',
        ]))->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]));

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertSame(DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITH, $record['basic_safe_water_status'] ?? null);
    }

    public function test_invalid_water_level_machine_value_is_rejected(): void
    {
        [$householdNo] = $this->seedLinkedHousehold('HH-616');

        $this->from(route('environmental-health.household-water-supply', [
            'household' => $householdNo,
        ]))->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo, [
            'water_supply_status' => 'level_1',
        ]))->assertSessionHasErrors('water_supply_status');
    }

    public function test_saved_value_reloads_and_step2_navigation_remains(): void
    {
        [$householdNo] = $this->seedLinkedHousehold('HH-617');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo, [
            'water_supply_status' => 'others',
            'specify_water_source' => 'Deep well',
        ]))->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]));

        $this->assertTrue(DemoHouseholdWaterSupply::hasCompletedStep1($householdNo));

        $this->get(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->assertOk();
    }

    public function test_unrecognized_household_cannot_store_step1(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload('HH-UNKNOWN'))
            ->assertSessionHasErrors('household_no');
    }

    public function test_derive_helpers_match_display_rules(): void
    {
        $this->assertSame(
            DemoHouseholdWaterSupply::BASIC_SAFE_WATER_PENDING,
            DemoHouseholdWaterSupply::deriveBasicSafeWaterStatus(null)
        );
        $this->assertSame(
            DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITH,
            DemoHouseholdWaterSupply::deriveBasicSafeWaterStatus('level_i')
        );
        $this->assertSame(
            DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITHOUT,
            DemoHouseholdWaterSupply::deriveBasicSafeWaterStatus('others')
        );
        $this->assertSame(
            'With Basic Safe Water',
            DemoHouseholdWaterSupply::basicSafeWaterStatusLabel(DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITH)
        );
        $this->assertSame(
            'Without Basic Safe Water',
            DemoHouseholdWaterSupply::basicSafeWaterStatusLabel(DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITHOUT)
        );
    }

    public function test_handoff_and_step1_get_do_not_create_profile(): void
    {
        [$householdNo, $household] = $this->seedLinkedHousehold('HH-620');

        $this->assertDatabaseMissing('household_environmental_profiles', [
            'household_id' => $household->id,
        ]);

        $this->get(route('environmental-health.household-water-supply', [
            'household' => $householdNo,
        ]))->assertOk();

        $this->assertDatabaseMissing('household_environmental_profiles', [
            'household_id' => $household->id,
        ]);
        $this->assertSame(1, Household::query()->count());
    }

    public function test_first_step1_post_creates_one_profile_with_nhts_from_hhts(): void
    {
        [$householdNo, $household] = $this->seedLinkedHousehold('HH-621', 'HHTS');
        $before = Household::query()->count();

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo))
            ->assertRedirect();

        $this->assertSame($before, Household::query()->count());
        $this->assertSame(1, HouseholdEnvironmentalProfile::query()->where('household_id', $household->id)->count());
        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
            'water_supply_status' => 'level_i',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
            'basic_safe_water_status' => DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITH,
            'completed_step' => 1,
        ]);
    }

    public function test_non_hhts_persists_as_non_nhts(): void
    {
        [$householdNo, $household] = $this->seedLinkedHousehold('HH-622', 'Non-HHTS');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo))
            ->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NON_NHTS,
        ]);
    }

    public function test_browser_household_type_cannot_override_trusted_link(): void
    {
        [$householdNo, $household] = $this->seedLinkedHousehold('HH-623', 'HHTS');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo, [
            'household_type' => 'Non-HHTS',
            'household_id' => 99999,
            'id' => 88888,
            'completed_step' => 4,
        ]))->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
            'completed_step' => 1,
        ]);
        $this->assertSame(1, HouseholdEnvironmentalProfile::query()->count());
    }

    public function test_repeat_step1_updates_same_profile_without_duplicate(): void
    {
        [$householdNo, $household] = $this->seedLinkedHousehold('HH-624');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo, [
            'water_supply_status' => 'level_i',
        ]))->assertRedirect();

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo, [
            'water_supply_status' => 'others',
            'specify_water_source' => 'Spring',
            'water_source_location' => 'no',
            'water_availability' => 'no',
        ]))->assertRedirect();

        $this->assertSame(1, HouseholdEnvironmentalProfile::query()->where('household_id', $household->id)->count());
        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'water_supply_status' => 'others',
            'specify_water_source' => 'Spring',
            'water_source_location' => 'no',
            'water_availability' => 'no',
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
            'completed_step' => 1,
        ]);
    }

    public function test_replot_does_not_overwrite_existing_household_type(): void
    {
        [$householdNo, $household] = $this->seedLinkedHousehold('HH-625', 'HHTS');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo))
            ->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
        ]);

        $replot = $this->postJson(route('spot-mapping.plot-handoff'), [
            'household_no' => $householdNo,
            'house_head' => 'Ana Reyes',
            'household_type' => 'Non-HHTS',
            'zone' => '2',
            'lat' => 13.3900,
            'lng' => 123.4400,
            'consent' => true,
            'confirm_replot' => true,
            'client_marker_id' => 'client-marker-replot',
        ])->assertOk();

        $token = (string) $replot->json('handoff_token');
        $this->get(route('environmental-health.household-water-supply', ['handoff' => $token]))
            ->assertRedirect();

        $household->refresh();
        $this->assertSame('13.39000000', (string) $household->latitude);
        $this->assertSame('123.44000000', (string) $household->longitude);

        // Transient link may show Non-NHTS, but DB profile must stay NHTS.
        $this->assertSame(
            DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NON_NHTS,
            DemoHouseholdWaterSupply::findLinkedForActor($householdNo)['household_type'] ?? null
        );

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo, [
            'water_supply_status' => 'level_ii',
        ]))->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
            'water_supply_status' => 'level_ii',
        ]);
        $this->assertSame(1, HouseholdEnvironmentalProfile::query()->where('household_id', $household->id)->count());
    }

    public function test_step1_get_prefills_saved_db_values(): void
    {
        [$householdNo] = $this->seedLinkedHousehold('HH-626');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo, [
            'water_supply_status' => 'others',
            'specify_water_source' => 'Open dug well',
            'water_source_location' => 'no',
            'water_availability' => 'no',
        ]))->assertRedirect();

        $html = $this->get(route('environmental-health.household-water-supply', [
            'household' => $householdNo,
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/name="water_supply_status"\s+value="others"[^>]*checked|value="others"[^>]*name="water_supply_status"[^>]*checked/',
            $html
        );
        $this->assertStringContainsString('value="Open dug well"', $html);
        $this->assertMatchesRegularExpression(
            '/name="water_source_location"\s+value="no"[^>]*checked|value="no"[^>]*name="water_source_location"[^>]*checked/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="water_availability"\s+value="no"[^>]*checked|value="no"[^>]*name="water_availability"[^>]*checked/',
            $html
        );
    }

    public function test_db_values_survive_session_flush_when_relinked(): void
    {
        [$householdNo, $household] = $this->seedLinkedHousehold('HH-627');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo, [
            'water_supply_status' => 'level_iii',
            'water_source_location' => 'yes',
            'water_availability' => 'no',
        ]))->assertRedirect();

        Session::flush();
        $this->actingAsStaff(StaffRole::BHW);

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), [
            'household_no' => $householdNo,
            'house_head' => 'Ana Reyes',
            'household_type' => 'Non-HHTS',
            'zone' => '2',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
            'confirm_replot' => true,
            'client_marker_id' => 'client-marker-resume',
        ])->assertOk();

        $token = (string) $issue->json('handoff_token');
        $this->get(route('environmental-health.household-water-supply', ['handoff' => $token]))
            ->assertRedirect();

        $html = $this->get(route('environmental-health.household-water-supply', [
            'household' => $householdNo,
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/name="water_supply_status"\s+value="level_iii"[^>]*checked|value="level_iii"[^>]*name="water_supply_status"[^>]*checked/',
            $html
        );
        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
            'water_supply_status' => 'level_iii',
        ]);
    }

    public function test_soft_deleted_household_cannot_receive_step1_persistence(): void
    {
        [$householdNo, $household] = $this->seedLinkedHousehold('HH-628');
        $household->delete();

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo))
            ->assertSessionHasErrors('household_no');

        $this->assertDatabaseMissing('household_environmental_profiles', [
            'household_id' => $household->id,
        ]);
    }

    public function test_amenities_socioeconomic_reads_canonical_db_type_after_step1(): void
    {
        [$householdNo] = $this->seedLinkedHousehold('HH-629', 'HHTS');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo))
            ->assertRedirect();

        $this->get(route('household-profiling.amenities.show', ['householdNo' => $householdNo]))
            ->assertOk()
            ->assertSee('NHTS', false)
            ->assertDontSee('>HHTS<', false);
    }

    public function test_normalize_canonical_household_type_helper(): void
    {
        $this->assertSame('NHTS', DemoHouseholdWaterSupply::normalizeCanonicalHouseholdType('HHTS'));
        $this->assertSame('NHTS', DemoHouseholdWaterSupply::normalizeCanonicalHouseholdType('NHTS'));
        $this->assertSame('Non-NHTS', DemoHouseholdWaterSupply::normalizeCanonicalHouseholdType('Non-HHTS'));
        $this->assertSame('Non-NHTS', DemoHouseholdWaterSupply::normalizeCanonicalHouseholdType('Non-NHTS'));
        $this->assertNull(DemoHouseholdWaterSupply::normalizeCanonicalHouseholdType('bogus'));
        $this->assertNull(DemoHouseholdWaterSupply::normalizeCanonicalHouseholdType(''));
    }

    public function test_empty_profile_and_session_use_household_row_nhts(): void
    {
        [$householdNo, $household] = $this->seedRecognizedHouseholdWithRowType('HH-631', 'NHTS');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo))
            ->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
        ]);
    }

    public function test_empty_profile_and_session_canonicalize_household_row_hhts_to_nhts(): void
    {
        [$householdNo, $household] = $this->seedRecognizedHouseholdWithRowType('HH-632', 'HHTS');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo))
            ->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
        ]);
    }

    public function test_session_type_beats_household_row_type(): void
    {
        [$householdNo, $household] = $this->seedRecognizedHouseholdWithRowType(
            'HH-633',
            'NHTS',
            DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NON_NHTS,
        );

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo))
            ->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NON_NHTS,
        ]);
    }

    public function test_existing_profile_type_beats_session_and_household_row(): void
    {
        [$householdNo, $household] = $this->seedLinkedHousehold('HH-634', 'HHTS');

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo))
            ->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
        ]);

        $this->ensureHouseholdsHouseholdTypeColumn();
        $household->forceFill([
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NON_NHTS,
        ])->save();

        DemoHouseholdWaterSupply::linkFromHousehold(
            $household->fresh(),
            DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NON_NHTS,
        );

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo, [
            'water_supply_status' => 'level_ii',
        ]))->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
            'water_supply_status' => 'level_ii',
        ]);
        $this->assertSame(1, HouseholdEnvironmentalProfile::query()->where('household_id', $household->id)->count());
    }

    public function test_laravel_schema_without_household_type_column_keeps_null_when_session_empty(): void
    {
        $this->assertFalse(Schema::hasColumn('households', 'household_type'));

        [$householdNo, $household] = $this->seedRecognizedHouseholdWithRowType('HH-635', null);

        $this->post(route('environmental-health.household-water-supply.store'), $this->validStep1Payload($householdNo))
            ->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'household_type' => null,
        ]);
        $this->assertFalse(Schema::hasColumn('households', 'household_type'));
    }

    /**
     * @return array{0: string, 1: Household}
     */
    private function seedRecognizedHouseholdWithRowType(
        string $householdNo,
        ?string $rowType,
        ?string $sessionType = null,
    ): array {
        $this->actingAsStaff(StaffRole::BHW);
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        if ($rowType !== null) {
            $this->ensureHouseholdsHouseholdTypeColumn();
        }

        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => null,
            'longitude' => null,
        ]);

        if ($rowType !== null) {
            $household->forceFill(['household_type' => $rowType])->save();
            $household->refresh();
        }

        DemoHouseholdWaterSupply::linkFromHousehold($household, $sessionType);

        return [$householdNo, $household->fresh()];
    }

    private function ensureHouseholdsHouseholdTypeColumn(): void
    {
        if (Schema::hasColumn('households', 'household_type')) {
            return;
        }

        Schema::table('households', function ($table): void {
            $table->string('household_type', 50)->nullable();
        });
    }
}
