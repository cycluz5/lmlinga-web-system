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

    private const DAYS = ['Lunes', 'Martes', 'Miyerkoles', 'Huwebes', 'Biyernes', 'Sabado', 'Dominggo']; // Monday first
    private const MONTHS = ['Enero', 'Pebrero', 'Marso', 'Abril', 'Mayo', 'Hunyo', 'Hulyo', 'Agosto',
        'Setyembre', 'Oktubre', 'Nobyembre', 'Disyembre'];

    /** Asks about the time, day or date (Iriga, Tagalog or English). */
    private const TIME_DATE_PATTERNS = [
        '/\b(oras|petsa|aldow\s+ngowan|ngowan\s+na\s+aldow|ngowan\s+na\s+taon|bulan\s+ngowan)\b/u',
        '/\b(anong|ano\s+ang)\s+(oras|petsa|araw|buwan|taon)\b/u',
        '/\bwhat\s+(time|day|date|month|year)\b/u',
        "/\\btoday'?s\\s+date\\b/u",
        '/\bis\s+it\s+(already\s+)?(morning|afternoon|evening|night|the\s+weekend)\b/u',
    ];

    /**
     * The real time/date in front of every message. An LLM cannot know the time, so the model is
     * trained (ml/bikol-chat/situational.py) to read this line. Keep the format identical to
     * context_line() in ml/bikol-chat/situational.py:
     *   [Oras: 8:00 PM | Aldow: Sabado | Petsa: Nobyembre 14, 2026]
     */
    public function contextLine(?\DateTimeInterface $now = null): string
    {
        $now = $now !== null
            ? \Carbon\Carbon::instance($now)->setTimezone('Asia/Manila')
            : \Carbon\Carbon::now('Asia/Manila');

        return sprintf(
            '[Oras: %d:%02d %s | Aldow: %s | Petsa: %s %d, %d]',
            (int) $now->format('g'),
            (int) $now->format('i'),
            $now->format('A'),
            self::DAYS[(int) $now->format('N') - 1],
            self::MONTHS[(int) $now->format('n') - 1],
            (int) $now->format('j'),
            (int) $now->format('Y')
        );
    }

    /**
     * Only turn this on after the model was retrained with the context line
     * (ml/bikol-chat v3 notebook); the first model (v2) never saw it.
     */
    public function sendsContext(): bool
    {
        return (bool) config('services.bikol_chat.send_context', false);
    }

    /** True when the message is only about the time, day or date (not health). */
    public function isTimeOrDateQuestion(string $message): bool
    {
        $text = mb_strtolower(trim($message), 'UTF-8');
        foreach (self::TIME_DATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Ono nang oras?" / "What day is it?": not health, so it goes to the fine-tuned model, which reads
     * the real time/date from the context line. Returns a chatbot result array, or null when the
     * message is not a time/date question, the model is not trained with the context line
     * (services.bikol_chat.send_context), or the model is unreachable (the normal flow then continues).
     * Called from ChatbotController before RagService::ask(), so the RAG code stays untouched.
     */
    public function timeDateResult(string $question): ?array
    {
        if (!$this->sendsContext() || !$this->isTimeOrDateQuestion($question)) {
            return null;
        }

        $answer = $this->reply($question);
        if ($answer === null) {
            return null;
        }

        return [
            'title' => null,
            'answer' => $answer,
            'points' => [],
            'sources' => [],
            'language' => 'bcl',
            'is_conversation' => true,
            'answered_by' => 'Bikol-Iriga LLM (fine-tuned)',
        ];
    }

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
                    'messages' => [['role' => 'user', 'content' => $this->sendsContext()
                        ? $this->contextLine() . "\n" . $message
                        : $message]],
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
