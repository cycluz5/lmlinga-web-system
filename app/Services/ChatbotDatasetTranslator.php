<?php

namespace App\Services;

use App\Exceptions\ChatbotDatasetWorkflowException;
use App\Support\ChatbotDatasetCatalog;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Drafts a translation of a verified dataset with the local Ollama model. The result is only a draft: the
 * Admin must read, correct and verify it before the chatbot may use it (ChatbotDatasetWorkflow::verifyAndPublish).
 * For Bikol-Iriga the team's verified Iriga word list (BikolIrigaTerms) is given to the model and applied afterwards.
 */
class ChatbotDatasetTranslator
{
    /** Roughly how many characters go to the model at once, so structure and headings stay in order. */
    private const CHUNK_CHARS = 1500;

    private const LANGUAGE_NAMES = [
        'en' => 'English',
        'tl' => 'Tagalog',
        'bcl' => 'Central Bikol as spoken in Iriga City (Bikol-Iriga)',
    ];

    public function __construct(private readonly BikolIrigaTerms $terms)
    {
    }

    public function model(): string
    {
        return (string) config('lmlinga.chatbot_datasets.translation_model', 'gemma3:4b');
    }

    /**
     * @throws ChatbotDatasetWorkflowException when the model cannot be reached or returns nothing
     */
    public function translate(string $text, string $from, string $to): string
    {
        if (! array_key_exists($from, self::LANGUAGE_NAMES) || ! array_key_exists($to, self::LANGUAGE_NAMES) || $from === $to) {
            throw new ChatbotDatasetWorkflowException('Choose a different supported language to translate into.', 422, 'language');
        }

        $out = [];
        foreach ($this->chunks($text) as $chunk) {
            $translated = $this->ask($chunk, $from, $to);
            $out[] = $to === 'bcl' ? $this->terms->apply($translated) : $translated;
        }

        return trim(implode("\n\n", $out));
    }

    /** @return list<string> blocks separated by blank lines, grouped up to CHUNK_CHARS */
    private function chunks(string $text): array
    {
        $chunks = [];
        $current = '';
        foreach (preg_split('/\n{2,}/u', trim($text)) ?: [] as $block) {
            if ($current !== '' && mb_strlen($current.$block) > self::CHUNK_CHARS) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= ($current === '' ? '' : "\n\n").$block;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function ask(string $chunk, string $from, string $to): string
    {
        $system = 'You are a careful translator of public health information. Translate the user text from '
            .self::LANGUAGE_NAMES[$from].' into '.self::LANGUAGE_NAMES[$to].'. '
            .'Keep every heading, line break, bullet and number exactly where it is. Do not add, remove, explain or summarise anything, '
            .'and do not add notes. Keep medical terms, drug names and numbers unchanged. Reply with the translation only.';
        if ($to === 'bcl' && ($glossary = $this->terms->glossaryPrompt()) !== '') {
            $system .= ' Prefer these Iriga words: '.$glossary.'.';
        }

        try {
            $response = Http::timeout((int) config('lmlinga.chatbot_datasets.translation_timeout', 180))
                ->post(rtrim((string) config('services.ollama.base_url'), '/').'/api/chat', [
                    'model' => $this->model(),
                    'stream' => false,
                    'options' => ['temperature' => 0.1],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $chunk],
                    ],
                ]);
            $content = trim((string) $response->json('message.content', ''));
        } catch (Throwable) {
            $content = '';
            $response = null;
        }

        if ($response === null || ! $response->successful() || $content === '') {
            throw new ChatbotDatasetWorkflowException('The translation model is not reachable. Try again later or add the translation yourself.');
        }

        return $content;
    }

    /** Supported language key to its display label (for messages). */
    public static function label(string $language): string
    {
        return ChatbotDatasetCatalog::LANGUAGES[$language] ?? $language;
    }
}
