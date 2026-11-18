<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Services\BikolCloudChatService;
use App\Services\BikolSmallTalkService;
use App\Services\RagService;
use App\Services\TagalogConversationService;
use App\Support\ResidentAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;


class ChatbotController extends Controller
{
    public function ask(Request $request, RagService $rag)
    {
        $request->validate([
            'question'        => 'required|string',
            'language'        => 'nullable|string|in:en,tl,bcl',
            'category'        => 'nullable|string|in:child_care,family_planning,maternal_care,other',
            'conversation_id' => 'nullable|integer|exists:chatbot_conversations,conversation_id',
            // Bikol - Iriga: the resident must choose a chatbot type; there is no default.
            'mode'            => [Rule::requiredIf(fn () => ($request->input('language') ?? 'bcl') === 'bcl'), 'nullable', 'string', 'in:context,ai,cloud'],
        ], [
            'mode.required' => 'Choose a chatbot type first: Context-based, Local Model or Cloud AI (Gemma).',
            'mode.in'       => 'Choose a chatbot type first: Context-based, Local Model or Cloud AI (Gemma).',
        ]);

        // FOD comparison switch: "context" = verified datasets + rules,
        // "ai" = locally trained Bikol-Iriga AI model, "cloud" = trained model
        // + cloud Gemma explains the verified answer.
        // The switch is for Bikol-Iriga only; English and Tagalog always use the
        // original pipeline, whatever "mode" the request carries.
        $language = $request->input('language', 'bcl');
        // Bikol - Iriga uses the type the resident chose (required above); English and Tagalog have none.
        $mode = $language === 'bcl' ? (string) $request->input('mode') : 'context';
        $rag->setAnswerMode($mode);
        // Same switch for unrelated (non-health) messages: ai = trained model, cloud = Gemma, context = no AI.
        BikolSmallTalkService::useMode($mode);

        // Pulled from the session by EnsureResidentChatbotAccount middleware —
        // never trust a client-supplied account_id for who is asking.
        $accountId = $request->session()->get(ResidentAuthenticator::SESSION_ACCOUNT_ID);

        $category = $request->input('category');
        $question = $request->input('question');

        return DB::transaction(function () use ($request, $rag, $language, $category, $question, $accountId, $mode) {

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

            // Bikol: time/date questions and unrelated (non-health) messages go to the fine-tuned
            // Bikol-Iriga model; health questions and anything unsure stay in RAG.
            $result = null;
            if ($language === 'bcl') {
                $smallTalk = app(BikolSmallTalkService::class);
                // The last chat turns of this conversation, so the model can follow what was just said.
                BikolSmallTalkService::useHistory(BikolSmallTalkService::historyFromMessages(
                    ChatbotMessage::query()
                        ->where('conversation_id', $conversation->conversation_id)
                        ->where('message_id', '<', $userMessage->message_id)
                        ->orderByDesc('message_id')
                        ->limit(12)
                        ->get(['sender', 'message_text'])
                        ->reverse()
                ));
                if ($mode === 'cloud') {
                    // Cloud AI (Gemma): time/date questions are answered by Gemma with the real clock. Every other
                    // message goes to RAG; when no document answers it, RagService hands it to Gemma (normal chat).
                    // Serious messages keep their fixed checked reply (handled first in RagService::ask).
                    if ($smallTalk->seriousReply($question) === null && $smallTalk->isTimeOrDateQuestion($question)) {
                        $result = app(BikolCloudChatService::class)->result($question);
                    }
                } else {
                    $result = $smallTalk->timeDateResult($question) ?? $smallTalk->unrelatedResult($question);
                }
            }
            $result ??= $rag->ask($question, $language, $category, $topicHint);
            $resolvedLanguage = $result['language'] ?? $language;

            Log::info('Chatbot response', [
    'question' => $question,
    'selected_language' => $language,
    'mode' => $mode,
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
    'mode'            => $mode,
    'answered_by'     => $result['answered_by'] ?? null,
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