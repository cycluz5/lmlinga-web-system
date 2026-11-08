<?php

namespace Tests\Feature;

use App\Models\ChildBirthHistory;
use App\Models\ChildNutrition;
use App\Models\ChildNutritionSfpOutcome;
use App\Models\Household;
use App\Models\Resident;
use App\Support\ChildNutritionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * DB-10 Phase 2 — Child Nutrition UI ↔ database persistence.
 */
class ChildNutritionPersistencePhase2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(\App\Support\StaffRole::BHW);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedPersistedChild(array $residentOverrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-910',
            'zone' => 'Zone 1',
            'street' => 'Nutrition St.',
        ]);

        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => 'MB-910',
            'first_name' => 'Nutri',
            'last_name' => 'Persist',
            'relation' => 'Son',
            'birthday' => '2024-01-01',
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

    private function showRoute(array $params): string
    {
        return route('household-profiling.members.child-nutrition', $params);
    }

    private function storeRoute(array $params): string
    {
        return route('household-profiling.members.child-nutrition.store', $params);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'newborn' => [
                'length' => '50.00',
                'weight' => '3.20',
                'breastfeeding_date' => '2025-01-02',
            ],
            'iron' => [
                '1st' => '2025-02-01',
                '2nd' => '',
                '3rd' => '',
            ],
            'vitamin_a' => [
                'va-6-11' => '2025-06-01',
                'va-12-59-1' => '',
                'va-12-59-2' => '',
            ],
            'mnp' => [
                'mnp-6-11' => '2025-07-01',
                'mnp-12-23' => '',
            ],
            'lns_sq' => [
                'lns-6-11' => '',
                'lns-12-23' => '',
            ],
            'mam' => [
                'identified' => ['date' => '2025-08-01'],
            ],
            'mam_identified' => 'yes',
            'sam' => [
                'enrolled' => ['date' => '2025-09-01'],
            ],
            'sam_enrolled' => 'no',
        ], $overrides);
    }

    public function test_store_route_is_registered(): void
    {
        $this->assertTrue(Route::has('household-profiling.members.child-nutrition.store'));
    }

    public function test_persisted_resident_form_uses_db_persistence_mode(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();

        $this->assertStringContainsString('data-persistence="db"', $html);
        $this->assertStringContainsString('data-demo="false"', $html);
        $this->assertStringContainsString('method="post"', strtolower($html));
        $this->assertStringContainsString($this->storeRoute($params), $html);
        $this->assertStringContainsString('name="_token"', $html);
    }

    public function test_demo_only_member_remains_preview_only(): void
    {
        $html = $this->get($this->showRoute([
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('No record found.', $html);
        $this->assertStringNotContainsString('data-persistence="preview"', $html);
        $this->assertStringNotContainsString('Kristine Reyes', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-child-nut-records[^>]*action=/u',
            $html
        );
    }

    public function test_demo_only_member_cannot_create_nutrition_or_resident_rows(): void
    {
        $beforeResidents = Resident::query()->count();
        $beforeNutrition = ChildNutrition::query()->count();

        $this->post($this->storeRoute([
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]), $this->validPayload())->assertNotFound();

        $this->assertSame($beforeResidents, Resident::query()->count());
        $this->assertSame($beforeNutrition, ChildNutrition::query()->count());
    }

    public function test_real_resident_can_save_child_nutrition(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), $this->validPayload())
            ->assertRedirect($this->showRoute($params))
            ->assertSessionHas('status', 'Child nutrition saved.');

        $this->assertDatabaseHas('child_nutritions', [
            'resident_id' => $resident->id,
        ]);

        $record = ChildNutrition::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('50.00', (string) $record->newborn_length_cm);
        $this->assertSame('3.20', (string) $record->newborn_weight_kg);
        $this->assertSame('2025-01-02', $record->newborn_breastfeeding_date?->format('Y-m-d'));
        $this->assertSame('2025-02-01', $record->iron_1st_date?->format('Y-m-d'));
        $this->assertSame('2025-06-01', $record->vitamin_a_va_6_11_date?->format('Y-m-d'));
        $this->assertSame('2025-07-01', $record->mnp_6_11_date?->format('Y-m-d'));

        $mam = ChildNutritionSfpOutcome::query()
            ->where('child_nutrition_id', $record->id)
            ->where('program', 'mam')
            ->where('outcome', 'identified')
            ->first();
        $this->assertNotNull($mam);
        $this->assertSame('2025-08-01', $mam->outcome_date?->format('Y-m-d'));
        $this->assertSame('yes', $mam->action_yes_no);

        $sam = ChildNutritionSfpOutcome::query()
            ->where('child_nutrition_id', $record->id)
            ->where('program', 'sam')
            ->where('outcome', 'enrolled')
            ->first();
        $this->assertNotNull($sam);
        $this->assertSame('2025-09-01', $sam->outcome_date?->format('Y-m-d'));
        $this->assertSame('no', $sam->action_yes_no);
    }

    public function test_row_is_linked_to_correct_resident_id(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), $this->validPayload())->assertRedirect();

        $this->assertSame(
            1,
            ChildNutrition::query()->where('resident_id', $resident->id)->count()
        );
    }

    public function test_repeated_save_updates_same_one_to_one_record(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), $this->validPayload())->assertRedirect();
        $firstId = ChildNutrition::query()->where('resident_id', $resident->id)->value('id');

        $this->post($this->storeRoute($params), $this->validPayload([
            'newborn' => [
                'length' => '51.50',
                'weight' => '3.40',
                'breastfeeding_date' => '2025-01-03',
            ],
            'iron' => ['1st' => '2025-03-01', '2nd' => '', '3rd' => ''],
        ]))->assertRedirect();

        $this->assertSame(1, ChildNutrition::query()->where('resident_id', $resident->id)->count());
        $record = ChildNutrition::query()->where('resident_id', $resident->id)->first();
        $this->assertSame($firstId, $record->id);
        $this->assertSame('51.50', (string) $record->newborn_length_cm);
        $this->assertSame('2025-03-01', $record->iron_1st_date?->format('Y-m-d'));
    }

    public function test_second_duplicate_parent_record_cannot_be_created(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        ChildNutrition::factory()->create(['resident_id' => $resident->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        ChildNutrition::factory()->create(['resident_id' => $resident->id]);
    }

    public function test_persisted_values_survive_reload(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), $this->validPayload())->assertRedirect();

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();

        $this->assertStringContainsString('value="50"', $html);
        $this->assertStringContainsString('value="3.2"', $html);
        $this->assertStringContainsString('value="2025-01-02"', $html);
        $this->assertStringContainsString('value="2025-02-01"', $html);
        $this->assertStringContainsString('value="2025-06-01"', $html);
        $this->assertStringContainsString('value="2025-08-01"', $html);
        $this->assertMatchesRegularExpression(
            '/id="lml-child-nut-mam-identified-yes"[^>]*\bchecked\b/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-child-nut-sam-enrolled-no"[^>]*\bchecked\b/u',
            $html
        );
    }

    public function test_optional_blank_fields_remain_valid(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), [])->assertRedirect($this->showRoute($params));

        $this->assertSame(0, ChildNutrition::query()->where('resident_id', $resident->id)->count());
        $this->assertSame(0, ChildNutritionSfpOutcome::query()->count());
    }

    public function test_malformed_values_are_rejected_and_old_input_preserved(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $response = $this->from($this->showRoute($params))
            ->post($this->storeRoute($params), [
                'newborn' => [
                    'length' => 'not-a-number',
                    'weight' => '-1',
                    'breastfeeding_date' => '2025-13-40',
                ],
                'mam_identified' => 'maybe',
            ]);

        $response->assertRedirect($this->showRoute($params));
        $response->assertSessionHasErrors([
            'newborn.length',
            'newborn.weight',
            'newborn.breastfeeding_date',
            'mam_identified',
        ]);
        $response->assertSessionHasInput('newborn.length', 'not-a-number');
        $this->assertDatabaseMissing('child_nutritions', ['resident_id' => $resident->id]);
    }

    public function test_future_initiated_feeding_date_on_nutrition_store_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);
        $tomorrow = Carbon::tomorrow()->toDateString();

        $this->from($this->showRoute($params))
            ->post($this->storeRoute($params), $this->validPayload([
                'newborn' => [
                    'length' => '50.00',
                    'weight' => '3.20',
                    'breastfeeding_date' => $tomorrow,
                ],
            ]))
            ->assertRedirect($this->showRoute($params))
            ->assertSessionHasErrors('newborn.breastfeeding_date')
            ->assertSessionHasInput('newborn.breastfeeding_date', $tomorrow);

        $this->assertDatabaseMissing('child_nutritions', ['resident_id' => $resident->id]);
    }

    public function test_nutrition_initiated_feeding_date_input_caps_at_today(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $today = Carbon::today()->toDateString();

        $html = $this->get($this->showRoute($this->routeParams($household, $resident)))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="lml-child-nut-nb-breastfeeding"', $html);
        $this->assertStringContainsString('max="'.$today.'"', $html);
    }

    public function test_browser_submitted_resident_id_is_prohibited(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $other = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-911',
        ]);
        $params = $this->routeParams($household, $resident);

        $this->from($this->showRoute($params))
            ->post($this->storeRoute($params), array_merge($this->validPayload(), [
                'resident_id' => $other->id,
            ]))
            ->assertRedirect($this->showRoute($params))
            ->assertSessionHasErrors(['resident_id']);

        $this->assertDatabaseMissing('child_nutritions', ['resident_id' => $other->id]);
        $this->assertDatabaseMissing('child_nutritions', ['resident_id' => $resident->id]);
    }

    public function test_browser_submitted_household_id_is_prohibited(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->from($this->showRoute($params))
            ->post($this->storeRoute($params), array_merge($this->validPayload(), [
                'household_id' => 99999,
            ]))
            ->assertRedirect($this->showRoute($params))
            ->assertSessionHasErrors(['household_id']);
    }

    public function test_browser_submitted_child_nutrition_id_is_prohibited(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->from($this->showRoute($params))
            ->post($this->storeRoute($params), array_merge($this->validPayload(), [
                'child_nutrition_id' => 99999,
            ]))
            ->assertRedirect($this->showRoute($params))
            ->assertSessionHasErrors(['child_nutrition_id']);
    }

    public function test_cross_household_member_mismatch_cannot_write_another_resident(): void
    {
        $householdA = Household::factory()->create(['household_no' => 'HH-920']);
        $householdB = Household::factory()->create(['household_no' => 'HH-921']);
        $residentA = Resident::factory()->create([
            'household_id' => $householdA->id,
            'member_no' => 'MB-920',
        ]);
        $residentB = Resident::factory()->create([
            'household_id' => $householdB->id,
            'member_no' => 'MB-921',
        ]);

        $this->post($this->storeRoute([
            'householdNo' => $householdA->household_no,
            'memberId' => $residentB->member_no,
        ]), $this->validPayload())->assertNotFound();

        $this->assertDatabaseMissing('child_nutritions', ['resident_id' => $residentA->id]);
        $this->assertDatabaseMissing('child_nutritions', ['resident_id' => $residentB->id]);
    }

    public function test_newborn_save_does_not_modify_child_birth_histories(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $birth = ChildBirthHistory::factory()->create([
            'resident_id' => $resident->id,
            'birth_weight_kg' => '2.80',
            'birth_length_cm' => '48.00',
            'breastfeeding_date' => '2024-12-01',
        ]);

        $this->post($this->storeRoute($params), $this->validPayload([
            'newborn' => [
                'length' => '55.00',
                'weight' => '4.00',
                'breastfeeding_date' => '2025-05-05',
            ],
        ]))->assertRedirect();

        $birth->refresh();
        $this->assertSame('2.80', (string) $birth->birth_weight_kg);
        $this->assertSame('48.00', (string) $birth->birth_length_cm);
        $this->assertSame('2024-12-01', $birth->breastfeeding_date?->format('Y-m-d'));
        $this->assertSame(1, ChildBirthHistory::query()->where('resident_id', $resident->id)->count());
    }

    public function test_blank_inputs_do_not_clear_existing_values(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        app(ChildNutritionService::class)->saveForResident($resident, $this->validPayload());

        $this->post($this->storeRoute($params), [
            'newborn' => ['length' => '', 'weight' => '', 'breastfeeding_date' => ''],
            'iron' => ['1st' => '', '2nd' => '', '3rd' => ''],
            'mam' => ['identified' => ['date' => '']],
        ])->assertRedirect();

        $record = ChildNutrition::query()->where('resident_id', $resident->id)->first();
        $this->assertSame('50.00', (string) $record->newborn_length_cm);
        $this->assertSame('2025-02-01', $record->iron_1st_date?->format('Y-m-d'));

        $mam = ChildNutritionSfpOutcome::query()
            ->where('child_nutrition_id', $record->id)
            ->where('program', 'mam')
            ->where('outcome', 'identified')
            ->first();
        $this->assertNotNull($mam);
        $this->assertNotNull($mam->outcome_date);

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/name="newborn\[length\]"[^>]*value="50(\.00)?"/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="iron\[1st\]"[^>]*value="2025-02-01"/s',
            $html
        );
    }

    public function test_iron_only_payload_does_not_create_sfp_placeholder_rows(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), [
            'iron' => [
                '1st' => '2025-02-01',
                '2nd' => '',
                '3rd' => '',
            ],
        ])->assertRedirect();

        $record = ChildNutrition::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('2025-02-01', $record->iron_1st_date?->format('Y-m-d'));
        $this->assertNull($record->newborn_length_cm);
        $this->assertSame(0, ChildNutritionSfpOutcome::query()->where('child_nutrition_id', $record->id)->count());
    }

    public function test_empty_save_creates_no_child_nutrition_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), [
            'newborn' => ['length' => '', 'weight' => ''],
            'iron' => ['1st' => ''],
        ])->assertRedirect();

        $this->assertSame(0, ChildNutrition::query()->where('resident_id', $resident->id)->count());
    }

    public function test_empty_persisted_resident_shows_blank_form(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/name="newborn\[weight\]"[^>]*value=""/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="vitamin_a\[va-6-11\]"[^>]*value=""/s',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-child-nut-mam-identified-yes"[^>]*\bchecked\b/u',
            $html
        );
    }

    public function test_adult_member_still_reaches_child_nutrition_destination(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild([
            'birthday' => now()->subYears(35)->format('Y-m-d'),
            'first_name' => 'Adult',
            'relation' => 'Head',
        ]);
        $params = $this->routeParams($household, $resident);

        $this->get($this->showRoute($params))->assertOk();
        $this->post($this->storeRoute($params), $this->validPayload())->assertRedirect();
        $this->assertDatabaseHas('child_nutritions', ['resident_id' => $resident->id]);
    }

    public function test_destination_ui_contract_remains_intact_for_persisted_resident(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();

        $this->assertStringContainsString('id="lml-child-nut-newborn"', $html);
        $this->assertStringContainsString('id="lml-child-nut-iron"', $html);
        $this->assertStringContainsString('id="lml-child-nut-status-panel"', $html);
        $this->assertStringNotContainsString(
            'Preview/demo presentation only; no clinical derivation or persistence yet.',
            $html
        );
        $this->assertStringNotContainsString('data-child-nut-demo-status="iron-completed"', $html);
        $this->assertStringContainsString('data-child-nut-program-status="iron"', $html);
        $this->assertDoesNotMatchRegularExpression('/>(?:\s*)COMPLETED(?:\s*)</u', $html);
        $this->assertStringNotContainsString('July 20, 2026', $html);
        $this->assertStringNotContainsString('4.0 kg', $html);
        $this->assertStringNotContainsString('13.2 cm', $html);
        preg_match('/id="lml-child-nut-status-panel"[\s\S]*?<\/aside>/u', $html, $panelMatch);
        $this->assertNotEmpty($panelMatch[0] ?? null);
        $this->assertDoesNotMatchRegularExpression(
            '/data-child-nut-status-overall[\s\S]{0,280}\bNormal\b/u',
            $panelMatch[0]
        );
        $this->assertMatchesRegularExpression(
            '/data-child-nut-status-overall[\s\S]{0,280}\bNo record\b/u',
            $panelMatch[0]
        );
    }

    public function test_sfp_outcome_slots_are_unique_per_program_outcome(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $record = ChildNutrition::factory()->create(['resident_id' => $resident->id]);

        ChildNutritionSfpOutcome::factory()->create([
            'child_nutrition_id' => $record->id,
            'program' => 'mam',
            'outcome' => 'identified',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        ChildNutritionSfpOutcome::factory()->create([
            'child_nutrition_id' => $record->id,
            'program' => 'mam',
            'outcome' => 'identified',
        ]);
    }
}
