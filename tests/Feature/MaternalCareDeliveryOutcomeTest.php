<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\MaternalCareErdMode;
use App\Support\Offline\OfflineOperationType;
use App\Support\StaffRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErdMaternalCareSchema;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

/**
 * FR-25 — Delivery outcome mapping, pregnancy completion, and history.
 * Runs on sqlite :memory: only. Does not touch lmlinga_erd_reference.
 */
class MaternalCareDeliveryOutcomeTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ErdMaternalCareSchema::ensure();
        $this->assertTrue(MaternalCareErdMode::isPersistenceActive());
        $this->actingAsStaff(StaffRole::BHW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedMember(array $overrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => $overrides['household_no'] ?? 'HH-2501',
            'zone' => 'Zone 1',
            'street' => 'FR-25 St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-2501',
            'first_name' => $overrides['first_name'] ?? 'Lina',
            'last_name' => $overrides['last_name'] ?? 'Outcome',
            'middle_name' => null,
            'relation' => 'Daughter',
            'sex' => 'Female',
            'birthday' => $overrides['birthday'] ?? '1992-03-15',
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

    private function registerPregnancy(Household $household, Resident $resident): void
    {
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();
    }

    private function maternalCareId(): int
    {
        return (int) DB::table('maternal_care')->value('maternal_care_id');
    }

    private function pregnancyId(): string
    {
        return sprintf('MC-%03d', $this->maternalCareId());
    }

    public function test_fd_round_trip_completes_without_fetal_death_date(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->registerPregnancy($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FD',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('delivery_outcomes')->count());
        $row = DB::table('delivery_outcomes')->first();
        $this->assertSame('FD', $row->outcome);
        $this->assertNull($row->date_terminated);
        $this->assertNull($row->date_time_of_delivery);

        $care = DB::table('maternal_care')->first();
        $this->assertSame(MaternalCareErdMode::STATUS_COMPLETED, $care->pregnancy_status);

        $delivery = $this->get(route('household-profiling.members.maternal-care.delivery', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="fetal_death_date"', $delivery);
        $this->assertStringNotContainsString('Date of Fetal Death', $delivery);
        $this->assertStringNotContainsString('id="lml-mc-abortion-date"', $delivery);
        $this->assertStringNotContainsString('data-mc-delivery-details" aria-disabled', $delivery);
        $this->assertStringNotContainsString('data-mc-place-of-delivery" aria-disabled', $delivery);

        // No delivery date was given, so there is nothing to gate 42 days
        // from: the episode stays out of Pregnancy History and the journey
        // (including Postnatal Care) remains reachable from the index, same
        // as an FT/PT completion with no recorded date would behave.
        $history = $this->get(route('household-profiling.members.maternal-care.history', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('No Record Yet', $history);

        $index = $this->get(route('household-profiling.members.maternal-care.index', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('data-lml-mc-mode="overview"', $index);
        $this->assertStringContainsString('data-mc-service="postnatal"', $index);
    }

    public function test_ab_round_trip_maps_date_terminated_completes_and_hydrates(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2502',
            'member_no' => 'MB-2502',
        ]);
        $this->registerPregnancy($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'AB',
            'date_terminated' => '2026-04-03',
        ])->assertRedirect();

        $row = DB::table('delivery_outcomes')->first();
        $this->assertSame('AB', $row->outcome);
        $this->assertSame('2026-04-03', substr((string) $row->date_terminated, 0, 10));
        $this->assertSame(MaternalCareErdMode::STATUS_COMPLETED, DB::table('maternal_care')->value('pregnancy_status'));

        $delivery = $this->get(route('household-profiling.members.maternal-care.delivery', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/id="lml-mc-date-terminated"[^>]*value="2026-04-03"/',
            $delivery
        );
        $this->assertStringNotContainsString('name="fetal_death_date"', $delivery);

        $history = $this->get(route('household-profiling.members.maternal-care.history', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('data-mc-history-date="2026-04-03"', $history);
        $this->assertStringContainsString('Abortion', $history);
    }

    public function test_ft_and_pt_complete_parent_and_show_delivery_datetime_in_history(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2503',
            'member_no' => 'MB-2503',
        ]);
        $this->registerPregnancy($household, $resident);

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'delivery_type' => 'VD',
            'datetime' => '2026-10-20T08:30',
            'status' => 'Live birth',
        ])->assertRedirect();

        $ft = DB::table('delivery_outcomes')->first();
        $this->assertSame('FT', $ft->outcome);
        $this->assertNotNull($ft->date_time_of_delivery);
        $this->assertSame(MaternalCareErdMode::STATUS_COMPLETED, DB::table('maternal_care')->value('pregnancy_status'));

        Carbon::setTestNow(Carbon::parse('2026-12-01')->startOfDay());
        $history = $this->get(route('household-profiling.members.maternal-care.history', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('Full Term', $history);
        $this->assertStringContainsString('data-mc-history-date="2026-10-20"', $history);

        DB::table('maternal_care')->update([
            'pregnancy_status' => MaternalCareErdMode::STATUS_ACTIVE,
            'updated_at' => now(),
        ]);
        DB::table('delivery_outcomes')->delete();
        MaternalCareErdMode::resetCachedState();

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'PT',
            'delivery_type' => 'CS',
            'datetime' => '2026-08-01T06:00',
        ])->assertRedirect();

        $pt = DB::table('delivery_outcomes')->first();
        $this->assertSame('PT', $pt->outcome);
        $this->assertSame(MaternalCareErdMode::STATUS_COMPLETED, DB::table('maternal_care')->value('pregnancy_status'));
        $this->assertSame(1, DB::table('delivery_outcomes')->count());

        Carbon::setTestNow(Carbon::parse('2026-09-12')->startOfDay());
        $historyPt = $this->get(route('household-profiling.members.maternal-care.history', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('Pre-Term', $historyPt);
        $this->assertStringContainsString('data-mc-history-date="2026-08-01"', $historyPt);
        Carbon::setTestNow();
    }

    public function test_outcome_correction_updates_same_row_and_stays_completed(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2504',
            'member_no' => 'MB-2504',
        ]);
        $this->registerPregnancy($household, $resident);

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'delivery_type' => 'VD',
            'datetime' => '2026-10-20T08:30',
            'birth_weight' => '3.2',
        ])->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FD',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('delivery_outcomes')->count());
        $row = DB::table('delivery_outcomes')->first();
        $this->assertSame('FD', $row->outcome);
        $this->assertNull($row->date_terminated);
        $this->assertSame('VD', $row->delivery_type);
        $this->assertNotNull($row->date_time_of_delivery);
        $this->assertSame(MaternalCareErdMode::STATUS_COMPLETED, DB::table('maternal_care')->value('pregnancy_status'));

        // Past the 42-day gate from the retained delivery datetime, the
        // corrected outcome (not the original FT) is what shows in history.
        Carbon::setTestNow(Carbon::parse('2026-12-01')->startOfDay());
        $history = $this->get(route('household-profiling.members.maternal-care.history', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('Fetal Death', $history);
        $this->assertStringNotContainsString('Full Term', $history);

        $delivery = $this->get(route('household-profiling.members.maternal-care.delivery', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="lml-mc-newborn-0-weight"[^>]*value="3.2"/', $delivery);

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'AB',
            'date_terminated' => '2026-09-15',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('delivery_outcomes')->count());
        $ab = DB::table('delivery_outcomes')->first();
        $this->assertSame('AB', $ab->outcome);
        $this->assertSame('2026-09-15', substr((string) $ab->date_terminated, 0, 10));
        $this->assertSame(MaternalCareErdMode::STATUS_COMPLETED, DB::table('maternal_care')->value('pregnancy_status'));
    }

    public function test_active_workflow_closes_and_historical_rows_are_preserved(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2505',
            'member_no' => 'MB-2505',
        ]);
        $this->registerPregnancy($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => ['date' => '2026-02-01', 'height' => '160', 'weight' => '59'],
            ],
        ])->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => [
                'v1' => ['date' => '2026-02-11', 'tablets' => 30],
            ],
        ])->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'hepatitis_b' => ['date' => '2026-02-15', 'result' => 'Negative'],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('prenatal_visits')->count());
        $this->assertSame(1, DB::table('ifa_supplementation')->count());
        $this->assertSame(1, DB::table('hepatitis_b_screening')->count());

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FD',
        ])->assertRedirect();

        $this->from($this->updateRoute($household, $resident, 'prenatal'))
            ->put($this->updateRoute($household, $resident, 'prenatal'), [
                'visits' => [
                    't1_v1' => ['date' => '2026-03-01', 'height' => '160', 'weight' => '60'],
                ],
            ])->assertForbidden();
        $this->from($this->updateRoute($household, $resident, 'supplementations'))
            ->put($this->updateRoute($household, $resident, 'supplementations'), [
                'deworming_date' => '2026-03-02',
            ])->assertForbidden();
        $this->from($this->updateRoute($household, $resident, 'laboratory'))
            ->put($this->updateRoute($household, $resident, 'laboratory'), [
                'cbc' => ['date' => '2026-03-03', 'result' => 'Without Anemia'],
            ])->assertForbidden();

        $this->assertSame(1, DB::table('prenatal_visits')->count());
        $this->assertSame('2026-02-01', substr((string) DB::table('prenatal_visits')->value('visit_date'), 0, 10));
        $this->assertSame(1, DB::table('ifa_supplementation')->count());
        $this->assertSame(1, DB::table('hepatitis_b_screening')->count());
        $this->assertSame(0, DB::table('cbc_hgb_hct_screening')->count());

        // No delivery date was given for the FD outcome, so this episode
        // isn't gated into Pregnancy History yet (same as the FT/PT case
        // with no recorded date) — it stays reachable as the continuing
        // journey instead, with the prenatal/supplementation data intact.
        $index = $this->get(route('household-profiling.members.maternal-care.index', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('data-lml-mc-mode="overview"', $index);

        $deliveryHtml = $this->get(route('household-profiling.members.maternal-care.delivery', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="fetal_death_date"', $deliveryHtml);
        $this->assertStringContainsString('name="newborns[0][sex]"', $deliveryHtml);
    }

    public function test_fd_and_ab_validation_and_crafted_ids(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2506',
            'member_no' => 'MB-2506',
        ]);
        $this->registerPregnancy($household, $resident);
        $from = route('household-profiling.members.maternal-care.delivery', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);

        $this->from($from)->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FD',
        ])->assertSessionDoesntHaveErrors('fetal_death_date')->assertRedirect();

        DB::table('delivery_outcomes')->delete();
        DB::table('maternal_care')->update([
            'pregnancy_status' => MaternalCareErdMode::STATUS_ACTIVE,
            'updated_at' => now(),
        ]);
        MaternalCareErdMode::resetCachedState();

        $this->from($from)->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'AB',
        ])->assertSessionDoesntHaveErrors('date_terminated')->assertRedirect();

        $this->assertSame('AB', DB::table('delivery_outcomes')->value('outcome'));

        DB::table('delivery_outcomes')->delete();
        DB::table('maternal_care')->update([
            'pregnancy_status' => MaternalCareErdMode::STATUS_ACTIVE,
            'updated_at' => now(),
        ]);
        MaternalCareErdMode::resetCachedState();

        $this->from($from)->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FD',
            'maternal_care_id' => 999,
            'delivery_outcome_id' => 888,
            'resident_id' => $resident->id,
        ])->assertSessionHasErrors([
            'maternal_care_id',
            'delivery_outcome_id',
            'resident_id',
        ]);

        $this->assertSame(0, DB::table('delivery_outcomes')->count());
        $this->assertSame(MaternalCareErdMode::STATUS_ACTIVE, DB::table('maternal_care')->value('pregnancy_status'));
    }

    public function test_offline_maternal_section_update_fd_and_ab_complete_pregnancy(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2507',
            'member_no' => 'MB-2507',
        ]);
        $this->registerPregnancy($household, $resident);

        $parent = ['parent_server' => [
            'household_id' => $household->getKey(),
            'household_no' => $household->household_no,
            'resident_id' => $resident->getKey(),
            'member_no' => $resident->member_no,
        ]];

        $fd = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'delivery',
                'outcome' => 'FD',
            ],
            $parent,
        ));
        $fd->assertOk();
        $fd->assertJsonPath('code', 'SYNCED');

        $this->assertSame(1, DB::table('delivery_outcomes')->count());
        $row = DB::table('delivery_outcomes')->first();
        $this->assertSame('FD', $row->outcome);
        $this->assertNull($row->date_terminated);
        $this->assertSame(MaternalCareErdMode::STATUS_COMPLETED, DB::table('maternal_care')->value('pregnancy_status'));

        $ab = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'delivery',
                'outcome' => 'AB',
                'date_terminated' => '2026-07-20',
            ],
            $parent,
        ));
        $ab->assertOk();

        $this->assertSame(1, DB::table('delivery_outcomes')->count());
        $corrected = DB::table('delivery_outcomes')->first();
        $this->assertSame('AB', $corrected->outcome);
        $this->assertSame('2026-07-20', substr((string) $corrected->date_terminated, 0, 10));
        $this->assertSame(MaternalCareErdMode::STATUS_COMPLETED, DB::table('maternal_care')->value('pregnancy_status'));

        $html = $this->get(route('household-profiling.members.maternal-care.delivery', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/id="lml-mc-date-terminated"[^>]*value="2026-07-20"/',
            $html
        );
    }

    public function test_fd_save_does_not_wipe_legacy_date_terminated(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2508',
            'member_no' => 'MB-2508',
        ]);
        $this->registerPregnancy($household, $resident);

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
            'date_terminated' => '2026-10-20',
        ])->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FD',
            'fetal_death_date' => '2026-11-01',
        ])->assertRedirect();

        $row = DB::table('delivery_outcomes')->first();
        $this->assertSame('FD', $row->outcome);
        $this->assertSame('2026-10-20', substr((string) $row->date_terminated, 0, 10));
    }

    public function test_newborn_sex_female_and_male_persist_and_hydrate(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2509',
            'member_no' => 'MB-2509',
        ]);
        $this->registerPregnancy($household, $resident);

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
            'newborn_sex' => 'Female',
        ])->assertRedirect();

        $row = DB::table('delivery_outcomes')->first();
        $this->assertSame('Female', $this->babies($row)->first()->sex);

        $html = $this->get(route('household-profiling.members.maternal-care.delivery', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/name="newborns\[0\]\[sex\]"[^>]*value="Female"[^>]*checked/',
            $html
        );

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
            'newborn_sex' => 'Male',
        ])->assertRedirect();

        $this->assertSame('Male', $this->babies(DB::table('delivery_outcomes')->first())->first()->sex);
    }

    public function test_invalid_newborn_sex_and_plurality_are_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2510',
            'member_no' => 'MB-2510',
        ]);
        $this->registerPregnancy($household, $resident);
        $from = route('household-profiling.members.maternal-care.delivery', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);

        $this->from($from)->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'newborn_sex' => 'Unknown',
        ])->assertSessionHasErrors('newborn_sex');

        $this->from($from)->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'plurality' => 'Triplet',
        ])->assertSessionHasErrors('plurality');

        $this->assertSame(0, DB::table('delivery_outcomes')->count());
    }

    public function test_plurality_single_twins_multiple_and_clearing(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2511',
            'member_no' => 'MB-2511',
        ]);
        $this->registerPregnancy($household, $resident);
        $from = route('household-profiling.members.maternal-care.delivery', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->from($from)->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
            'plurality' => 'Multiple',
        ])->assertSessionHasErrors('plurality_number');
        $this->assertSame(0, DB::table('delivery_outcomes')->count());

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
            'plurality' => 'Single',
            'plurality_number' => 4,
        ])->assertRedirect();
        $this->assertCount(1, $this->babies(DB::table('delivery_outcomes')->first()));

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
            'plurality' => 'Twins',
            'plurality_number' => 2,
        ])->assertRedirect();
        $this->assertCount(2, $this->babies(DB::table('delivery_outcomes')->first()));

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
            'plurality' => 'Multiple',
            'plurality_number' => 4,
        ])->assertRedirect();
        $this->assertCount(4, $this->babies(DB::table('delivery_outcomes')->first()));

        $html = $this->get(route('household-profiling.members.maternal-care.delivery', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/name="plurality"[^>]*value="Multiple"[^>]*checked/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-mc-plurality-number"[^>]*value="4"/',
            $html
        );

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
            'plurality' => 'Single',
            'plurality_number' => 4,
        ])->assertRedirect();
        $this->assertCount(1, $this->babies(DB::table('delivery_outcomes')->first()));

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
            'plurality' => 'Multiple',
            'plurality_number' => 5,
        ])->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
            'plurality' => 'Twins',
            'plurality_number' => 5,
        ])->assertRedirect();
        $this->assertCount(2, $this->babies(DB::table('delivery_outcomes')->first()));
    }

    public function test_history_hydrates_newborn_sex_and_plurality_without_fetal_death_date(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2512',
            'member_no' => 'MB-2512',
        ]);
        $this->registerPregnancy($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FD',
            'newborn_sex' => 'Female',
            'plurality' => 'Multiple',
            'plurality_number' => 3,
        ])->assertRedirect();

        // No delivery date was given, so this FD episode stays out of
        // Pregnancy History (same as an undated FT/PT) and remains reachable
        // — and still editable — through the normal Delivery & Outcome page.
        $show = $this->get(route('household-profiling.members.maternal-care.delivery', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="fetal_death_date"', $show);
        $this->assertMatchesRegularExpression(
            '/name="newborns\[0\]\[sex\]"[^>]*value="Female"[^>]*checked/',
            $show
        );
        $this->assertMatchesRegularExpression(
            '/name="plurality"[^>]*value="Multiple"[^>]*checked/',
            $show
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-mc-plurality-number"[^>]*value="3"/',
            $show
        );
    }

    public function test_offline_replays_newborn_sex_and_plurality(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2513',
            'member_no' => 'MB-2513',
        ]);
        $this->registerPregnancy($household, $resident);

        $parent = ['parent_server' => [
            'household_id' => $household->getKey(),
            'household_no' => $household->household_no,
            'resident_id' => $resident->getKey(),
            'member_no' => $resident->member_no,
        ]];

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'delivery',
                'outcome' => 'PT',
                'datetime' => '2026-08-01T06:00',
                'newborn_sex' => 'Male',
                'plurality' => 'Multiple',
                'plurality_number' => 4,
            ],
            $parent,
        ));
        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');

        $row = DB::table('delivery_outcomes')->first();
        $this->assertSame('PT', $row->outcome);
        // Legacy single-baby keys from a queued offline edit fill baby 1.
        $babies = $this->babies($row);
        $this->assertCount(4, $babies);
        $this->assertSame('Male', $babies->first()->sex);
        $this->assertSame(MaternalCareErdMode::STATUS_COMPLETED, DB::table('maternal_care')->value('pregnancy_status'));
    }

    public function test_cross_resident_delivery_isolation(): void
    {
        ['household' => $firstHh, 'resident' => $first] = $this->seedMember([
            'household_no' => 'HH-2514',
            'member_no' => 'MB-2514',
            'first_name' => 'First',
        ]);
        ['household' => $secondHh, 'resident' => $second] = $this->seedMember([
            'household_no' => 'HH-2515',
            'member_no' => 'MB-2515',
            'first_name' => 'Second',
        ]);
        $this->registerPregnancy($firstHh, $first);
        $this->registerPregnancy($secondHh, $second);

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($firstHh, $first, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
            'newborn_sex' => 'Female',
            'plurality' => 'Single',
        ])->assertRedirect();

        $this->put($this->updateRoute($secondHh, $second, 'delivery'), [
            'outcome' => 'PT',
            'datetime' => '2026-08-01T06:00',
            'newborn_sex' => 'Male',
            'plurality' => 'Twins',
        ])->assertRedirect();

        $firstCare = (int) DB::table('maternal_care')->where('resident_id', $first->getKey())->value('maternal_care_id');
        $secondCare = (int) DB::table('maternal_care')->where('resident_id', $second->getKey())->value('maternal_care_id');

        $firstRow = DB::table('delivery_outcomes')->where('maternal_care_id', $firstCare)->first();
        $secondRow = DB::table('delivery_outcomes')->where('maternal_care_id', $secondCare)->first();
        $this->assertSame('Female', $this->babies($firstRow)->first()->sex);
        $this->assertCount(1, $this->babies($firstRow));
        $this->assertSame('Male', $this->babies($secondRow)->first()->sex);
        $this->assertCount(2, $this->babies($secondRow));

        $other = $this->get(route('household-profiling.members.maternal-care.delivery', [
            'householdNo' => $secondHh->household_no,
            'memberId' => $second->member_no,
        ]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/name="newborns\[0\]\[sex\]"[^>]*value="Male"[^>]*checked/',
            $other
        );
        $this->assertDoesNotMatchRegularExpression(
            '/name="newborns\[0\]\[sex\]"[^>]*value="Female"[^>]*checked/',
            $other
        );
    }

    public function test_twins_store_one_row_per_baby_and_hydrate_each(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2516',
            'member_no' => 'MB-2516',
        ]);
        $this->registerPregnancy($household, $resident);

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
            'plurality' => 'Twins',
            'newborns' => [
                ['sex' => 'Female', 'birth_weight' => '2.80'],
                ['sex' => 'Male', 'birth_weight' => '2.65'],
            ],
        ])->assertRedirect();

        $babies = $this->babies(DB::table('delivery_outcomes')->first());
        $this->assertSame([1, 2], $babies->pluck('birth_order')->map(fn ($order) => (int) $order)->all());
        $this->assertSame(['Female', 'Male'], $babies->pluck('sex')->all());
        $this->assertSame(['2.80', '2.65'], $babies->pluck('birth_weight_kg')->map(fn ($kg) => number_format((float) $kg, 2))->all());

        $html = $this->get(route('household-profiling.members.maternal-care.delivery', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="newborns\[0\]\[sex\]"[^>]*value="Female"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="newborns\[1\]\[sex\]"[^>]*value="Male"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/id="lml-mc-newborn-1-weight"[^>]*value="2.65"/', $html);

        // Back to a single birth: baby 2 is removed.
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
            'plurality' => 'Single',
            'newborns' => [['sex' => 'Female', 'birth_weight' => '2.80']],
        ])->assertRedirect();
        $this->assertSame(1, DB::table('delivery_newborns')->count());
    }

    public function test_facility_is_reused_by_name_and_holds_bemonc_capability(): void
    {
        ['household' => $firstHh, 'resident' => $first] = $this->seedMember([
            'household_no' => 'HH-2517',
            'member_no' => 'MB-2517',
        ]);
        ['household' => $secondHh, 'resident' => $second] = $this->seedMember([
            'household_no' => 'HH-2518',
            'member_no' => 'MB-2518',
        ]);
        $this->registerPregnancy($firstHh, $first);
        $this->registerPregnancy($secondHh, $second);

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($firstHh, $first, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
            'place' => 'public',
            'facility_name' => 'Iriga City Hospital',
            'bemonc_cemonc' => 'No',
        ])->assertRedirect();

        $this->put($this->updateRoute($secondHh, $second, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
            'place' => 'public',
            'facility_name' => '  iriga   city hospital ',
            'bemonc_cemonc' => 'Yes',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('health_facilities')->count());
        $facility = DB::table('health_facilities')->first();
        $this->assertSame('Iriga City Hospital', $facility->facility_name);
        $this->assertSame(1, (int) $facility->bemonc_cemonc_capable);
        $this->assertSame(
            [(int) $facility->facility_id],
            DB::table('delivery_outcomes')->pluck('facility_id')->map(fn ($id) => (int) $id)->unique()->values()->all()
        );

        // The capability belongs to the facility, so the first delivery now shows it too.
        $html = $this->get(route('household-profiling.members.maternal-care.delivery', [
            'householdNo' => $firstHh->household_no,
            'memberId' => $first->member_no,
        ]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="bemonc_cemonc"[^>]*value="Yes"[^>]*checked/', $html);
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function babies(?object $delivery): \Illuminate\Support\Collection
    {
        $this->assertNotNull($delivery, 'Expected a delivery_outcomes row.');

        return DB::table('delivery_newborns')
            ->where('delivery_outcome_id', $delivery->delivery_outcome_id)
            ->orderBy('birth_order')
            ->get();
    }
}
