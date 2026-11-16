<?php

namespace Tests\Feature;

use App\Services\BikolCloudChatService;
use App\Services\RagService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BikolCloudChatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ollama.api_key' => 'test-key']);
    }

    private function cloudRag(): RagService
    {
        $rag = new RagService();
        $rag->setAnswerMode('cloud');

        return $rag;
    }

    private function retell(array $verified, string $question = 'Ano an sintomas kan dengue?'): ?array
    {
        $method = new \ReflectionMethod(RagService::class, 'conversationalBikolAnswer');
        $method->setAccessible(true);

        return $method->invoke($this->cloudRag(), $question, $verified);
    }

    private function verified(): array
    {
        return [
            'title' => 'Dengue',
            'answer' => 'An dengue igwa nin lagnat na 2 hasta 7 aldaw.',
            'points' => ['Sakit sa ulo', 'Pananakit kan mata'],
            'sources' => ['dengue.txt'],
        ];
    }

    public function test_small_talk_uses_gemma_cloud_with_the_time_line(): void
    {
        Http::fake(['ollama.com/*' => Http::response(['message' => ['content' => 'Maray na banggi! Alas otso na.']])]);

        $result = (new BikolCloudChatService())->result('Ano na an oras?');

        $this->assertSame('Maray na banggi! Alas otso na.', $result['answer']);
        $this->assertSame('Cloud AI (Gemma)', $result['answered_by']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'ollama.com/api/chat')
            && $r['model'] === 'gemma4:31b-cloud'
            && str_starts_with($r['messages'][1]['content'], '[Oras:')
            && str_contains($r['messages'][1]['content'], 'Ano na an oras?'));
    }

    public function test_cloud_failure_gives_the_offline_message(): void
    {
        Http::fake(['*' => Http::response('payment required', 402)]);

        $result = (new BikolCloudChatService())->result('Kumusta ka?');

        $this->assertSame(BikolCloudChatService::OFFLINE_MESSAGE, $result['answer']);
        $this->assertSame('Cloud AI (Gemma) - offline', $result['answered_by']);
    }

    public function test_health_answer_is_retold_from_the_verified_text(): void
    {
        Http::fake(['ollama.com/*' => Http::response(['message' => ['content' =>
            "An dengue nagtutulod nin lagnat na 2 hasta 7 aldaw.\n- Sakit sa ulo\n- Pananakit kan mata\nMagpakonsulta sa health center."]])]);

        $result = $this->retell($this->verified());

        $this->assertStringContainsString('• Sakit sa ulo', $result['answer']);
        $this->assertSame(['dengue.txt'], $result['sources']);
        $this->assertSame('Dengue', $result['title']);
        $this->assertSame('Cloud AI (Gemma) + verified health info', $result['answered_by']);
        Http::assertSent(fn ($r) => str_contains($r['messages'][1]['content'], 'VERIFIED ANSWER:')
            && str_contains($r['messages'][1]['content'], 'Sakit sa ulo'));
    }

    public function test_new_numbers_from_gemma_fall_back_to_the_verified_answer(): void
    {
        Http::fake(['ollama.com/*' => Http::response(['message' => ['content' =>
            'Uminom nin 500 mg na paracetamol kada 4 oras para sa dengue, tapos magpahuway.']])]);

        $verified = $this->verified();
        $result = $this->retell($verified);

        $this->assertSame($verified['answer'], $result['answer']);
        $this->assertSame($verified['points'], $result['points']);
        $this->assertSame('Verified health information', $result['answered_by']);
    }

    public function test_health_retelling_reports_offline_when_the_cloud_is_down(): void
    {
        Http::fake(['*' => Http::response('error', 500)]);

        $result = $this->retell($this->verified());

        $this->assertSame(BikolCloudChatService::OFFLINE_MESSAGE, $result['answer']);
        $this->assertSame([], $result['sources']);
    }

    public function test_serious_messages_get_the_fixed_reply_without_any_call(): void
    {
        Http::fake();

        $result = $this->cloudRag()->ask('Uda na akong pag-asa', 'bcl');

        $this->assertSame(\App\Services\BikolSmallTalkService::REPLY_NO_HOPE, $result['answer']);
        $this->assertSame('Fixed checked reply', $result['answered_by']);
        Http::assertNothingSent();
    }

    public function test_gemma_can_say_the_documents_do_not_answer_the_question(): void
    {
        Http::fake(['ollama.com/*' => Http::response(['message' => ['content' => 'NO_ANSWER']])]);

        // null tells ask() to switch to normal small talk instead of showing a health answer.
        $this->assertNull($this->retell($this->verified(), 'Ano an panahon ngunyan na aldaw?'));
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'], 'NO_ANSWER'));
    }

    public function test_small_talk_prompt_has_weather_health_and_tagalog_rules(): void
    {
        $prompt = (new BikolCloudChatService())->smallTalkSystemPrompt();

        $this->assertStringContainsString('PAGASA', $prompt);
        $this->assertStringContainsString('BAKONG health', $prompt);
        $this->assertStringContainsString("'po'", $prompt);
        $this->assertStringContainsString("'ay'", $prompt);
        $this->assertStringContainsString("'maogma'", $prompt);
    }
}
