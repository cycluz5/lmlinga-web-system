<?php

namespace Tests\Feature;

use App\Exceptions\ChatbotAiUnavailableException;
use App\Http\Controllers\Chatbot\ChatbotController;
use App\Models\HealthChunk;
use App\Models\ResidentAccount;
use App\Services\RagService;
use App\Support\OllamaChatEndpoint;
use App\Support\ResidentAuthenticator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * SR-08 configurable AI provider: defaults reproduce the existing behaviour exactly, local mode never sends
 * the API key, no automatic provider fallback, friendly reply on AI failure, read-only status/evaluation.
 * No real model is called (Http::fake / preventStrayRequests).
 */
class ChatbotAiProviderTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_KEY = 'test-key-not-real-0000';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.ollama.api_key' => self::TEST_KEY, 'services.ollama.base_url' => 'http://127.0.0.1:11434']);
    }

    private function fakeOllama(): void
    {
        Http::fake([
            'https://ollama.com/api/chat' => Http::response(['message' => ['content' => '{"answer":"placeholder answer","points":[]}']]),
            'http://127.0.0.1:11434/api/chat' => Http::response(['message' => ['content' => '{"answer":"placeholder answer","points":[]}']]),
            'http://127.0.0.1:11434/api/embeddings' => Http::response(['embedding' => array_fill(0, 8, 0.1)]),
            'http://127.0.0.1:11434/api/tags' => Http::response(['models' => [['name' => 'nomic-embed-text:latest']]]),
        ]);
    }

    /** RagService with its protected request helpers exposed for request-shape checks. */
    private function rag(): RagService
    {
        return new class extends RagService
        {
            public function chatRequest(array $payload): \Illuminate\Http\Client\Response
            {
                return $this->ollamaChatRequest($payload);
            }

            public function embedText(string $text): array
            {
                return $this->embed($text);
            }
        };
    }

    public function test_endpoint_rules(): void
    {
        $r = fn (string $p, string $m, ?string $k) => OllamaChatEndpoint::resolve($p, $m, $k, 'http://local:11434/', 'https://ollama.com');

        // auto = the existing rule in all four combinations.
        $this->assertSame(['provider' => 'cloud', 'url' => 'https://ollama.com/api/chat', 'token' => 'k'], $r('auto', 'gemma4:31b-cloud', 'k'));
        $this->assertSame(['provider' => 'local', 'url' => 'http://local:11434/api/chat', 'token' => null], $r('auto', 'gemma4:31b-cloud', null));
        $this->assertSame(['provider' => 'local', 'url' => 'http://local:11434/api/chat', 'token' => null], $r('auto', 'small-model:3b', 'k'));
        $this->assertSame(['provider' => 'local', 'url' => 'http://local:11434/api/chat', 'token' => null], $r('auto', 'small-model:3b', ''));

        // local never sends the key; cloud requires it; no silent fallback.
        $this->assertNull($r('local', 'small-model:3b', 'k')['token']);
        $this->assertSame('cloud', $r('cloud', 'small-model:3b', 'k')['provider']);
        foreach ([['local', 'gemma4:31b-cloud', 'k'], ['cloud', 'gemma4:31b-cloud', null], ['remote', 'x', 'k']] as [$p, $m, $k]) {
            try {
                $r($p, $m, $k);
                $this->fail("expected configuration error for {$p}");
            } catch (ChatbotAiUnavailableException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->assertSame(180, OllamaChatEndpoint::boundedTimeout(0, 180));
        $this->assertSame(5, OllamaChatEndpoint::boundedTimeout(1, 180));
        $this->assertSame(600, OllamaChatEndpoint::boundedTimeout(99999, 180));
    }

    public function test_default_configuration_is_unchanged(): void
    {
        $this->assertSame('auto', config('services.ollama.chat_provider'));
        $this->assertSame('gemma4:31b-cloud', config('services.ollama.chat_model'));
        $this->assertSame('nomic-embed-text', config('services.ollama.embedding_model'));
        $this->assertSame(180, config('services.ollama.chat_timeout'));
        $this->assertSame(60, config('services.ollama.embedding_timeout'));
        $this->assertSame('30m', config('services.ollama.keep_alive'));
        $this->assertSame('https://ollama.com', config('services.ollama.cloud_url'));
    }

    public function test_default_chat_and_embedding_requests_match_the_former_behaviour(): void
    {
        $this->fakeOllama();
        $rag = $this->rag();

        $rag->chatRequest(['model' => 'gemma4:31b-cloud', 'messages' => []]);
        $rag->embedText('placeholder');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://ollama.com/api/chat'
            && $r->hasHeader('Authorization', 'Bearer '.self::TEST_KEY));
        Http::assertSent(fn (Request $r) => $r->url() === 'http://127.0.0.1:11434/api/embeddings'
            && $r['model'] === 'nomic-embed-text' && ! $r->hasHeader('Authorization'));
        Http::assertSentCount(2);
    }

    public function test_local_provider_never_sends_the_api_key(): void
    {
        $this->fakeOllama();
        config(['services.ollama.chat_provider' => 'local', 'services.ollama.chat_model' => 'small-model:3b']);

        $this->rag()->chatRequest(['model' => 'small-model:3b', 'messages' => []]);

        Http::assertSent(fn (Request $r) => $r->url() === 'http://127.0.0.1:11434/api/chat' && ! $r->hasHeader('Authorization'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'ollama.com'));
    }

    public function test_english_answer_request_payload_is_unchanged(): void
    {
        $this->fakeOllama();
        $path = resource_path('health_docs/en/other/asthma.txt');
        $this->assertFileExists($path);
        HealthChunk::query()->create([
            'language' => 'en', 'category' => 'other', 'source_file' => 'asthma.txt', 'chunk_index' => 0,
            'content' => mb_substr((string) file_get_contents($path), 0, 500), 'embedding' => array_fill(0, 8, 0.1),
        ]);

        app(RagService::class)->ask('What is asthma?', 'en');

        $chat = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_ends_with($r->url(), '/api/chat'));
        $this->assertCount(1, $chat, 'one chat call for an English health question');
        $request = $chat->first();
        $this->assertSame('https://ollama.com/api/chat', $request->url());
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.self::TEST_KEY));
        $this->assertSame('gemma4:31b-cloud', $request['model']);
        $this->assertFalse($request['stream']);
        $this->assertSame('json', $request['format']);
        $this->assertSame('30m', $request['keep_alive']);
        $this->assertSame(['temperature' => 0.2, 'num_predict' => 700], $request['options']);
    }

    public function test_bikol_answers_never_call_the_chat_model(): void
    {
        $this->fakeOllama();
        $path = resource_path('health_docs/bcl/other/asthma.txt');
        if (is_file($path)) {
            HealthChunk::query()->create([
                'language' => 'bcl', 'category' => 'other', 'source_file' => 'asthma.txt', 'chunk_index' => 0,
                'content' => mb_substr((string) file_get_contents($path), 0, 500), 'embedding' => array_fill(0, 8, 0.1),
            ]);
        }

        app(RagService::class)->ask('Uno an asthma?', 'bcl');
        app(RagService::class)->ask('kumusta', 'bcl');

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/chat'));
    }

    private function residentAsk(string $language = 'en'): \Illuminate\Testing\TestResponse
    {
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
        $account = ResidentAccount::query()->where('email', 'ai.resident@example.test')->first()
            ?? ResidentAccount::factory()->create(['email' => 'ai.resident@example.test']);

        return $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $account->getKey(),
            ResidentAuthenticator::SESSION_EMAIL => 'ai.resident@example.test',
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ])->postJson(route('chatbot.ask'), ['question' => 'What is asthma?', 'language' => $language]);
    }

    public function test_ai_failure_gives_a_friendly_reply_without_saving_history_or_switching_provider(): void
    {
        Http::fake([
            'https://ollama.com/api/chat' => fn () => throw new ConnectionException('placeholder connection failure'),
            'http://127.0.0.1:11434/*' => Http::response(['embedding' => array_fill(0, 8, 0.1)]),
        ]);
        HealthChunk::query()->create([
            'language' => 'en', 'category' => 'other', 'source_file' => 'asthma.txt', 'chunk_index' => 0,
            'content' => mb_substr((string) file_get_contents(resource_path('health_docs/en/other/asthma.txt')), 0, 500),
            'embedding' => array_fill(0, 8, 0.1),
        ]);

        $this->residentAsk()->assertStatus(503)->assertExactJson([
            'error' => 'ai_unavailable',
            'message' => ChatbotController::AI_UNAVAILABLE_MESSAGE,
        ]);

        $this->assertSame(0, DB::table('chatbot_messages')->count(), 'transaction rolled back as before');
        $this->assertSame(0, DB::table('chatbot_conversations')->count());
        Http::assertNotSent(fn (Request $r) => $r->url() === 'http://127.0.0.1:11434/api/chat');
    }

    public function test_misconfiguration_is_friendly_and_other_errors_are_unchanged(): void
    {
        Http::fake();
        config(['services.ollama.chat_provider' => 'local']); // local + "-cloud" model = configuration error
        $this->mock(RagService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ask')->andThrow(new ChatbotAiUnavailableException('Chat provider "local" cannot use the cloud model.'));
        });
        $this->residentAsk()->assertStatus(503)->assertJson(['error' => 'ai_unavailable']);

        $this->mock(RagService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ask')->andThrow(new RuntimeException('unrelated failure'));
        });
        $this->residentAsk()->assertStatus(500);
    }

    public function test_status_command_never_prints_the_key_and_reports_local_server(): void
    {
        $this->fakeOllama();

        $this->assertSame(0, Artisan::call('chatbot:ai-status'));
        $output = Artisan::output();
        $this->assertStringNotContainsString(self::TEST_KEY, $output);
        $this->assertStringContainsString('configured (value hidden)', $output);
        $this->assertStringContainsString('gemma4:31b-cloud', $output);
        $this->assertStringContainsString('nomic-embed-text — installed locally', $output);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'ollama.com'));

        config(['services.ollama.chat_provider' => 'local']);
        $this->assertSame(1, Artisan::call('chatbot:ai-status'));
        $this->assertStringContainsString('CONFIGURATION ERROR', Artisan::output());
    }

    public function test_evaluate_writes_a_private_csv_and_saves_no_chat_history(): void
    {
        $this->fakeOllama();
        Storage::fake('chatbot_evaluations');

        $this->artisan('chatbot:evaluate', ['--dry-run' => true, '--limit' => 2])->assertSuccessful();
        $this->assertSame([], Storage::disk('chatbot_evaluations')->allFiles());

        // D-C9: a cloud run is refused without --allow-cloud (nothing written).
        $this->artisan('chatbot:evaluate', ['--limit' => 3, '--no-interaction' => true])->assertFailed();
        $this->assertSame([], Storage::disk('chatbot_evaluations')->allFiles());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'ollama.com'));

        $this->artisan('chatbot:evaluate', ['--limit' => 3, '--no-interaction' => true, '--allow-cloud' => true])->assertSuccessful();
        $files = Storage::disk('chatbot_evaluations')->allFiles();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^evaluation-\d{8}-\d{6}-cloud-gemma4-31b-cloud\.csv$/', $files[0]);
        $lines = array_values(array_filter(explode("\n", Storage::disk('chatbot_evaluations')->get($files[0]))));
        $this->assertCount(4, $lines, 'header + 3 cases');
        $this->assertStringStartsWith('test_id,language,topic,intent,question,expected_result,provider,chat_model,status,latency_ms,chat_calls', $lines[0]);
        $this->assertStringNotContainsString(self::TEST_KEY, implode("\n", $lines));
        $this->assertFalse(Schema::hasTable('chatbot_messages') && DB::table('chatbot_messages')->exists());

        // Bad provider/model combination: refused before any call.
        $this->artisan('chatbot:evaluate', ['--provider' => 'local', '--model' => 'gemma4:31b-cloud', '--no-interaction' => true])->assertFailed();
        $this->assertCount(1, Storage::disk('chatbot_evaluations')->allFiles());
    }

    public function test_evaluate_records_errors_instead_of_stopping(): void
    {
        Storage::fake('chatbot_evaluations');
        $this->mock(RagService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('withChatSettings')->andReturnSelf();
            $mock->shouldReceive('chatSettings')->andReturn([
                'provider' => 'auto', 'chat_model' => 'gemma4:31b-cloud', 'embedding_model' => 'nomic-embed-text',
                'endpoint' => ['provider' => 'cloud', 'url' => 'https://ollama.com/api/chat', 'token' => 'hidden'],
            ]);
            $mock->shouldReceive('ask')->andThrow(new ConnectionException('placeholder'));
        });

        $this->artisan('chatbot:evaluate', ['--limit' => 2, '--no-interaction' => true, '--allow-cloud' => true])->assertSuccessful();
        $csv = Storage::disk('chatbot_evaluations')->get(Storage::disk('chatbot_evaluations')->allFiles()[0]);
        $rows = array_slice(array_values(array_filter(explode("\n", $csv))), 1);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('error', str_getcsv($row, escape: '\\')[8]);
        }
        $this->assertStringContainsString('Illuminate\Http\Client\ConnectionException', $csv);
    }
}
