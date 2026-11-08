<?php

namespace App\Support;

use App\Models\ChatbotDatasetVersion;
use App\Models\ChatbotTermVersion;
use Illuminate\Support\Facades\Schema;

/**
 * SR-06 Language Review queue. Lists items waiting for a Linguist's decision.
 *
 * Terms (SR-05): Bikol-Iriga term versions an Admin submitted for review, oldest first. Nothing is
 * invented here: the list is empty until an Admin submits a term. Datasets (SR-07): dataset versions an
 * Admin submitted, oldest first.
 * Approve/reject actions are authorized by the `review-language-content` gate.
 */
final class LanguageReviewQueue
{
    public const SECTION_TERMS = 'terms';

    public const SECTION_DATASETS = 'datasets';

    /**
     * @return array<string, array{title: string, description: string, items: list<array<string, mixed>>}>
     */
    public function sections(): array
    {
        return [
            self::SECTION_TERMS => [
                'title' => 'Bikol-Iriga terms',
                'description' => 'Terms added by administrators are reviewed here before the chatbot may use them.',
                'items' => $this->termItems(),
            ],
            self::SECTION_DATASETS => [
                'title' => 'Chatbot datasets',
                'description' => 'Health information datasets are reviewed here before the chatbot may use them.',
                'items' => $this->datasetItems(),
            ],
        ];
    }

    public function pendingCount(): int
    {
        return array_sum(array_map(static fn (array $section): int => count($section['items']), $this->sections()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function termItems(): array
    {
        if (! Schema::hasTable('chatbot_term_versions')) {
            return [];
        }

        $submitted = ChatbotTermVersion::query()
            ->join('chatbot_terms', 'chatbot_terms.id', '=', 'chatbot_term_versions.chatbot_term_id')
            ->where('chatbot_terms.language', ChatbotTermStatus::LANGUAGE_BIKOL)
            ->whereNull('chatbot_terms.retired_at')
            ->where('chatbot_term_versions.status', ChatbotTermStatus::SUBMITTED)
            ->orderBy('chatbot_term_versions.updated_at')
            ->orderBy('chatbot_term_versions.id')
            ->get(['chatbot_term_versions.*']);

        $withApproved = ChatbotTermVersion::query()
            ->whereIn('chatbot_term_id', $submitted->pluck('chatbot_term_id')->all())
            ->where('status', ChatbotTermStatus::APPROVED)
            ->pluck('chatbot_term_id')
            ->all();

        return $submitted
            ->map(fn (ChatbotTermVersion $v): array => ChatbotTermPresenter::queueItem($v, in_array($v->chatbot_term_id, $withApproved, false)))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function datasetItems(): array
    {
        if (! Schema::hasTable('chatbot_dataset_versions')) {
            return [];
        }

        $submitted = ChatbotDatasetVersion::query()
            ->join('chatbot_datasets', 'chatbot_datasets.id', '=', 'chatbot_dataset_versions.chatbot_dataset_id')
            ->whereNull('chatbot_datasets.retired_at')
            ->where('chatbot_dataset_versions.status', ChatbotDatasetCatalog::SUBMITTED)
            ->orderBy('chatbot_dataset_versions.updated_at')
            ->orderBy('chatbot_dataset_versions.id')
            ->get([
                'chatbot_dataset_versions.id', 'chatbot_dataset_versions.chatbot_dataset_id', 'chatbot_dataset_versions.title',
                'chatbot_dataset_versions.screening', 'chatbot_dataset_versions.updated_at',
                'chatbot_datasets.language', 'chatbot_datasets.category',
            ]);

        $withApproved = ChatbotDatasetVersion::query()
            ->whereIn('chatbot_dataset_id', $submitted->pluck('chatbot_dataset_id')->all())
            ->where('status', ChatbotDatasetCatalog::APPROVED)
            ->pluck('chatbot_dataset_id')
            ->all();

        return $submitted
            ->map(fn (ChatbotDatasetVersion $v): array => ChatbotDatasetPresenter::queueItem(
                $v, (string) $v->language, (string) $v->category, in_array($v->chatbot_dataset_id, $withApproved, false)
            ))
            ->values()
            ->all();
    }
}
