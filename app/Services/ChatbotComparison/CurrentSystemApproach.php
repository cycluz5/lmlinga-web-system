<?php

namespace App\Services\ChatbotComparison;

use App\Services\RagService;

/**
 * SR-09 Approach C — the current system, exactly as residents use it: `RagService::ask` over the existing
 * (not Linguist-verified) corpus with production settings. Reference baseline only.
 */
final class CurrentSystemApproach implements ComparisonApproach
{
    public function __construct(private readonly RagService $rag)
    {
    }

    public function key(): string
    {
        return 'current';
    }

    public function label(): string
    {
        return 'C — current system (existing corpus, not Linguist-verified; reference baseline)';
    }

    public function pendingReason(): ?string
    {
        return null;
    }

    public function usesChatModel(): bool
    {
        return true; // English and Tagalog answers call the chat model; Bikol-Iriga answers are extractive.
    }

    public function answer(array $case): array
    {
        $result = $this->rag->ask($case['question'], $case['language']);

        return [
            'answer' => trim((string) ($result['answer'] ?? '')),
            'points' => array_values(array_map('strval', (array) ($result['points'] ?? []))),
            'sources' => array_values(array_map(
                static fn ($s): string => is_array($s) ? (string) ($s['source_file'] ?? '') : (string) $s,
                (array) ($result['sources'] ?? [])
            )),
            'language' => (string) ($result['language'] ?? ''),
            'output_valid' => 'n/a',
        ];
    }
}
