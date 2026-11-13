<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Bikol-Iriga small talk (not health).
 *
 *  - Serious messages (wanting to die / hurt oneself) always get the team's fixed, checked reply.
 *  - Other non-health messages go to the fine-tuned model "lmlinga-bikol-chat"
 *    (trained in ml/bikol-chat/colab/train_bikol_chat.ipynb, run by the local Ollama).
 *  - Health answers never come from this model: they stay in the RAG path.
 */
class BikolSmallTalkService
{
    /** Team-checked replies (ml/bikol-chat/build_chat_pairs.py, chats 37-38). */
    public const REPLY_NO_HOPE = 'Importante ika. Mag-istorya tabi sa usad na tawo na pinagtitiwalaan mo ngowan sana.';
    public const REPLY_SELF_HARM = 'Pakiusip, diri mo ini gibowon. Isabi mo man sa pamilya o amigo mo ngowan.';

    private const SELF_HARM_PATTERNS = [
        '/\bkolgan\s+(a|ang)\s+(sadiri|sarili)/u',
        '/\bmaati\s+sa\s+sadiri\b/u',
        '/\b(saktan|sugatan)\s+(ko\s+)?(ang\s+)?sarili\b/u',
        '/\bhurt\s+myself\b/u',
        '/\bcut\s+myself\b/u',
    ];

    private const NO_HOPE_PATTERNS = [
        '/\bmagraan\b/u',
        '/\bmauda\s+sa\s+kinaban\b/u',
        '/\buda\s+na\s+(akong\s+|ako\s+)?pag-?asa\b/u',
        '/\bwala\s+na\s+(akong\s+)?pag-?asa\b/u',
        '/\bmagpakamatay\b/u',
        '/\bmamatay\s+na\s+(ako|lang)\b/u',
        '/\bpatayon\s+ko\s+a\s+sadiri\b/u',
        '/\b(want|wanna)\s+to\s+die\b/u',
        '/\bkill\s+myself\b/u',
        '/\bsuicid/u',
        '/\bno\s+hope\b/u',
    ];

    /**
     * Fixed reply for a serious message, or null when the message is not serious.
     */
    public function seriousReply(string $message): ?string
    {
        $text = mb_strtolower(trim($message), 'UTF-8');

        foreach (self::SELF_HARM_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return self::REPLY_SELF_HARM;
            }
        }
        foreach (self::NO_HOPE_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return self::REPLY_NO_HOPE;
            }
        }

        return null;
    }

    public function enabled(): bool
    {
        return (bool) config('services.bikol_chat.enabled', true);
    }

    /**
     * Ask the fine-tuned model. Returns null when it is off, not installed, or fails,
     * so the caller can fall back to its normal reply.
     */
    public function reply(string $message): ?string
    {
        if (!$this->enabled() || trim($message) === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('services.bikol_chat.timeout', 60))
                ->post(rtrim((string) config('services.bikol_chat.base_url'), '/') . '/api/chat', [
                    'model' => (string) config('services.bikol_chat.model', 'lmlinga-bikol-chat'),
                    'messages' => [['role' => 'user', 'content' => $message]],
                    'stream' => false,
                    'options' => ['num_predict' => 120],
                ]);
        } catch (\Throwable $e) {
            Log::warning('Bikol small-talk model unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful()) {
            Log::warning('Bikol small-talk model error', ['status' => $response->status()]);

            return null;
        }

        $answer = trim((string) $response->json('message.content', ''));

        return $answer === '' ? null : $answer;
    }
}
