<?php

namespace Tests\Feature;

use App\Models\HealthChunk;
use App\Models\ResidentAccount;
use App\Services\RagService;
use App\Support\ResidentAuthenticator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Residents' chatbot questions and the answers they receive must never reach the application log:
 * they can contain personal health information. Each test first proves the log call it targets fired.
 */
class ChatbotLogPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const QUESTION = 'SENTINELQUESTION';
    private const ANSWER = 'SENTINELANSWER';
    private const POINT = 'SENTINELPOINT';

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ollama.base_url' => 'http://127.0.0.1:11434',
            'services.ollama.chat_provider' => 'local',
            'services.ollama.chat_model' => 'mock-model',
        ]);
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e->level.' '.$e->message.' '.json_encode($e->context);
        });
        foreach (['en', 'tl', 'bcl'] as $language) {
            HealthChunk::query()->create([
                'language' => $language, 'category' => 'other', 'source_file' => 'asthma.txt', 'chunk_index' => 0,
                'content' => mb_substr((string) file_get_contents(resource_path("health_docs/$language/other/asthma.txt")), 0, 500),
                'embedding' => array_fill(0, 8, 0.1),
            ]);
        }
    }

    private function fakeAi(string $content): void
    {
        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::response(['message' => ['content' => $content]]),
            '127.0.0.1:11434/api/embeddings' => Http::response(['embedding' => array_fill(0, 8, 0.1)]),
            '127.0.0.1:11434/api/tags' => Http::response(['models' => [['name' => 'nomic-embed-text:latest']]]),
        ]);
    }

    private function jsonAnswer(): string
    {
        return json_encode(['answer' => self::ANSWER.' reply.', 'points' => [self::POINT.' one.']]);
    }

    private function ask(string $question, string $language): void
    {
        // The testing schema has no chatbot history tables; same approach as ChatbotAiProviderTest::residentAsk().
        foreach (['chatbot_conversations' => 'conversation_id', 'chatbot_messages' => 'message_id'] as $table => $key) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, function (Blueprint $t) use ($table, $key): void {
                    $t->id($key);
                    if ($table === 'chatbot_conversations') {
                        $t->unsignedBigInteger('account_id');
                        $t->string('title', 150)->nullable();
                        $t->boolean('is_pinned')->default(false);
                        $t->timestamp('last_message_at')->nullable();
                        $t->timestamps();
                    } else {
                        $t->unsignedBigInteger('conversation_id');
                        $t->string('sender', 20);
                        $t->text('message_text');
                        $t->string('language', 10)->nullable();
                        $t->string('category', 50)->nullable();
                        $t->timestamp('sent_at')->nullable();
                        $t->timestamp('created_at')->nullable();
                    }
                });
            }
        }
        $account = ResidentAccount::factory()->create(['email' => 'privacy.resident@example.test']);
        $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $account->getKey(),
            ResidentAuthenticator::SESSION_EMAIL => 'privacy.resident@example.test',
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ])->postJson(route('chatbot.ask'), ['question' => $question, 'language' => $language])->assertOk();
    }

    private function assertLogCallFired(string $message): void
    {
        $this->assertTrue(
            collect($this->logged)->contains(fn (string $entry): bool => str_contains($entry, $message)),
            "Expected the '$message' log call to fire, otherwise this test proves nothing"
        );
    }

    private function assertNoResidentTextLogged(): void
    {
        foreach ($this->logged as $entry) {
            foreach ([self::QUESTION, self::ANSWER, self::POINT] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $entry, "Resident text reached the log: $sentinel");
            }
        }
    }

    public function test_answered_question_is_not_logged(): void
    {
        $this->fakeAi($this->jsonAnswer());
        $this->ask('What is asthma? '.self::QUESTION, 'en');
        $this->assertLogCallFired('Chatbot response');
        $this->assertNoResidentTextLogged();
    }

    public function test_language_override_does_not_log_the_question(): void
    {
        $this->fakeAi($this->jsonAnswer());
        $this->ask('Ano ang asthma? '.self::QUESTION, 'en');
        $this->assertLogCallFired('Language detection overrode selected language');
        $this->assertNoResidentTextLogged();
    }

    public function test_tagalog_follow_up_does_not_log_the_question(): void
    {
        $this->fakeAi($this->jsonAnswer());
        app(RagService::class)->ask('Ano pa ang mga sintomas? '.self::QUESTION, 'tl', null, 'asthma');
        $this->assertLogCallFired('Tagalog follow-up pipeline question');
        $this->assertNoResidentTextLogged();
    }

    public function test_english_follow_up_does_not_log_the_question(): void
    {
        $this->fakeAi($this->jsonAnswer());
        app(RagService::class)->ask('What are the symptoms? '.self::QUESTION, 'en', null, 'asthma');
        $this->assertLogCallFired('English follow-up pipeline question');
        $this->assertNoResidentTextLogged();
    }

    public function test_unparseable_model_reply_is_not_logged(): void
    {
        $this->fakeAi('not json at all '.self::ANSWER);
        $this->ask('What is asthma?', 'en');
        $this->assertLogCallFired('JSON parsing failed completely');
        $this->assertNoResidentTextLogged();
    }

    public function test_operational_fields_are_still_logged(): void
    {
        $this->fakeAi($this->jsonAnswer());
        $this->ask('What is asthma?', 'en');
        $entry = collect($this->logged)->first(fn (string $e): bool => str_contains($e, 'Chatbot response'));
        $this->assertNotNull($entry);
        foreach (['selected_language', 'resolved_language', 'question_length', 'answer_length', 'sources'] as $field) {
            $this->assertStringContainsString($field, $entry);
        }
    }
}
