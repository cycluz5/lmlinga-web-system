<?php

namespace Tests\Feature;

use App\Models\ChildNutrition;
use App\Models\Household;
use App\Models\MaternalPregnancy;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Models\TimbangRecord;
use App\Support\Offline\OfflineOperationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class NutritionalStatusTimbangPersistenceTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(\App\Support\StaffRole::BHW);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedMember(
        string $householdNo = 'HH-1212',
        string $memberNo = 'MB-1212',
        array $overrides = []
    ): array {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 1',
        ]);
        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => 'Timbang',
            'last_name' => 'Child',
            'relation' => 'Son',
            'birthday' => now()->subMonths(18)->format('Y-m-d'),
            'sex' => 'Male',
        ], $overrides));

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

    public function test_migration_creates_expected_schema_without_unregistered_or_bmi(): void
    {
        $this->assertTrue(Schema::hasTable('timbang_records'));
        foreach ([
            'timbang_id',
            'resident_id',
            'measurement_date',
            'weight_kg',
            'height_cm',
            'muac_cm',
            'weight_for_age',
            'height_for_age',
            'weight_for_height',
            'remarks',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('timbang_records', $column), $column);
        }
        $this->assertFalse(Schema::hasColumn('timbang_records', 'bmi'));
        $this->assertFalse(Schema::hasColumn('timbang_records', 'unregistered_child_id'));

        $residentKey = Schema::hasColumn('residents', 'resident_id') && ! Schema::hasColumn('residents', 'id')
            ? 'resident_id'
            : 'id';
        $this->assertTrue(Schema::hasColumn('residents', $residentKey));
        $fkSql = (string) (DB::selectOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['timbang_records']
        )->sql ?? '');
        $this->assertMatchesRegularExpression(
            '/references\s+["`]?residents["`]?\s*\(\s*["`]?'.preg_quote($residentKey, '/').'["`]?\s*\)/i',
            $fkSql
        );
        $this->assertMatchesRegularExpression('/on delete restrict/i', $fkSql);

        $migration = include database_path('migrations/2026_09_12_100000_create_timbang_records_table.php');
        $migration->up();
        $this->assertTrue(Schema::hasTable('timbang_records'));
    }

    public function test_member_card_has_edit_and_empty_state_without_records(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $params = $this->routeParams($household, $resident);

        $html = $this->get(route('household-profiling.members.show', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Nutritional Status', $html);
        $this->assertStringContainsString('data-hh-nav="edit-nutrition"', $html);
        $this->assertStringContainsString('aria-label="View nutritional status history for Timbang Child"', $html);
        // The member profile's nutrition link goes to the history page —
        // "Add Measurement" is reachable only via that page's own "Add
        // Record" button, not directly from the member profile.
        $this->assertStringContainsString(route('households.residents.nutritional-status', [
            'householdNo' => $household->household_no,
            'residentId' => $resident->id,
        ]), $html);
        $this->assertStringNotContainsString(route('households.residents.nutritional-status.create', [
            'householdNo' => $household->household_no,
            'residentId' => $resident->id,
        ]), $html);
        $this->assertStringContainsString('data-hh-nav="edit-member"', $html);
        $this->assertDoesNotMatchRegularExpression('/id="lml-hh-mv-nutrition"[\s\S]*20\.8/u', $html);
    }

    public function test_create_history_keeps_first_row_and_latest_feeds_card(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $params = $this->routeParams($household, $resident);
        $store = route('household-profiling.members.nutritional-status.store', $params);

        $this->post($store, [
            'measurement_date' => '2026-09-01',
            'weight_kg' => '20.00',
            'height_cm' => '110.00',
        ])->assertRedirect(route('household-profiling.members.nutritional-status', $params));

        $this->post($store, [
            'measurement_date' => '2026-09-12',
            'weight_kg' => '21.00',
            'height_cm' => '111.00',
            'muac_cm' => '14.5',
        ])->assertRedirect();

        $this->assertSame(2, TimbangRecord::query()->where('resident_id', $resident->id)->count());
        $first = TimbangRecord::query()->where('resident_id', $resident->id)->orderBy('timbang_id')->first();
        $this->assertSame('20.00', number_format((float) $first->weight_kg, 2, '.', ''));
        $this->assertSame('2026-09-01', $first->measurement_date->format('Y-m-d'));

        // 18-month-old (seedMember default birthday): the member-view card is
        // age-adaptive — a baby shows Weight/Height/Status (Overall
        // Nutritional Status), not a BMI figure, which only applies at 5y+.
        $card = $this->get(route('household-profiling.members.show', $params))->assertOk()->getContent();
        $this->assertStringContainsString('21 kg', $card);
        $this->assertStringContainsString('111 cm', $card);
        $this->assertStringContainsString('>Status<', $card);
        $this->assertStringContainsString('At Risk', $card);
        $this->assertStringNotContainsString('>BMI<', $card);

        $history = $this->get(route('household-profiling.members.nutritional-status', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Sep 01, 2026', $history);
        $this->assertStringContainsString('Sep 12, 2026', $history);
        $this->assertStringContainsString('21 kg', $history);
        $this->assertStringContainsString('20 kg', $history);
        $this->assertStringContainsString('Normal', $history);
        $this->assertStringContainsString('Weight Progress', $history);
        $this->assertStringContainsString('data-weight-progress="+1 kg"', $history);
    }

    public function test_same_date_uses_pk_desc_as_latest(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('HH-1213', 'MB-1213');
        $params = $this->routeParams($household, $resident);
        $store = route('household-profiling.members.nutritional-status.store', $params);

        $this->post($store, [
            'measurement_date' => '2026-09-12',
            'weight_kg' => '10.00',
            'height_cm' => '80.00',
        ])->assertRedirect();
        $this->post($store, [
            'measurement_date' => '2026-09-12',
            'weight_kg' => '10.50',
            'height_cm' => '80.50',
        ])->assertRedirect();

        $html = $this->get(route('household-profiling.members.show', $params))->assertOk()->getContent();
        $this->assertStringContainsString('10.5 kg', $html);
        $this->assertStringContainsString('80.5 cm', $html);
    }

    public function test_future_date_and_invalid_numbers_are_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('HH-1214', 'MB-1214');
        $store = route('household-profiling.members.nutritional-status.store', $this->routeParams($household, $resident));

        $this->post($store, [
            'measurement_date' => now()->addDay()->toDateString(),
            'weight_kg' => '20',
        ])->assertSessionHasErrors('measurement_date');

        $this->post($store, [
            'measurement_date' => '2026-09-01',
            'weight_kg' => 'abc',
        ])->assertSessionHasErrors('weight_kg');

        $this->post($store, [
            'measurement_date' => '2026-09-01',
            'weight_kg' => '0',
        ])->assertSessionHasErrors('weight_kg');

        $this->post($store, [
            'measurement_date' => '2026-09-01',
            'weight_kg' => '-1',
        ])->assertSessionHasErrors('weight_kg');

        $this->post($store, [
            'measurement_date' => '2026-09-01',
        ])->assertSessionHasErrors('weight_kg');

        $this->assertSame(0, TimbangRecord::query()->count());
    }

    public function test_bmi_and_identity_injection_are_prohibited(): void
    {
        $a = $this->seedMember('HH-1215', 'MB-1215');
        $b = $this->seedMember('HH-1216', 'MB-1216', [
            'first_name' => 'Other',
            'last_name' => 'Kid',
        ]);

        $this->post(
            route('household-profiling.members.nutritional-status.store', $this->routeParams($a['household'], $a['resident'])),
            [
                'measurement_date' => '2026-09-01',
                'weight_kg' => '20',
                'height_cm' => '110',
                'bmi' => '99',
                'resident_id' => $b['resident']->id,
                'timbang_id' => 999,
                'household_id' => $b['household']->id,
                'unregistered_child_id' => 1,
            ]
        )->assertSessionHasErrors(['bmi', 'resident_id', 'timbang_id', 'household_id', 'unregistered_child_id']);

        $this->assertSame(0, TimbangRecord::query()->count());
    }

    public function test_resident_isolation(): void
    {
        $a = $this->seedMember('HH-1217', 'MB-1217');
        $b = $this->seedMember('HH-1218', 'MB-1218', [
            'first_name' => 'Other',
            'last_name' => 'Kid',
        ]);

        $this->post(
            route('household-profiling.members.nutritional-status.store', $this->routeParams($a['household'], $a['resident'])),
            [
                'measurement_date' => '2026-09-01',
                'weight_kg' => '20',
                'height_cm' => '110',
            ]
        )->assertRedirect();

        $bShow = $this->get(route(
            'household-profiling.members.show',
            $this->routeParams($b['household'], $b['resident'])
        ))->assertOk()->getContent();
        $this->assertStringNotContainsString('20 kg', $bShow);

        $bHistory = $this->get(route(
            'household-profiling.members.nutritional-status',
            $this->routeParams($b['household'], $b['resident'])
        ))->assertOk()->getContent();
        $this->assertStringNotContainsString('20 kg', $bHistory);
        $this->assertSame(0, TimbangRecord::query()->where('resident_id', $b['resident']->id)->count());
    }

    public function test_does_not_write_other_health_tables_and_computes_weight_for_age_automatically(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('HH-1219', 'MB-1219');

        $this->post(
            route('household-profiling.members.nutritional-status.store', $this->routeParams($household, $resident)),
            [
                'measurement_date' => '2026-09-01',
                'weight_kg' => '20',
                'height_cm' => '110',
            ]
        )->assertRedirect();

        $row = TimbangRecord::query()->first();
        // 18-month-old (seedMember default birthday), 20kg/110cm: both are
        // far above the NNC reference's normal range for that age — so both
        // classify as Overweight/Tall rather than Normal. This is
        // auto-computed server-side and never taken from client input.
        $this->assertSame('Overweight', $row->weight_for_age);
        $this->assertSame('Tall', $row->height_for_age);
        // Weight-for-Length/Height (Phase 1 completion): 20kg at 110cm,
        // 18 months old (recumbent-length table) -> exactly at SD+1 -> Normal.
        $this->assertSame('Normal', $row->weight_for_height);
        // MUAC was not submitted this visit — applicable band, but no data,
        // so it stays null (never invented as 'N/A' or a guessed status).
        $this->assertNull($row->muac_status);
        // Worst applicable result wins: Weight-for-Age Overweight -> At Risk.
        $this->assertSame('At Risk', $row->overall_nutritional_status);

        $this->assertSame(0, RiskAssessment::query()->count());
        $this->assertSame(0, MaternalPregnancy::query()->count());
        if (Schema::hasTable('prenatal_visits')) {
            $this->assertSame(0, DB::table('prenatal_visits')->count());
        }
        if (Schema::hasTable('maternal_care')) {
            $this->assertSame(0, DB::table('maternal_care')->count());
        }
        $this->assertSame(0, ChildNutrition::query()->count());
        $this->assertFalse(Schema::hasColumn('timbang_records', 'bmi'));
    }

    public function test_form_exposes_measurement_fields_without_manual_classification_inputs(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('HH-1220', 'MB-1220');
        $html = $this->get(route(
            'household-profiling.members.nutritional-status.create',
            $this->routeParams($household, $resident)
        ))->assertOk()->getContent();

        $this->assertStringContainsString('name="measurement_date"', $html);
        $this->assertStringContainsString('name="weight_kg"', $html);
        $this->assertStringContainsString('name="height_cm"', $html);
        $this->assertStringContainsString('name="muac_cm"', $html);
        $this->assertStringContainsString('name="remarks"', $html);

        // Manual classification inputs are gone — these are always server-computed now.
        $this->assertStringNotContainsString('name="weight_for_age"', $html);
        $this->assertStringNotContainsString('name="height_for_age"', $html);
        $this->assertStringNotContainsString('name="weight_for_height"', $html);
        $this->assertStringNotContainsString('name="bmi"', $html);

        // 18-month-old seedMember() resident: MUAC band is enabled by default.
        $this->assertStringNotContainsString('disabled', $this->muacFieldMarkup($html));

        $this->assertStringContainsString('max="'.now()->toDateString().'"', $html);
        $this->assertStringContainsString('data-offline-health-action="timbang_record_store"', $html);
        $this->assertStringContainsString('data-offline-parent-member-no="MB-1220"', $html);
    }

    public function test_form_disables_muac_for_infant_under_six_months(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('HH-1222', 'MB-1222', [
            'birthday' => now()->subMonths(3)->format('Y-m-d'),
        ]);
        $html = $this->get(route(
            'household-profiling.members.nutritional-status.create',
            $this->routeParams($household, $resident)
        ))->assertOk()->getContent();

        $this->assertStringContainsString('disabled', $this->muacFieldMarkup($html));
        $this->assertStringContainsString('Not applicable below 6 months.', $html);
    }

    public function test_form_disables_muac_and_shows_bmi_for_adult(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('HH-1223', 'MB-1223', [
            'birthday' => now()->subYears(40)->format('Y-m-d'),
        ]);
        $html = $this->get(route(
            'household-profiling.members.nutritional-status.create',
            $this->routeParams($household, $resident)
        ))->assertOk()->getContent();

        $this->assertStringContainsString('disabled', $this->muacFieldMarkup($html));
        $this->assertStringContainsString('data-timbang-bmi-field', $html);
        $this->assertDoesNotMatchRegularExpression('/data-timbang-bmi-field[^>]*\shidden/', $html);
    }

    private function muacFieldMarkup(string $html): string
    {
        preg_match('/<input[^>]*data-timbang-muac[^>]*>/', $html, $matches);

        return $matches[0] ?? '';
    }

    public function test_offline_replay_creates_timbang_row(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember('HH-1221', 'MB-1221');

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'timbang_record_store',
                'measurement_date' => '2026-09-01',
                'weight_kg' => '20.5',
                'height_cm' => '109',
            ],
            ['parent_server' => [
                'household_id' => $household->getKey(),
                'household_no' => $household->household_no,
                'resident_id' => $resident->getKey(),
                'member_no' => $resident->member_no,
            ]],
        ));

        $response->assertOk();
        $this->assertSame(1, TimbangRecord::query()->where('resident_id', $resident->getKey())->count());
        $this->assertSame(0, RiskAssessment::query()->count());
    }
}
