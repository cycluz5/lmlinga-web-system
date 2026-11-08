<?php

namespace App\Http\Controllers\Chatbot;

use App\Exceptions\ChatbotAiUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Services\RagService;
use App\Services\TagalogConversationService;
use App\Support\ResidentAuthenticator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;


class ChatbotController extends Controller
{
    /**
     * SR-08 friendly reply when the AI provider fails. English only for now: Tagalog/Bikol-Iriga wording
     * needs Linguist-verified text before it is added (no unverified Bikol-Iriga content).
     */
    /** Longest question accepted, so an oversized message cannot fail the search or the save. */
    public const MAX_QUESTION_LENGTH = 2000;

    public const AI_UNAVAILABLE_MESSAGE = 'Sorry, the chatbot cannot answer right now. Please try again in a few minutes.';

    public function ask(Request $request, RagService $rag)
    {
        $request->validate([
            'question'        => 'required|string|max:'.self::MAX_QUESTION_LENGTH,
            'language'        => 'nullable|string|in:en,tl,bcl',
            'category'        => 'nullable|string|in:child_care,family_planning,maternal_care,other',
            'conversation_id' => 'nullable|integer|exists:chatbot_conversations,conversation_id',
        ]);

        // Pulled from the session by EnsureResidentChatbotAccount middleware —
        // never trust a client-supplied account_id for who is asking.
        $accountId = $request->session()->get(ResidentAuthenticator::SESSION_ACCOUNT_ID);

        $language = $request->input('language', 'bcl');
        $category = $request->input('category');
        $question = $request->input('question');

        // SR-08: an AI provider failure (configuration, connection, failed request) gets a friendly reply
        // instead of a server error. The transaction is rolled back exactly as before (no partial history),
        // and the provider is never switched automatically.
        try {
            return $this->answer($request, $rag, $language, $category, $question, $accountId);
        } catch (ConnectionException|RequestException|ChatbotAiUnavailableException $e) {
            Log::warning('Chatbot AI provider unavailable', [
                'reason' => $e::class,
                'status' => $e instanceof RequestException ? $e->response->status() : null,
                'detail' => $e instanceof ChatbotAiUnavailableException ? $e->getMessage() : null,
                'provider' => config('services.ollama.chat_provider'),
                'chat_model' => config('services.ollama.chat_model'),
                'language' => $language,
            ]);

            return response()->json([
                'error' => 'ai_unavailable',
                'message' => self::AI_UNAVAILABLE_MESSAGE,
            ], 503);
        } catch (ValidationException|ModelNotFoundException|HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            // The transaction has already rolled back. Exception messages can quote saved values and
            // stack traces can hold arguments, so neither may reach the log or the response here.
            Log::error('Chatbot request failed', [
                'reason' => $e::class,
                'sql_state' => $e instanceof QueryException ? $e->getCode() : null,
                'location' => $this->firstApplicationFrame($e),
                'language' => $language,
            ]);

            return response()->json(['message' => 'Server Error'], 500);
        }
    }

    /** First place in application code the failure passed through: file and line only, never arguments. */
    private function firstApplicationFrame(Throwable $e): string
    {
        $root = app_path();
        $frames = array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace());

        foreach ($frames as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if (str_starts_with($file, $root)) {
                $relative = str_replace('\\', '/', substr($file, strlen(base_path()) + 1));

                return $relative.':'.($frame['line'] ?? 0);
            }
        }

