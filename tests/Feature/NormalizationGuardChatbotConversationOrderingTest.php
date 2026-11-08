<?php

namespace Tests\Feature;

use App\Models\ResidentAccount;
use App\Services\RagService;
use App\Support\ResidentAuthenticator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Normalization guard (Phase 1): chatbot_conversations.last_message_at is a sort cache.
 * Sending a message refreshes it, the conversation list is ordered by it (latest activity
 * first), and it always equals the conversation's latest message time.
 */
class NormalizationGuardChatbotConversationOrderingTest extends TestCase
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

        $this->accountId = (int) ResidentAccount::factory()->create(['email' => 'chat.order@example.test'])->getKey();

        // No network: the answer content is irrelevant to ordering.
        $this->mock(RagService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('deriveTopicHintFromText')->andReturn(null);
            $mock->shouldReceive('ask')->andReturn([
                'title' => null,
                'answer' => 'Stub answer.',
                'points' => [],
                'sources' => [],
                'language' => 'en',
                'is_conversation' => false,
            ]);
        });
    }

    private function ask(string $question, ?int $conversationId = null): int
    {
        $payload = ['question' => $question, 'language' => 'en'];
        if ($conversationId !== null) {
            $payload['conversation_id'] = $conversationId;
        }

        return (int) $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $this->accountId,
            ResidentAuthenticator::SESSION_EMAIL => 'chat.order@example.test',
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ])->postJson(route('chatbot.ask'), $payload)->assertOk()->json('conversation_id');
    }

    /**
     * @return list<int>
     */
    private function listedOrder(): array
    {
        return collect($this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $this->accountId,
            ResidentAuthenticator::SESSION_EMAIL => 'chat.order@example.test',
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ])->getJson(route('chatbot.conversations'))->assertOk()->json())
            ->pluck('conversation_id')->map(fn ($id) => (int) $id)->all();
    }

    private function assertLastMessageAtMatchesLatestMessage(int $conversationId): void
    {
        $last = DB::table('chatbot_conversations')->where('conversation_id', $conversationId)->value('last_message_at');
        $latest = DB::table('chatbot_messages')->where('conversation_id', $conversationId)->max('sent_at');

        $this->assertNotNull($last);
        $this->assertSame(Carbon::parse($latest)->toDateTimeString(), Carbon::parse($last)->toDateTimeString());
    }

    public function test_sending_a_message_sets_last_message_at_to_the_latest_message_time(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 08:00:00'));
        $conversation = $this->ask('What is dengue?');

        $this->assertSame(2, DB::table('chatbot_messages')->where('conversation_id', $conversation)->count());
        $this->assertSame('2026-09-01 08:00:00', Carbon::parse(
            DB::table('chatbot_conversations')->where('conversation_id', $conversation)->value('last_message_at')
        )->toDateTimeString());
        $this->assertLastMessageAtMatchesLatestMessage($conversation);

        $this->travelTo(Carbon::parse('2026-09-02 09:30:00'));
        $this->ask('How is it prevented?', $conversation);

        $this->assertSame(4, DB::table('chatbot_messages')->where('conversation_id', $conversation)->count());
        $this->assertLastMessageAtMatchesLatestMessage($conversation);
    }

    public function test_conversations_are_listed_by_latest_activity(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 08:00:00'));
        $older = $this->ask('First topic');

        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $newer = $this->ask('Second topic');

        $this->assertSame([$newer, $older], $this->listedOrder());

        // New activity in the older conversation moves it to the top.
        $this->travelTo(Carbon::parse('2026-09-01 10:00:00'));
        $this->ask('Follow-up on first topic', $older);

        $this->assertSame([$older, $newer], $this->listedOrder());
        $this->assertLastMessageAtMatchesLatestMessage($older);
        $this->assertLastMessageAtMatchesLatestMessage($newer);
    }
}
