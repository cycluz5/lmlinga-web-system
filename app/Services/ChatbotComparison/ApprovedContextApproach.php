<?php

namespace App\Services\ChatbotComparison;

use App\Support\ApprovedChatbotDatasets;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * SR-09 Approach B — context-based answers from Linguist-approved datasets (SR-07), using the same model
 * as Approach A. Not available in Phase 1: it needs SR-07b activation (approved-dataset index and corpus
 * source), which is approved in principle for a later phase only. It never answers from the unverified
 * corpus and never substitutes another approach — every row is reported as pending.
 */
final class ApprovedContextApproach implements ComparisonApproach
{
    public function key(): string
    {
        return 'approved_context';
    }

    public function label(): string
    {
        return 'B — context-based, Linguist-approved datasets only (same model as A)';
    }

    public function pendingReason(): ?string
    {
        // Direct read-only count (no cache write).
        $approved = Schema::hasTable('chatbot_dataset_versions') ? ApprovedChatbotDatasets::query()->count() : 0;

        return 'Pending: SR-07b dataset activation is not implemented (approved datasets available: '.$approved.').';
    }

    public function usesChatModel(): bool
    {
        return false;
    }

    public function answer(array $case): array
    {
        throw new LogicException('Approach B is pending SR-07b activation.');
    }
}
