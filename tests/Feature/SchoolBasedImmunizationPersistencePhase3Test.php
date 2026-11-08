<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\SchoolImmunization;
use App\Models\SchoolImmunizationDose;
use App\Support\SchoolImmunizationService;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * DB-09 Phase 3 — School-Based Immunization UI ↔ database integration.
 */
class SchoolBasedImmunizationPersistencePhase3Test extends TestCase
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
            'household_no' => 'HH-980',
            'zone' => 'Zone 1',
            'street' => 'SBI Persist St.',
        ]);

        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => 'MB-980',
            'first_name' => 'School',
            'last_name' => 'Persist',
            'relation' => 'Daughter',
            'birthday' => now()->subYears(10)->format('Y-m-d'),
            'sex' => 'Female',
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
                'grade-1' => [
                    'td' => '2025-01-15',
                    'mr' => '2025-01-20',
                ],
                'grade-7' => [
                    'td' => '2025-02-01',
                    'mr' => '',
                ],
                'hpv' => [
                    '1' => '2025-03-01',
                    '2' => '',
                ],
            ],
            'vaccine_types' => ['grade1_td', 'grade1_mr', 'hpv_1'],
        ], $overrides);
    }

    private function showRoute(array $params): string
    {
        return route('household-profiling.members.school-based-immunization', $params);
    }

    private function storeRoute(array $params): string
    {
        return route('household-profiling.members.school-based-immunization.store', $params);
    }

    public function test_persisted_resident_get_uses_db_persistence_mode(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();

        $this->assertStringContainsString('data-persistence="db"', $html);
        $this->assertStringContainsString('method="post"', strtolower($html));
        $this->assertStringContainsString($this->storeRoute($params), $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('data-demo="false"', $html);
    }

    public function test_empty_persisted_resident_shows_blank_sbi_form(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/name="vaccines\[grade-1\]\[td\]"[^>]*value=""/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="vaccines\[hpv\]\[2\]"[^>]*value=""/s',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-sbi-type-g1-td"[^>]*\bchecked\b/u',
            $html
        );
        $this->assertStringNotContainsString('lml-sbi__status--recorded', $html);
    }

    public function test_all_six_date_slots_hydrate_from_db(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        app(SchoolImmunizationService::class)->saveForResident($resident, [
            'vaccines' => [
                'grade-1' => ['td' => '2025-01-01', 'mr' => '2025-01-02'],
                'grade-7' => ['td' => '2025-02-01', 'mr' => '2025-02-02'],
                'hpv' => ['1' => '2025-03-01', '2' => '2025-03-02'],
            ],
        ]);

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();

        foreach (['2025-01-01', '2025-01-02', '2025-02-01', '2025-02-02', '2025-03-01', '2025-03-02'] as $date) {
            $this->assertStringContainsString('value="'.$date.'"', $html);
        }
        $this->assertSame(6, substr_count($html, 'lml-sbi__status--recorded'));
    }

    public function test_all_six_checkbox_states_hydrate_from_selected_vaccine_types(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        app(SchoolImmunizationService::class)->saveForResident($resident, [
            'vaccine_types' => SchoolImmunization::SELECTABLE_TYPE_KEYS,
        ]);

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();

        foreach ([
            'lml-sbi-type-g1-td',
            'lml-sbi-type-g1-mr',
            'lml-sbi-type-g7-td',
            'lml-sbi-type-g7-mr',
            'lml-sbi-type-hpv-1',
            'lml-sbi-type-hpv-2',
        ] as $id) {
            $this->assertMatchesRegularExpression(
                '/id="'.preg_quote($id, '/').'"[^>]*\bchecked\b/u',
                $html
            );
        }
    }

    public function test_save_creates_school_immunizations_header(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), $this->validPayload())
            ->assertRedirect($this->showRoute($params))
            ->assertSessionHas('status', 'School-based immunization saved.');

        $this->assertDatabaseHas('school_immunizations', [
            'resident_id' => $resident->id,
        ]);
    }

    public function test_save_creates_school_immunization_doses_rows(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), $this->validPayload())->assertRedirect();

        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(6, SchoolImmunizationDose::query()->where('school_immunization_id', $record->id)->count());
    }

    public function test_update_modifies_existing_data(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), $this->validPayload());
        $this->post($this->storeRoute($params), $this->validPayload([
            'vaccines' => [
                'grade-1' => ['td' => '2025-08-08', 'mr' => '2025-01-20'],
                'grade-7' => ['td' => '2025-02-01', 'mr' => ''],
                'hpv' => ['1' => '2025-03-01', '2' => ''],
            ],
            'vaccine_types' => ['grade7_td'],
        ]));

        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame(['grade1_td', 'grade1_mr', 'grade7_td', 'hpv_1'], $record->selected_vaccine_types);
        $this->assertSame(
            '2025-08-08',
            SchoolImmunizationDose::query()
                ->where('school_immunization_id', $record->id)
                ->where('slot_group', 'grade-1')
                ->where('slot_key', 'td')
                ->first()
                ->date_given
                ->format('Y-m-d')
        );
    }

    public function test_repeated_save_does_not_duplicate_header(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);
        $payload = $this->validPayload();

        $this->post($this->storeRoute($params), $payload);
        $this->post($this->storeRoute($params), $payload);
        $this->post($this->storeRoute($params), $payload);

        $this->assertSame(1, SchoolImmunization::query()->where('resident_id', $resident->id)->count());
    }

    public function test_repeated_save_does_not_duplicate_dose_slots(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);
        $payload = $this->validPayload();

        $this->post($this->storeRoute($params), $payload);
        $this->post($this->storeRoute($params), $payload);

        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame(6, SchoolImmunizationDose::query()->where('school_immunization_id', $record->id)->count());
        $this->assertSame(
            1,
            SchoolImmunizationDose::query()
                ->where('school_immunization_id', $record->id)
                ->where('slot_group', 'grade-1')
                ->where('slot_key', 'td')
                ->count()
        );
    }

    public function test_clearing_an_existing_date_persists_null(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), [
            'vaccines' => ['grade-1' => ['td' => '2025-01-01']],
        ]);
        // Simulate browser clear-date → uncheck (key omitted from vaccine_types).
        $this->post($this->storeRoute($params), [
            'vaccines' => ['grade-1' => ['td' => '']],
            'vaccine_types' => [],
        ])->assertRedirect();

        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $dose = SchoolImmunizationDose::query()
            ->where('school_immunization_id', $record->id)
            ->where('slot_group', 'grade-1')
            ->where('slot_key', 'td')
            ->first();

        $this->assertNotNull($dose);
        $this->assertNull($dose->date_given);
        $this->assertSame([], $record->selected_vaccine_types);

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/name="vaccines\[grade-1\]\[td\]"[^>]*value=""/s',
            $html
        );
        $this->assertDoesNotMatchRegularExpression('/id="lml-sbi-type-g1-td"[^>]*\bchecked\b/u', $html);
    }

    public function test_checkbox_selections_persist(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), [
            'vaccine_types' => ['grade7_mr', 'hpv_2'],
        ])->assertRedirect();

        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame(['grade7_mr', 'hpv_2'], $record->selected_vaccine_types);
    }

    public function test_unchecking_a_stored_checkbox_removes_it_from_selected_vaccine_types(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), [
            'vaccine_types' => ['grade1_td', 'hpv_1'],
        ]);
        $this->post($this->storeRoute($params), [
            'vaccine_types' => [],
        ]);

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/id="lml-sbi-type-g1-td"[^>]*\bchecked\b/u', $html);
        $this->assertDoesNotMatchRegularExpression('/id="lml-sbi-type-hpv-1"[^>]*\bchecked\b/u', $html);

        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame([], $record->selected_vaccine_types);
    }

    public function test_checkbox_only_save_does_not_create_fake_date(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), [
            'vaccine_types' => ['grade1_td'],
        ])->assertRedirect();

        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame(['grade1_td'], $record->selected_vaccine_types);
        $this->assertSame(0, SchoolImmunizationDose::query()->where('school_immunization_id', $record->id)->count());

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="lml-sbi-type-g1-td"[^>]*\bchecked\b/u', $html);
        $this->assertStringNotContainsString('lml-sbi__status--recorded', $html);
    }

    public function test_date_only_save_automatically_derives_checkbox_selection(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), [
            'vaccines' => ['grade-1' => ['td' => '2025-04-04']],
            'vaccine_types' => [],
        ])->assertRedirect();

        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame(['grade1_td'], $record->selected_vaccine_types);

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();
        $this->assertStringContainsString('value="2025-04-04"', $html);
        $this->assertMatchesRegularExpression('/id="lml-sbi-type-g1-td"[^>]*\bchecked\b/u', $html);
        $this->assertStringContainsString('lml-sbi__status--recorded', $html);
    }

    public function test_validation_failure_preserves_old_dates(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);
        $showUrl = $this->showRoute($params);

        $this->from($showUrl)
            ->post($this->storeRoute($params), [
                'vaccines' => ['grade-1' => ['td' => '2025-01-01']],
                'vaccine_types' => ['not-a-real-key'],
            ])
            ->assertSessionHasErrors('vaccine_types.0');

        $html = $this->get($showUrl)->assertOk()->getContent();
        $this->assertStringContainsString('value="2025-01-01"', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertDatabaseCount('school_immunizations', 0);
    }

    public function test_validation_failure_preserves_checkbox_selections(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);
        $showUrl = $this->showRoute($params);

        $this->from($showUrl)
            ->post($this->storeRoute($params), [
                'vaccines' => ['grade-1' => ['td' => 'not-a-date']],
                'vaccine_types' => ['grade1_td', 'hpv_2'],
            ])
            ->assertSessionHasErrors('vaccines.grade-1.td');

        $html = $this->get($showUrl)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="lml-sbi-type-g1-td"[^>]*\bchecked\b/u', $html);
        $this->assertMatchesRegularExpression('/id="lml-sbi-type-hpv-2"[^>]*\bchecked\b/u', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertDatabaseCount('school_immunizations', 0);
    }

    public function test_forged_resident_id_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);
        $other = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-981',
        ]);

        $this->post($this->storeRoute($params), array_merge(
            $this->validPayload(),
            ['resident_id' => $other->id]
        ))->assertSessionHasErrors('resident_id');

        $this->assertDatabaseCount('school_immunizations', 0);
    }

    public function test_forged_school_immunization_id_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), array_merge(
            $this->validPayload(),
            ['school_immunization_id' => 999]
        ))->assertSessionHasErrors('school_immunization_id');

        $this->assertDatabaseCount('school_immunizations', 0);
    }

    public function test_cross_household_member_spoof_is_rejected(): void
    {
        $householdA = Household::factory()->create(['household_no' => 'HH-982']);
        $householdB = Household::factory()->create(['household_no' => 'HH-983']);
        $resident = Resident::factory()->create([
            'household_id' => $householdA->id,
            'member_no' => 'MB-982',
        ]);

        $this->post($this->storeRoute([
            'householdNo' => $householdB->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload())->assertNotFound();

        $this->assertDatabaseCount('school_immunizations', 0);
    }

    public function test_demo_only_member_remains_preview_only(): void
    {
        $html = $this->get($this->showRoute([
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('No record found.', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-sbi-records[^>]*action=/u',
            $html
        );
        $this->assertStringNotContainsString('data-persistence="db"', $html);
    }

    public function test_demo_only_member_cannot_create_db_rows(): void
    {
        $this->post($this->storeRoute([
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]), $this->validPayload())->assertNotFound();

        $this->assertDatabaseCount('school_immunizations', 0);
        $this->assertDatabaseCount('school_immunization_doses', 0);
    }

    public function test_successful_post_redirects_to_same_sbi_page_with_flash(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), $this->validPayload())
            ->assertRedirect($this->showRoute($params))
            ->assertSessionHas('status', 'School-based immunization saved.');
    }

    public function test_reload_after_save_shows_persisted_values(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), $this->validPayload())->assertRedirect();

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();

        $this->assertStringContainsString('value="2025-01-15"', $html);
        $this->assertStringContainsString('value="2025-01-20"', $html);
        $this->assertStringContainsString('value="2025-02-01"', $html);
        $this->assertStringContainsString('value="2025-03-01"', $html);
        $this->assertMatchesRegularExpression('/id="lml-sbi-type-g1-td"[^>]*\bchecked\b/u', $html);
        $this->assertMatchesRegularExpression('/id="lml-sbi-type-g1-mr"[^>]*\bchecked\b/u', $html);
        $this->assertMatchesRegularExpression('/id="lml-sbi-type-g7-td"[^>]*\bchecked\b/u', $html);
        $this->assertMatchesRegularExpression('/id="lml-sbi-type-hpv-1"[^>]*\bchecked\b/u', $html);
        $this->assertDoesNotMatchRegularExpression('/id="lml-sbi-type-g7-mr"[^>]*\bchecked\b/u', $html);
        $this->assertDoesNotMatchRegularExpression('/id="lml-sbi-type-hpv-2"[^>]*\bchecked\b/u', $html);
    }

    public function test_frozen_ui_labels_and_field_structure_remain_intact(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();

        $this->assertStringContainsString('School-Based Immunization', $html);
        $this->assertStringContainsString('id="lml-sbi-grade-1"', $html);
        $this->assertStringContainsString('id="lml-sbi-grade-7"', $html);
        $this->assertStringContainsString('Human Papillomavirus (HPV)', $html);
        $this->assertStringContainsString('Vaccines Type', $html);
        $this->assertStringContainsString('Tetanus Diphtheria (TD)', $html);
        $this->assertStringContainsString('Measles Rubella', $html);
        $this->assertStringContainsString('Human Papillomavirus (1st Dose)', $html);
        $this->assertStringContainsString('Human Papillomavirus (2nd Dose)', $html);
        $this->assertStringContainsString(route('household-profiling.members.show', $params), $html);
        $this->assertStringNotContainsString('For 9 Years Old Female', $html);
    }

    public function test_non_resident_sbi_remains_preview_only(): void
    {
        $this->assertTrue(Route::has('health-records.child-care.non-residents.school-based-immunization'));
        $this->assertFalse(Route::has('health-records.child-care.non-residents.school-based-immunization.store'));

        $before = SchoolImmunization::query()->count();

        $html = $this->get(route('health-records.child-care.non-residents.school-based-immunization', [
            'childKey' => 'andrei-b-malaya',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-persistence="preview"', $html);
        $this->assertSame($before, SchoolImmunization::query()->count());
    }

    public function test_edit_save_reload_cycle_survives_for_persisted_resident(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), [
            'vaccines' => ['hpv' => ['1' => '2025-05-05']],
            'vaccine_types' => ['hpv_1'],
        ])->assertRedirect($this->showRoute($params));

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();
        $this->assertStringContainsString('value="2025-05-05"', $html);
        $this->assertMatchesRegularExpression('/id="lml-sbi-type-hpv-1"[^>]*\bchecked\b/u', $html);
        $this->assertStringContainsString('data-persistence="db"', $html);
    }
}
