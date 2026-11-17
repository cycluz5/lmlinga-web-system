<?php

namespace Tests\Feature;

use App\Services\BikolSmallTalkService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The trained model sometimes says the wrong hour (mostly exactly on the hour). timeDateResult() checks its
 * answer against the real clock and replaces a wrong one with the reviewed wording (situational.py).
 */
class BikolClockCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bikol_chat.send_context' => true, 'services.bikol_chat.enabled' => true]);
        BikolSmallTalkService::useMode('ai');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        BikolSmallTalkService::useMode('ai');
        parent::tearDown();
    }

    private function at(string $manila): void
    {
        Carbon::setTestNow(Carbon::parse($manila, 'Asia/Manila'));
    }

    private function ask(string $question, string $modelSays): array
    {
        Http::fake(['*' => Http::response(['message' => ['content' => $modelSays]])]);

        return (new BikolSmallTalkService())->timeDateResult($question);
    }

    public function test_the_reviewed_wording_for_every_kind_of_time(): void
    {
        $service = new BikolSmallTalkService();
        $at = fn (string $t) => Carbon::parse($t, 'Asia/Manila');

        $this->assertSame('Ngowan, 3:46 na sa apon.', $service->clockAnswer('time', $at('2026-11-16 15:46')));
        $this->assertSame('Ngowan, alas siyete na sa ramrag.', $service->clockAnswer('time', $at('2026-11-16 07:00')));
        $this->assertSame('Ngowan, ala ona na sa modto.', $service->clockAnswer('time', $at('2026-11-16 13:00')));
        $this->assertSame('Ngowan, alas onse na sa gab\'i.', $service->clockAnswer('time', $at('2026-11-16 23:00')));
        $this->assertSame('Ngowan, alas dose na sa gab\'i.', $service->clockAnswer('time', $at('2026-11-16 00:00')));
        $this->assertSame('Ngowan, alas dose na sa modto.', $service->clockAnswer('time', $at('2026-11-16 12:00')));
        $this->assertSame('Ngowan, Lunes.', $service->clockAnswer('day', $at('2026-11-16 09:00')));
        $this->assertSame('Ngowan ana Lunes, Nobyembre 16, 2026.', $service->clockAnswer('date', $at('2026-11-16 09:00')));
        $this->assertSame('Ngowan na bulan, Nobyembre.', $service->clockAnswer('month', $at('2026-11-16 09:00')));
        $this->assertSame('Ngowan na taon, 2026.', $service->clockAnswer('year', $at('2026-11-16 09:00')));
    }

    public function test_a_right_answer_is_kept_as_the_model_wrote_it(): void
    {
        $this->at('2026-11-16 15:46');
        $result = $this->ask('Ono nang oras?', '3:46 na sa apon ngowan.');

        $this->assertSame('3:46 na sa apon ngowan.', $result['answer']);
        $this->assertSame('Bikol-Iriga LLM (fine-tuned)', $result['answered_by']);
    }

    public function test_a_wrong_hour_on_the_hour_is_replaced(): void
    {
        $this->at('2026-11-16 11:00');   // v5 really said "alas nuwebe" for 11:00 AM
        $result = $this->ask('Anong oras na?', 'Ngowan, alas nuwebe na sa ramrag.');

        $this->assertSame('Ngowan, alas onse na sa ramrag.', $result['answer']);
        $this->assertSame('Clock check (real time)', $result['answered_by']);
        $this->assertTrue($result['is_conversation']);
    }

    public function test_a_spelling_variant_of_the_right_hour_is_replaced_with_the_team_spelling(): void
    {
        $this->at('2026-11-16 16:00');   // v5 said "alas quatro"; the team writes "kuwatro"
        $result = $this->ask('Pirang oras na?', 'Ngowan, alas quatro na sa apon.');

        $this->assertSame('Ngowan, alas kuwatro na sa apon.', $result['answer']);
    }

    public function test_a_refusal_wrong_part_of_day_or_wrong_minute_is_replaced(): void
    {
        $this->at('2026-11-16 15:46');
        $this->assertSame('Ngowan, 3:46 na sa apon.',
            $this->ask('What time is it?', 'Pasensiya na, diri ko isi a oras. Alagad maray sa kalusugan a tamang oras ka torog!')['answer']);
        $this->assertSame('Ngowan, 3:46 na sa apon.', $this->ask('Ono nang oras?', '3:46 na sa gab\'i ngowan.')['answer']);
        $this->assertSame('Ngowan, 3:46 na sa apon.', $this->ask('Ono nang oras?', '3:45 na sa apon ngowan.')['answer']);
    }

    public function test_one_thirty_seven_is_not_mistaken_for_eleven_thirty_seven(): void
    {
        $this->at('2026-11-16 01:37');
        $result = $this->ask('Ono nang oras?', '11:37 na sa gab\'i ngowan.');

        $this->assertSame('Ngowan, 1:37 na sa gab\'i.', $result['answer']);
    }

    public function test_day_date_month_and_year_answers_are_checked_too(): void
    {
        $this->at('2026-11-16 09:00');   // Monday

        $this->assertSame('Ngowan, Lunes.', $this->ask('Ono ngowan na aldow?', 'Ngowan, Martes.')['answer']);
        $this->assertSame('Ngowan, Lunes.', $this->ask('What day is it today?', 'Ngowan, Lunes.')['answer']);
        $this->assertSame('Ngowan ana Lunes, Nobyembre 16, 2026.',
            $this->ask('Ono a petsa ngowan?', 'Ngowan ana Lunes, Nobyembre 15, 2026.')['answer']);
        $this->assertSame('Ngowan ana Lunes, Nobyembre 16, 2026.',
            $this->ask("What's the date today?", 'Ngowan ana Lunes, Nobyembre 16, 2026.')['answer']);
        $this->assertSame('Ngowan na bulan, Nobyembre.', $this->ask('Ono a bulan ngowan?', 'Ngowan na bulan, Disyembre.')['answer']);
        $this->assertSame('Ngowan na taon, 2026.', $this->ask('Ono nang taon ngowan?', 'Ngowan na taon, 2025.')['answer']);
    }

    public function test_kinds_are_recognised_from_the_question(): void
    {
        $service = new BikolSmallTalkService();

        foreach ([
            'Ono nang oras?' => 'time', 'What time is it?' => 'time', 'Anong oras na ngayon?' => 'time',
            'Ono ngowan na aldow?' => 'day', 'What day is it today?' => 'day', 'Anong araw ngayon?' => 'day',
            'Ono a petsa ngowan?' => 'date', "What's the date today?" => 'date',
            'Ono a bulan ngowan?' => 'month', 'What month is it?' => 'month',
            'Ono nang taon ngowan?' => 'year', 'What year is it?' => 'year',
            'Is it already night?' => null, 'Kumusta ka?' => null, 'Ono ana dengue?' => null,
        ] as $question => $kind) {
            $this->assertSame($kind, $service->timeDateKind($question), $question);
        }
    }

    public function test_a_question_that_cannot_be_checked_keeps_the_model_answer(): void
    {
        $this->at('2026-11-16 09:00');
        $result = $this->ask('Is it already night?', 'Diri pa, ramrag pa ngowan.');

        $this->assertSame('Diri pa, ramrag pa ngowan.', $result['answer']);
        $this->assertSame('Bikol-Iriga LLM (fine-tuned)', $result['answered_by']);
    }

    public function test_the_same_clock_is_sent_to_the_model_and_used_for_the_check(): void
    {
        $this->at('2026-11-16 15:46');
        $this->ask('Ono nang oras?', '3:46 na sa apon ngowan.');

        Http::assertSent(fn ($r) => str_starts_with(
            $r['messages'][0]['content'],
            '[Oras: 3:46 PM | Aldow: Lunes | Petsa: Nobyembre 16, 2026]'
        ));
    }

    public function test_a_model_that_is_down_leaves_the_question_to_the_normal_flow(): void
    {
        $this->at('2026-11-16 15:46');
        Http::fake(['*' => Http::response('error', 500)]);

        $this->assertNull((new BikolSmallTalkService())->timeDateResult('Ono nang oras?'));
    }

    public function test_cloud_mode_is_left_alone(): void
    {
        $this->at('2026-11-16 15:46');
        config(['services.ollama.api_key' => 'test-key']);
        Http::fake(['ollama.com/*' => Http::response(['message' => ['content' => 'Ngowan, alas nuwebe na sa ramrag.']])]);
        BikolSmallTalkService::useMode('cloud');
        $result = (new BikolSmallTalkService())->timeDateResult('Ono nang oras?');
        $this->assertSame('Ngowan, alas nuwebe na sa ramrag.', $result['answer']);   // no clock check in cloud mode
        $this->assertSame('Cloud AI (Gemma)', $result['answered_by']);
    }
}
