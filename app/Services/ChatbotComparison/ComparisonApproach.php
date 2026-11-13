<?php

namespace App\Services\ChatbotComparison;

/**
 * SR-09 one chatbot approach under evaluation. Approaches run only inside `chatbot:compare`; none of them
 * changes the resident chatbot, saves chat history or reads resident data.
 */
interface ComparisonApproach
{
    /** Short key used in CSV files, e.g. "model_only". */
    public function key(): string;

    /** Human label, e.g. "A — model only (locally hosted, configured, not trained)". */
    public function label(): string;

    /** Null when the approach can run; otherwise why it is pending (shown instead of a result). */
    public function pendingReason(): ?string;

    /** True when a run would call the chat model (used for the cloud-approval guard). */
    public function usesChatModel(): bool;

    /**
     * @param  array{case_id: string, language: string, question: string}  $case
     * @return array{answer: string, points: list<string>, sources: list<string>, language: string, output_valid: string}
     */
    public function answer(array $case): array;
}
