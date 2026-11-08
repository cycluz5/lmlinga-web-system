<?php

namespace App\Services;

/**
 * English chit-chat replies. Not part of the health RAG corpus.
 * Separate from Tagalog/Bikol conversation services — do not share response text.
 */
class EnglishConversationService
{
    /**
     * Longer phrases first so "good morning" wins over "good".
     *
     * @return list<array{intent: string, input: string, response: string}>
     */
    public function phrases(): array
    {
        $groups = [
            'greeting' => [
                'inputs' => [
                    'good morning',
                    'good afternoon',
                    'good evening',
                    'hello there',
                    'hello',
                    'hey there',
                    'hey',
                    'hi there',
                    'hi',
                ],
                'response' => 'Hello! I\'m the LMLinga Health Chatbot. How can I help you with health information today?',
            ],
            'thanks' => [
                'inputs' => [
                    'thank you so much',
                    'thank you',
                    'thanks a lot',
                    'thanks',
                ],
                'response' => 'You\'re welcome. You can ask me another health question anytime.',
            ],
            'welcome' => [
                'inputs' => [
                    'you are welcome',
                    'you\'re welcome',
                    'your welcome',
                ],
                'response' => 'Happy to help. Feel free to ask if you have another health question.',
            ],
            'goodbye' => [
                'inputs' => [
                    'see you later',
                    'goodbye',
                    'good bye',
                    'bye bye',
                    'bye',
                ],
                'response' => 'Take care! Stay healthy, and come back if you need more health information.',
            ],
            'identity' => [
                'inputs' => [
                    'who are you',
                    'what are you',
                    'what is lmlinga',
                    'what is the lmlinga chatbot',
                ],
                'response' => 'I\'m the LMLinga Health Chatbot. I share barangay health information for residents, based on our health materials.',
            ],
            'capabilities' => [
                'inputs' => [
                    'what can you do',
                    'what can you help with',
                    'how can you help',
                    'what can i ask',
                ],
                'response' => 'You can ask about health topics in our materials—such as what a condition is, causes, signs, prevention, treatment, and benefits. I answer from those sources and won\'t invent medical advice.',
            ],
        ];

        $phrases = [];
        foreach ($groups as $intent => $group) {
            foreach ($group['inputs'] as $input) {
                $phrases[] = [
                    'intent' => $intent,
                    'input' => $input,
                    'response' => $group['response'],
                ];
            }
        }

        usort($phrases, function (array $a, array $b): int {
            return mb_strlen($b['input']) <=> mb_strlen($a['input']);
        });

        return $phrases;
    }

    public function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Strip wrapping terminal punctuation for whole-utterance matching.
     */
    public function normalizeUtterance(string $text): string
    {
        $text = $this->normalize($text);
        $text = preg_replace('/^[\s?!.,;:]+/u', '', $text) ?? $text;
        $text = preg_replace('/[\s?!.,;:]+$/u', '', $text) ?? $text;
        $text = trim($text);

        // Harmless filler such as "ah" / "ahh" before a known phrase.
        $text = preg_replace('/^(ah+)\b(?:\s*[?!.,;:]+)?\s*/u', '', $text) ?? $text;
        $text = trim($text);
        $text = preg_replace('/^[\s?!.,;:]+/u', '', $text) ?? $text;
        $text = preg_replace('/[\s?!.,;:]+$/u', '', $text) ?? $text;
        $text = trim($text);

        // Stretched goodbye only: byee / byeee → bye.
        if (preg_match('/^bye{2,}$/u', $text) === 1) {
            $text = 'bye';
        }

        // Normalize curly apostrophe in you're / you're welcome.
        $text = str_replace(["\u{2019}", '`'], "'", $text);

        return $text;
    }

    /**
     * @return array{type: 'canned', intent: string, answer: string}|array{type: 'health', question: string}|array{type: 'none'}
     */
    public function resolve(string $question): array
    {
        $utterance = $this->normalizeUtterance($question);
        if ($utterance === '') {
            return ['type' => 'none'];
        }

        foreach ($this->phrases() as $phrase) {
            if ($utterance === $phrase['input']) {
                return [
                    'type' => 'canned',
                    'intent' => $phrase['intent'],
                    'answer' => $phrase['response'],
                ];
            }
        }

        $remainder = $this->stripConversationalPrefix($question);
        if ($remainder === null) {
            return ['type' => 'none'];
        }

        $remainderUtterance = $this->normalizeUtterance($remainder);
        foreach ($this->phrases() as $phrase) {
            if ($remainderUtterance === $phrase['input']) {
                return [
                    'type' => 'canned',
                    'intent' => $phrase['intent'],
                    'answer' => $phrase['response'],
                ];
            }
        }

        return [
            'type' => 'health',
            'question' => $remainder,
        ];
    }

    /**
     * Remove a leading canned phrase when a separate remainder remains.
     */
    public function stripConversationalPrefix(string $question): ?string
    {
        $normalized = $this->normalize($question);
        $normalized = str_replace(["\u{2019}", '`'], "'", $normalized);

        foreach ($this->phrases() as $phrase) {
            $input = $phrase['input'];
            $quoted = preg_quote($input, '/');

            if (preg_match('/^' . $quoted . '[,.!?;:]+\s*(.+)$/u', $normalized, $m) === 1) {
                $remainder = trim($m[1]);
                if ($remainder !== '') {
                    return $remainder;
                }
            }

            if (
                preg_match('/^' . $quoted . '\s+(.+)$/u', $normalized, $m) === 1
                && $this->remainderLooksLikeHealth($m[1])
            ) {
                return trim($m[1]);
            }
        }

        return null;
    }

    protected function remainderLooksLikeHealth(string $remainder): bool
    {
        $remainder = $this->normalize($remainder);

        return preg_match(
            '/\b(what|why|how|when|where|who|symptom|symptoms|cause|causes|prevent|prevention|treat|treatment|benefit|benefits|hypertension|dengue|autism|influenza|pneumonia|vaccine|fever)\b/u',
            $remainder
        ) === 1;
    }
}
