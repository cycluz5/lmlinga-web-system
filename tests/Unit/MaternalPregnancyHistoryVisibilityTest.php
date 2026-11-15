<?php

namespace Tests\Unit;

use App\Support\DemoMaternalCare;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * #15 — shared read-time Pregnancy History visibility helper.
 */
class MaternalPregnancyHistoryVisibilityTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function episode(string $status, array $delivery = []): array
    {
        return [
            'id' => 'MC-001',
            'status' => $status,
            'delivery' => $delivery,
        ];
    }

    public function test_helper_does_not_mutate_the_episode(): void
    {
        $episode = $this->episode('completed', [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
        ]);
        $before = $episode;

        DemoMaternalCare::isVisibleInPregnancyHistory(
            $episode,
            Carbon::parse('2026-12-01')->startOfDay()
        );

        $this->assertSame($before, $episode);
    }

    /**
     * @dataProvider liveBirthDayProvider
     */
    public function test_ft_and_pt_use_date_only_42_day_inclusive_gate(
        string $outcome,
        string $today,
        bool $visible
    ): void {
        $episode = $this->episode('completed', [
            'outcome' => $outcome,
            'datetime' => '2026-10-20T22:15',
        ]);

        $this->assertSame(
            $visible,
            DemoMaternalCare::isVisibleInPregnancyHistory(
                $episode,
                Carbon::parse($today)->startOfDay()
            )
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function liveBirthDayProvider(): array
    {
        return [
            'FT day 0 hidden' => ['FT', '2026-10-20', false],
            'FT day 41 hidden' => ['FT', '2026-11-30', false],
            'FT day 42 visible' => ['FT', '2026-12-01', true],
            'FT day 43 visible' => ['FT', '2026-12-02', true],
            'PT day 0 hidden' => ['PT', '2026-10-20', false],
            'PT day 41 hidden' => ['PT', '2026-11-30', false],
            'PT day 42 visible' => ['PT', '2026-12-01', true],
            'PT day 43 visible' => ['PT', '2026-12-02', true],
        ];
    }

    public function test_ft_pt_null_datetime_is_hidden(): void
    {
        foreach (['FT', 'PT'] as $outcome) {
            $this->assertFalse(DemoMaternalCare::isVisibleInPregnancyHistory(
                $this->episode('completed', ['outcome' => $outcome, 'datetime' => '']),
                Carbon::parse('2026-12-01')->startOfDay()
            ));
        }
    }

    /*
     * Current #15 rule (DemoMaternalCare::isVisibleInPregnancyHistory doc, and the end-to-end
     * MaternalCarePregnancyHistoryGateTest::test_ab_and_fd_follow_the_same_42_day_gate_as_live_birth):
     * every completed outcome (FT/PT/FD/AB) waits 42 inclusive days from its delivery date; an outcome with no
     * date has nothing to gate from and stays in the continuing journey; Trans-Out is immediately visible.
     * The two tests below previously expected the earlier rule (AB/FD/no-outcome visible at once), which
     * contradicted both the helper and the end-to-end test.
     */
    public function test_ab_uses_date_terminated_with_the_same_42_day_gate_and_is_not_mutated(): void
    {
        $episode = $this->episode('completed', [
            'outcome' => 'AB',
            'datetime' => '',
            'date_terminated' => '2026-10-20',
            'abortion_date' => '2026-10-20',
        ]);

        $this->assertFalse(DemoMaternalCare::isVisibleInPregnancyHistory($episode, Carbon::parse('2026-10-20')->startOfDay()));
        $this->assertFalse(DemoMaternalCare::isVisibleInPregnancyHistory($episode, Carbon::parse('2026-11-30')->startOfDay()));
        $this->assertTrue(DemoMaternalCare::isVisibleInPregnancyHistory($episode, Carbon::parse('2026-12-01')->startOfDay()));
        $this->assertSame('2026-10-20', $episode['delivery']['date_terminated']);
    }

    public function test_trans_out_is_immediately_visible_and_undated_outcomes_stay_in_the_journey(): void
    {
        $today = Carbon::parse('2026-10-20')->startOfDay();
        $muchLater = Carbon::parse('2027-06-01')->startOfDay();

        $this->assertTrue(DemoMaternalCare::isVisibleInPregnancyHistory($this->episode('transferred_out'), $today));
        $this->assertTrue(DemoMaternalCare::isVisibleInPregnancyHistory($this->episode('trans-out'), $today));

        foreach ([['outcome' => 'FD'], [], ['outcome' => 'UNK']] as $delivery) {
            $this->assertFalse(DemoMaternalCare::isVisibleInPregnancyHistory($this->episode('completed', $delivery), $today));
            $this->assertFalse(DemoMaternalCare::isVisibleInPregnancyHistory($this->episode('completed', $delivery), $muchLater));
        }

        // FD with a date follows the same 42-day gate.
        $datedFd = $this->episode('completed', ['outcome' => 'FD', 'date_terminated' => '2026-10-20']);
        $this->assertFalse(DemoMaternalCare::isVisibleInPregnancyHistory($datedFd, Carbon::parse('2026-11-30')->startOfDay()));
        $this->assertTrue(DemoMaternalCare::isVisibleInPregnancyHistory($datedFd, Carbon::parse('2026-12-01')->startOfDay()));
    }
}
