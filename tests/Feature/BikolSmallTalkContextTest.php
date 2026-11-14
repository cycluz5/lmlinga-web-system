<?php

namespace Tests\Feature;

use App\Services\BikolSmallTalkService;
use App\Services\RagService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BikolSmallTalkContextTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_context_line_uses_philippine_time_in_the_trained_format(): void
    {
        // 12:00 UTC = 8:00 PM in Manila; 2026-11-14 is a Saturday.
        $line = (new BikolSmallTalkService())->contextLine(Carbon::parse('2026-11-14 12:00:00', 'UTC'));

        $this->assertSame('[Oras: 8:00 PM | Aldow: Sabado | Petsa: Nobyembre 14, 2026]', $line);
        $this->assertSame(
            '[Oras: 12:05 AM | Aldow: Lunes | Petsa: Enero 4, 2027]',
            (new BikolSmallTalkService())->contextLine(Carbon::parse('2027-01-04 00:05:00', 'Asia/Manila'))
        );
    }

    public function test_detects_time_and_date_questions_but_not_health_questions(): void
    {
        $service = new BikolSmallTalkService();

        foreach (['Ono nang oras?', 'What time is it?', 'Anong oras na?', 'Ono a petsa ngowan?',
            'What day is it today?', 'Ono ngowan na aldow?'] as $question) {
            $this->assertTrue($service->isTimeOrDateQuestion($question), $question);
        }
        foreach (['Ono ana dengue?', 'Ono ana sinyales kana otos?', 'Pirang aldow ana otos?', 'Kumusta?'] as $question) {
            $this->assertFalse($service->isTimeOrDateQuestion($question), $question);
        }
    }

    public function test_context_is_sent_to_the_model_only_when_switched_on(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-14 20:00:00', 'Asia/Manila'));
        Http::fake(['*' => Http::response(['message' => ['content' => 'Ngowan, alas otso na kana gab\'i.']])]);

        config(['services.bikol_chat.send_context' => false]);
        (new BikolSmallTalkService())->reply('Ono nang oras?');
        Http::assertSent(fn ($request) => $request['messages'][0]['content'] === 'Ono nang oras?');

        config(['services.bikol_chat.send_context' => true]);
        $answer = (new BikolSmallTalkService())->reply('Ono nang oras?');
        $this->assertSame("Ngowan, alas otso na kana gab'i.", $answer);
        Http::assertSent(fn ($request) => $request['messages'][0]['content']
            === "[Oras: 8:00 PM | Aldow: Sabado | Petsa: Nobyembre 14, 2026]\nOno nang oras?");
    }

    public function test_time_question_goes_straight_to_the_model_and_health_is_left_to_rag(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'Alas otso na kana gab\'i.']])]);
        config(['services.bikol_chat.send_context' => true, 'services.bikol_chat.enabled' => true]);

        $service = new BikolSmallTalkService();
        $result = $service->timeDateResult('Ono nang oras?');

        $this->assertSame("Alas otso na kana gab'i.", $result['answer']);
        $this->assertTrue($result['is_conversation']);
        $this->assertSame('Bikol-Iriga LLM (fine-tuned)', $result['answered_by']);

        // Health questions are not handled here: they stay with RAG (the controller then calls RagService).
        $this->assertNull($service->timeDateResult('Ono ana sinyales kana otos?'));
    }

    public function test_time_question_is_left_to_the_normal_flow_while_context_is_off(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'should not be used']])]);
        config(['services.bikol_chat.send_context' => false]);

        $this->assertNull((new BikolSmallTalkService())->timeDateResult('Ono nang oras?'));
        Http::assertNothingSent();
    }

    public function test_time_question_is_left_to_the_normal_flow_when_the_model_is_down(): void
    {
        Http::fake(['*' => Http::response('error', 500)]);
        config(['services.bikol_chat.send_context' => true]);

        $this->assertNull((new BikolSmallTalkService())->timeDateResult('Ono nang oras?'));
    }
}
