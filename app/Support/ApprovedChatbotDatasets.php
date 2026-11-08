<?php

namespace App\Support;

use App\Models\ChatbotDatasetVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * SR-07 read-only list of Linguist-approved chatbot datasets — the only datasets a future indexer may use.
 *
 * Only approved versions of datasets that are not retired. Draft, submitted, rejected and superseded
 * versions, and every version of a retired dataset, are never included. Not called by the chatbot or by
 * `health:index`: indexing/activation needs a separate approval (the current health_docs corpus stays in use).
 */
final class ApprovedChatbotDatasets
{
    private const CACHE_KEY = 'lmlinga.chatbot.approved-datasets';

    /**
     * @return list<array{version_id: int, language: string, category: string, source_name: string, title: string, content_sha256: string}>
     */
    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, static function (): array {
            if (! Schema::hasTable('chatbot_dataset_versions')) {
                return [];
            }

            return self::query()
                ->orderBy('chatbot_datasets.language')
                ->orderBy('chatbot_datasets.category')
                ->orderBy('chatbot_datasets.source_name')
                ->get([
                    'chatbot_dataset_versions.id', 'chatbot_datasets.language', 'chatbot_datasets.category',
                    'chatbot_datasets.source_name', 'chatbot_dataset_versions.title', 'chatbot_dataset_versions.content_sha256',
                ])
                ->map(static fn (ChatbotDatasetVersion $v): array => [
                    'version_id' => (int) $v->id,
                    'language' => (string) $v->language,
                    'category' => (string) $v->category,
                    'source_name' => (string) $v->source_name,
                    'title' => $v->title,
                    'content_sha256' => $v->content_sha256,
                ])
                ->all();
        });
    }

    /** Approved, non-retired versions (with content) — for a future, separately approved indexer. */
    public static function query(): Builder
    {
        return ChatbotDatasetVersion::query()
            ->join('chatbot_datasets', 'chatbot_datasets.id', '=', 'chatbot_dataset_versions.chatbot_dataset_id')
            ->whereNull('chatbot_datasets.retired_at')
            ->where('chatbot_dataset_versions.status', ChatbotDatasetCatalog::APPROVED);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
