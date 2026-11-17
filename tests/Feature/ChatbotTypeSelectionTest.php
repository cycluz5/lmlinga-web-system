<?php

namespace Tests\Feature;

use App\Models\ResidentAccount;
use App\Services\RagService;
use App\Support\ResidentAuthenticator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Support\ClientTestingErdSchema;

/**
 * Bikol - Iriga has three chatbot types (Context-based, Local AI model, Cloud AI) and NO default:
 * the resident must choose one before chatting. English and Tagalog have no types and are unchanged.
 */
class ChatbotTypeSelectionTest extends TestCase
{
    use RefreshDatabase;

    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();

        // The chatbot tables are not built by the SQLite test migrations; mirror the live columns.
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

        $this->accountId = (int) ResidentAccount::factory()->create(['email' => 'chat.type@example.test'])->getKey();

        // No network, no AI model: only the controller's handling of the chosen type is under test.
        Http::fake();
        config(['services.bikol_chat.enabled' => false]);
    }

    private function mockRag(?string $expectedMode, string $language): void
    {
        $this->mock(RagService::class, function (MockInterface $mock) use ($expectedMode, $language): void {
            if ($expectedMode === null) {
                $mock->shouldNotReceive('setAnswerMode');
                $mock->shouldNotReceive('ask');

                return;
            }
            $mock->shouldReceive('setAnswerMode')->with($expectedMode)->once();
            $mock->shouldReceive('deriveTopicHintFromText')->andReturn(null);
            $mock->shouldReceive('ask')->andReturn([
                'title' => null,
                'answer' => 'Stub answer.',
                'points' => [],
                'sources' => [],
                'language' => $language,
                'is_conversation' => false,
            ]);
        });
    }

    /** @param array<string, mixed> $payload */
    private function askChat(array $payload)
    {
        return $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $this->accountId,
            ResidentAuthenticator::SESSION_EMAIL => 'chat.type@example.test',
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ])->postJson(route('chatbot.ask'), $payload);
    }

    public function test_a_bikol_message_without_a_chatbot_type_is_refused_and_nothing_is_saved(): void
    {
        $this->mockRag(null, 'bcl');

        $this->askChat(['question' => 'Ono ana dengue?', 'language' => 'bcl'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mode'])
            ->assertJsonPath('errors.mode.0', 'Choose a chatbot type first: Context-based, Local AI model or Cloud AI (Gemma).');

        $this->assertSame(0, DB::table('chatbot_conversations')->count());
        $this->assertSame(0, DB::table('chatbot_messages')->count());
    }

    public function test_the_language_defaults_to_bikol_so_a_missing_type_is_refused_there_too(): void
    {
        $this->mockRag(null, 'bcl');

        $this->askChat(['question' => 'Ono ana dengue?'])->assertStatus(422)->assertJsonValidationErrors(['mode']);
    }

    public function test_an_unknown_chatbot_type_is_refused(): void
    {
        $this->mockRag(null, 'bcl');

        $this->askChat(['question' => 'Ono ana dengue?', 'language' => 'bcl', 'mode' => 'gemma'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);
    }

    /** @return array<string, array{0: string}> */
    public static function chatbotTypes(): array
    {
        return ['Context-based' => ['context'], 'Local AI model' => ['ai'], 'Cloud AI (Gemma)' => ['cloud']];
    }

    #[DataProvider('chatbotTypes')]
    public function test_a_bikol_message_is_answered_in_the_chosen_type(string $mode): void
    {
        $this->mockRag($mode, 'bcl');

        $this->askChat(['question' => 'Ono ana dengue?', 'language' => 'bcl', 'mode' => $mode])
            ->assertOk()
            ->assertJsonPath('mode', $mode)
            ->assertJsonPath('answer', 'Stub answer.');

        $this->assertSame(1, DB::table('chatbot_conversations')->count());
    }

    #[DataProvider('chatbotTypes')]
    public function test_english_and_tagalog_need_no_type_and_always_use_the_plain_pipeline(string $ignoredMode): void
    {
        foreach (['en', 'tl'] as $language) {
            $this->mockRag('context', $language);

            // no type at all
            $this->askChat(['question' => 'What is dengue?', 'language' => $language])
                ->assertOk()
                ->assertJsonPath('mode', 'context');
        }
    }

    public function test_english_ignores_a_type_that_was_sent(): void
    {
        $this->mockRag('context', 'en');

        $this->askChat(['question' => 'What is dengue?', 'language' => 'en', 'mode' => 'cloud'])
            ->assertOk()
            ->assertJsonPath('mode', 'context');
    }

    public function test_the_chat_page_has_one_switch_with_three_usable_buttons_and_nothing_selected(): void
    {
        ClientTestingErdSchema::ensure();

        $accountId = DB::table('resident_accounts')->insertGetId([
            'first_name' => 'Chat',
            'middle_name' => 'X',
            'last_name' => 'Type',
            'zone_purok' => '3',
            'email' => 'chat.type.page@example.test',
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $accountId,
            ResidentAuthenticator::SESSION_EMAIL => 'chat.type.page@example.test',
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ])->get(route('chatbot.main'))->assertOk()->getContent();

        // exactly one switch (the merge of two branches had left two of them)
        $this->assertSame(1, substr_count($html, 'data-lml-modes'));
        $this->assertSame(1, preg_match_all('/data-lml-mode-status(?!-)/', $html), 'one status line');

        preg_match_all('/<button[^>]*data-lml-mode="(context|ai|cloud)"[^>]*>/s', $html, $buttons);
        $this->assertSame(['context', 'ai', 'cloud'], $buttons[1]);

        foreach ($buttons[0] as $tag) {
            $this->assertStringNotContainsString('disabled', $tag, 'every chatbot type can be chosen');
            $this->assertStringNotContainsString('mode-btn--active', $tag, 'nothing is selected at the start');
            $this->assertStringContainsString('aria-pressed="false"', $tag);
        }

        $this->assertStringContainsString('Choose a chatbot type to start chatting', $html);
        $this->assertStringNotContainsString('Not available', $html);
    }
}
