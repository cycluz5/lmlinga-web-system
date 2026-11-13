<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Services\RagService;
use App\Services\TagalogConversationService;
use App\Support\ResidentAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;


class ChatbotController extends Controller
{
    public function ask(Request $request, RagService $rag)
    {
        $request->validate([
            'question'        => 'required|string',
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

            Log::info('Chatbot response', [
    'question' => $question,
    'selected_language' => $language,
    'resolved_language' => $result['language'] ?? $language,
    'answer' => $result['answer'],
    'points' => $result['points'],
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