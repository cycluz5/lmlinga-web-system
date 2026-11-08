<?php

namespace App\Services;

/**
 * Tagalog chit-chat replies. Not part of the health RAG corpus.
 * Separate from BikolConversationService — do not share response text.
 */
class TagalogConversationService
{
    /**
     * Longer phrases first so "kumusta ka" wins over "kumusta".
     *
     * @return list<array{intent: string, input: string, response: string}>
     */
    public function phrases(): array
    {
        $groups = [
            'greeting' => [
                'inputs' => [
                    'magandang umaga',
                    'magandang hapon',
                    'magandang gabi',
                    'kumusta ka',
                    'kamusta ka',
                    'kumusta',
                    'kamusta',
                    'hello',
                    'hi',
                ],
                'response' => 'Magandang araw! Ako ang LMLinga Health Chatbot. Ano ang maitutulong ko sa iyo?',
            ],
            'thanks' => [
                'inputs' => [
                    'maraming salamat',
                    'thank you',
                    'salamat',
                    'thanks',
                ],
                'response' => 'Walang anuman. Maaari kang magtanong muli tungkol sa kalusugan.',
            ],
            'welcome' => [
                'inputs' => [
                    'walang anuman',
                ],
                'response' => 'Sige. Kung may iba pang tanong sa kalusugan, sabihin mo lang.',
            ],
            'goodbye' => [
                'inputs' => [
                    'sige, paalam',
                    'goodbye',
                    'paalam',
                    'bye',
                ],
                'response' => 'Sige! Ingat palagi at magandang araw.',
            ],
            'help' => [
                'inputs' => [
                    'tulungan mo ako',
                    'pwede magtanong',
                    'may tanong ako',
                ],
                'response' => 'Oo. Maaari kang magtanong tungkol sa impormasyong pangkalusugan.',
            ],
            'identity' => [
                'inputs' => [
                    'ano ang lmlinga chatbot',
                    'ano ang lmlinga',
                    'sino ka',
                    'ano ka',
                ],
                'response' => 'Ako ang LMLinga Health Chatbot. Nagbibigay ako ng impormasyong pangkalusugan para sa mga residente.',
            ],
            'capabilities' => [
                'inputs' => [
                    'ano ang kaya mong gawin',
                    'ano ang pwede kong itanong',
                    'ano ang maitutulong mo',
                ],
                'response' => 'Maaari kang magtanong tungkol sa mga paksang pangkalusugan—tulad ng ano ito, sanhi, sintomas, pag-iwas, paggamot, at benepisyo—batay sa aming health materials.',
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
            '/\b(ano|paano|bakit|sintomas|senyales|sanhi|dahilan|benepisyo|paggamot|gamot|ginagamot|maiwasan|maiiwasan|pag-iwas|hypertension|dengue|autism|malaria|tetanus|trangkaso|malnutrition|bakuna|lagnat|influenza|pneumonia)\b/u',
            $remainder
        ) === 1;
    }
}
