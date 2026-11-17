<?php

namespace App\Services;

use App\Models\ChatbotDataset;
use App\Models\HealthChunk;
use App\Support\ChatbotDatasetCatalog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Sends a published dataset into the chatbot's health information space.
 *
 * The live (approved) version's text is written to <admin_docs_path>/<language>/<category>/<source>.txt and only
 * that file is indexed (RagService::indexDocument), so the rest of the index is never rebuilt or emptied. The
 * chatbot reads that folder in addition to resources/health_docs, and a file here replaces a built-in file of
 * the same name.
 *
 * Writing the file is what makes the dataset part of the corpus; indexing makes it searchable. If the embedding
 * model is unreachable the file is still written and the dataset reports "waiting to be indexed"; the Admin can
 * retry with Re-index, and a full `health:index` also picks the file up. Nothing here changes dataset statuses.
 */
class ChatbotDatasetPublisher
{
    public function __construct(private readonly RagService $rag) {}

    /**
     * @return array{written: bool, indexed: bool, error: ?string}
     */
    public function publish(ChatbotDataset $dataset): array
    {
        $dataset->load('versions');
        $version = $dataset->approvedVersion();
        if ($dataset->isRetired() || $version === null) {
            return ['written' => false, 'indexed' => false, 'error' => 'This dataset has no live version.'];
        }

        try {
            $path = $this->path($dataset);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $version->content, true);
        } catch (Throwable $e) {
            Log::warning('chatbot_dataset.write_failed', ['dataset' => $dataset->id, 'error' => $e::class]);

            return ['written' => false, 'indexed' => false, 'error' => 'The file could not be saved for the chatbot.'];
        }

        try {
            $this->rag->indexDocument($dataset->language, $dataset->category, $dataset->source_name, $version->content);
        } catch (Throwable $e) {
            Log::warning('chatbot_dataset.index_failed', ['dataset' => $dataset->id, 'error' => $e::class]);

            return ['written' => true, 'indexed' => false, 'error' => 'The chatbot could not index the file yet (the AI embedding model is not reachable).'];
        }

        return ['written' => true, 'indexed' => true, 'error' => null];
    }

    /** Take the dataset out of the chatbot: remove its file and its indexed chunks. */
    public function unpublish(ChatbotDataset $dataset): void
    {
        try {
            File::delete($this->path($dataset));
        } catch (Throwable $e) {
            Log::warning('chatbot_dataset.delete_failed', ['dataset' => $dataset->id, 'error' => $e::class]);
        }

        if (Schema::hasTable('health_chunks')) {
            $this->rag->forgetDocument($dataset->language, $dataset->category, $dataset->source_name);
        }
    }

    public function isWritten(ChatbotDataset $dataset): bool
    {
        try {
            return File::exists($this->path($dataset));
        } catch (Throwable) {
            return false;
        }
    }

    public function isIndexed(ChatbotDataset $dataset): bool
    {
        return Schema::hasTable('health_chunks')
            && HealthChunk::query()
                ->where('language', $dataset->language)
                ->where('category', $dataset->category)
                ->where('source_file', $dataset->source_name)
                ->exists();
    }

    /** Never builds a path from anything but catalog-validated parts, and never outside the admin folder. */
    private function path(ChatbotDataset $dataset): string
    {
        if (! array_key_exists($dataset->language, ChatbotDatasetCatalog::LANGUAGES)
            || ! array_key_exists($dataset->category, ChatbotDatasetCatalog::CATEGORIES)
            || preg_match(ChatbotDatasetCatalog::SOURCE_NAME_PATTERN, $dataset->source_name) !== 1) {
            throw new RuntimeException('Dataset has an invalid language, category or source name.');
        }

        return $this->rag->adminDocsRoot().'/'.$dataset->language.'/'.$dataset->category.'/'.$dataset->source_name;
    }
}
