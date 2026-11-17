<?php

namespace Tests\Feature;

use App\Services\BikolSmallTalkService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Something the chatbot has not learned (gibberish, English, Tagalog, Central Bikol) gets the team's reviewed
 * "I do not know that" reply, and nothing normal is mistaken for it.
 */
class BikolUnlearnedTest extends TestCase
{
    private const NOT_KNOW = 'Pasensiya na, diri ko isi ana simbag kadi. Maitatabang ko kanimo manongod sa kalusugan.';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bikol_chat.enabled' => true, 'services.bikol_chat.unlearned_enabled' => true,
            'services.bikol_chat.reviewed_enabled' => false, 'services.bikol_chat.candidates' => 1]);
        BikolSmallTalkService::useMode('ai');
        BikolSmallTalkService::useHistory([]);
    }

    protected function tearDown(): void
    {
        BikolSmallTalkService::useMode('ai');
        parent::tearDown();
    }

    public function test_unlearned_messages_are_recognised(): void
    {
        $s = new BikolSmallTalkService();

        foreach ([
            'asdfgh jkl', 'zxqv blorp wumbo quizzle',                       // gibberish
            'hello how are you doing today', 'What is the meaning of life?', // English
            'Ang bilis ng takbo ng kabayo', 'Kumusta ka na? Matagal na tayong hindi nagkita', // Tagalog
            'Marhay na aga po', 'Ano ang paborito mong pelikula',            // Central Bikol / Tagalog markers
        ] as $message) {
            $this->assertTrue($s->looksUnlearned($message), $message);
            $this->assertTrue($s->isUnrelated($message), $message);   // routed to the model layer, not to RAG
        }
    }

    public function test_normal_messages_are_never_mistaken_for_unlearned(): void
    {
        $s = new BikolSmallTalkService();

        foreach ([
            'Kumusta ika?', 'Napagal ako ngowan', 'Mainit ngowan, diri ako makatorog.', 'Agko ako bagong selpon!', 'Salamat tabi',
            'Sari ika nguwan?', 'Hello', 'Hmm', 'Ono ana dengue?', 'Masakit a payo ko', 'Ono nang oras?', 'What time is it?',
            "What's the date today?", 'Maray na apon! Kumusta a trabawo mo ngowan?',
        ] as $message) {
            $this->assertFalse($s->looksUnlearned($message), $message);
        }
    }

    public function test_the_reply_is_the_teams_reviewed_text_with_its_own_tag(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'should not be used']])]);

        $result = (new BikolSmallTalkService())->unrelatedResult('asdfgh jkl');

        $this->assertSame(self::NOT_KNOW, $result['answer']);
        $this->assertSame('Not learned yet (team-reviewed reply)', $result['answered_by']);
        Http::assertNothingSent();
    }

    public function test_price_news_math_and_trivia_questions_get_the_same_reply_path(): void
    {
        $s = new BikolSmallTalkService();

        foreach (['Pira a 5 + 7?', 'Ono a kapital ka Pransya?', 'Ono a presyo ka bigas?', 'Siisay a presidente?'] as $message) {
            $this->assertTrue($s->isUnrelated($message), $message);
        }
    }

    public function test_it_can_be_switched_off_and_only_applies_to_the_local_model_button(): void
    {
        $s = new BikolSmallTalkService();
        config(['services.bikol_chat.unlearned_enabled' => false]);
        $this->assertFalse($s->looksUnlearned('asdfgh jkl'));

        config(['services.bikol_chat.unlearned_enabled' => true]);
        BikolSmallTalkService::useMode('context');
        $this->assertNull($s->reply('asdfgh jkl'));   // Context-based: no AI, no reply layer
    }
}
