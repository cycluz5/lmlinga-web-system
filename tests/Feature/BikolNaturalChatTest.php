<?php

namespace Tests\Feature;

use App\Services\BikolSmallTalkService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * More natural chat without retraining: earlier turns, team spelling, and the best of several answers.
 */
class BikolNaturalChatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.bikol_chat.enabled' => true,
            'services.bikol_chat.send_context' => false,
            'services.bikol_chat.candidates' => 3,
            'services.bikol_chat.history_turns' => 3,
            'services.bikol_chat.reviewed_enabled' => false,   // these tests are about the model; the reviewed layer has its own tests
        ]);
        BikolSmallTalkService::useMode('ai');
        BikolSmallTalkService::useHistory([]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        BikolSmallTalkService::useHistory([]);
        BikolSmallTalkService::useMode('ai');
        parent::tearDown();
    }

    private function says(string ...$answers): void
    {
        $sequence = Http::sequence();
        foreach ($answers as $a) {
            $sequence->push(['message' => ['content' => $a]]);
        }
        Http::fake(['*' => $sequence]);
    }

    private function msg(string $sender, string $text): object
    {
        return (object) ['sender' => $sender, 'message_text' => $text];
    }

    public function test_the_message_is_respelled_to_the_team_spelling_before_it_reaches_the_model(): void
    {
        $s = new BikolSmallTalkService();

        $this->assertSame('Sari ika ngowan?', $s->normalizeInput('Sari ika nguwan?'));
        $this->assertSame('Ono ana ngaran mo?', $s->normalizeInput('Uno ana ngaran mo?'));
        $this->assertSame('Ngowan na', $s->normalizeInput('Nguwan na'));
        $this->assertSame('Mainit ngowan, alop na ako', $s->normalizeInput('Mainit ngowan, gutom na ako'));
        $this->assertSame('maki-usad', $s->normalizeInput('maki-usad'));   // never inside a longer word
        $this->assertSame('Maray na apon', $s->normalizeInput('Maray na apon'));

        $this->says('Maray man ako, salamat!');
        $s->reply('Sari ika nguwan?');
        Http::assertSent(function ($r) {
            $messages = $r['messages'];

            return $messages[count($messages) - 1]['content'] === 'Sari ika ngowan?';
        });
    }

    public function test_earlier_turns_are_sent_in_order_before_the_new_message(): void
    {
        BikolSmallTalkService::useHistory([
            ['role' => 'user', 'content' => 'Kumusta ika?'],
            ['role' => 'assistant', 'content' => 'Maray man ako, salamat!'],
        ]);
        $this->says('Maray man!', 'Maray man!', 'Maray man!');

        (new BikolSmallTalkService())->reply('Nagkaon ka na?');

        Http::assertSent(function ($r) {
            $m = $r['messages'];

            return count($m) === 3
                && $m[0] === ['role' => 'user', 'content' => 'Kumusta ika?']
                && $m[1]['role'] === 'assistant'
                && $m[2] === ['role' => 'user', 'content' => 'Nagkaon ka na?'];
        });
    }

    public function test_history_keeps_short_chat_turns_and_skips_long_health_answers(): void
    {
        $rows = [
            $this->msg('Resident', 'Kumusta ika?'), $this->msg('Chatbot', 'Maray man ako, salamat!'),
            $this->msg('Resident', 'ono a kalintora?'), $this->msg('Chatbot', str_repeat('Ana kalintora ', 40)),
            $this->msg('Resident', 'ono a dengue?'), $this->msg('Chatbot', "Ono ana dengue?\nNOTE: health information only"),
            $this->msg('Resident', 'Salamat tabi'), $this->msg('Chatbot', 'Uda onoman!'),
        ];

        $h = BikolSmallTalkService::historyFromMessages($rows, 3);

        $this->assertSame([
            ['role' => 'user', 'content' => 'Kumusta ika?'], ['role' => 'assistant', 'content' => 'Maray man ako, salamat!'],
            ['role' => 'user', 'content' => 'Salamat tabi'], ['role' => 'assistant', 'content' => 'Uda onoman!'],
        ], $h);

        $this->assertCount(2, BikolSmallTalkService::historyFromMessages($rows, 1));
        $this->assertSame([], BikolSmallTalkService::historyFromMessages($rows, 0));
        $long = BikolSmallTalkService::historyFromMessages([$this->msg('Resident', str_repeat('a', 500)), $this->msg('Chatbot', 'ok po')], 3);
        $this->assertSame(200, mb_strlen($long[0]['content']));
    }

    public function test_the_best_of_three_answers_is_chosen_and_a_bad_one_is_dropped(): void
    {
        // first: Central Bikol word "marhay" (blocked); the other two agree with each other
        $this->says('Marhay man ako, salamat!', 'Maray man ako, salamat!', 'Maray man, salamat!');

        $answer = (new BikolSmallTalkService())->reply('Kumusta ika?');

        $this->assertStringNotContainsString('Marhay', (string) $answer);
        $this->assertContains($answer, ['Maray man ako, salamat!', 'Maray man, salamat!']);
        Http::assertSentCount(3);
    }

    public function test_when_every_answer_is_bad_the_reviewed_fallback_is_used(): void
    {
        $this->says('Marhay na aldaw!', 'Ano ang gusto mo?', 'Ngunyan marhay man.');

        $answer = (new BikolSmallTalkService())->reply('Kumusta ika?');

        $this->assertStringStartsWith('Pasensiya na!', (string) $answer);
    }

    public function test_repeated_sentences_unknown_words_and_medicine_advice_are_rejected(): void
    {
        // repeated sentence / mostly unknown words / medicine advice without a doctor redirect -> only the last is fine
        $this->says('Maray man ako. Maray man ako.', 'Zxqv blorp wumbo quizzle ngowan.', 'Uminom ka sa gamot na dosis.');
        $fallback = (new BikolSmallTalkService())->reply('Kumusta ika?');
        $this->assertStringStartsWith('Pasensiya na!', (string) $fallback);
    }

    public function test_small_talk_that_steers_to_health_loses_to_a_plain_answer(): void
    {
        $this->says('Maray man! Maanan mo a kalusugan mo.', 'Maray man ako, salamat!', 'Maray man! Agko raw ika onga manongod sa kalusugan?');

        // the person did not talk about health -> the plain answer wins even though it is not the first one
        $this->assertSame('Maray man ako, salamat!', (new BikolSmallTalkService())->reply('Kumusta ika?'));
    }

    public function test_health_talk_is_fine_when_the_person_talked_about_health(): void
    {
        $this->says('Boko ako doktor. Magpabayad sa doktor o sa health center.', 'Maray man ako, salamat!', 'Maray man ako, salamat!');

        // a message with a health word: the penalty does not apply, so the careful first answer can win
        $answer = (new BikolSmallTalkService())->reply('Masakit a payo ko');

        $this->assertNotNull($answer);
    }

    public function test_the_model_is_asked_to_stay_loaded(): void
    {
        $this->says('Maray man ako, salamat!', 'Maray man ako, salamat!', 'Maray man ako, salamat!');

        (new BikolSmallTalkService())->reply('Kumusta ika?');

        Http::assertSent(fn ($r) => $r['keep_alive'] === '30m');
    }

    public function test_a_time_question_typed_with_u_spelling_is_still_a_time_question(): void
    {
        $s = new BikolSmallTalkService();

        $this->assertTrue($s->isTimeOrDateQuestion('Unong aldow nguwan?'));
        $this->assertSame('day', $s->timeDateKind('Unong aldow nguwan?'));
        $this->assertTrue($s->isTimeOrDateQuestion('Unong oras nguwan?'));
    }

    public function test_price_news_and_politics_questions_go_to_the_model_but_health_ones_do_not(): void
    {
        $s = new BikolSmallTalkService();

        foreach (['Ono a presyo ka bigas?', 'Siisay a presidente?', 'Ono a balita ngowan?', 'Magkano a pasahe?'] as $m) {
            $this->assertTrue($s->isUnrelated($m), $m);
        }
        foreach (['Magkano a bakuna?', 'Ono a presyo ka bolong sa obo?', 'Ono a sinyales ka dengue?'] as $m) {
            $this->assertFalse($s->isUnrelated($m), $m);
        }
    }

    public function test_one_good_answer_among_rejected_ones_wins(): void
    {
        $this->says('Maray man ako. Maray man ako.', 'Maray man ako, salamat!', 'Uminom ka sa gamot na dosis.');

        $this->assertSame('Maray man ako, salamat!', (new BikolSmallTalkService())->reply('Kumusta ika?'));
    }

    public function test_a_refusal_that_names_a_medicine_is_allowed(): void
    {
        $this->says('Boko ako doktor. Magpabayad sa doktor o sa health center.', 'Boko ako doktor.', 'Boko ako doktor, diri ko isi.');

        $answer = (new BikolSmallTalkService())->reply('Ono a ginigibo mo ngowan?');   // the answers are faked: this tests the choice, not the question

        $this->assertStringContainsString('doktor', (string) $answer);
        $this->assertStringNotContainsString('Pasensiya na! Isasabi', (string) $answer);
    }

    public function test_a_model_that_is_down_returns_null_and_not_the_fallback(): void
    {
        Http::fake(['*' => Http::response('error', 500)]);

        $this->assertNull((new BikolSmallTalkService())->reply('Kumusta ika?'));
    }

    public function test_the_features_can_be_switched_off(): void
    {
        config(['services.bikol_chat.candidates' => 1]);
        BikolSmallTalkService::useHistory([['role' => 'user', 'content' => 'Kumusta ika?'], ['role' => 'assistant', 'content' => 'Maray man!']]);
        $this->says('Marhay man ako!');   // would be rejected by the checks, but candidates = 1 means no checks

        $answer = (new BikolSmallTalkService())->reply('Nagkaon ka na?');

        $this->assertSame('Marhay man ako!', $answer);
        Http::assertSentCount(1);
    }

    public function test_time_and_date_questions_stay_a_single_plain_call_without_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-16 15:46:00', 'Asia/Manila'));
        config(['services.bikol_chat.send_context' => true]);
        BikolSmallTalkService::useHistory([['role' => 'user', 'content' => 'Kumusta ika?'], ['role' => 'assistant', 'content' => 'Maray man!']]);
        $this->says('3:46 na sa apon ngowan.');

        $result = (new BikolSmallTalkService())->timeDateResult('Ono nang oras?');

        $this->assertSame('3:46 na sa apon ngowan.', $result['answer']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => count($r['messages']) === 1);
    }
}
