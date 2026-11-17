<?php

namespace Tests\Feature;

use App\Services\BikolSmallTalkService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A message the team already answered gets the team's own reviewed reply; everything else is left to the model.
 */
class BikolReviewedRepliesTest extends TestCase
{
    private string $bankPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bankPath = tempnam(sys_get_temp_dir(), 'bank');
        $this->writeBank([
            ['k' => 'kumusta ika', 't' => 1, 'p' => 0, 'r' => 'Maray man ako, salamat! Ika, kumusta ika?'],
            ['k' => 'salamat', 't' => 1, 'p' => 1, 'r' => 'Uda onoman! Maanan mo a kalusugan mo.'],
            ['k' => 'salamat', 't' => 1, 'p' => 0, 'r' => 'Uda onoman! Maogma akong makatabang.'],
            ['k' => 'napagal ako', 't' => 2, 'p' => 0, 'r' => 'Pasensiya na. Paingalo ika tabi muna.'],
            ['k' => 'siisay ika', 't' => 1, 'p' => 0, 'r' => 'Ako si LMLinga, osad akong chatbot.'],
            ['k' => 'siisay ika', 't' => 2, 'p' => 0, 'r' => 'Chatbot sana ako. Ika, siisay ika?'],
        ]);
        config([
            'services.bikol_chat.enabled' => true,
            'services.bikol_chat.send_context' => false,
            'services.bikol_chat.candidates' => 1,
            'services.bikol_chat.reviewed_enabled' => true,
            'services.bikol_chat.reviewed_min' => 0.82,
            'services.bikol_chat.reviewed_min_auto' => 0.90,
            'services.bikol_chat.reviewed_path' => $this->bankPath,
        ]);
        BikolSmallTalkService::useMode('ai');
        BikolSmallTalkService::useHistory([]);
    }

    protected function tearDown(): void
    {
        @unlink($this->bankPath);
        BikolSmallTalkService::useHistory([]);
        BikolSmallTalkService::useMode('ai');
        parent::tearDown();
    }

    private function writeBank(array $entries): void
    {
        file_put_contents($this->bankPath, json_encode(['entries' => $entries]));
    }

    public function test_an_exact_and_a_near_message_get_the_team_reply_without_calling_the_model(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'should not be used']])]);
        $s = new BikolSmallTalkService();

        $this->assertSame('Maray man ako, salamat! Ika, kumusta ika?', $s->reply('Kumusta ika?'));
        $this->assertSame('Maray man ako, salamat! Ika, kumusta ika?', $s->reply('kumusta ika tabi!'));   // tabi and punctuation are ignored
        Http::assertNothingSent();
    }

    public function test_a_message_that_is_not_close_goes_to_the_model(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'Maray man ako, salamat!']])]);

        $answer = (new BikolSmallTalkService())->reply('Ono a gusto mong pagkaon sa pista?');

        $this->assertSame('Maray man ako, salamat!', $answer);
        Http::assertSentCount(1);
    }

    public function test_a_reply_without_a_health_tagline_is_preferred(): void
    {
        $s = new BikolSmallTalkService();

        for ($i = 0; $i < 12; $i++) {
            $this->assertSame('Uda onoman! Maogma akong makatabang.', $s->reply('Salamat'));
        }
    }

    public function test_the_team_reviewed_reply_beats_a_machine_made_one(): void
    {
        $s = new BikolSmallTalkService();

        for ($i = 0; $i < 12; $i++) {
            $this->assertSame('Ako si LMLinga, osad akong chatbot.', $s->reply('Siisay ika?'));
        }
    }

    public function test_machine_made_entries_need_a_stricter_match(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'Pasensiya na!']])]);
        $s = new BikolSmallTalkService();

        $this->assertSame('Pasensiya na. Paingalo ika tabi muna.', $s->reply('Napagal ako'));          // exact: ok
        $this->assertSame('Pasensiya na!', $s->reply('Napagal ako ngowan sa trabawo'));                  // only close: model
    }

    public function test_the_reply_just_given_is_not_repeated_when_there_is_another_choice(): void
    {
        $this->writeBank([
            ['k' => 'salamat', 't' => 1, 'p' => 0, 'r' => 'Uda onoman!'],
            ['k' => 'salamat', 't' => 1, 'p' => 0, 'r' => 'Maogma akong makatabang.'],
        ]);
        BikolSmallTalkService::useHistory([['role' => 'user', 'content' => 'Salamat'], ['role' => 'assistant', 'content' => 'Uda onoman!']]);
        $s = new BikolSmallTalkService();

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame('Maogma akong makatabang.', $s->reply('Salamat'));
        }
    }

    public function test_health_words_never_use_the_layer_and_it_can_be_switched_off(): void
    {
        $this->writeBank([['k' => 'ono a sinyales ka dengue', 't' => 1, 'p' => 0, 'r' => 'should never be sent']]);
        Http::fake(['*' => Http::response(['message' => ['content' => 'model answer here']])]);
        $s = new BikolSmallTalkService();

        // never the reviewed bank and never the chat model: the honest reviewed "no verified answer" reply
        $this->assertSame('Pasensiya na, diri ko isi ana simbag kadi. Maitatabang ko kanimo manongod sa kalusugan.', $s->reply('Ono a sinyales ka dengue?'));
        Http::assertNothingSent();

        config(['services.bikol_chat.reviewed_enabled' => false]);
        $this->writeBank([['k' => 'kumusta ika', 't' => 1, 'p' => 0, 'r' => 'reviewed']]);
        $this->assertSame('model answer here', $s->reply('Kumusta ika?'));
    }

    public function test_only_the_local_ai_button_uses_the_layer(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'model answer here']])]);
        BikolSmallTalkService::useMode('context');

        $this->assertNull((new BikolSmallTalkService())->reply('Kumusta ika?'));   // no AI, no reviewed replies in Context-based mode
    }

    public function test_the_answer_is_tagged_as_a_team_reply_and_serious_messages_are_unchanged(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'model answer here']])]);
        $s = new BikolSmallTalkService();

        $result = $s->unrelatedResult('Kumusta ika?');
        $this->assertSame('Reviewed reply (team)', $result['answered_by']);
        $this->assertSame('Maray man ako, salamat! Ika, kumusta ika?', $result['answer']);

        $serious = $s->unrelatedResult('Uda na akong pag-asa');
        $this->assertTrue($serious === null || $serious['answered_by'] === 'Checked reply');

        // a message that goes to the model keeps the model tag
        $other = $s->unrelatedResult('Ono a gusto mong pagkaon sa pista?');
        $this->assertSame('Bikol-Iriga LLM (fine-tuned)', $other['answered_by'] ?? 'Bikol-Iriga LLM (fine-tuned)');
    }
}
