<?php

namespace App\Services;

/**
 * BCL chit-chat replies. Not part of the health RAG corpus.
 */
class BikolConversationService
{
    /**
     * Longer phrases first so "kumusta ika" wins over "kumusta".
     *
     * @return list<array{intent: string, input: string, response: string}>
     */
    public function phrases(): array
    {
        $groups = [
            'greeting' => [
                'inputs' => [
                    'maray na aldow',
                    'maray na mudto',
                    'maray na apon',
                    'maray na gab e',
                    'kumusta ika',
                    'kumusta',
                    'hello',
                    'hi',
                ],
                'response' => 'Maray na aldow! Ako a LMLinga Health Chatbot. Uno a maitatabang ko kanimo?',
            ],
            'thanks' => [
                'inputs' => [
                    'thank you',
                    'salamaton',
                    'salamat',
                    'thanks',
                ],
                'response' => 'Salamat man. Kin agko ika unga manungod sa kalusugan, pwede ika mag unga Kanako.',
            ],
            'goodbye' => [
                'inputs' => [
                    'sige mauna na ako',
                    'goodbye',
                    'paalam',
                    'bye',
                ],
                'response' => 'Sige! Ingat pirmi pala maray na aldow.',
            ],
            'help' => [
                'inputs' => [
                    'pwede mag unga',
                    'agko ako unga',
                    'tabangi ako',
                ],
                'response' => 'Amo. Pwede ika mag unga manungod sa impormasyon sa kalusugan.',
            ],
            'identity' => [
                'inputs' => [
                    'uno a lmlinga chatbot',
                    'uno a lmlinga',
                    'isay ika',
                    'uno ika',
                    'say ika',
                ],
                'response' => 'Ako a LMLinga Health Chatbot. Nagtatao ako sa impormasyon tungkol sa kalusugan base sa mga health information para sa residente.',
            ],
            'capabilities' => [
                'inputs' => [
                    'uno a kaya mong gibuhon',
                    'uno a pwede mong itabang',
                    'uno a pwede i-unga',
                ],
                'response' => 'Pwede ika mag unga manungod sa mga health topic. Arug ka sintomas, dahilan, paglikay, pagbubulong, bakuna, pala iba pang health information.',
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

        // Harmless filler such as "ah" / "ahh" / "ahh." before a known phrase.
        $text = preg_replace('/^(ah+)\b(?:\s*[?!.,;:]+)?\s*/u', '', $text) ?? $text;
        $text = trim($text);
        $text = preg_replace('/^[\s?!.,;:]+/u', '', $text) ?? $text;
        $text = preg_replace('/[\s?!.,;:]+$/u', '', $text) ?? $text;
        $text = trim($text);

        // Stretched goodbye only: byee / byeee → bye. Not biye, baby, or maybe.
        if (preg_match('/^bye{2,}$/u', $text) === 1) {
            $text = 'bye';
        }

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
            '/\b(uno|pauno|paano|ngata|senyales|sintomas|malikayan|maiwasan|paglikay|dengue|malaria|cholera|hepatitis|bakuna|milestone)\b/u',
            $remainder
        ) === 1;
    }
}
