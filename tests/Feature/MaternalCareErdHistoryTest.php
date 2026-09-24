<?php

namespace Tests\Feature;

use App\Support\StaffRole;
use App\Models\Household;
use App\Models\Resident;
use App\Services\AnnouncementAudienceMatcher;
use App\Support\MaternalCareErdMode;
use App\Support\MaternalPregnancyService;
use App\Support\RiskAssessmentClinicalValues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\ErdMaternalCareSchema;
use Tests\TestCase;

/**
 * Refinement #18 — ERD maternal_care history persistence.
 * Runs on sqlite :memory: only.
 */
class MaternalCareErdHistoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedPersistedResident(array $overrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => $overrides['household_no'] ?? 'HH-980',
            'zone' => 'Zone 1',
            'street' => 'ERD Maternal St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-980',
            'first_name' => $overrides['first_name'] ?? 'Ana',
            'last_name' => $overrides['last_name'] ?? 'Erd',
            'middle_name' => null,
            'relation' => 'Daughter',
            'sex' => 'Female',
            'birthday' => '1992-03-15',
            'relationship_status' => 'Married',
            'fp_user' => 'No',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    /**
     * @return array<string, mixed>
     */
    private function validRegisterPayload(array $overrides = []): array
    {
        return array_merge([
            'lmp' => '2026-01-15',
            'gravida' => 2,
            'parity' => 1,
            'edd' => '2026-10-22',
            'weight' => 58.5,
            'height' => 160,
            'bmi' => 22.9,
            'blood_pressure' => '110/70',
        ], $overrides);
    }

    private function storeRoute(Household $household, Resident $resident): string
    {
        return route('household-profiling.members.maternal-care.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);
    }

    private function updateRoute(Household $household, Resident $resident, string $section): string
    {
        return route('household-profiling.members.maternal-care.update', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'section' => $section,
        ]);
    }

    private function registerPregnancy(Household $household, Resident $resident, array $overrides = []): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload($overrides))
            ->assertRedirect(route('household-profiling.members.maternal-care.index', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        ErdMaternalCareSchema::ensure();
        $this->assertTrue(MaternalCareErdMode::isPersistenceActive());
        $this->assertFalse(Schema::hasTable('maternal_pregnancies'));
    }

    public function test_erd_active_maternal_record_loads_correctly(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);

        $this->assertSame(1, DB::table('maternal_care')->count());
        $row = DB::table('maternal_care')->first();
        $this->assertSame(MaternalCareErdMode::STATUS_ACTIVE, $row->pregnancy_status);
        $this->assertSame($resident->id, (int) $row->resident_id);
        $this->assertEqualsWithDelta(22.9, (float) $row->bmi, 0.1);

        $html = $this->get(route('household-profiling.members.maternal-care.index', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-lml-mc-mode="overview"', $html);
        $this->assertStringContainsString('Active Pregnancy', $html);
        $this->assertStringContainsString('data-mc-history-link', $html);
    }

    public function test_completed_pregnancy_appears_in_past_maternal_records(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);

        DB::table('maternal_care')->update([
            'pregnancy_status' => MaternalCareErdMode::STATUS_COMPLETED,
            'updated_at' => now(),
        ]);
        DB::table('delivery_outcomes')->insert([
            'maternal_care_id' => (int) DB::table('maternal_care')->value('maternal_care_id'),
            'outcome' => 'FT',
            'date_time_of_delivery' => now()->subDays(60),
            'created_at' => now()->subDays(60),
            'updated_at' => now()->subDays(60),
        ]);
        MaternalCareErdMode::resetCachedState();

        $history = $this->get(route('household-profiling.members.maternal-care.history', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-mc-history-list', $history);
        $this->assertStringContainsString('data-mc-history-status="completed"', $history);
        $this->assertStringContainsString('Completed', $history);
        $this->assertStringContainsString('Pregnancy 1', $history);

        $index = $this->get(route('household-profiling.members.maternal-care.index', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('data-mc-has-history', $index);
        $this->assertStringContainsString('Register Maternal Record', $index);
        $this->assertStringContainsString('data-mc-register-cta', $index);
    }

    public function test_trans_out_appears_in_history_and_stays_distinct_from_completed(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU La Medalla',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2026-06-01',
        ])->assertRedirect(route('household-profiling.members.maternal-care.history', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]));

        $row = DB::table('maternal_care')->first();
        $this->assertSame(MaternalCareErdMode::STATUS_TRANS_OUT, $row->pregnancy_status);

        $history = $this->get(route('household-profiling.members.maternal-care.history', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-mc-history-status="transferred_out"', $history);
        $this->assertStringContainsString('Trans-Out', $history);
        $this->assertStringNotContainsString('data-mc-history-status="completed"', $history);

        $index = $this->get(route('household-profiling.members.maternal-care.index', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('data-mc-has-history', $index);
        $this->assertStringNotContainsString('data-mc-no-record', $index);
        $this->assertStringContainsString('Past Maternal Records', $index);
        $this->assertStringContainsString('Register Maternal Record', $index);
        $this->assertStringContainsString('data-mc-register-cta', $index);
    }

    public function test_history_list_shows_newest_closed_pregnancy_first_with_chronological_numbers(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->registerPregnancy($household, $resident, ['lmp' => '2025-01-15']);
        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2025-06-01',
        ])->assertRedirect();
        $olderId = (int) DB::table('maternal_care')->value('maternal_care_id');

        $this->registerPregnancy($household, $resident, [
            'lmp' => '2026-07-01',
            'edd' => '2027-04-07',
        ]);
        $newerId = (int) DB::table('maternal_care')
            ->where('pregnancy_status', MaternalCareErdMode::STATUS_ACTIVE)
            ->value('maternal_care_id');
        DB::table('maternal_care')->where('maternal_care_id', $newerId)->update([
            'pregnancy_status' => MaternalCareErdMode::STATUS_COMPLETED,
            'created_at' => '2026-08-01 12:00:00',
            'updated_at' => now(),
        ]);
        DB::table('delivery_outcomes')->insert([
            'maternal_care_id' => $newerId,
            'outcome' => 'FT',
            'date_time_of_delivery' => now()->subDays(60),
            'created_at' => now()->subDays(60),
            'updated_at' => now()->subDays(60),
        ]);
        DB::table('maternal_care')->where('maternal_care_id', $olderId)->update([
            'created_at' => '2025-03-01 08:00:00',
        ]);
        MaternalCareErdMode::resetCachedState();

        $this->assertNotSame($olderId, $newerId);

        $html = $this->get(route('household-profiling.members.maternal-care.history', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        preg_match_all('/data-mc-history-item="([^"]+)"/', $html, $itemIds);
        preg_match_all('/Pregnancy (\d+)/', $html, $numbers);

        $this->assertSame([
            sprintf('MC-%03d', $newerId),
            sprintf('MC-%03d', $olderId),
        ], $itemIds[1]);
        $this->assertSame(['2', '1'], $numbers[1]);
        $this->assertStringContainsString('data-mc-history-status="completed"', $html);
        $this->assertStringContainsString('data-mc-history-status="transferred_out"', $html);
        $this->assertStringNotContainsString('data-mc-history-status="active"', $html);
    }

    public function test_historical_pregnancy_is_read_only(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);
        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2026-06-01',
        ])->assertRedirect();

        $id = (int) DB::table('maternal_care')->value('maternal_care_id');
        $html = $this->get(route('household-profiling.members.maternal-care.history.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'pregnancyId' => sprintf('MC-%03d', $id),
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-mc-readonly="true"', $html);
        $this->assertStringContainsString('data-mc-history-record-status="transferred_out"', $html);
        $this->assertStringContainsString('Status: <strong>Trans-Out</strong>', $html);
        $this->assertStringNotContainsString('data-mc-edit-for="prenatal"', $html);
        $this->assertStringNotContainsString('data-mc-save-for="prenatal"', $html);
        $this->assertStringNotContainsString('data-mc-edit-for="delivery"', $html);
    }

    public function test_historical_pregnancy_cannot_be_updated_through_active_section_routes(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => ['date' => '2026-02-01', 'height' => '160', 'weight' => '59'],
            ],
        ])->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2026-06-01',
        ])->assertRedirect();

        $before = DB::table('prenatal_visits')->first();
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => ['date' => '2026-09-01', 'weight' => '99'],
            ],
        ])->assertForbidden();

        $after = DB::table('prenatal_visits')->first();
        $this->assertSame($before->visit_date, $after->visit_date);
        $this->assertSame((string) $before->weight_kg, (string) $after->weight_kg);
        $this->assertSame(MaternalCareErdMode::STATUS_TRANS_OUT, DB::table('maternal_care')->value('pregnancy_status'));
    }

    public function test_new_pregnancy_after_history_creates_a_separate_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident, ['lmp' => '2025-01-15']);
        $firstId = (int) DB::table('maternal_care')->value('maternal_care_id');
        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2025-06-01',
        ])->assertRedirect();

        $this->registerPregnancy($household, $resident, [
            'lmp' => '2026-07-01',
            'edd' => '2027-04-07',
        ]);

        $rows = DB::table('maternal_care')->where('resident_id', $resident->id)->orderBy('maternal_care_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(MaternalCareErdMode::STATUS_TRANS_OUT, $rows[0]->pregnancy_status);
        $this->assertSame(MaternalCareErdMode::STATUS_ACTIVE, $rows[1]->pregnancy_status);
        $this->assertSame('2025-01-15', $rows[0]->lmp_date);
        $this->assertSame('2026-07-01', $rows[1]->lmp_date);
        $this->assertNotSame($firstId, (int) $rows[1]->maternal_care_id);
    }

    public function test_existing_historical_pregnancy_remains_unchanged_after_new_pregnancy(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'date' => '2026-02-01',
                    'height' => '160',
                    'weight' => '59',
                    'bp' => '112/70',
                ],
            ],
        ])->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2026-06-01',
        ])->assertRedirect();

        $historical = DB::table('maternal_care')->first();
        $visit = DB::table('prenatal_visits')->first();

        $this->registerPregnancy($household, $resident, [
            'lmp' => '2026-08-01',
            'edd' => '2027-05-08',
        ]);
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => ['date' => '2026-08-20', 'weight' => '70'],
            ],
        ])->assertRedirect();

        $historicalAfter = DB::table('maternal_care')->where('maternal_care_id', $historical->maternal_care_id)->first();
        $visitAfter = DB::table('prenatal_visits')->where('prenatal_visit_id', $visit->prenatal_visit_id)->first();
        $this->assertSame($historical->lmp_date, $historicalAfter->lmp_date);
        $this->assertSame(MaternalCareErdMode::STATUS_TRANS_OUT, $historicalAfter->pregnancy_status);
        $this->assertSame($visit->visit_date, $visitAfter->visit_date);
        $this->assertSame((string) $visit->weight_kg, (string) $visitAfter->weight_kg);
        $this->assertSame(2, DB::table('prenatal_visits')->count());
    }

    public function test_second_active_pregnancy_is_rejected(): void
    {
        ['resident' => $resident] = $this->seedPersistedResident();
        $service = app(MaternalPregnancyService::class);
        $service->createForResident($resident, $this->validRegisterPayload());

        try {
            $service->createForResident($resident, $this->validRegisterPayload([
                'lmp' => '2026-08-01',
            ]));
            $this->fail('Expected ValidationException for duplicate active pregnancy.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lmp', $e->errors());
        }

        $this->assertSame(1, DB::table('maternal_care')->where('resident_id', $resident->id)->count());
        $this->assertSame(1, DB::table('maternal_care')->where('pregnancy_status', 'Active')->count());
    }

    public function test_cross_resident_history_isolation(): void
    {
        ['household' => $h1, 'resident' => $r1] = $this->seedPersistedResident([
            'household_no' => 'HH-981',
            'member_no' => 'MB-981',
            'first_name' => 'Owner',
            'last_name' => 'One',
        ]);
        ['household' => $h2, 'resident' => $r2] = $this->seedPersistedResident([
            'household_no' => 'HH-982',
            'member_no' => 'MB-982',
            'first_name' => 'Other',
            'last_name' => 'Two',
        ]);

        $this->registerPregnancy($h1, $r1);
        $this->put($this->updateRoute($h1, $r1, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2026-06-01',
        ])->assertRedirect();
        $this->registerPregnancy($h2, $r2, ['lmp' => '2026-02-01']);

        $ownerHistory = $this->get(route('household-profiling.members.maternal-care.history', [
            'householdNo' => $h1->household_no,
            'memberId' => $r1->member_no,
        ]))->assertOk()->getContent();
        $otherIndex = $this->get(route('household-profiling.members.maternal-care.index', [
            'householdNo' => $h2->household_no,
            'memberId' => $r2->member_no,
        ]))->assertOk()->getContent();
        $otherHistory = $this->get(route('household-profiling.members.maternal-care.history', [
            'householdNo' => $h2->household_no,
            'memberId' => $r2->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-mc-history-list', $ownerHistory);
        $this->assertStringContainsString('Owner One', $ownerHistory);
        $this->assertStringNotContainsString('Other Two', $ownerHistory);
        $this->assertStringContainsString('Other Two', $otherIndex);
        $this->assertStringContainsString('data-mc-history-empty', $otherHistory);
        $this->assertSame(1, DB::table('maternal_care')->where('resident_id', $r1->id)->count());
        $this->assertSame(1, DB::table('maternal_care')->where('resident_id', $r2->id)->count());
    }

    public function test_prenatal_sibling_visits_remain_preserved(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'date' => '2026-02-01',
                    'height' => '160',
                    'weight' => '59',
                    'bp' => '112/70',
                ],
                't2_v1' => [
                    'date' => '2026-02-15',
                    'height' => '160',
                    'weight' => '60',
                    'bp' => '114/72',
                ],
            ],
        ])->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => ['bp' => '118/76'],
            ],
        ])->assertRedirect();

        $v1 = DB::table('prenatal_visits')->where('trimester', '1st')->where('visit_number', 1)->first();
        $v2 = DB::table('prenatal_visits')->where('trimester', '2nd')->where('visit_number', 1)->first();
        $this->assertNotNull($v1);
        $this->assertNotNull($v2);
        $this->assertSame(118, (int) $v1->bp_systolic);
        $this->assertSame('2026-02-01', $v1->visit_date);
        $this->assertSame('160', (string) (float) $v1->height_cm);
        $this->assertSame('2026-02-15', $v2->visit_date);
        $this->assertSame(114, (int) $v2->bp_systolic);
        $this->assertSame('60', (string) (float) $v2->weight_kg);
    }

    public function test_historical_child_records_load_under_the_correct_maternal_care_id(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => ['date' => '2026-02-01', 'height' => '160', 'weight' => '59'],
            ],
        ])->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'hepatitis_b' => ['date' => '2026-02-15', 'result' => 'Negative'],
            'syphilis' => ['date' => '2026-02-16', 'result' => 'REACTIVE'],
            'cvc' => ['value' => '7.25'],
        ])->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2026-06-01',
        ])->assertRedirect();

        $historicalId = (int) DB::table('maternal_care')->orderBy('maternal_care_id')->value('maternal_care_id');
        $this->registerPregnancy($household, $resident, ['lmp' => '2026-08-01', 'edd' => '2027-05-08']);
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => ['date' => '2026-08-20', 'height' => '160', 'weight' => '70'],
            ],
        ])->assertRedirect();

        $this->assertSame($historicalId, (int) DB::table('prenatal_visits')->where('visit_date', '2026-02-01')->value('maternal_care_id'));
        $this->assertSame($historicalId, (int) DB::table('hepatitis_b_screening')->value('maternal_care_id'));
        $this->assertSame($historicalId, (int) DB::table('syphilis_screening')->value('maternal_care_id'));
        $this->assertSame($historicalId, (int) DB::table('cvc_screening')->value('maternal_care_id'));
        $activeId = (int) DB::table('maternal_care')->where('pregnancy_status', 'Active')->value('maternal_care_id');
        $this->assertSame($activeId, (int) DB::table('prenatal_visits')->where('visit_date', '2026-08-20')->value('maternal_care_id'));

        $show = $this->get(route('household-profiling.members.maternal-care.history.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'pregnancyId' => sprintf('MC-%03d', $historicalId),
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('value="2026-02-01"', $show);
        $this->assertStringContainsString('>23.0<', $show);
        $this->assertStringContainsString('value="2026-02-16"', $show);
        $this->assertStringContainsString('value="7.25"', $show);
        $this->assertStringNotContainsString('value="2026-08-20"', $show);
    }

    public function test_generated_bmi_is_never_explicitly_written(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $sql = [];
        DB::listen(static function ($query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });

        $this->registerPregnancy($household, $resident);
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'date' => '2026-02-01',
                    'height' => '160',
                    'weight' => '59',
                    'bmi' => '99.9',
                ],
            ],
        ])->assertRedirect();

        foreach ($sql as $statement) {
            if (! str_contains($statement, 'maternal_care') && ! str_contains($statement, 'prenatal_visits')) {
                continue;
            }
            if (! str_contains($statement, 'insert') && ! str_contains($statement, 'update')) {
                continue;
            }
            $this->assertStringNotContainsString('bmi', $statement, $statement);
        }

        $header = DB::table('maternal_care')->first();
        $visit = DB::table('prenatal_visits')->first();
        $this->assertEqualsWithDelta(22.9, (float) $header->bmi, 0.1);
        $this->assertEqualsWithDelta(23.0, (float) $visit->bmi, 0.1);
    }

    public function test_historical_bmi_remains_derived_from_stored_height_and_weight(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => ['height' => '160', 'weight' => '59', 'bmi' => '1.0'],
                't2_v1' => ['height' => '160', 'weight' => '55', 'bmi' => '99.9'],
            ],
        ])->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2026-06-01',
        ])->assertRedirect();

        $id = (int) DB::table('maternal_care')->value('maternal_care_id');
        $t1 = DB::table('prenatal_visits')->where('trimester', '1st')->where('visit_number', 1)->first();
        $t2 = DB::table('prenatal_visits')->where('trimester', '2nd')->where('visit_number', 1)->first();
        $this->assertEqualsWithDelta(23.0, (float) $t1->bmi, 0.1);
        $this->assertEqualsWithDelta(21.5, (float) $t2->bmi, 0.1);

        $html = $this->get(route('household-profiling.members.maternal-care.history.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'pregnancyId' => sprintf('MC-%03d', $id),
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('>23.0<', $html);
        $this->assertStringContainsString('>21.5<', $html);
    }

    public function test_missing_historical_height_or_weight_produces_unavailable_bmi(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => ['height' => '', 'weight' => '59', 'bmi' => '22.0'],
                't2_v1' => ['height' => '160', 'weight' => '', 'bmi' => '22.0'],
            ],
        ])->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2026-06-01',
        ])->assertRedirect();

        $t1 = DB::table('prenatal_visits')->where('trimester', '1st')->where('visit_number', 1)->first();
        $t2 = DB::table('prenatal_visits')->where('trimester', '2nd')->where('visit_number', 1)->first();
        $this->assertNull($t1->bmi);
        $this->assertNull($t2->bmi);

        $id = (int) DB::table('maternal_care')->value('maternal_care_id');
        $html = $this->get(route('household-profiling.members.maternal-care.history.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'pregnancyId' => sprintf('MC-%03d', $id),
        ]))->assertOk()->getContent();
        $this->assertStringNotContainsString('value="22.0"', $html);
    }

    public function test_erd_prenatal_display_bmi_matches_shared_calculator(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-984',
            'member_no' => 'MB-984',
        ]);
        $this->registerPregnancy($household, $resident);
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'date' => '2026-02-01',
                    'height' => '160',
                    'weight' => '59',
                    'bmi' => '99.9',
                ],
                't2_v1' => [
                    'height' => '160',
                    'weight' => '55',
                    'bmi' => '1.0',
                ],
            ],
        ])->assertRedirect();

        $expectedT1 = RiskAssessmentClinicalValues::calculateBmi(160, 59);
        $expectedT2 = RiskAssessmentClinicalValues::calculateBmi(160, 55);
        $this->assertSame('23.0', $expectedT1);
        $this->assertSame('21.5', $expectedT2);

        $t1 = DB::table('prenatal_visits')->where('trimester', '1st')->where('visit_number', 1)->first();
        $t2 = DB::table('prenatal_visits')->where('trimester', '2nd')->where('visit_number', 1)->first();
        $this->assertEqualsWithDelta((float) $expectedT1, (float) $t1->bmi, 0.1);
        $this->assertEqualsWithDelta((float) $expectedT2, (float) $t2->bmi, 0.1);

        $html = $this->get(route('household-profiling.members.maternal-care.prenatal', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('>'.$expectedT1.'<', $html);
        $this->assertStringContainsString('>'.$expectedT2.'<', $html);
        $this->assertDoesNotMatchRegularExpression('/name="visits\[[^\]]+\]\[bmi\]"/', $html);
        $this->assertStringNotContainsString('>99.9<', $html);
    }

    public function test_erd_registration_ignores_submitted_bmi_and_does_not_write_generated_column(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-985',
            'member_no' => 'MB-985',
        ]);

        $sql = [];
        DB::listen(static function ($query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });

        $this->registerPregnancy($household, $resident, [
            'weight' => 60,
            'height' => 161,
            'bmi' => '99.9',
        ]);

        foreach ($sql as $statement) {
            if (! str_contains($statement, 'maternal_care') && ! str_contains($statement, 'prenatal_visits')) {
                continue;
            }
            if (! str_contains($statement, 'insert') && ! str_contains($statement, 'update')) {
                continue;
            }
            $this->assertStringNotContainsString('bmi', $statement, $statement);
        }

        $expected = RiskAssessmentClinicalValues::calculateBmi(161, 60);
        $header = DB::table('maternal_care')->first();
        $visit = DB::table('prenatal_visits')->first();
        $this->assertNotSame('99.9', (string) $header->bmi);
        $this->assertEqualsWithDelta((float) $expected, (float) $header->bmi, 0.1);
        $this->assertEqualsWithDelta((float) $expected, (float) $visit->bmi, 0.1);

        $html = $this->get(route('household-profiling.members.maternal-care.prenatal', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('>'.$expected.'<', $html);
        $this->assertStringNotContainsString('>99.9<', $html);
    }

    public function test_announcement_active_maternal_does_not_require_maternal_pregnancies(): void
    {
        ['resident' => $active] = $this->seedPersistedResident([
            'household_no' => 'HH-983',
            'member_no' => 'MB-983',
        ]);
        app(MaternalPregnancyService::class)->createForResident($active, $this->validRegisterPayload());

        $keys = (new AnnouncementAudienceMatcher)->matchingResidentKeys([
            'target_group' => 'active_maternal',
            'zone_mode' => 'all',
            'as_of' => now(),
        ]);

        $this->assertTrue($keys->contains($active->getKey()));
    }

    public function test_delivery_sparse_update_does_not_wipe_unspecified_fields(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'delivery_type' => 'VD',
            'birth_weight' => '3.2',
            'status' => 'Live birth',
            'place' => 'public',
        ])->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'facility_name' => 'RHU La Medalla',
        ])->assertRedirect();

        $row = DB::table('delivery_outcomes')->first();
        $this->assertSame('FT', $row->outcome);
        $this->assertSame('VD', $row->delivery_type);
        $this->assertEqualsWithDelta(3.2, (float) $row->birth_weight_kg, 0.01);
        $this->assertSame('Live birth', $row->status);
        $this->assertSame('Public Health Facility', $row->place_of_delivery);
        $this->assertSame('RHU La Medalla', $row->facility_name);
    }

    public function test_get_register_redirects_when_an_active_pregnancy_exists(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-986',
            'member_no' => 'MB-986',
        ]);
        $this->registerPregnancy($household, $resident);

        $this->get(route('household-profiling.members.maternal-care.register', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertRedirect(route('household-profiling.members.maternal-care.index', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]));

        $this->assertSame(1, DB::table('maternal_care')->where('resident_id', $resident->id)->count());
        $this->assertSame(1, DB::table('maternal_care')->where('pregnancy_status', 'Active')->count());
    }

    public function test_post_register_while_active_does_not_create_a_second_episode(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-987',
            'member_no' => 'MB-987',
        ]);
        $this->registerPregnancy($household, $resident, ['lmp' => '2026-01-15']);

        $this->from(route('household-profiling.members.maternal-care.register', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->post($this->storeRoute($household, $resident), $this->validRegisterPayload([
            'lmp' => '2026-08-01',
            'edd' => '2027-05-08',
        ]))->assertRedirect(route('household-profiling.members.maternal-care.index', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]));

        $this->assertSame(1, DB::table('maternal_care')->where('resident_id', $resident->id)->count());
        $this->assertSame('2026-01-15', DB::table('maternal_care')->value('lmp_date'));
        $this->assertSame(MaternalCareErdMode::STATUS_ACTIVE, DB::table('maternal_care')->value('pregnancy_status'));
    }
}
