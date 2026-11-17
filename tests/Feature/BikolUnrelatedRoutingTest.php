<?php

namespace Tests\Feature;

use App\Services\BikolSmallTalkService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Unrelated messages go to the trained model; health, serious and unsure messages never do.
 */
class BikolUnrelatedRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.bikol_chat.enabled' => true,
            'services.bikol_chat.gate_enabled' => true,
            'services.bikol_chat.gate_min_confidence' => 0.75,
            'services.bikol_chat.send_context' => false,
            'services.bikol_chat.reviewed_enabled' => false,   // these tests are about the model; the reviewed layer has its own tests
        ]);
    }

    protected function tearDown(): void
    {
        BikolSmallTalkService::useMode('ai');
        parent::tearDown();
    }

    public function test_context_button_uses_no_ai_for_unrelated_messages(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'should not be used']])]);
        BikolSmallTalkService::useMode('context');
        $service = new BikolSmallTalkService();

        $this->assertNull($service->unrelatedResult('Kumusta ka?'));
        $this->assertNull($service->reply('Kumusta ka?')); // also the fallback RagService uses
        Http::assertNothingSent();

        // A serious message still gets the checked reply in every mode.
        $serious = $service->seriousReply('Uda na akong pag-asa');
        $this->assertSame(BikolSmallTalkService::REPLY_NO_HOPE, $serious);
    }

    public function test_cloud_button_uses_gemma_with_the_real_time_line(): void
    {
        config(['services.ollama.api_key' => 'test-key', 'services.bikol_chat.cloud_model' => 'gemma4:31b-cloud']);
        Http::fake(['ollama.com/*' => Http::response(['message' => ['content' => 'Maray man ako, salamat!']])]);
        BikolSmallTalkService::useMode('cloud');

        $result = (new BikolSmallTalkService())->unrelatedResult('Kumusta ka?');

        $this->assertSame('Maray man ako, salamat!', $result['answer']);
        $this->assertSame('Cloud AI (Gemma)', $result['answered_by']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'ollama.com/api/chat')
            && $r['model'] === 'gemma4:31b-cloud'
            && str_starts_with($r['messages'][1]['content'], '[Oras: ')
            && $r->hasHeader('Authorization', 'Bearer test-key'));

        // Time questions go to Gemma too, without needing the trained-model context switch.
        config(['services.bikol_chat.send_context' => false]);
        $this->assertSame('Cloud AI (Gemma)', (new BikolSmallTalkService())->timeDateResult('Ono nang oras?')['answered_by']);
    }

    public function test_cloud_button_tells_the_user_when_offline_and_never_uses_the_trained_model(): void
    {
        config(['services.ollama.api_key' => 'test-key']);
        Http::fake(['*' => Http::response('error', 500)]);
        BikolSmallTalkService::useMode('cloud');

        $result = (new BikolSmallTalkService())->unrelatedResult('Kumusta ka?');

        $this->assertStringContainsString('Diri makakonekta sa Cloud AI (Gemma)', $result['answer']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '11434'));
    }

    public function test_unrelated_message_goes_to_the_trained_model(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'Maray man, salamat!']])]);

        $result = (new BikolSmallTalkService())->unrelatedResult('Kumusta ka?');

        $this->assertNotNull($result);
        $this->assertSame('Maray man, salamat!', $result['answer']);
        $this->assertSame('Bikol-Iriga LLM (fine-tuned)', $result['answered_by']);
        $this->assertTrue($result['is_conversation']);
        $this->assertSame([], $result['sources']);
    }

    public function test_health_questions_never_reach_the_model(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'should not be used']])]);
        $service = new BikolSmallTalkService();

        foreach (['Ono a sinyales ka dengue?', 'Pirang aldow ana otos?', 'Masakit a payo ko',
            'Ono a gigibowon kin may kalintora?', 'Pwede ba magpabakuna ana buntis?'] as $question) {
            $this->assertNull($service->unrelatedResult($question), $question);
        }
        Http::assertNothingSent();
    }

    public function test_serious_message_gets_the_checked_reply_not_the_model(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'should not be used']])]);

        $result = (new BikolSmallTalkService())->unrelatedResult('Uda na akong pag-asa');

        // Self-harm wording: the team's own phrase and a variant with "ko" are both checked-reply messages.
        $service = new BikolSmallTalkService();
        $this->assertSame(BikolSmallTalkService::REPLY_SELF_HARM, $service->seriousReply('Gusto kong kolgan a sadiri ko.'));
        $this->assertSame(BikolSmallTalkService::REPLY_SELF_HARM, $service->seriousReply('Kolgan ko a sadiri ko'));

        // The gate may call this chat or not; either way the model must never answer it.
        if ($result !== null) {
            $this->assertSame(BikolSmallTalkService::REPLY_NO_HOPE, $result['answer']);
        }
        Http::assertNothingSent();
    }

    public function test_model_down_leaves_the_message_to_rag(): void
    {
        Http::fake(['*' => Http::response('error', 500)]);

        $this->assertNull((new BikolSmallTalkService())->unrelatedResult('Kumusta ka?'));
    }

    public function test_gate_off_or_model_missing_leaves_everything_to_rag(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'x']])]);

        config(['services.bikol_chat.gate_enabled' => false]);
        $this->assertNull((new BikolSmallTalkService())->unrelatedResult('Kumusta ka?'));

        config(['services.bikol_chat.gate_enabled' => true,
            'services.bikol_chat.gate_model_path' => base_path('resources/ml/does_not_exist.json')]);
        $this->assertNull((new BikolSmallTalkService())->unrelatedResult('Kumusta ka?'));
        Http::assertNothingSent();
    }

    public function test_everyday_sentences_with_short_or_ambiguous_health_words_still_reach_the_model(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'Maray man!']])]);
        $service = new BikolSmallTalkService();

        // "baga" used in an everyday sentence (weather) and "tb" inside "basketball" are NOT health questions.
        foreach (['lang init baga ka panawon ngowan', 'Tattao ika mag basketball?'] as $message) {
            $this->assertNotNull($service->unrelatedResult($message), $message);
        }
    }

    public function test_real_health_questions_about_lungs_and_tb_still_go_to_rag(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'should not be used']])]);
        $service = new BikolSmallTalkService();

        foreach (['Ono a kanser sa baga?', 'Ono a TB?', 'Ono a sinyales ka pneumonia sa baga?', 'Ono a sintomas ka hika?',
            'Masakit a baga ko'] as $message) {
            $this->assertNull($service->unrelatedResult($message), $message);
        }
        Http::assertNothingSent();
    }

    public function test_unsure_gate_leaves_the_message_to_rag(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'x']])]);
        config(['services.bikol_chat.gate_min_confidence' => 0.9999]);

        $this->assertNull((new BikolSmallTalkService())->unrelatedResult('Kumusta ka?'));
        Http::assertNothingSent();
    }
}
