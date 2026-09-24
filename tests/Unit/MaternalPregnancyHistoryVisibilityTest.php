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

    public function test_ab_is_immediately_visible_and_does_not_use_date_terminated(): void
    {
        $today = Carbon::parse('2026-10-20')->startOfDay();
        $episode = $this->episode('completed', [
            'outcome' => 'AB',
            'datetime' => '',
            'date_terminated' => '2026-10-20',
            'abortion_date' => '2026-10-20',
        ]);

        $this->assertTrue(DemoMaternalCare::isVisibleInPregnancyHistory($episode, $today));
        $this->assertSame('2026-10-20', $episode['delivery']['date_terminated']);
    }

    public function test_fd_trans_out_and_non_ft_pt_completed_are_immediately_visible(): void
    {
        $today = Carbon::parse('2026-10-20')->startOfDay();

        $this->assertTrue(DemoMaternalCare::isVisibleInPregnancyHistory(
            $this->episode('completed', ['outcome' => 'FD']),
            $today
        ));
        $this->assertTrue(DemoMaternalCare::isVisibleInPregnancyHistory(
            $this->episode('transferred_out'),
            $today
        ));
        $this->assertTrue(DemoMaternalCare::isVisibleInPregnancyHistory(
            $this->episode('trans-out'),
            $today
        ));
        $this->assertTrue(DemoMaternalCare::isVisibleInPregnancyHistory(
            $this->episode('completed', []),
            $today
        ));
        $this->assertTrue(DemoMaternalCare::isVisibleInPregnancyHistory(
            $this->episode('completed', ['outcome' => 'UNK']),
            $today
        ));
    }
}
