<?php

namespace Tests\Feature;

use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Models\HealthChunk;
use App\Models\ResidentAccount;
use App\Services\RagService;
use App\Support\ResidentAuthenticator;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

/**
 * A failed chatbot request must not put the resident's question, the answer or exception
 * details (which contain them) into the log or the response, and must leave no partial history.
 */
class ChatbotFailedSavePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const QUESTION = 'SENTINELQUESTION what is asthma please';
    private const ANSWER = 'SENTINELANSWER';
    private const POINT = 'SENTINELPOINT';

    /** @var list<array{level: string, message: string, context: array<string, mixed>, flat: string}> */
    private array $entries = [];

    private ResidentAccount $account;

    /** What the fake AI chat endpoint does: a reply string, 'no-content' or 'http-500'. */
    private string $aiReply = '';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ollama.base_url' => 'http://127.0.0.1:11434',
            'services.ollama.chat_provider' => 'local',
            'services.ollama.chat_model' => 'mock-model',
        ]);
        $this->createChatTables();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $exception = $e->context['exception'] ?? null;
            $this->entries[] = [
                'level' => $e->level,
                'message' => $e->message,
                'context' => $e->context,
                'flat' => $e->message.' '.json_encode(array_diff_key($e->context, ['exception' => 1]))
                    .($exception instanceof \Throwable ? ' '.$exception->getMessage().' '.$exception->getTraceAsString() : ''),
            ];
        });
        HealthChunk::query()->create([
            'language' => 'en', 'category' => 'other', 'source_file' => 'asthma.txt', 'chunk_index' => 0,
            'content' => mb_substr((string) file_get_contents(resource_path('health_docs/en/other/asthma.txt')), 0, 500),
            'embedding' => array_fill(0, 8, 0.1),
        ]);
        $this->account = ResidentAccount::factory()->create(['email' => 'failedsave.resident@example.test']);
        $this->aiReply = json_encode(['answer' => self::ANSWER.' reply.', 'points' => [self::POINT.' one.']]);
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_ends_with($request->url(), '/api/embeddings')) {
                return Http::response(['embedding' => array_fill(0, 8, 0.1)]);
            }
            if (str_ends_with($request->url(), '/api/tags')) {
                return Http::response(['models' => [['name' => 'nomic-embed-text:latest']]]);
            }

            return match ($this->aiReply) {
                'no-content' => Http::response(['message' => []]),
                'http-500' => Http::response(['error' => 'down'], 500),
                default => Http::response(['message' => ['content' => $this->aiReply]]),
            };
        });
    }

    /** The testing schema has no chatbot history tables; same approach as ChatbotAiProviderTest::residentAsk(). */
    private function createChatTables(): void
    {
        if (! Schema::hasTable('chatbot_conversations')) {
            Schema::create('chatbot_conversations', function (Blueprint $t): void {
                $t->id('conversation_id');
                $t->unsignedBigInteger('account_id');
                $t->string('title', 150)->nullable();
                $t->boolean('is_pinned')->default(false);
                $t->timestamp('last_message_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('chatbot_messages')) {
            Schema::create('chatbot_messages', function (Blueprint $t): void {
                $t->id('message_id');
                $t->unsignedBigInteger('conversation_id');
                $t->string('sender', 20);
                $t->text('message_text');
                $t->string('language', 10)->nullable();
                $t->string('category', 50)->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    private function ask(string $question = self::QUESTION, array $extra = []): TestResponse
    {
        return $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $this->account->getKey(),
            ResidentAuthenticator::SESSION_EMAIL => 'failedsave.resident@example.test',
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ])->postJson(route('chatbot.ask'), $extra + ['question' => $question, 'language' => 'en']);
    }

    private function failOn(string $when): void
    {
        DB::unprepared(match ($when) {
            'conversation insert' => "CREATE TRIGGER fs_fail BEFORE INSERT ON chatbot_conversations BEGIN SELECT RAISE(ABORT, 'forced failure'); END",
            'resident message' => "CREATE TRIGGER fs_fail BEFORE INSERT ON chatbot_messages WHEN NEW.sender = 'Resident' BEGIN SELECT RAISE(ABORT, 'forced failure'); END",
            'reply' => "CREATE TRIGGER fs_fail BEFORE INSERT ON chatbot_messages WHEN NEW.sender = 'Chatbot' BEGIN SELECT RAISE(ABORT, 'forced failure'); END",
            'conversation update' => "CREATE TRIGGER fs_fail BEFORE UPDATE ON chatbot_conversations BEGIN SELECT RAISE(ABORT, 'forced failure'); END",
        });
    }

    private function assertNoResidentTextLogged(): void
    {
        foreach ($this->entries as $entry) {
            // 'SENTINELQ' also catches the 15-character argument PHP keeps in stack traces.
            foreach (['SENTINELQ', self::ANSWER, self::POINT] as $text) {
                $this->assertStringNotContainsString($text, $entry['flat'], "Resident text reached the log: {$entry['message']}");
            }
        }
    }

    private function assertSafeFailureLogged(string $expectedReason): void
    {
        $failures = array_values(array_filter($this->entries, fn (array $e): bool => $e['message'] === 'Chatbot request failed'));
        $this->assertCount(1, $failures, 'exactly one failure entry, so failures stay diagnosable');
        $context = $failures[0]['context'];
        $this->assertSame('error', $failures[0]['level']);
        $this->assertSame($expectedReason, $context['reason']);
        $this->assertArrayNotHasKey('exception', $context);
        $this->assertSame([], array_values(array_filter($this->entries, fn (array $e): bool => isset($e['context']['exception']))), 'no raw exception may be reported');
        $this->assertStringStartsWith('app/', $context['location'], 'points at application code, not vendor');
    }

    private function assertGenericServerError(TestResponse $response): void
    {
        $response->assertStatus(500);
        $this->assertSame(['message' => 'Server Error'], $response->json());
    }

    /** @return array<string, array{string}> */
    public static function saveFailureProvider(): array
    {
        return [
            'conversation insert fails' => ['conversation insert'],
            'resident message fails' => ['resident message'],
            'reply fails' => ['reply'],
            'last update fails' => ['conversation update'],
        ];
    }

    #[DataProvider('saveFailureProvider')]
    public function test_failed_save_keeps_text_out_of_the_log_and_leaves_no_partial_history(string $when): void
    {
        $this->failOn($when);

        $this->assertGenericServerError($this->ask());

        $this->assertNoResidentTextLogged();
        $this->assertSafeFailureLogged(QueryException::class);
        $this->assertSame(0, ChatbotConversation::query()->count());
        $this->assertSame(0, ChatbotMessage::query()->count());
    }

    #[DataProvider('saveFailureProvider')]
    public function test_failed_save_reveals_nothing_in_the_response_with_debug_on(string $when): void
    {
        config(['app.debug' => true]);
        $this->failOn($when);

        $response = $this->ask();

        $this->assertGenericServerError($response);
        foreach (['SENTINELQ', self::ANSWER, self::POINT, 'SQLSTATE', 'insert into'] as $text) {
            $this->assertStringNotContainsString($text, $response->getContent());
        }
        $this->assertNoResidentTextLogged();
    }

    /** @return array<string, array{string}> */
    public static function existingConversationFailureProvider(): array
    {
        return ['resident message fails' => ['resident message'], 'reply fails' => ['reply'], 'last update fails' => ['conversation update']];
    }

    #[DataProvider('existingConversationFailureProvider')]
    public function test_failure_in_an_existing_conversation_keeps_earlier_history_only(string $when): void
    {
        $id = $this->ask('What is asthma?')->assertOk()->json('conversation_id');
        $this->assertSame(2, ChatbotMessage::query()->count());
        $this->failOn($when);
        $this->travel(5)->minutes();

        $this->assertGenericServerError($this->ask(self::QUESTION, ['conversation_id' => $id]));

        $this->assertNoResidentTextLogged();
        $this->assertSame(2, ChatbotMessage::query()->where('conversation_id', $id)->count());
        $this->assertSame(0, ChatbotMessage::query()->where('message_text', 'like', '%SENTINELQ%')->count());
    }

    public function test_ai_reply_without_content_is_not_logged_with_the_question(): void
    {
        $this->aiReply = 'no-content';

        $this->assertGenericServerError($this->ask());

        $this->assertNoResidentTextLogged();
        $this->assertSafeFailureLogged(\TypeError::class);
        $this->assertSame(0, ChatbotMessage::query()->count());
    }

    public function test_exception_message_is_never_logged_even_if_it_quotes_the_question(): void
    {
        $this->app->instance(RagService::class, new class extends RagService
        {
            public function ask(string $question, string $language, ?string $category = null, ?string $topicHint = null): array
            {
                throw new RuntimeException('unexpected failure for '.$question);
            }
        });

        $this->assertGenericServerError($this->ask());

        $this->assertNoResidentTextLogged();
        $this->assertSafeFailureLogged(RuntimeException::class);
        $this->assertSame(0, ChatbotMessage::query()->count());
    }

    /** @return array<string, array{string}> */
    public static function tooLongProvider(): array
    {
        return [
            'one over the limit' => [str_repeat('x', 1984).'SENTINELQ whats'.'xx'],
            'multibyte one over the limit' => [str_repeat('ñ', 1991).'SENTINELQx'],
            'causes the search to fail today' => ['SENTINELQ '.str_repeat('x', 40000)],
            'too long for the database column' => ['SENTINELQ '.str_repeat('x', 70000)],
        ];
    }

    #[DataProvider('tooLongProvider')]
    public function test_too_long_question_is_rejected_before_anything_is_saved_logged_or_sent(string $question): void
    {
        $this->assertGreaterThan(2000, mb_strlen($question));

        $response = $this->ask($question);

        $response->assertStatus(422)->assertJsonValidationErrors('question');
        $this->assertStringNotContainsString('SENTINELQ', $response->getContent());
        $this->assertNoResidentTextLogged();
        $this->assertSame(0, ChatbotConversation::query()->count());
        $this->assertSame(0, ChatbotMessage::query()->count());
        Http::assertNothingSent();
    }

    public function test_question_at_the_limit_is_answered_and_saved(): void
    {
        foreach ([str_repeat('x', 1975).' what is asthma?', str_repeat('ñ', 1975).' what is asthma?'] as $question) {
            $this->assertLessThanOrEqual(2000, mb_strlen($question));
            $this->ask($question)->assertOk();
            $this->assertSame(1, ChatbotMessage::query()->where('sender', 'Resident')->where('message_text', $question)->count());
        }
        $this->assertSame([], array_filter($this->entries, fn (array $e): bool => $e['message'] === 'Chatbot request failed'));
    }

    public function test_successful_chat_is_unchanged(): void
    {
        $response = $this->ask('What is asthma?')->assertOk();

        $this->assertSame('en', $response->json('language'));
        $this->assertStringContainsString(self::ANSWER, $response->json('answer'));
        $this->assertSame(1, ChatbotConversation::query()->count());
        $this->assertSame(['Resident', 'Chatbot'], ChatbotMessage::query()->orderBy('message_id')->pluck('sender')->all());
        $this->assertSame([], array_filter($this->entries, fn (array $e): bool => $e['message'] === 'Chatbot request failed'));
    }

    public function test_ai_unavailable_still_gets_the_friendly_reply(): void
    {
        $this->aiReply = 'http-500';

        $this->ask()->assertStatus(503)->assertJsonPath('error', 'ai_unavailable');

        $this->assertNoResidentTextLogged();
        $this->assertSame([], array_filter($this->entries, fn (array $e): bool => $e['message'] === 'Chatbot request failed'));
        $this->assertSame(0, ChatbotMessage::query()->count());
    }

    public function test_another_residents_conversation_is_still_not_found(): void
    {
        $other = ResidentAccount::factory()->create(['email' => 'failedsave.other@example.test']);
        $conversation = ChatbotConversation::query()->create(['account_id' => $other->getKey(), 'title' => 'Other', 'is_pinned' => false]);

        $this->ask(self::QUESTION, ['conversation_id' => $conversation->conversation_id])->assertNotFound();

        $this->assertNoResidentTextLogged();
        $this->assertSame([], array_filter($this->entries, fn (array $e): bool => $e['message'] === 'Chatbot request failed'));
        $this->assertSame(0, ChatbotMessage::query()->count());
    }

    public function test_validation_failure_is_still_a_validation_response(): void
    {
        $this->ask(self::QUESTION, ['conversation_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('conversation_id');
        $this->ask(self::QUESTION, ['language' => 'xx'])->assertStatus(422)->assertJsonValidationErrors('language');
        $this->assertNoResidentTextLogged();
    }

    public function test_message_box_limits_length_in_the_browser(): void
    {
        // Same setup as ChatbotRegisterAndMainHouseholdUiTest: the page needs the ERD schema, then an account in it.
        ClientTestingErdSchema::ensure();
        $accountId = DB::table('resident_accounts')->insertGetId([
            'first_name' => 'Fictional',
            'middle_name' => 'X',
            'last_name' => 'Resident',
            'zone_purok' => '3',
            'email' => 'failedsave.page@example.test',
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $page = $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $accountId,
            ResidentAuthenticator::SESSION_EMAIL => 'failedsave.page@example.test',
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ])->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-lml-composer-input[^>]*maxlength="2000"/s', $page);
    }
}