        return 'unknown';
    }

    private function answer(Request $request, RagService $rag, string $language, ?string $category, string $question, mixed $accountId)
    {
        return DB::transaction(function () use ($request, $rag, $language, $category, $question, $accountId) {

            $conversation = $request->filled('conversation_id')
                ? ChatbotConversation::where('account_id', $accountId)
                    ->findOrFail($request->input('conversation_id'))
                : ChatbotConversation::create([
                    'account_id' => $accountId,
                    'title'      => \Illuminate\Support\Str::limit($question, 60),
                    'is_pinned'  => false,
                ]);

            $userMessage = ChatbotMessage::create([
                'conversation_id' => $conversation->conversation_id,
                'sender'          => 'Resident',
                'message_text'    => $question,
                'language'        => $language,
                'category'        => $category,
                'sent_at'         => now(),
                'created_at'      => now(),
            ]);

            // v1 limitation: topic is derived from prior Resident text only
            // (sources/topic metadata are not persisted on messages).
            $topicHint = null;
            $priorResidentMessages = ChatbotMessage::query()
                ->where('conversation_id', $conversation->conversation_id)
                ->where('sender', 'Resident')
                ->where('message_id', '<', $userMessage->message_id)
                ->orderByDesc('sent_at')
                ->orderByDesc('message_id')
                ->limit(8)
                ->get(['message_text']);

            foreach ($priorResidentMessages as $priorMessage) {
                $priorText = (string) $priorMessage->message_text;

                // TL-D2: conversational priors must not become health topic hints.
                if ($language === 'tl') {
                    $priorConversation = app(TagalogConversationService::class)->resolve($priorText);
                    if (($priorConversation['type'] ?? '') === 'canned') {
                        continue;
                    }
                }

                $hint = $rag->deriveTopicHintFromText(
                    $priorText,
                    $language,
                    $category
                );
                if ($hint !== null) {
                    $topicHint = $hint;
                    break;
                }
            }

            $result = $rag->ask($question, $language, $category, $topicHint);
            $resolvedLanguage = $result['language'] ?? $language;

            // Never log the question or the answer: they can contain residents' health information.
Log::info('Chatbot response', [
    'selected_language' => $language,
    'resolved_language' => $result['language'] ?? $language,
    'category' => $category,
    'question_length' => mb_strlen((string) $question),
    'answer_length' => mb_strlen((string) $result['answer']),
    'point_count' => count($result['points']),
    'sources' => $result['sources'],
]);

// For DB history, store a flattened plain-text version (points as lines)
$flattenedText = trim($result['answer'] . "\n" . implode("\n", $result['points']));

ChatbotMessage::create([
    'conversation_id' => $conversation->conversation_id,
    'sender'          => 'Chatbot',
    'message_text'    => $flattenedText,
    'language'        => $resolvedLanguage,
    'category'        => $category,
    'sent_at'         => now(),
    'created_at'      => now(),
]);

$conversation->update(['last_message_at' => now()]);

return response()->json([
    'conversation_id' => $conversation->conversation_id,
    'title'           => $result['title'] ?? null,
    'answer'          => $result['answer'],
    'points'          => $result['points'],
    'sources'         => $result['sources'],
    'language'        => $resolvedLanguage,
    'is_conversation' => (bool) ($result['is_conversation'] ?? false),
]);
        });
    }

    public function history(int $conversationId, Request $request)
    {
        $accountId = $request->session()->get(ResidentAuthenticator::SESSION_ACCOUNT_ID);

        $conversation = ChatbotConversation::where('account_id', $accountId)
            ->with('messages')
            ->findOrFail($conversationId);

        return response()->json([
            'conversation_id' => $conversation->conversation_id,
            'title'           => $conversation->title,
            'messages'        => $conversation->messages,
        ]);
    }

    public function listConversations(Request $request)
    {
        $accountId = $request->session()->get(ResidentAuthenticator::SESSION_ACCOUNT_ID);

        $conversations = ChatbotConversation::where('account_id', $accountId)
            ->orderByDesc('last_message_at')
            ->get(['conversation_id', 'title', 'is_pinned', 'last_message_at']);

        return response()->json($conversations);
    }

    /**
     * Hard-delete one owned conversation (messages + notification refs first).
     * FK rules are RESTRICT — never delete the conversation row first.
     */
    public function destroy(Request $request, int $conversationId)
    {
        $accountId = $request->session()->get(ResidentAuthenticator::SESSION_ACCOUNT_ID);

        $conversation = ChatbotConversation::where('account_id', $accountId)
            ->where('conversation_id', $conversationId)
            ->firstOrFail();

        DB::transaction(function () use ($conversation) {
            $id = (int) $conversation->conversation_id;

            // related_conversation_id is nullable — null refs before conversation delete.
            if (Schema::hasTable('notifications') && Schema::hasColumn('notifications', 'related_conversation_id')) {
                DB::table('notifications')
                    ->where('related_conversation_id', $id)
                    ->update(['related_conversation_id' => null]);
            }

            ChatbotMessage::query()
                ->where('conversation_id', $id)
                ->delete();

            $conversation->delete();
        });

        return response()->json([
            'ok' => true,
            'conversation_id' => (int) $conversationId,
        ]);
    }

    /**
     * Persist pin/unpin for one owned conversation.
     */
    public function updatePin(Request $request, int $conversationId)
    {
        $validated = $request->validate([
            'is_pinned' => ['required', 'boolean'],
        ]);

        $accountId = $request->session()->get(ResidentAuthenticator::SESSION_ACCOUNT_ID);

        $conversation = ChatbotConversation::where('account_id', $accountId)
            ->where('conversation_id', $conversationId)
            ->firstOrFail();

        $conversation->is_pinned = (bool) $validated['is_pinned'];
        $conversation->save();

        return response()->json([
            'ok' => true,
            'conversation_id' => (int) $conversation->conversation_id,
            'is_pinned' => (bool) $conversation->is_pinned,
        ]);
    }
}