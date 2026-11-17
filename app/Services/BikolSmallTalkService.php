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
    public const REPLY_NO_HOPE = 'Importante ika. Mag-istorya tabi sa osad na tawo na pinagtitiwalaan mo ngowan sana.';
    public const REPLY_SELF_HARM = 'Pakiusip, diri mo ini gibowon. Isabi mo man sa pamilya o amigo mo ngowan.';

    private const SELF_HARM_PATTERNS = [
        '/\bkolgan\s+(ko\s+)?(a|ana|ang)\s+(sadiri|sarili)/u',
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
        '/\b(oras|petsa|aldow\s+ngowan|ngowan\s+na\s+aldow|ngowan\s+na\s+taon|taon\s+ngowan|bulan\s+ngowan)\b/u',
        '/\b(anong|ano\s+ang)\s+(oras|petsa|araw|buwan|taon)\b/u',
        '/\bwhat\s+(time|day|date|month|year)\b/u',
        "/\\bwhat(?:'s|\\s+is)\\s+(?:the|today'?s)\\s+(time|day|date|month|year)\\b/u",   // What's the date today?
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
        // respelled first, so "Unong aldow nguwan?" (u-spelling) is recognised like "Onong aldow ngowan?"
        $text = mb_strtolower(trim($this->normalizeInput($message)), 'UTF-8');
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
        // The trained model needs the v3 context line switch; Gemma (cloud) always gets the line.
        $needsSwitch = self::$mode !== 'cloud';
        if (($needsSwitch && !$this->sendsContext()) || !$this->isTimeOrDateQuestion($question)) {
            return null;
        }

        // One "now" for the context line AND the check below, so they can never disagree.
        $now = \Carbon\Carbon::now('Asia/Manila');
        $answer = $this->reply($question, $now, false);   // plain single call: the clock check judges it
        if ($answer === null) {
            return null;
        }

        // Clock check (trained model only): the model can say the wrong hour, especially exactly on the hour.
        // A wrong or refused time/day/date answer is replaced by the reviewed wording with the real clock.
        $kind = self::$mode === 'ai' ? $this->timeDateKind($question) : null;
        if ($kind !== null && !$this->clockAnswerIsRight($kind, $answer, $now)) {
            Log::info('Clock check replaced a wrong time/date answer', ['question' => $question, 'model_answer' => $answer]);

            return $this->conversationResult($this->clockAnswer($kind, $now), false, 'Clock check (real time)');
        }

        return $this->conversationResult($answer);
    }

    private const HOUR_WORDS = [1 => 'ona', 2 => 'dos', 3 => 'tres', 4 => 'kuwatro', 5 => 'singko', 6 => 'sais',
        7 => 'siyete', 8 => 'otso', 9 => 'nuwebe', 10 => 'dies', 11 => 'onse', 12 => 'dose'];

    /** What a time/date question asks for: time, day, date, month, year, or null when we cannot check it. */
    public function timeDateKind(string $message): ?string
    {
        $text = mb_strtolower(trim($this->normalizeInput($message)), 'UTF-8');
        if (!$this->isTimeOrDateQuestion($message)) {
            return null;
        }

        return match (true) {
            preg_match('/\b(petsa|date)\b/u', $text) === 1 => 'date',
            preg_match('/\b(bulan|buwan|month)\b/u', $text) === 1 => 'month',
            preg_match('/\b(taon|year)\b/u', $text) === 1 => 'year',
            preg_match('/\b(oras|time)\b/u', $text) === 1 => 'time',
            preg_match('/\b(aldow|araw|day)\b/u', $text) === 1 => 'day',
            default => null,   // for example "Is it already night?": not checked
        };
    }

    /** Part of the day in Iriga words. Same rule as period() in ml/bikol-chat/situational.py. */
    private function dayPart(int $hour): string
    {
        return match (true) {
            $hour >= 5 && $hour < 12 => 'ramrag',
            $hour >= 12 && $hour < 14 => 'modto',
            $hour >= 14 && $hour < 18 => 'apon',
            default => "gab'i",
        };
    }

    /** "alas otso" on the hour, "ala ona" at 1 o'clock, otherwise "3:46". Same as time_text() in situational.py. */
    private function timeText(\Carbon\Carbon $now): string
    {
        $h12 = (int) $now->format('g');

        return (int) $now->format('i') === 0
            ? ($h12 === 1 ? 'ala' : 'alas') . ' ' . self::HOUR_WORDS[$h12]
            : $h12 . ':' . $now->format('i');
    }

    /** The reviewed answer wording (situational.py) filled with the real clock. */
    public function clockAnswer(string $kind, \DateTimeInterface $now): string
    {
        $now = \Carbon\Carbon::instance($now)->setTimezone('Asia/Manila');
        $day = self::DAYS[(int) $now->format('N') - 1];
        $month = self::MONTHS[(int) $now->format('n') - 1];

        return match ($kind) {
            'time' => 'Ngowan, ' . $this->timeText($now) . ' na sa ' . $this->dayPart((int) $now->format('G')) . '.',
            'day' => "Ngowan, {$day}.",
            'date' => 'Ngowan ana ' . $day . ', ' . $month . ' ' . (int) $now->format('j') . ', ' . $now->format('Y') . '.',
            'month' => "Ngowan na bulan, {$month}.",
            default => 'Ngowan na taon, ' . $now->format('Y') . '.',
        };
    }

    /**
     * Is the model's answer right for this clock? Strict on purpose: the team's wording ("alas siyete", not "alas
     * siete"), the real hour and minute, and the right part of the day. Anything else is replaced.
     */
    public function clockAnswerIsRight(string $kind, string $answer, \DateTimeInterface $now): bool
    {
        $now = \Carbon\Carbon::instance($now)->setTimezone('Asia/Manila');
        $text = mb_strtolower($answer, 'UTF-8');
        $has = static fn (string $needle): bool => $needle !== '' && mb_strpos($text, mb_strtolower($needle, 'UTF-8')) !== false;
        $number = static fn (string $n): bool => preg_match('/(?<![\d:])' . preg_quote($n, '/') . '(?![\d:])/u', $text) === 1;

        $day = self::DAYS[(int) $now->format('N') - 1];
        $month = self::MONTHS[(int) $now->format('n') - 1];

        return match ($kind) {
            'time' => ((int) $now->format('i') === 0
                    ? $has($this->timeText($now)) || $number((int) $now->format('g') . ':00')
                    : $number($this->timeText($now)))
                && $has('sa ' . $this->dayPart((int) $now->format('G'))),
            'day' => $has($day),
            'date' => $has($day) && $has($month) && $number((string) (int) $now->format('j')) && $has($now->format('Y')),
            'month' => $has($month),
            'year' => $has($now->format('Y')),
            default => true,
        };
    }

    /**
     * Which chatbot button is selected (set by ChatbotController for each request):
     *  ai      = the trained Bikol-Iriga chat model answers unrelated messages
     *  cloud   = Gemma (cloud) answers unrelated messages
     *  context = no AI for unrelated messages (verified documents + rules only)
     * Health answers are never affected: they stay in the RAG path in every mode.
     */
    private static string $mode = 'ai';

    public static function useMode(string $mode): void
    {
        self::$mode = in_array($mode, ['context', 'ai', 'cloud'], true) ? $mode : 'ai';
    }

    private function conversationResult(?string $answer, bool $checked = false, ?string $label = null): ?array
    {
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
            'answered_by' => $label ?? ($checked
                ? 'Checked reply'
                : (self::$lastSource === 'reviewed' && self::$mode !== 'cloud' ? 'Reviewed reply (team)'
                    : (self::$lastSource === 'unlearned' && self::$mode !== 'cloud' ? 'Not learned yet (team-reviewed reply)'
                        : (self::$lastSource === 'nohealth' && self::$mode !== 'cloud' ? 'No verified answer found (team-reviewed reply)'
                            : (self::$mode === 'cloud' ? 'Cloud AI (Gemma)' : 'Bikol-Iriga LLM (fine-tuned)'))))),
        ];
    }

    /**
     * Stems that mean the message may be about health. If one is present the gate is not trusted
     * and the message goes to RAG (the safe side), however the gate scored it.
     * Short words are matched as whole words, longer stems anywhere in a word.
     */
    private const HEALTH_STEMS = [
        'sakit', 'kolog', 'kulug', 'makulug', 'bolong', 'bulong', 'gamot', 'kalintora', 'lagnat', 'buntis',
        'igin', 'bakuna', 'dengue', 'otos', 'diabetes', 'sinyales', 'sintomas', 'sanhi', 'kwasa',
        'sipon', 'pagsusuka', 'pagtatae', 'rogo', 'dugo', 'doktor', 'ospital', 'clinic', 'health',
        'kalusugan', 'pasoso', 'pagpasoso', 'breastfeed', 'nutrition', 'bitamina', 'vitamin', 'family planning',
        'cancer', 'kanser', 'tuberculosis', 'pneumonia', 'hypertension', 'hypotension', 'mental',
    ];
    // Short words are matched as WHOLE words only: as a stem "tb" matched inside "basketball" and "ubo" inside other words.
    // "baga" (lungs) is left out on purpose: people also use it in everyday sentences ("lang init baga ka panawon
    // ngowan"); real lung questions still go to RAG through "kanser", "pneumonia", "sakit", "hika" etc. and the gate.
    private const HEALTH_WHOLE_WORDS = ['obo', 'ubo', 'tb', 'ulo', 'payo', 'tiyan', 'poso', 'mata', 'bayo', 'tae'];

    /** A health stem or short health word appears in the (lowercase) text. */
    private function looksHealthRelated(string $text): bool
    {
        foreach (self::HEALTH_STEMS as $stem) {
            if (str_contains($text, $stem)) {
                return true;
            }
        }
        foreach (self::HEALTH_WHOLE_WORDS as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * True only when the trained gate says "chat" with enough confidence AND no health word appears.
     * Unsure, model file missing, or any health word -> false, so the message goes to RAG as before.
     */
    public function isUnrelated(string $message): bool
    {
        if (!(bool) config('services.bikol_chat.gate_enabled', true)) {
            return false;
        }

        $text = mb_strtolower(trim($message), 'UTF-8');
        if ($text === '') {
            return false;
        }

        if ($this->looksHealthRelated($text)) {
            return false;
        }

        // Prices, news, politics, shows, scores: not health and not something LMLinga knows. The gate is often unsure about these
        // ("Ono a presyo ka bigas?" scored 53% chat), which sent them to RAG with a different tag. The model gives the team's
        // polite "I can only help with health" reply instead, every time.
        if (preg_match('/\b(presyo|magkano|pirahon|mamira|balita|presidente|mayor|gobernador|eleksyon|politika|pulitiko|pelikula|artista|resulta|score|kapital)\b|\d\s*[+\-x×*\/]\s*\d/u', $text) === 1) {
            return true;
        }
        if ($this->looksUnlearned($text)) {
            return true;   // answered with the reviewed "not learned yet" reply, consistently, instead of going to RAG
        }

        $gate = new BikolIntentClassifier((string) config(
            'services.bikol_chat.gate_model_path',
            base_path('resources/ml/bikol_gate_model.json')
        ));
        $prediction = $gate->predict($message);
        if ($prediction === null) {
            return false;
        }

        return $prediction['intent'] === 'chat'
            && $prediction['confidence'] >= (float) config('services.bikol_chat.gate_min_confidence', 0.75);
    }

    /**
     * Unrelated (non-health) message -> fixed reply if it is serious, otherwise the trained model.
     * Returns null when the message looks health-related or the model is off/unreachable, so the
     * normal RAG flow continues unchanged.
     */
    public function unrelatedResult(string $question): ?array
    {
        if (!$this->isUnrelated($question)) {
            return null;
        }

        // A serious message can look like plain chat to the gate: the checked reply always wins.
        $serious = $this->seriousReply($question);
        if ($serious !== null) {
            return $this->conversationResult($serious, true);
        }

        return $this->conversationResult($this->reply($question));
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
    public function reply(string $message, ?\DateTimeInterface $now = null, bool $natural = true): ?string
    {
        if (self::$mode === 'context' || trim($message) === '') {
            return null; // "Context-based" button: no AI for unrelated messages
        }
        if (self::$mode === 'cloud') {
            return $this->cloudReply($message);
        }

        // A message your team already answered (or a very close one) gets the team's own reviewed reply; only the rest is left to the model.
        self::$lastSource = 'model';
        if ($natural) {
            $reviewed = $this->reviewedReply($message);
            if ($reviewed !== null) {
                self::$lastSource = 'reviewed';

                return $reviewed;
            }
            // Something the chatbot has not learned (gibberish, English, Tagalog, Central Bikol): the team's reviewed
            // "I do not know that" reply, never a made-up answer.
            if ($this->looksUnlearned($message)) {
                self::$lastSource = 'unlearned';

                return $this->notKnowReply();
            }
            // Health is never answered by the chat model. If a health question reaches this point (the documents had no answer),
            // the honest reviewed reply is better than a made-up one.
            if ($this->looksHealthRelated(mb_strtolower($message, 'UTF-8')) || $this->gateSaysHealth($message)) {
                self::$lastSource = 'nohealth';

                return $this->notKnowReply();
            }
        }
        if (!$this->enabled()) {
            return null;
        }

        // $natural (small talk): earlier chat turns are sent too, the message is respelled to the team spelling
        // and the model is asked several times; the best answer by the automatic checks wins (pickBest).
        // Not natural (time/date): one plain call; the clock check judges the answer afterwards.
        $text = $natural ? $this->normalizeInput($message) : $message;
        $current = $this->sendsContext() ? $this->contextLine($now) . "\n" . $text : $text;
        $messages = $natural ? array_map(
            fn (array $turn): array => $turn['role'] === 'user'
                ? ['role' => 'user', 'content' => $this->normalizeInput($turn['content'])] : $turn,
            self::$history
        ) : [];
        $messages[] = ['role' => 'user', 'content' => $current];

        $candidates = $natural ? max(1, min(5, (int) config('services.bikol_chat.candidates', 3))) : 1;
        if ($candidates === 1) {
            return $this->askModelMany($messages, 1, false)[0] ?? null;
        }

        $answers = $this->askModelMany($messages, $candidates, true);

        return $answers === [] ? null : $this->pickBest($answers, $message);
    }

    /** Earlier chat turns sent with the next message (set by ChatbotController for each request). */
    private static array $history = [];

    /** @param list<array{role: string, content: string}> $turns */
    public static function useHistory(array $turns): void
    {
        self::$history = array_values(array_filter(
            $turns,
            static fn ($t): bool => is_array($t) && in_array($t['role'] ?? '', ['user', 'assistant'], true)
                && is_string($t['content'] ?? null) && trim($t['content']) !== ''
        ));
    }

    /**
     * Last chat turns from the stored messages (oldest first; objects with ->sender and ->message_text).
     * A turn is kept only when the bot's reply was short chat: long RAG health answers (over 300 characters or with a
     * "NOTE:") are skipped together with the question that caused them. Each text is cut to 200 characters.
     *
     * @return list<array{role: string, content: string}>
     */
    public static function historyFromMessages(iterable $messages, ?int $turns = null): array
    {
        $turns ??= (int) config('services.bikol_chat.history_turns', 3);
        if ($turns <= 0) {
            return [];
        }

        $pairs = [];
        $pendingUser = null;
        foreach ($messages as $m) {
            $text = trim((string) ($m->message_text ?? ''));
            if ($text === '') {
                continue;
            }
            if (($m->sender ?? '') === 'Resident') {
                $pendingUser = $text;
                continue;
            }
            if ($pendingUser !== null) {
                if (mb_strlen($text) <= 300 && !str_contains($text, 'NOTE:')) {
                    $pairs[] = [mb_substr($pendingUser, 0, 200), mb_substr($text, 0, 200)];
                }
                $pendingUser = null;
            }
        }

        $out = [];
        foreach (array_slice($pairs, -$turns) as [$user, $bot]) {
            $out[] = ['role' => 'user', 'content' => $user];
            $out[] = ['role' => 'assistant', 'content' => $bot];
        }

        return $out;
    }

    /** Respellings to the team spelling (decisions of the team; same list as ml/bikol-chat/build_team_translation.py). */
    private const INPUT_SPELLING = [
        'uno' => 'ono', 'pauno' => 'paono', 'nguwan' => 'ngowan', 'kaipuhan' => 'kaipowan', 'kaipuwan' => 'kaipowan',
        'tubig' => 'tobig', 'kalintura' => 'kalintora', 'rugo' => 'rogo', 'mudto' => 'modto', 'usad' => 'osad',
        'unga' => 'onga', 'sako' => 'kanako', 'ingat' => 'maan', 'ingatan' => 'maanan', 'anoman' => 'onoman',
        'dakul' => 'dakol', 'trabaho' => 'trabawo', 'gutom' => 'alop', 'unong' => 'onong', 'manungod' => 'manongod',
        'mamuya' => 'mamoya', 'matuod' => 'matood', 'maturog' => 'matorog', 'makaturog' => 'makatorog',
    ];

    /** The message as the model knows it (team spelling). Only what is sent to the model; the stored message is unchanged. */
    public function normalizeInput(string $message): string
    {
        return (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}\'-])\p{L}+(?![\p{L}\p{N}\'-])/u',
            static function (array $m): string {
                $new = self::INPUT_SPELLING[mb_strtolower($m[0], 'UTF-8')] ?? null;
                if ($new === null) {
                    return $m[0];
                }

                return mb_strtoupper(mb_substr($m[0], 0, 1), 'UTF-8') === mb_substr($m[0], 0, 1) && mb_strtolower($m[0], 'UTF-8') !== $m[0]
                    ? mb_strtoupper(mb_substr($new, 0, 1), 'UTF-8') . mb_substr($new, 1)
                    : $new;
            },
            $message
        );
    }

    private function chatUrl(): string
    {
        return rtrim((string) config('services.bikol_chat.base_url'), '/') . '/api/chat';
    }

    /**
     * Ask the trained model $count times (in parallel). With $varied the first call is careful (temperature 0.2) and the
     * others lively (0.7); otherwise the model's own settings are used. Failed or empty answers are left out.
     *
     * @return list<string>
     */
    private function askModelMany(array $messages, int $count, bool $varied): array
    {
        $payload = function (int $i) use ($messages, $varied): array {
            $options = ['num_predict' => 120];
            if ($varied) {
                $options += ['temperature' => $i === 0 ? 0.2 : 0.7, 'top_p' => 0.9, 'repeat_penalty' => 1.1];
            }

            // keep_alive: without it Ollama unloads the model after about 5 idle minutes and the next message waits for it to load
            return ['model' => (string) config('services.bikol_chat.model', 'lmlinga-bikol-chat'),
                'messages' => $messages, 'stream' => false, 'keep_alive' => (string) config('services.bikol_chat.keep_alive', '30m'),
                'options' => $options];
        };
        $timeout = (int) config('services.bikol_chat.timeout', 60);

        try {
            $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($count, $payload, $timeout) {
                $requests = [];
                for ($i = 0; $i < $count; $i++) {
                    $requests[] = $pool->as('c' . $i)->timeout($timeout)->post($this->chatUrl(), $payload($i));
                }

                return $requests;
            });
        } catch (\Throwable $e) {
            Log::warning('Bikol small-talk model unreachable', ['error' => $e->getMessage()]);

            return [];
        }

        $answers = [];
        foreach ($responses as $response) {
            if (!$response instanceof \Illuminate\Http\Client\Response) {
                Log::warning('Bikol small-talk model unreachable', ['error' => $response instanceof \Throwable ? $response->getMessage() : 'no response']);
                continue;
            }
            if (!$response->successful()) {
                Log::warning('Bikol small-talk model error', ['status' => $response->status()]);
                continue;
            }
            $text = trim((string) $response->json('message.content', ''));
            if ($text !== '') {
                $answers[] = $text;
            }
        }

        return $answers;
    }

    private const MEDICAL_ADVICE = ['bolong', 'gamot', 'dosis', 'antibiotic', 'paracetamol', 'inomon'];
    private const REDIRECT_WORDS = ['doktor', 'kalusugan', 'pasensiya', 'diri', 'boko'];

    private static ?array $chatVocab = null;

    /** @return array{allowed: array<string, true>, blocked: array<string, true>, fallback: string}|null */
    private function chatVocab(): ?array
    {
        if (self::$chatVocab === null) {
            $path = base_path('resources/ml/bikol_chat_vocab.json');
            $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            self::$chatVocab = is_array($data) && isset($data['allowed'], $data['blocked'])
                ? ['allowed' => array_fill_keys($data['allowed'], true), 'blocked' => array_fill_keys($data['blocked'], true),
                    'fallback' => (string) ($data['fallback'] ?? '')]
                : [];
        }

        return self::$chatVocab ?: null;
    }

    /**
     * Choose the most natural of several answers, with no linguist needed:
     *  reject  empty, blocked (Central Bikol / Tagalog / u-spelling) word, repeated sentence, a copy of the user's message,
     *          medicine advice without a "ask a doctor" redirect, under 2 or over 45 words, under 85% known spoken words;
     *  score   share of known words + how much the answer agrees with the others (the typical answer beats odd outliers).
     * If every answer is rejected the team-reviewed "I did not understand" reply is used instead of a bad guess.
     *
     * @param list<string> $answers first = the careful (greedy) one
     */
    private function pickBest(array $answers, string $userMessage): string
    {
        $vocab = $this->chatVocab();
        if ($vocab === null) {
            return $answers[0];   // no word list installed: keep the first answer
        }

        $userNorm = $this->squash($userMessage);
        $valid = [];
        foreach ($answers as $i => $answer) {
            $answer = trim(str_replace(['**', '##'], '', $answer));
            preg_match_all('/\p{L}+(?:[\'\x{2019}-]\p{L}+)*/u', $answer, $m);
            $words = $m[0];
            $count = count($words);
            if ($answer === '' || $count < 2 || $count > 45 || $this->squash($answer) === $userNorm) {
                continue;
            }

            $known = 0;
            $bad = false;
            foreach ($words as $w) {
                $lower = mb_strtolower($w, 'UTF-8');
                if (isset($vocab['blocked'][$lower]) && $w !== 'Mayo') {
                    $bad = true;
                    break;
                }
                $known += isset($vocab['allowed'][$lower]) ? 1 : 0;
            }
            if ($bad || $known / $count < 0.85) {
                continue;
            }

            $lowerWords = array_map(static fn (string $w): string => mb_strtolower($w, 'UTF-8'), $words);
            if (array_intersect($lowerWords, self::MEDICAL_ADVICE) !== [] && array_intersect($lowerWords, self::REDIRECT_WORDS) === []) {
                continue;
            }

            $sentences = array_values(array_filter(array_map([$this, 'squash'], preg_split('/[.!?]+/u', $answer) ?: []),
                static fn (string $s): bool => mb_strlen($s) > 8));
            if (count($sentences) !== count(array_unique($sentences))) {
                continue;
            }

            // The model likes to steer small talk to health ("Maanan mo a kalusugan mo", "agko raw ika onga manongod sa
            // kalusugan?"). When the person did not talk about health that feels unnatural, so such an answer loses.
            $pushesHealth = preg_match('/kalusugan|doktor|health|namamatian|onga\s+manongod/iu', $answer) === 1
                && !$this->looksHealthRelated(mb_strtolower($userMessage, 'UTF-8'));

            $valid[$i] = ['text' => $answer, 'share' => $known / $count, 'push' => $pushesHealth];
        }

        if ($valid === []) {
            return $vocab['fallback'] !== '' ? $vocab['fallback'] : $answers[0];
        }

        $best = null;
        $bestScore = -1.0;
        foreach ($valid as $i => $candidate) {
            $agree = 0.0;
            $others = 0;
            foreach ($valid as $j => $other) {
                if ($i !== $j) {
                    similar_text($this->squash($candidate['text']), $this->squash($other['text']), $percent);
                    $agree += $percent / 100;
                    $others++;
                }
            }
            $score = 2 * $candidate['share'] + 0.5 * ($others > 0 ? $agree / $others : 0.5) + ($i === 0 ? 0.05 : 0.0)
                - ($candidate['push'] ? 1.0 : 0.0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate['text'];
            }
        }

        return (string) $best;
    }

    /** Where the last reply of reply() came from: 'model' or 'reviewed' (the team's own reply). */
    private static string $lastSource = 'model';
    private static ?array $reviewedBank = null;

    /** The message as a match key: team spelling, lower case, no "tabi", letters, digits and apostrophes only. */
    private function matchKey(string $message): string
    {
        $text = mb_strtolower($this->normalizeInput($message), 'UTF-8');
        $text = (string) preg_replace('/\btabi\b/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}\' ]+/u', ' ', $text)));
    }

    /** @return array<string, int> character 3-grams with counts */
    private function grams(string $text): array
    {
        $text = ' ' . $text . ' ';
        $out = [];
        for ($i = 0, $n = mb_strlen($text, 'UTF-8') - 2; $i < $n; $i++) {
            $g = mb_substr($text, $i, 3, 'UTF-8');
            $out[$g] = ($out[$g] ?? 0) + 1;
        }

        return $out;
    }

    /** @return list<array{r: string, t: int, p: int, g: array<string, int>, n: float}> */
    private function reviewedBank(): array
    {
        $path = (string) config('services.bikol_chat.reviewed_path', base_path('resources/ml/bikol_reviewed_replies.json'));
        if (self::$reviewedBank === null || self::$reviewedBank['path'] !== $path) {
            $entries = [];
            $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            foreach (is_array($data) ? ($data['entries'] ?? []) : [] as $e) {
                if (!isset($e['k'], $e['r']) || !is_string($e['k']) || !is_string($e['r'])) {
                    continue;
                }
                $g = $this->grams($e['k']);
                $entries[] = ['r' => $e['r'], 't' => (int) ($e['t'] ?? 1), 'p' => (int) ($e['p'] ?? 0), 'g' => $g,
                    'n' => sqrt((float) array_sum(array_map(static fn (int $c): int => $c * $c, $g)))];
            }
            self::$reviewedBank = ['path' => $path, 'entries' => $entries];
        }

        return self::$reviewedBank['entries'];
    }

    /**
     * The team's reviewed reply for a message that is (nearly) one they already answered, or null.
     * Character 3-gram cosine against the bank (ml/bikol-chat/export_reviewed.py): team-reviewed messages need
     * services.bikol_chat.reviewed_min (0.82), filter-checked machine-made ones reviewed_min_auto (0.90).
     * Among the best matches: team-reviewed first, a reply without a health tagline first, and never the reply just given.
     */
    private function reviewedReply(string $message): ?string
    {
        if (!(bool) config('services.bikol_chat.reviewed_enabled', true) || self::$mode !== 'ai') {
            return null;
        }
        $key = $this->matchKey($message);
        if (mb_strlen($key, 'UTF-8') < 2 || $this->looksHealthRelated($key)) {
            return null;
        }
        $bank = $this->reviewedBank();
        $query = $this->grams($key);
        $queryNorm = sqrt((float) array_sum(array_map(static fn (int $c): int => $c * $c, $query)));
        if ($bank === [] || $queryNorm == 0.0) {
            return null;
        }

        $min1 = (float) config('services.bikol_chat.reviewed_min', 0.82);
        $min2 = (float) config('services.bikol_chat.reviewed_min_auto', 0.90);
        $candidates = [];
        foreach ($bank as $entry) {
            $dot = 0;
            foreach ($query as $g => $count) {
                if (isset($entry['g'][$g])) {
                    $dot += $count * $entry['g'][$g];
                }
            }
            $score = $entry['n'] > 0 ? $dot / ($queryNorm * $entry['n']) : 0.0;
            if ($score >= ($entry['t'] === 1 ? $min1 : $min2)) {
                $candidates[] = ['s' => $score, 'e' => $entry];
            }
        }
        if ($candidates === []) {
            return null;
        }

        $top = max(array_column($candidates, 's'));
        $pool = array_filter($candidates, static fn (array $c): bool => $c['s'] >= $top - 0.03);
        foreach ([fn (array $c): bool => $c['e']['t'] === 1, fn (array $c): bool => $c['e']['p'] === 0] as $prefer) {
            $better = array_filter($pool, $prefer);
            $pool = $better !== [] ? $better : $pool;
        }
        $last = '';
        foreach (array_reverse(self::$history) as $turn) {
            if ($turn['role'] === 'assistant') {
                $last = $turn['content'];
                break;
            }
        }
        $fresh = array_filter($pool, static fn (array $c): bool => $c['e']['r'] !== $last);
        $pool = $fresh !== [] ? $fresh : $pool;

        return (string) $pool[array_rand($pool)]['e']['r'];
    }

    /** English and Tagalog function words: two or more of them mean the message is not Bikol-Iriga (the chatbot only learned Iriga). */
    private const ENGLISH_FUNCTION = ['the', 'is', 'are', 'you', 'what', 'how', 'do', 'does', 'of', 'and', 'this', 'that', 'with', 'can',
        'will', 'would', 'there', 'here', 'have', 'has', 'was', 'were', 'why', 'who', 'where', 'when', 'they', 'he', 'she', 'your', 'my'];
    private const TAGALOG_FUNCTION = ['ang', 'mga', 'ng', 'hindi', 'tayo', 'tayong', 'siya', 'sila', 'kayo', 'natin', 'ito', 'iyan', 'iyon',
        'yung', 'dito', 'diyan', 'doon', 'bakit', 'ngayon'];

    /**
     * A message the chatbot has not learned: gibberish, English, Tagalog or Central Bikol. Measured on 1,344 normal messages
     * (reviewed chats, teammates' phrases, test messages) it fires 0 times; it fires when
     *  - 2+ English function words, or 2+ Tagalog function words, or 2+ blocked Central Bikol/Tagalog words, or
     *  - 70% or more of the words (3+ letters) are outside the chatbot's spoken vocabulary.
     * Health and time/date messages are never judged here. Switch: services.bikol_chat.unlearned_enabled.
     */
    public function looksUnlearned(string $message): bool
    {
        if (!(bool) config('services.bikol_chat.unlearned_enabled', true)) {
            return false;
        }
        $text = mb_strtolower($this->normalizeInput($message), 'UTF-8');
        preg_match_all('/\p{L}+(?:[\'\x{2019}-]\p{L}+)*/u', $text, $m);
        $words = $m[0];
        if (count($words) < 2 || $this->looksHealthRelated($text) || $this->isTimeOrDateQuestion($message)) {
            return false;
        }
        if (count(array_intersect($words, self::ENGLISH_FUNCTION)) >= 2 || count(array_intersect($words, self::TAGALOG_FUNCTION)) >= 2) {
            return true;
        }
        $vocab = $this->chatVocab();
        if ($vocab === null) {
            return false;
        }
        $blocked = $content = $unknown = 0;
        foreach ($words as $w) {
            $blocked += isset($vocab['blocked'][$w]) ? 1 : 0;
            if (mb_strlen($w, 'UTF-8') > 2) {
                $content++;
                $unknown += isset($vocab['allowed'][$w]) ? 0 : 1;
            }
        }

        return $blocked >= 2 || ($content >= 2 && $unknown / $content >= 0.7);
    }

    /** The trained health-or-chat gate calls this message a health question (the verified documents answer those). */
    private function gateSaysHealth(string $message): bool
    {
        if (!(bool) config('services.bikol_chat.gate_enabled', true)) {
            return false;
        }
        $prediction = (new BikolIntentClassifier((string) config(
            'services.bikol_chat.gate_model_path',
            base_path('resources/ml/bikol_gate_model.json')
        )))->predict($message);

        return $prediction !== null && $prediction['intent'] === 'health' && $prediction['confidence'] >= 0.6;
    }

    /** The team's reviewed "I do not know that; I can help with health" reply (situational.csv, category not_know). */
    private function notKnowReply(): string
    {
        $path = base_path('resources/ml/bikol_chat_vocab.json');
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $text = is_array($data) ? trim((string) ($data['not_know'] ?? '')) : '';

        return $text !== '' ? $text : 'Pasensiya na, diri ko isi ana simbag kadi. Maitatabang ko kanimo manongod sa kalusugan.';
    }

    private function squash(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}\' ]+/u', ' ', mb_strtolower($text, 'UTF-8')));
    }

    /**
     * "Cloud AI (Gemma)" button: Gemma chats about unrelated things in Bikol-Iriga. It gets the real
     * time/date line and is told to leave health questions to the verified health information.
     * Fully online, like the cloud mode in RagService: on failure the user is told, nothing else is tried.
     */
    private function cloudReply(string $message): string
    {
        $system = "Ika ay LMLinga, chatbot kana health center sa Iriga City. Nakikipag-istorya ka sa kada-aldow na bagay.\n"
            . "Magsimbag SANA sa Bikol-Iriga (Rinconada), maikli (1-3 sentence). BOKO Naga Bikol, BOKO Tagalog.\n"
            . "Pirming gamiton: ana (boko 'an/ang'), kana (boko 'kan/nin/ng'), ono (boko 'ano/uno'), paono (boko 'pano'), "
            . "agko (boko 'igwa/may'), uda (boko 'mayo/wala'), osad (boko 'sarong/isa'), ag (boko 'asin/at'), "
            . "kin (boko 'kun/kung'), kanimo (boko 'saimo'), ika (=you), ako (=I), diri (=don't), boko (=no/not), "
            . "aldow (boko 'aldaw'), maray (boko 'marhay'), ngowan (=now/today).\n"
            . "Sa oras, aldow o petsa: gamiton SANA ana oras ag petsa sa unang linya kana mensahe.\n"
            . "Diri magtao health advice o bolong. Kin health ana hapot, sabihon na mag-onga ika manongod sa health topic "
            . "(halimbawa: dengue, otos, kalintora) ag verified health information ana kukuon.";

        $apiKey = (string) config('services.ollama.api_key', '');
        $base = $apiKey !== '' ? 'https://ollama.com' : rtrim((string) config('services.ollama.base_url'), '/');

        try {
            $request = Http::timeout(60);
            if ($apiKey !== '') {
                $request = $request->withToken($apiKey);
            }
            $response = $request->post($base . '/api/chat', [
                'model' => (string) config('services.bikol_chat.cloud_model', 'gemma4:31b-cloud'),
                'stream' => false,
                'options' => ['temperature' => 0.3, 'num_predict' => 200],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $this->contextLine() . "\n" . $message],
                ],
            ]);
            $text = $response->successful() ? trim(str_replace(['**', '##', '#'], '', (string) $response->json('message.content', ''))) : '';
        } catch (\Throwable $e) {
            Log::warning('Cloud AI (Gemma) small talk failed', ['error' => $e->getMessage()]);
            $text = '';
        }

        return mb_strlen($text) >= 2
            ? $text
            : 'Diri makakonekta sa Cloud AI (Gemma). Siguraduhon na agko internet ag uliton ulit.';
    }
}
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
