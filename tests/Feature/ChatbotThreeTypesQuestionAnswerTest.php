<?php

namespace Tests\Feature;

use App\Models\HealthChunk;
use App\Models\ResidentAccount;
use App\Services\BikolCloudChatService;
use App\Services\BikolIrigaTerms;
use App\Services\BikolSmallTalkService;
use App\Support\ResidentAuthenticator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Question and answer tests for the three Bikol - Iriga chatbot types, through the real chat endpoint
 * (controller, RAG retrieval over the real verified documents, small-talk routing). Only the two AI models
 * are faked (no network):
 *
 *   Context-based   verified documents + rules. No AI model is ever called.
 *   Local AI model  the locally trained Bikol-Iriga model answers everyday (unrelated) messages;
 *                   health questions are answered from the verified documents, never by the model.
 *   Cloud AI        Gemma (cloud) answers everyday messages and re-tells the verified health answer;
 *                   it may not add numbers, and when it is not reachable the resident is told so.
 *
 * In every type a serious message ("no hope") gets the team's fixed, checked reply and no model is called.
 */
class ChatbotThreeTypesQuestionAnswerTest extends TestCase
{
    use RefreshDatabase;

    private const LOCAL_REPLY = 'Maray man ako, salamat!';
    private const CLOUD_REPLY = 'Maray man ako, ika man?';

    private int $accountId;

    /** Http::fake() cannot be overridden once set (the first stub wins), so a test fakes the models exactly once. */
    private bool $modelsFaked = false;

