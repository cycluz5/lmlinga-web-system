<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\ChatbotHouseholdMemberHealthSummary;
use App\Support\MaternalCareErdMode;
use App\Support\MaternalPregnancyService;
use App\Support\StaffRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErdMaternalCareSchema;
use Tests\TestCase;

/**
 * #15 — read-time 42-day Pregnancy History visibility gate.
 * sqlite :memory: only.
 */
class MaternalCarePregnancyHistoryGateTest extends TestCase
{
    use RefreshDatabase;

    private const DELIVERY = '2026-10-20';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::DELIVERY)->startOfDay());
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
            'household_no' => $overrides['household_no'] ?? 'HH-1501',
            'zone' => 'Zone 1',
            'street' => 'History Gate St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-1501',
            'first_name' => $overrides['first_name'] ?? 'Gina',
            'last_name' => $overrides['last_name'] ?? 'Gate',
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
    private function registerPayload(): array
    {
        return [
            'lmp' => '2026-01-15',
            'gravida' => 1,
            'parity' => 0,
            'edd' => '2026-10-22',
            'weight' => 58.5,
            'height' => 160,
            'blood_pressure' => '110/70',
        ];
    }

    /**
     * @return array{householdNo: string, memberId: string}
     */
    private function params(Household $household, Resident $resident): array
    {
        return [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];
    }

    private function storeRoute(Household $household, Resident $resident): string
    {
        return route('household-profiling.members.maternal-care.store', $this->params($household, $resident));
    }

    private function updateRoute(Household $household, Resident $resident, string $section): string
    {
        return route('household-profiling.members.maternal-care.update', $this->params($household, $resident) + [
            'section' => $section,
        ]);
    }

    private function register(Household $household, Resident $resident): void
    {
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();
    }

    private function maternalCareId(?Resident $resident = null): int
    {
        $query = DB::table('maternal_care')->orderByDesc('maternal_care_id');
        if ($resident !== null) {
            $query->where('resident_id', $resident->getKey());
        }

        return (int) $query->value('maternal_care_id');
    }

    private function pregnancyId(?Resident $resident = null): string
    {
        return sprintf('MC-%03d', $this->maternalCareId($resident));
    }

    private function travelToDay(int $day): void
    {
        Carbon::setTestNow(Carbon::parse(self::DELIVERY)->startOfDay()->addDays($day));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function saveDelivery(Household $household, Resident $resident, array $payload): void
    {
        $this->put($this->updateRoute($household, $resident, 'delivery'), $payload)->assertRedirect();
    }

    private function historyHtml(Household $household, Resident $resident): string
    {
        return $this->get(route('household-profiling.members.maternal-care.history', $this->params($household, $resident)))
            ->assertOk()
            ->getContent();
    }

    private function indexHtml(Household $household, Resident $resident): string
    {
        return $this->get(route('household-profiling.members.maternal-care.index', $this->params($household, $resident)))
            ->assertOk()
            ->getContent();
    }

    /**
     * @dataProvider liveBirthVisibilityProvider
     */
    public function test_ft_and_pt_history_follows_inclusive_42_day_gate(
        string $outcome,
        int $day,
        bool $visible
    ): void {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => sprintf('HH-%d', $outcome === 'FT' ? 1530 + $day : 1570 + $day),
            'member_no' => sprintf('MB-%d', $outcome === 'FT' ? 1530 + $day : 1570 + $day),
        ]);
        $this->register($household, $resident);
        Carbon::setTestNow(Carbon::parse(self::DELIVERY.'T08:30'));
        $this->saveDelivery($household, $resident, [
            'outcome' => $outcome,
            'delivery_type' => $outcome === 'PT' ? 'CS' : 'VD',
            'datetime' => self::DELIVERY.'T08:30',
            'status' => 'Live birth',
        ]);

        $this->travelToDay($day);
        $history = $this->historyHtml($household, $resident);

        if ($visible) {
            $this->assertStringContainsString('data-mc-history-list', $history);
            $this->assertStringContainsString('data-mc-history-date="'.self::DELIVERY.'"', $history);
            $this->assertStringContainsString($outcome === 'PT' ? 'Pre-Term' : 'Full Term', $history);
        } else {
            $this->assertStringContainsString('data-mc-history-empty', $history);
            $this->assertStringNotContainsString('data-mc-history-list', $history);
            $this->assertStringNotContainsString('Full Term', $history);
            $this->assertStringNotContainsString('Pre-Term', $history);
        }
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: bool}>
     */
    public static function liveBirthVisibilityProvider(): array
    {
        return [
            'FT day 0 hidden' => ['FT', 0, false],
            'FT day 41 hidden' => ['FT', 41, false],
            'FT day 42 visible' => ['FT', 42, true],
            'FT day 43 visible' => ['FT', 43, true],
            'PT day 0 hidden' => ['PT', 0, false],
            'PT day 41 hidden' => ['PT', 41, false],
            'PT day 42 visible' => ['PT', 42, true],
            'PT day 43 visible' => ['PT', 43, true],
        ];
    }

    public function test_ft_pt_null_datetime_is_hidden_and_direct_show_is_blocked(): void
    {
        foreach (['FT' => 'HH-1510', 'PT' => 'HH-1511'] as $outcome => $householdNo) {
            ['household' => $household, 'resident' => $resident] = $this->seedMember([
                'household_no' => $householdNo,
                'member_no' => str_replace('HH', 'MB', $householdNo),
            ]);
            $this->register($household, $resident);
            $this->saveDelivery($household, $resident, ['outcome' => $outcome]);
            $this->assertNull(DB::table('delivery_outcomes')->where('maternal_care_id', $this->maternalCareId($resident))->value('date_time_of_delivery'));

            $this->travelToDay(50);
            $this->assertStringContainsString('data-mc-history-empty', $this->historyHtml($household, $resident));

            $this->get(route('household-profiling.members.maternal-care.history.show', $this->params($household, $resident) + [
                'pregnancyId' => $this->pregnancyId($resident),
            ]))->assertRedirect(route('household-profiling.members.maternal-care.history', $this->params($household, $resident)));
        }
    }

    public function test_later_saved_delivery_datetime_restarts_the_42_day_clock(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1512',
            'member_no' => 'MB-1512',
        ]);
        $this->register($household, $resident);
        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->saveDelivery($household, $resident, [
            'outcome' => 'FT',
            'delivery_type' => 'VD',
            'datetime' => '2026-10-20T08:30',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-01T09:00'));
        $this->saveDelivery($household, $resident, [
            'outcome' => 'FT',
            'delivery_type' => 'VD',
            'datetime' => '2026-11-01T09:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-12-01')->startOfDay());
        $this->assertStringContainsString('data-mc-history-empty', $this->historyHtml($household, $resident));

        Carbon::setTestNow(Carbon::parse('2026-12-13')->startOfDay());
        $history = $this->historyHtml($household, $resident);
        $this->assertStringContainsString('data-mc-history-list', $history);
        $this->assertStringContainsString('data-mc-history-date="2026-11-01"', $history);
    }

    public function test_ab_and_fd_follow_the_same_42_day_gate_as_live_birth(): void
    {
        // AB with a recorded date: gated exactly like FT/PT, then visible.
        ['household' => $abHousehold, 'resident' => $abResident] = $this->seedMember([
            'household_no' => 'HH-1513',
            'member_no' => 'MB-1513',
            'first_name' => 'Ava',
        ]);
        $this->register($abHousehold, $abResident);
        Carbon::setTestNow(Carbon::parse(self::DELIVERY)->startOfDay());
        $this->saveDelivery($abHousehold, $abResident, [
            'outcome' => 'AB',
            'date_terminated' => self::DELIVERY,
        ]);
        $this->assertStringNotContainsString('Abortion', $this->historyHtml($abHousehold, $abResident));
        $abOverview = $this->indexHtml($abHousehold, $abResident);
        $this->assertStringContainsString('data-lml-mc-mode="overview"', $abOverview);

        Carbon::setTestNow(Carbon::parse(self::DELIVERY)->startOfDay()->addDays(42));
        $abHistory = $this->historyHtml($abHousehold, $abResident);
        $this->assertStringContainsString('Abortion', $abHistory);
        $this->assertStringContainsString('data-mc-history-date="'.self::DELIVERY.'"', $abHistory);

        // FD with no date given: nothing to gate 42 days from, so it stays
        // in the continuing journey indefinitely rather than blocking on a
        // "fetal death date" field that no longer exists.
        ['household' => $fdHousehold, 'resident' => $fdResident] = $this->seedMember([
            'household_no' => 'HH-1514',
            'member_no' => 'MB-1514',
            'first_name' => 'Faye',
        ]);
        $this->register($fdHousehold, $fdResident);
        $this->saveDelivery($fdHousehold, $fdResident, ['outcome' => 'FD']);
        $this->assertNull(DB::table('delivery_outcomes')->where('maternal_care_id', $this->maternalCareId($fdResident))->value('date_terminated'));
        $this->assertStringNotContainsString('Fetal Death', $this->historyHtml($fdHousehold, $fdResident));
        $this->assertStringContainsString('data-lml-mc-mode="overview"', $this->indexHtml($fdHousehold, $fdResident));

        // Trans-Out remains immediately visible: the episode continues at
        // another facility, so there is no postnatal window to wait out here.
        ['household' => $toHousehold, 'resident' => $toResident] = $this->seedMember([
            'household_no' => 'HH-1515',
            'member_no' => 'MB-1515',
        ]);
        $this->register($toHousehold, $toResident);
        $this->put($this->updateRoute($toHousehold, $toResident, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => self::DELIVERY,
        ])->assertRedirect();
        $this->assertStringContainsString('data-mc-history-status="transferred_out"', $this->historyHtml($toHousehold, $toResident));

        // Completed with no delivery details recorded at all: same as the
        // undated FD case, stays in the continuing journey.
        ['household' => $noneHousehold, 'resident' => $noneResident] = $this->seedMember([
            'household_no' => 'HH-1516',
            'member_no' => 'MB-1516',
        ]);
        $this->register($noneHousehold, $noneResident);
        DB::table('maternal_care')->where('resident_id', $noneResident->getKey())->update([
            'pregnancy_status' => MaternalCareErdMode::STATUS_COMPLETED,
            'updated_at' => now(),
        ]);
        $this->assertSame(0, DB::table('delivery_outcomes')->where('maternal_care_id', $this->maternalCareId($noneResident))->count());
        $this->assertStringContainsString('data-lml-mc-mode="overview"', $this->indexHtml($noneHousehold, $noneResident));

        // Completed with an unrecognized outcome and no date: same again.
        ['household' => $otherHousehold, 'resident' => $otherResident] = $this->seedMember([
            'household_no' => 'HH-1517',
            'member_no' => 'MB-1517',
        ]);
        $this->register($otherHousehold, $otherResident);
        $id = $this->maternalCareId($otherResident);
        DB::table('maternal_care')->where('maternal_care_id', $id)->update([
            'pregnancy_status' => MaternalCareErdMode::STATUS_COMPLETED,
            'updated_at' => now(),
        ]);
        DB::table('delivery_outcomes')->insert([
            'maternal_care_id' => $id,
            'outcome' => 'UNK',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertStringContainsString('data-lml-mc-mode="overview"', $this->indexHtml($otherHousehold, $otherResident));
    }

    public function test_two_episodes_are_gated_independently(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1518',
            'member_no' => 'MB-1518',
        ]);
        $this->register($household, $resident);
        $this->saveDelivery($household, $resident, [
            'outcome' => 'FT',
            'delivery_type' => 'VD',
            'datetime' => '2026-01-01T08:00',
        ]);
        $olderId = $this->pregnancyId($resident);

        $this->register($household, $resident);
        $this->saveDelivery($household, $resident, [
            'outcome' => 'FT',
            'delivery_type' => 'VD',
            'datetime' => '2026-10-20T08:30',
        ]);
        $newerId = $this->pregnancyId($resident);
        $this->assertNotSame($olderId, $newerId);

        Carbon::setTestNow(Carbon::parse('2026-11-30')->startOfDay());
        $history = $this->historyHtml($household, $resident);
        $this->assertStringContainsString($olderId, $history);
        $this->assertStringNotContainsString($newerId, $history);
        $this->assertSame(1, substr_count($history, 'data-mc-history-item='));
    }

    public function test_resident_isolation_and_direct_history_cannot_bypass_gate(): void
    {
        ['household' => $ownerHousehold, 'resident' => $owner] = $this->seedMember([
            'household_no' => 'HH-1519',
            'member_no' => 'MB-1519',
            'first_name' => 'Owner',
        ]);
        $this->register($ownerHousehold, $owner);
        Carbon::setTestNow(Carbon::parse(self::DELIVERY.'T08:30'));
        $this->saveDelivery($ownerHousehold, $owner, [
            'outcome' => 'FT',
            'delivery_type' => 'VD',
            'datetime' => self::DELIVERY.'T08:30',
        ]);
        $ownerId = $this->pregnancyId($owner);

        ['household' => $otherHousehold, 'resident' => $other] = $this->seedMember([
            'household_no' => 'HH-1520',
            'member_no' => 'MB-1520',
            'first_name' => 'Other',
        ]);
        $this->register($otherHousehold, $other);
        $this->saveDelivery($otherHousehold, $other, [
            'outcome' => 'AB',
            'date_terminated' => self::DELIVERY,
        ]);

        $this->get(route('household-profiling.members.maternal-care.history.show', $this->params($otherHousehold, $other) + [
            'pregnancyId' => $ownerId,
        ]))->assertRedirect(route('household-profiling.members.maternal-care.history', $this->params($otherHousehold, $other)));

        $this->travelToDay(0);
        $this->get(route('household-profiling.members.maternal-care.history.show', $this->params($ownerHousehold, $owner) + [
            'pregnancyId' => $ownerId,
        ]))->assertRedirect(route('household-profiling.members.maternal-care.history', $this->params($ownerHousehold, $owner)));

        $this->travelToDay(42);
        $show = $this->get(route('household-profiling.members.maternal-care.history.show', $this->params($ownerHousehold, $owner) + [
            'pregnancyId' => $ownerId,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('data-mc-readonly="true"', $show);
        $this->assertStringNotContainsString('data-mc-edit-for=', $show);
    }

    public function test_landing_distinguishes_no_record_from_gated_closed_episode(): void
    {
        ['household' => $neverHousehold, 'resident' => $neverResident] = $this->seedMember([
            'household_no' => 'HH-1521',
            'member_no' => 'MB-1521',
        ]);
        $never = $this->indexHtml($neverHousehold, $neverResident);
        $this->assertStringContainsString('NO RECORD', $never);
        $this->assertStringNotContainsString('NO ACTIVE PREGNANCY', $never);
        $this->assertStringNotContainsString('data-mc-has-history', $never);

        ['household' => $gatedHousehold, 'resident' => $gatedResident] = $this->seedMember([
            'household_no' => 'HH-1522',
            'member_no' => 'MB-1522',
        ]);
        $this->register($gatedHousehold, $gatedResident);
        Carbon::setTestNow(Carbon::parse(self::DELIVERY.'T08:30'));
        $this->saveDelivery($gatedHousehold, $gatedResident, [
            'outcome' => 'FT',
            'delivery_type' => 'VD',
            'datetime' => self::DELIVERY.'T08:30',
        ]);

        // Still inside the 6-week window: the journey (and Postnatal Care)
        // stays reachable from the main entry point, not a "landing" dead end.
        $gated = $this->indexHtml($gatedHousehold, $gatedResident);
        $this->assertStringContainsString('data-lml-mc-mode="overview"', $gated);
        $this->assertStringContainsString('data-mc-service="postnatal"', $gated);
        $this->assertStringNotContainsString('NO ACTIVE PREGNANCY', $gated);

        // Once the gate passes, the episode drops out of the journey view
        // and the member shows as having no active pregnancy.
        $this->travelToDay(42);
        $afterGate = $this->indexHtml($gatedHousehold, $gatedResident);
        $this->assertStringContainsString('NO ACTIVE PREGNANCY', $afterGate);
        $this->assertStringContainsString('data-mc-has-history', $afterGate);
        $this->assertStringContainsString('data-mc-register-cta', $afterGate);
        $this->assertStringNotContainsString('NO RECORD', $afterGate);
        $this->assertStringContainsString('Past Maternal Records', $afterGate);
        $this->assertStringContainsString('data-mc-history-link', $afterGate);
    }

    public function test_register_next_pregnancy_remains_allowed_inside_the_window(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1523',
            'member_no' => 'MB-1523',
        ]);
        $this->register($household, $resident);
        Carbon::setTestNow(Carbon::parse(self::DELIVERY.'T08:30'));
        $this->saveDelivery($household, $resident, [
            'outcome' => 'FT',
            'delivery_type' => 'VD',
            'datetime' => self::DELIVERY.'T08:30',
        ]);

        $this->post($this->storeRoute($household, $resident), $this->registerPayload())->assertRedirect();
        $this->assertSame(2, DB::table('maternal_care')->where('resident_id', $resident->getKey())->count());
        $this->assertSame(1, DB::table('maternal_care')->where('resident_id', $resident->getKey())->where('pregnancy_status', MaternalCareErdMode::STATUS_ACTIVE)->count());

        $overview = $this->indexHtml($household, $resident);
        $this->assertStringContainsString('data-lml-mc-mode="overview"', $overview);
        $this->assertStringContainsString('data-mc-history-link', $overview);
        $this->assertStringContainsString('Pregnancy History', $overview);
        $this->assertStringContainsString('data-mc-history-empty', $this->historyHtml($household, $resident));
    }

    public function test_postnatal_and_delivery_remain_writable_while_rusf_and_lab_stay_forbidden(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1524',
            'member_no' => 'MB-1524',
        ]);
        $this->register($household, $resident);
        Carbon::setTestNow(Carbon::parse(self::DELIVERY.'T08:30'));
        $this->saveDelivery($household, $resident, [
            'outcome' => 'FT',
            'delivery_type' => 'VD',
            'datetime' => self::DELIVERY.'T08:30',
        ]);
        $this->assertSame(MaternalCareErdMode::STATUS_COMPLETED, DB::table('maternal_care')->value('pregnancy_status'));

        Carbon::setTestNow(Carbon::parse('2026-10-21')->startOfDay());
        $this->put($this->updateRoute($household, $resident, 'postnatal'), [
            'contacts' => ['c1' => '2026-10-21'],
        ])->assertRedirect();
        $this->assertSame(1, DB::table('postnatal_care_visits')->count());

        Carbon::setTestNow(Carbon::parse('2026-10-21T10:00'));
        $this->saveDelivery($household, $resident, [
            'outcome' => 'FT',
            'delivery_type' => 'VD',
            'datetime' => '2026-10-21T10:00',
        ]);
        $this->assertSame('2026-10-21', substr((string) DB::table('delivery_outcomes')->value('date_time_of_delivery'), 0, 10));

        $this->from($this->updateRoute($household, $resident, 'supplementations'))
            ->put($this->updateRoute($household, $resident, 'supplementations'), [
                'rusf' => [['date' => '2026-10-22']],
            ])->assertForbidden();
        $this->assertSame(0, DB::table('rusf_supplementation')->count());

        $this->from($this->updateRoute($household, $resident, 'laboratory'))
            ->put($this->updateRoute($household, $resident, 'laboratory'), [
                'urinalysis' => ['date' => '2026-10-22'],
            ])->assertForbidden();
        $this->assertSame(0, DB::table('urinalysis_screening')->count());
    }

    public function test_chatbot_has_maternal_record_while_history_is_gated(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1525',
            'member_no' => 'MB-1525',
        ]);
        $this->register($household, $resident);
        Carbon::setTestNow(Carbon::parse(self::DELIVERY.'T08:30'));
        $this->saveDelivery($household, $resident, [
            'outcome' => 'FT',
            'delivery_type' => 'VD',
            'datetime' => self::DELIVERY.'T08:30',
        ]);

        $service = app(MaternalPregnancyService::class);
        $this->assertTrue($service->hasAnyEpisode($resident));
        $this->assertTrue($service->hasClosedEpisodeForResident($resident));
        $this->assertSame([], $service->historyRowsForResident($resident));

        $rows = app(ChatbotHouseholdMemberHealthSummary::class)->rowsForResident($resident);
        $maternal = collect($rows)->firstWhere('key', 'maternal');
        $this->assertIsArray($maternal);
        $this->assertTrue($maternal['available']);
    }
}
