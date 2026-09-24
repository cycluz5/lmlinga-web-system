<?php

namespace Tests\Feature;

use App\Models\ChildImmunization;
use App\Models\Household;
use App\Models\ImmunizationDose;
use App\Models\Resident;
use App\Support\ChildImmunizationService;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * DB-08 Phase 3 — Child Immunization UI ↔ database integration.
 */
class ChildImmunizationPersistencePhase3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedPersistedChild(array $residentOverrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-860',
            'zone' => 'Zone 1',
            'street' => 'Imm St.',
        ]);

        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => 'MB-860',
            'first_name' => 'Baby',
            'last_name' => 'Persist',
            'relation' => 'Son',
            'birthday' => now()->subMonths(8)->format('Y-m-d'),
            'sex' => 'Male',
        ], $residentOverrides));

        return ['household' => $household, 'resident' => $resident];
    }

    /**
     * @return array{householdNo: string, memberId: string}
     */
    private function routeParams(Household $household, Resident $resident): array
    {
        return [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'vaccines' => [
                'bcg' => [0 => '2025-01-15'],
                'mmr' => [0 => '2025-09-01'],
            ],
            'vaccine_types' => ['bcg', 'mmr'],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function ficCompletePayload(): array
    {
        return [
            'vaccines' => [
                'bcg' => [0 => '2025-01-01'],
                'opv' => [0 => '2025-01-15', 1 => '2025-02-15', 2 => '2025-03-15'],
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
                'mmr' => [0 => '2025-09-01'],
            ],
            'vaccine_types' => ['fic'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cicCompletePayload(): array
    {
        return [
            'vaccines' => [
                'bcg' => [0 => '2025-01-01'],
                'opv' => [0 => '2025-01-15', 1 => '2025-02-15', 2 => '2025-03-15'],
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
                'mmr' => [0 => '2025-09-01', 1 => '2025-12-01'],
            ],
            'vaccine_types' => ['cic'],
        ];
    }

    public function test_persisted_resident_form_uses_db_persistence_mode(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-persistence="db"', $html);
        $this->assertStringContainsString('method="post"', strtolower($html));
        $this->assertStringContainsString(
            route('household-profiling.members.child-immunization.store', $params),
            $html
        );
        $this->assertStringContainsString('name="_token"', $html);
    }

    public function test_demo_only_member_remains_preview_only(): void
    {
        $html = $this->get(route('household-profiling.members.child-immunization', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('No record found.', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-child-imm-immunization[^>]*action=/u',
            $html
        );
        $this->assertStringNotContainsString('data-persistence="db"', $html);
    }

    public function test_persisted_resident_page_hydrates_existing_dose_dates(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        app(ChildImmunizationService::class)->saveForResident($resident, [
            'vaccines' => [
                'bcg' => [0 => '2025-02-10'],
                'mmr' => [1 => '2025-12-01'],
            ],
        ]);

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="2025-02-10"', $html);
        $this->assertStringContainsString('value="2025-12-01"', $html);
    }

    public function test_persisted_resident_page_hydrates_manual_checkbox_selections(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        app(ChildImmunizationService::class)->saveForResident($resident, [
            'vaccine_types' => ['bcg', 'fic', 'cic'],
        ]);

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="lml-child-imm-type-bcg"[^>]*\bchecked\b/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-child-imm-type-fic"[^>]*\bchecked\b/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-child-imm-type-cic"[^>]*\bchecked\b/u',
            $html
        );
    }

    public function test_empty_persisted_resident_shows_blank_immunization_form(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/name="vaccines\[bcg\]\[0\]"[^>]*value=""/s',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-child-imm-type-bcg"[^>]*\bchecked\b/u',
            $html
        );
    }

    public function test_save_creates_immunization_header_and_dose_rows(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), $this->validPayload())
            ->assertRedirect(route('household-profiling.members.child-immunization', $params));

        $this->assertDatabaseHas('child_immunizations', ['resident_id' => $resident->id]);
        $record = ChildImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(2, ImmunizationDose::query()->where('child_immunization_id', $record->id)->count());
    }

    public function test_save_persists_manual_checkbox_selections(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccine_types' => ['mmr', 'fic'],
        ])->assertRedirect();

        $record = ChildImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame(['mmr', 'fic'], $record->selected_vaccine_types);
    }

    public function test_save_with_empty_optional_dates_succeeds(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccines' => ['mmr' => [0 => '']],
            'vaccine_types' => [],
        ])->assertRedirect();

        $record = ChildImmunization::query()->where('resident_id', $resident->id)->first();
        $dose = ImmunizationDose::query()
            ->where('child_immunization_id', $record->id)
            ->where('vaccine_type', 'mmr')
            ->where('dose_index', 0)
            ->first();

        $this->assertNotNull($dose);
        $this->assertNull($dose->date_given);
    }

    public function test_save_reload_preserves_dose_dates(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccines' => ['bcg' => [0 => '2025-03-01']],
        ])->assertRedirect();

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="2025-03-01"', $html);
    }

    public function test_save_reload_preserves_checkbox_states(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccine_types' => ['opv', 'cic'],
        ])->assertRedirect();

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/id="lml-child-imm-type-opv"[^>]*\bchecked\b/u', $html);
        $this->assertMatchesRegularExpression('/id="lml-child-imm-type-cic"[^>]*\bchecked\b/u', $html);
    }

    public function test_updating_date_persists_change_after_reload(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccines' => ['bcg' => [0 => '2025-01-01']],
        ]);
        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccines' => ['bcg' => [0 => '2025-04-15']],
        ]);

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="2025-04-15"', $html);
        $this->assertStringNotContainsString('value="2025-01-01"', $html);
    }

    public function test_clearing_stored_date_persists_null_and_reload_shows_blank(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccines' => ['bcg' => [0 => '2025-01-01']],
        ]);
        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccines' => ['bcg' => [0 => '']],
        ]);

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/name="vaccines\[bcg\]\[0\]"[^>]*value=""/s',
            $html
        );
    }

    public function test_unchecking_stored_vaccine_type_removes_selection_on_reload(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccine_types' => ['bcg', 'fic'],
        ]);
        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccine_types' => [],
        ]);

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/id="lml-child-imm-type-bcg"[^>]*\bchecked\b/u', $html);
        $this->assertDoesNotMatchRegularExpression('/id="lml-child-imm-type-fic"[^>]*\bchecked\b/u', $html);
    }

    public function test_checkbox_and_date_independence_survives_full_ui_submit(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccine_types' => ['fic'],
        ]);
        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccines' => ['mmr' => [0 => '2025-09-01']],
            'vaccine_types' => [],
        ]);

        $record = ChildImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame([], $record->selected_vaccine_types);
        $this->assertSame(
            1,
            ImmunizationDose::query()
                ->where('child_immunization_id', $record->id)
                ->where('vaccine_type', 'mmr')
                ->count()
        );
    }

    public function test_repeated_save_does_not_duplicate_header_or_dose_slot(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);
        $payload = ['vaccines' => ['bcg' => [0 => '2025-01-01']]];

        $this->post(route('household-profiling.members.child-immunization.store', $params), $payload);
        $this->post(route('household-profiling.members.child-immunization.store', $params), $payload);

        $this->assertSame(1, ChildImmunization::query()->where('resident_id', $resident->id)->count());
        $record = ChildImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame(
            1,
            ImmunizationDose::query()
                ->where('child_immunization_id', $record->id)
                ->where('vaccine_type', 'bcg')
                ->where('dose_index', 0)
                ->count()
        );
    }

    public function test_fic_derived_state_is_surfaced_after_save(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), $this->ficCompletePayload());

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-fic-completed="true"', $html);
        $this->assertStringContainsString('data-cic-completed="false"', $html);
    }

    public function test_mmr_one_dose_produces_fic_but_not_cic_on_page(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), $this->ficCompletePayload());

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-fic-completed="true"', $html);
        $this->assertStringContainsString('data-cic-completed="false"', $html);
    }

    public function test_mmr_two_doses_satisfies_cic_when_other_doses_exist(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), $this->cicCompletePayload());

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-cic-completed="true"', $html);
        $this->assertStringContainsString('data-fic-completed="true"', $html);
    }

    public function test_forged_resident_id_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $other = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-861',
        ]);

        $this->post(route('household-profiling.members.child-immunization.store', $params), array_merge(
            $this->validPayload(),
            ['resident_id' => $other->id]
        ))->assertSessionHasErrors('resident_id');

        $this->assertDatabaseCount('child_immunizations', 0);
    }

    public function test_forged_child_immunization_id_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), array_merge(
            $this->validPayload(),
            ['child_immunization_id' => 999]
        ))->assertSessionHasErrors('child_immunization_id');
    }

    public function test_cross_household_spoof_is_rejected(): void
    {
        $householdA = Household::factory()->create(['household_no' => 'HH-862']);
        $householdB = Household::factory()->create(['household_no' => 'HH-863']);
        $resident = Resident::factory()->create([
            'household_id' => $householdA->id,
            'member_no' => 'MB-862',
        ]);

        $this->post(route('household-profiling.members.child-immunization.store', [
            'householdNo' => $householdB->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload())->assertNotFound();

        $this->assertDatabaseCount('child_immunizations', 0);
    }

    public function test_demo_only_member_cannot_create_immunization_db_rows(): void
    {
        $this->post(route('household-profiling.members.child-immunization.store', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]), $this->validPayload())->assertNotFound();

        $this->assertDatabaseCount('child_immunizations', 0);
    }

    public function test_validation_error_preserves_entered_ui_values(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);
        $showUrl = route('household-profiling.members.child-immunization', $params);

        $this->from($showUrl)
            ->post(route('household-profiling.members.child-immunization.store', $params), [
                'vaccines' => ['bcg' => [0 => '2025-01-01']],
                'vaccine_types' => ['not-a-real-key'],
            ])
            ->assertSessionHasErrors('vaccine_types.0');

        $html = $this->get($showUrl)->assertOk()->getContent();
        $this->assertStringContainsString('value="2025-01-01"', $html);
        $this->assertStringContainsString('role="alert"', $html);
    }

    public function test_frozen_copy_and_navigation_remain_intact_for_persisted_resident(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('2nd Dose (12 months)', $html);
        $this->assertStringContainsString('Child Immunization', $html);
        $this->assertStringContainsString('Vaccines Type', $html);
        $this->assertStringContainsString(
            route('household-profiling.members.show', $params),
            $html
        );
        $this->assertStringContainsString('data-child-imm-edit="immunization"', $html);
    }

    public function test_edit_save_reload_cycle_survives_for_persisted_resident(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccines' => [
                'bcg' => [0 => '2025-01-01'],
                'mmr' => [0 => '2025-09-01', 1 => ''],
            ],
            'vaccine_types' => ['bcg', 'fic'],
        ])->assertRedirect();

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="2025-01-01"', $html);
        $this->assertStringContainsString('value="2025-09-01"', $html);
        $this->assertMatchesRegularExpression('/id="lml-child-imm-type-bcg"[^>]*\bchecked\b/u', $html);
        $this->assertMatchesRegularExpression('/id="lml-child-imm-type-fic"[^>]*\bchecked\b/u', $html);
        $this->assertStringContainsString('data-saved-message="Child immunization saved."', $html);
    }

    public function test_persisted_form_preserves_accessibility_associations(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('for="lml-child-imm-bcg-dose-0"', $html);
        $this->assertStringContainsString('aria-describedby="lml-child-imm-bcg-dose-0-caption"', $html);
        $this->assertStringContainsString('for="lml-child-imm-type-bcg"', $html);
        $this->assertStringContainsString('aria-label="Edit child immunization"', $html);
    }

    public function test_immunization_store_route_exists(): void
    {
        $this->assertTrue(Route::has('household-profiling.members.child-immunization.store'));
    }
}