    protected function setUp(): void
    {
        parent::setUp();
        set_time_limit(0);

        if (! Schema::hasTable('chatbot_conversations')) {
            Schema::create('chatbot_conversations', function (Blueprint $table): void {
                $table->id('conversation_id');
                $table->unsignedBigInteger('account_id');
                $table->string('title', 150)->nullable();
                $table->boolean('is_pinned')->default(false);
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('chatbot_messages')) {
            Schema::create('chatbot_messages', function (Blueprint $table): void {
                $table->id('message_id');
                $table->unsignedBigInteger('conversation_id');
                $table->string('sender', 20);
                $table->text('message_text');
                $table->string('language', 10)->nullable();
                $table->string('category', 50)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        // The real Bikol - Iriga verified documents are what the chatbot retrieves from.
        foreach (glob(resource_path('health_docs_v2/bcl/*/*.txt')) ?: [] as $path) {
            HealthChunk::create([
                'language' => 'bcl',
                'category' => basename(dirname($path)),
                'source_file' => basename($path),
                'chunk_index' => 0,
                'content' => (string) file_get_contents($path),
                'embedding' => [0.0],
            ]);
        }

        $this->accountId = (int) ResidentAccount::factory()->create(['email' => 'chat.qa@example.test'])->getKey();

        config([
            'services.ollama.api_key' => 'test-key',
            'services.bikol_chat.enabled' => true,
            'services.bikol_chat.gate_enabled' => true,
            'services.bikol_chat.gate_min_confidence' => 0.75,
            'services.bikol_chat.send_context' => false,
            'services.bikol_chat.reviewed_enabled' => false,   // the reviewed-replies layer has its own tests
        ]);
    }

    protected function tearDown(): void
    {
        BikolSmallTalkService::useMode('ai');
        BikolSmallTalkService::useHistory([]);
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    /** @param list<string> $cloudReplies answers of the cloud model, in order (the last one repeats) */
    private function fakeModels(array $cloudReplies = [self::CLOUD_REPLY], string $localReply = self::LOCAL_REPLY): void
    {
        $this->modelsFaked = true;
        $cloud = Http::sequence();
        foreach ($cloudReplies as $text) {
            $cloud->push(['message' => ['content' => $text]]);
        }
        $cloud->whenEmpty(Http::response(['message' => ['content' => end($cloudReplies)]]));

        Http::fake([
            '*api/embeddings' => Http::response(['embedding' => [0.0]]),
            'ollama.com/*' => $cloud,                                                    // Cloud AI (Gemma)
            '*/api/chat' => Http::response(['message' => ['content' => $localReply]]),   // local trained model
        ]);
    }

    /** @return array<string, mixed> */
    private function askChat(string $question, string $mode): array
    {
        if (! $this->modelsFaked) {
            $this->fakeModels();
        }

        $response = $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $this->accountId,
            ResidentAuthenticator::SESSION_EMAIL => 'chat.qa@example.test',
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ])->postJson(route('chatbot.ask'), ['question' => $question, 'language' => 'bcl', 'mode' => $mode]);

        $response->assertOk()->assertJsonPath('mode', $mode);

        return $response->json();
    }

    private static function isCloudCall(Request $request): bool
    {
        return str_contains($request->url(), 'ollama.com/api/chat');
    }

    private static function isLocalModelCall(Request $request): bool
    {
        return str_contains($request->url(), '/api/chat') && ! self::isCloudCall($request);
    }

    private function assertNoModelWasCalled(): void
    {
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/api/chat'));
    }

    private function documentText(string $file): string
    {
        $paths = glob(resource_path('health_docs_v2/bcl/*/' . $file)) ?: [];
        $this->assertNotEmpty($paths, "document {$file} exists");

        return $this->normalize((string) file_get_contents($paths[0]));
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(['’', '‘', '“', '”', '–', '—'], ["'", "'", '"', '"', '-', '-'], $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Every line the chatbot returned must be text of the document: nothing is made up. */
    private function assertEveryLineIsFromTheDocument(array $reply, string $file): void
    {
        $document = $this->documentText($file);
        $checked = 0;
        foreach (array_merge([(string) $reply['answer']], array_map('strval', $reply['points'])) as $part) {
            foreach (preg_split('/\R+/u', $part) ?: [] as $line) {
                $line = $this->normalize(ltrim(trim($line), "-•* \t"));
                if (mb_strlen($line) < 12) {
                    continue;
                }
                $this->assertStringContainsString($line, $document, "a returned line is not in {$file}");
                $checked++;
            }
        }
        $this->assertGreaterThan(0, $checked, 'the reply has text');
    }

    private function allText(array $reply): string
    {
        return trim((string) $reply['answer'] . "\n" . implode("\n", array_map('strval', $reply['points'])));
    }

    // ------------------------------------------------------------------ 1. health questions: Context-based and Local AI

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function healthQuestions(): array
    {
        $questions = [
            ['Ono ana hika?', 'asthma.txt', 'osad na pang maubanang kondisyon'],
            ['Ono ana diyagnosis kana hika?', 'asthma.txt', 'Diri madaling maisihan ana hika'],
            ['Ono ana atake sa poso?', 'heart_attack.txt', 'nagbabarahan ana mga ugat kana poso'],
            ['Paono binubulong ana Leptospirosis?', 'leptospirosis.txt', 'Penicillin'],
            ['Ono ana alangkaw na presyon kana rogo?', 'high_blood_pressure.txt', 'puwersang nalilikha kapag ibinubusol kana rogo'],
        ];
        $cases = [];
        foreach (['context', 'ai'] as $mode) {
            foreach ($questions as [$question, $file, $phrase]) {
                $cases["{$mode}: {$question}"] = [$mode, $question, $file, $phrase];
            }
        }

        return $cases;
    }

    #[DataProvider('healthQuestions')]
    public function test_a_health_question_is_answered_from_the_verified_document_and_never_by_an_ai_model(
        string $mode,
        string $question,
        string $file,
        string $phrase
    ): void {
        // the expectation itself comes from the document
        $this->assertStringContainsString($this->normalize($phrase), $this->documentText($file));

        $reply = $this->askChat($question, $mode);

        $this->assertSame([$file], $reply['sources']);
        $this->assertStringContainsString($this->normalize($phrase), $this->normalize($this->allText($reply)));
        $this->assertEveryLineIsFromTheDocument($reply, $file);
        $this->assertNull($reply['answered_by'] ?? null, 'a verified answer is not an AI reply');
        $this->assertNoModelWasCalled();
    }

    // ------------------------------------------------------------------ 2. Cloud AI and health questions

    public function test_cloud_ai_retells_the_verified_answer_and_only_sees_the_verified_facts(): void
    {
        $this->fakeModels(['Ana hika ay osad na pang maubanang kondisyon na nakakaapektar kana mga agihan kana angin sa baga. Magpakonsulta sa health center kin nagmamalala.']);

        $reply = $this->askChat('Ono ana hika?', 'cloud');

        $this->assertSame(['asthma.txt'], $reply['sources']);
        $this->assertSame(BikolCloudChatService::ANSWERED_BY . ' + verified health info', $reply['answered_by']);
        $this->assertStringContainsString('osad na pang maubanang kondisyon', $reply['answer']);
        Http::assertSent(function (Request $r): bool {
            if (! self::isCloudCall($r)) {
                return false;
            }
            $messages = $r['messages'];
            $last = end($messages)['content'];

            return str_contains($last, 'VERIFIED ANSWER:')
                && str_contains($last, 'osad na pang maubanang kondisyon')
                && str_contains($last, 'Ono ana hika?');
        });
        Http::assertNotSent(fn (Request $r) => self::isLocalModelCall($r));
    }

    public function test_cloud_ai_may_not_add_numbers_that_are_not_in_the_verified_answer(): void
    {
        $this->fakeModels(['Ana hika ay osad na pang maubanang kondisyon na nakakaapektar sa 95% kana mga tawo ngowan, magpakonsulta sa health center.']);

        $reply = $this->askChat('Ono ana hika?', 'cloud');

        $this->assertSame('Verified health information', $reply['answered_by']);
        $this->assertStringNotContainsString('95', $this->allText($reply));
        $this->assertStringContainsString('osad na pang maubanang kondisyon', $this->normalize($this->allText($reply)));
        $this->assertEveryLineIsFromTheDocument($reply, 'asthma.txt');
    }

    public function test_cloud_ai_sends_the_question_to_normal_chat_when_the_document_does_not_answer_it(): void
    {
        // RAG finds asthma.txt and builds a verified answer. The prompt tells Gemma to write NO_ANSWER when the
        // verified answer is about another topic (a wrong match); here Gemma judges exactly that. The message is
        // then handled as normal chat (second call) and is not shown as a health answer.
        $this->fakeModels(['NO_ANSWER', self::CLOUD_REPLY]);

        $reply = $this->askChat('Ono ana hika?', 'cloud');

        $this->assertSame(self::CLOUD_REPLY, $reply['answer']);
        $this->assertSame(BikolCloudChatService::ANSWERED_BY, $reply['answered_by']);
        $this->assertSame([], $reply['sources']);

        // proof that the document path really ran first: two Gemma calls, the first one with the verified facts
        $cloudCalls = array_values(Http::recorded(fn (Request $r) => self::isCloudCall($r))->all());
        $this->assertCount(2, $cloudCalls);
        $first = $cloudCalls[0][0]['messages'];
        $second = $cloudCalls[1][0]['messages'];
        $this->assertStringContainsString('VERIFIED ANSWER:', end($first)['content']);
        $this->assertStringNotContainsString('VERIFIED ANSWER:', end($second)['content']);
    }

    public function test_cloud_ai_tells_the_resident_when_it_cannot_be_reached_and_gives_no_other_answer(): void
    {
        $this->modelsFaked = true;
        Http::fake([
            '*api/embeddings' => Http::response(['embedding' => [0.0]]),
            'ollama.com/*' => Http::response('error', 500),
        ]);

        $reply = $this->askChat('Ono ana hika?', 'cloud');

        $this->assertSame(BikolCloudChatService::OFFLINE_MESSAGE, $reply['answer']);
        $this->assertSame(BikolCloudChatService::ANSWERED_BY . ' - offline', $reply['answered_by']);
        $this->assertSame([], $reply['sources']);
        Http::assertNotSent(fn (Request $r) => self::isLocalModelCall($r));
    }

    // ------------------------------------------------------------------ 3. everyday (unrelated) messages

    public function test_context_based_uses_no_ai_for_an_everyday_message(): void
    {
        $reply = $this->askChat('Kumusta ka?', 'context');

        $this->assertStringContainsString('Uda ako sapat na impormasyon', $reply['answer']);
        $this->assertSame([], $reply['sources']);
        $this->assertNull($reply['answered_by'] ?? null);
        $this->assertNoModelWasCalled();
    }

    public function test_local_ai_answers_an_everyday_message_with_the_trained_model(): void
    {
        $reply = $this->askChat('Kumusta ka?', 'ai');

        $this->assertSame(self::LOCAL_REPLY, $reply['answer']);
        $this->assertSame('Bikol-Iriga LLM (fine-tuned)', $reply['answered_by']);
        $this->assertSame([], $reply['sources']);
        Http::assertSent(fn (Request $r) => self::isLocalModelCall($r)
            && $r['model'] === config('services.bikol_chat.model'));
        Http::assertNotSent(fn (Request $r) => self::isCloudCall($r));
    }

    public function test_cloud_ai_answers_an_everyday_message_with_gemma(): void
    {
        $reply = $this->askChat('Kumusta ka?', 'cloud');

        $this->assertSame(self::CLOUD_REPLY, $reply['answer']);
        $this->assertSame(BikolCloudChatService::ANSWERED_BY, $reply['answered_by']);
        $this->assertSame([], $reply['sources']);
        Http::assertSent(fn (Request $r) => self::isCloudCall($r) && $r->hasHeader('Authorization', 'Bearer test-key'));
        Http::assertNotSent(fn (Request $r) => self::isLocalModelCall($r));
    }

    public function test_cloud_ai_gives_the_teams_fixed_reply_when_gemma_says_it_is_a_health_question(): void
    {
        $this->fakeModels([BikolCloudChatService::HEALTH_NO_INFO]);

        $reply = $this->askChat('Ono a paborito mong pagkaon?', 'cloud');

        $this->assertSame('Fixed checked reply', $reply['answered_by']);
        $this->assertStringNotContainsString(BikolCloudChatService::HEALTH_NO_INFO, $reply['answer']);
        $this->assertNotSame('', trim($reply['answer']));
        $this->assertSame(app(BikolIrigaTerms::class)->healthRedirect() ?: $reply['answer'], $reply['answer']);
    }

    public function test_local_ai_never_shows_a_model_reply_that_gives_medical_advice(): void
    {
        // The model must not give health advice; if it does, the resident never sees it.
        $this->fakeModels([self::CLOUD_REPLY], 'Uminom ka sa gamot na dosis.');

        $reply = $this->askChat('Kumusta ka?', 'ai');

        $this->assertStringNotContainsString('dosis', mb_strtolower($reply['answer']));
        $this->assertNotSame('Uminom ka sa gamot na dosis.', $reply['answer']);
        $this->assertNotSame('', trim($reply['answer']));
        $this->assertSame([], $reply['sources']);
    }

    // ------------------------------------------------------------------ 4. serious message, every type

    /** @return array<string, array{0: string}> */
    public static function threeTypes(): array
    {
        return ['Context-based' => ['context'], 'Local AI model' => ['ai'], 'Cloud AI (Gemma)' => ['cloud']];
    }

    #[DataProvider('threeTypes')]
    public function test_a_serious_message_always_gets_the_fixed_checked_reply_and_no_model_is_called(string $mode): void
    {
        $reply = $this->askChat('Uda na akong pag-asa', $mode);

        $this->assertSame(BikolSmallTalkService::REPLY_NO_HOPE, $reply['answer']);
        $this->assertSame([], $reply['sources']);
        $this->assertNoModelWasCalled();
    }
}
