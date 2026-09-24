<?php

namespace Tests\Feature;

use App\Models\ChildNutrition;
use App\Models\Household;
use App\Models\Resident;
use App\Support\ChildBirthHistoryService;
use App\Support\ChildNutritionErdMode;
use App\Support\HouseholdProfilingPresenter;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ErdChildNutritionSchema;
use Tests\TestCase;

/**
 * Refinement 2.0-5 — Birth History persists through child_nutrition / child_nutritions.
 */
class ChildBirthHistoryNutritionPersistenceTest extends TestCase
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
    private function seedChild(string $householdNo = 'HH-850', string $memberNo = 'MB-850', array $residentOverrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 1',
            'street' => 'Birth St.',
        ]);

        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => 'Baby',
            'last_name' => 'History',
            'relation' => 'Son',
            'birthday' => now()->subMonths(4)->format('Y-m-d'),
            'sex' => 'Male',
        ], $residentOverrides));

        return ['household' => $household, 'resident' => $resident];
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'birth_weight' => '3.10',
            'birth_length' => '49.00',
            'pcab' => ChildBirthHistoryService::PCAB_AT_LEAST_2_DOSES,
            'breastfeeding_date' => '2024-06-01',
        ], $overrides);
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

    public function test_a_birth_history_saves_to_child_nutrition(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', $this->routeParams($household, $resident)), $this->validPayload())
            ->assertRedirect(route('household-profiling.members.child-immunization', $this->routeParams($household, $resident)));

        $this->assertDatabaseHas('child_nutritions', [
            'resident_id' => $resident->id,
            'newborn_weight_kg' => '3.10',
            'newborn_length_cm' => '49.00',
            'newborn_breastfeeding_date' => '2024-06-01',
        ]);
        $this->assertSame(1, ChildNutrition::query()->where('resident_id', $resident->id)->count());
    }

    public function test_b_saved_birth_history_reloads_on_member_and_child_care_views(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-851', 'MB-851');
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', $params), $this->validPayload())
            ->assertRedirect();

        $this->get(route('household-profiling.members.show', $params))
            ->assertOk()
            ->assertSee('Baby History', false);

        $imm = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('3.10', $imm);
        $this->assertStringContainsString('49.00', $imm);
        $this->assertStringContainsString('06/01/2024', $imm);

        $edit = $this->get(route('household-profiling.members.child-immunization.birth-history.edit', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('value="3.10"', $edit);
        $this->assertStringContainsString('value="49.00"', $edit);
        $this->assertStringContainsString('value="2024-06-01"', $edit);
    }

    public function test_c_updating_birth_history_updates_the_same_resident_record(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-852', 'MB-852');
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', $params), $this->validPayload())
            ->assertRedirect();

        $firstId = ChildNutrition::query()->where('resident_id', $resident->id)->value('id');
        $this->assertNotNull($firstId);

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', $params), $this->validPayload([
            'birth_weight' => '2.20',
            'birth_length' => '46.00',
            'breastfeeding_date' => '2024-05-15',
        ]))->assertRedirect();

        $this->assertSame(1, ChildNutrition::query()->where('resident_id', $resident->id)->count());
        $record = ChildNutrition::query()->where('resident_id', $resident->id)->firstOrFail();
        $this->assertSame($firstId, $record->id);
        $this->assertSame('2.20', (string) $record->newborn_weight_kg);
        $this->assertSame('46.00', (string) $record->newborn_length_cm);
        $this->assertTrue($record->newborn_breastfeeding_date->equalTo('2024-05-15'));
        $this->assertSame($household->id, $record->resident->household_id);
    }

    public function test_d_another_resident_birth_history_is_not_modified(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-853', 'MB-853');
        $other = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-854',
            'first_name' => 'Other',
            'last_name' => 'Child',
            'relation' => 'Daughter',
            'sex' => 'Female',
            'birthday' => now()->subMonths(8)->format('Y-m-d'),
        ]);

        ChildNutrition::factory()->create([
            'resident_id' => $other->id,
            'newborn_weight_kg' => '3.80',
            'newborn_length_cm' => '51.00',
            'newborn_breastfeeding_date' => '2024-01-01',
        ]);

        $this->post(
            route('household-profiling.members.child-immunization.birth-history.store', $this->routeParams($household, $resident)),
            $this->validPayload()
        )->assertRedirect();

        $otherRow = ChildNutrition::query()->where('resident_id', $other->id)->firstOrFail();
        $this->assertSame('3.80', (string) $otherRow->newborn_weight_kg);
        $this->assertSame('51.00', (string) $otherRow->newborn_length_cm);
    }

    public function test_e_cross_household_member_mismatch_is_rejected(): void
    {
        $householdA = Household::factory()->create(['household_no' => 'HH-854']);
        $householdB = Household::factory()->create(['household_no' => 'HH-855']);
        $resident = Resident::factory()->create([
            'household_id' => $householdA->id,
            'member_no' => 'MB-855',
        ]);

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', [
            'householdNo' => $householdB->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload())->assertNotFound();

        $this->assertSame(0, ChildNutrition::query()->where('resident_id', $resident->id)->count());
    }

    public function test_f_posted_resident_id_cannot_reassign_ownership(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-856', 'MB-856');
        $other = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-857',
        ]);

        $this->post(
            route('household-profiling.members.child-immunization.birth-history.store', $this->routeParams($household, $resident)),
            array_merge($this->validPayload(), [
                'resident_id' => $other->id,
                'child_nutrition_id' => 99999,
            ])
        )->assertSessionHasErrors(['resident_id', 'child_nutrition_id']);

        $this->assertSame(0, ChildNutrition::query()->count());
    }

    public function test_g_future_initiated_feeding_date_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-857', 'MB-858');

        $this->from(route('household-profiling.members.child-immunization.birth-history.edit', $this->routeParams($household, $resident)))
            ->post(
                route('household-profiling.members.child-immunization.birth-history.store', $this->routeParams($household, $resident)),
                $this->validPayload(['breastfeeding_date' => Carbon::tomorrow()->toDateString()])
            )
            ->assertSessionHasErrors('breastfeeding_date');

        $this->assertSame(0, ChildNutrition::query()->where('resident_id', $resident->id)->count());
    }

    public function test_g2_initiated_feeding_date_input_caps_at_today(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-878', 'MB-878');
        $today = Carbon::today()->toDateString();

        $html = $this->get(route(
            'household-profiling.members.child-immunization.birth-history.edit',
            $this->routeParams($household, $resident)
        ))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/id="lml-child-imm-bh-breastfeeding"[^>]*max="'.$today.'"|max="'.$today.'"[^>]*id="lml-child-imm-bh-breastfeeding"/',
            $html
        );
    }

    public function test_h_today_is_accepted(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-858', 'MB-859');
        $today = Carbon::today()->toDateString();

        $this->post(
            route('household-profiling.members.child-immunization.birth-history.store', $this->routeParams($household, $resident)),
            $this->validPayload(['breastfeeding_date' => $today])
        )->assertRedirect();

        $this->assertDatabaseHas('child_nutritions', [
            'resident_id' => $resident->id,
            'newborn_breastfeeding_date' => $today,
        ]);
    }

    public function test_i_past_date_is_accepted(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-859', 'MB-860');
        $past = Carbon::yesterday()->toDateString();

        $this->post(
            route('household-profiling.members.child-immunization.birth-history.store', $this->routeParams($household, $resident)),
            $this->validPayload(['breastfeeding_date' => $past])
        )->assertRedirect();

        $this->assertDatabaseHas('child_nutritions', [
            'resident_id' => $resident->id,
            'newborn_breastfeeding_date' => $past,
        ]);
    }

    public function test_j_missing_optional_values_remain_null(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-860', 'MB-861');

        $this->post(
            route('household-profiling.members.child-immunization.birth-history.store', $this->routeParams($household, $resident)),
            [
                'birth_weight' => '',
                'birth_length' => '',
                'pcab' => '',
                'breastfeeding_date' => '',
            ]
        )->assertRedirect();

        $record = ChildNutrition::query()->where('resident_id', $resident->id)->firstOrFail();
        $this->assertNull($record->newborn_weight_kg);
        $this->assertNull($record->newborn_length_cm);
        $this->assertNull($record->newborn_breastfeeding_date);
    }

    public function test_k_empty_data_does_not_render_fake_static_birth_history(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-861', 'MB-862');
        $params = $this->routeParams($household, $resident);

        $this->post(
            route('household-profiling.members.child-immunization.birth-history.store', $params),
            ['birth_weight' => '', 'birth_length' => '', 'breastfeeding_date' => '']
        )->assertRedirect();

        $presentation = HouseholdProfilingPresenter::memberFromModel($resident->fresh());
        $this->assertSame('', $presentation['birth_history']['weight']);
        $this->assertSame('', $presentation['birth_history']['status']);
        $this->assertSame('', $presentation['birth_history']['breastfeeding_date_display']);

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/data-birth-summary="weight"[^>]*>\s*No record\s*</u', $html);
        $this->assertMatchesRegularExpression('/data-birth-summary="status"[^>]*>\s*No record\s*</u', $html);
        $this->assertStringNotContainsString('3.1', $html);
        $this->assertStringNotContainsString('Kristine Reyes', $html);
    }

    public function test_l_child_nutrition_status_aside_stays_empty_without_static_stamps(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-862', 'MB-863');
        $params = $this->routeParams($household, $resident);

        $this->post(
            route('household-profiling.members.child-immunization.birth-history.store', $params),
            $this->validPayload(['birth_length' => '', 'breastfeeding_date' => ''])
        )->assertRedirect();

        $html = $this->get(route('household-profiling.members.child-nutrition', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-child-nut-status-overall', $html);
        $this->assertMatchesRegularExpression(
            '/data-child-nut-status-overall[\s\S]{0,500}No record/u',
            $html
        );
        $this->assertStringNotContainsString('COMPLETED', $html);
        $this->assertStringContainsString('3.10', $html);
    }

    public function test_m_existing_hh_prefixed_route_works(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-001', 'MB-864');
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', $params), $this->validPayload())
            ->assertRedirect(route('household-profiling.members.child-immunization', $params));

        $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->assertSee('3.10', false);
    }

    public function test_n_three_digit_household_route_works(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('121', 'MB-865');
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', $params), $this->validPayload())
            ->assertRedirect(route('household-profiling.members.child-immunization', [
                'householdNo' => '121',
                'memberId' => $resident->member_no,
            ]));

        $this->get(route('household-profiling.members.child-immunization', [
            'householdNo' => '121',
            'memberId' => $resident->member_no,
        ]))->assertOk()->assertSee('06/01/2024', false);

        $this->get(route('household-profiling.members.child-immunization.birth-history.edit', [
            'householdNo' => '121',
            'memberId' => $resident->member_no,
        ]))->assertOk()->assertSee('value="2024-06-01"', false);
    }

    public function test_o_repeated_saves_do_not_duplicate_child_nutrition_rows(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-863', 'MB-866');
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', $params), $this->validPayload())->assertRedirect();
        $this->post(route('household-profiling.members.child-immunization.birth-history.store', $params), $this->validPayload([
            'birth_weight' => '3.40',
        ]))->assertRedirect();

        $this->assertSame(1, ChildNutrition::query()->where('resident_id', $resident->id)->count());
    }

    public function test_erd_birth_history_persists_on_child_nutrition_without_duplicates(): void
    {
        $this->enableErdNutritionSchema();

        $household = Household::factory()->create([
            'household_no' => '121',
            'zone' => 'Zone 2',
            'street' => 'Erd St.',
        ]);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-870',
            'first_name' => 'Erd',
            'last_name' => 'Baby',
            'relation' => 'Son',
            'birthday' => now()->subMonths(3)->format('Y-m-d'),
            'sex' => 'Male',
        ]);
        $params = [
            'householdNo' => '121',
            'memberId' => $resident->member_no,
        ];

        $this->assertTrue(ChildNutritionErdMode::isActive());
        $this->assertFalse(Schema::hasTable('child_birth_histories'));

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', $params), [
            'birth_weight' => '3.10',
            'birth_length' => '49.00',
            'pcab' => ChildBirthHistoryService::PCAB_AT_LEAST_2_DOSES,
            'breastfeeding_date' => '2024-06-01',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('child_nutrition')->where('resident_id', $resident->id)->count());
        $row = DB::table('child_nutrition')->where('resident_id', $resident->id)->first();
        $this->assertSame('3.10', number_format((float) $row->weight_at_birth_kg, 2, '.', ''));
        $this->assertSame('49.00', number_format((float) $row->length_at_birth_cm, 2, '.', ''));
        $this->assertSame('2024-06-01', Carbon::parse($row->initiated_breastfeeding_date)->toDateString());

        $presentation = HouseholdProfilingPresenter::memberFromModel($resident->fresh());
        $this->assertSame('3.10', $presentation['birth_history']['weight']);
        $this->assertSame('Normal', $presentation['birth_history']['status']);
        $this->assertSame('06/01/2024', $presentation['birth_history']['breastfeeding_date_display']);
        $this->assertSame('', $presentation['birth_history']['pcab']);

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', $params), [
            'birth_weight' => '2.20',
            'birth_length' => '45.00',
            'breastfeeding_date' => Carbon::today()->toDateString(),
        ])->assertRedirect();

        $this->assertSame(1, DB::table('child_nutrition')->where('resident_id', $resident->id)->count());
        $updated = DB::table('child_nutrition')->where('resident_id', $resident->id)->first();
        $this->assertSame($row->child_nutrition_id, $updated->child_nutrition_id);
        $this->assertSame('2.20', number_format((float) $updated->weight_at_birth_kg, 2, '.', ''));

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('2.20', $html);
        $this->assertStringContainsString('Low Birth Weight', $html);
        $this->assertStringNotContainsString('At least 2 doses received', $html);
    }

    public function test_erd_future_date_rejected_and_empty_values_stay_null(): void
    {
        $this->enableErdNutritionSchema();

        $household = Household::factory()->create(['household_no' => 'HH-871']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-871',
            'relation' => 'Son',
            'birthday' => now()->subMonths(3)->format('Y-m-d'),
            'sex' => 'Male',
        ]);
        $params = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', $params), [
            'birth_weight' => '3.10',
            'breastfeeding_date' => Carbon::tomorrow()->toDateString(),
        ])->assertSessionHasErrors('breastfeeding_date');

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', $params), [
            'birth_weight' => '',
            'birth_length' => '',
            'breastfeeding_date' => '',
        ])->assertRedirect();

        $row = DB::table('child_nutrition')->where('resident_id', $resident->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->weight_at_birth_kg);
        $this->assertNull($row->length_at_birth_cm);
        $this->assertNull($row->initiated_breastfeeding_date);
    }

    private function enableErdNutritionSchema(): void
    {
        ErdChildNutritionSchema::ensure();
        Schema::dropIfExists('child_birth_histories');
        ChildNutritionErdMode::resetCachedState();
    }
}
