<?php

namespace App\Services;

use App\Exceptions\ChatbotDatasetWorkflowException;
use App\Models\ChatbotDataset;
use App\Models\ChatbotDatasetVersion;
use App\Models\User;
use App\Support\ChatbotDatasetCatalog;
use App\Support\ChatbotDatasetFileInspector;

/**
 * Lists the chatbot's existing documents (resources/health_docs/{bcl,en,tl}/{category}/*.txt) as datasets so the
 * Admin can see and manage them in Chatbot Datasets. Used by the `chatbot:datasets-import-existing` command and
 * by the "Show existing documents" button.
 *
 * Importing only records the file as a dataset. The document itself, health_chunks and the chatbot are not
 * touched: the built-in file keeps serving the chatbot until the Admin changes the dataset (then the new text is
 * written to the admin folder and replaces it, see ChatbotDatasetPublisher). With Linguist review on, each file
 * becomes a Submitted dataset (never Approved); without review it becomes a Live dataset.
 * Idempotent: files already listed (same source name, or same content) are skipped.
 */
class ChatbotDatasetImporter
{
    public function __construct(
        private readonly ChatbotDatasetWorkflow $workflow,
        private readonly ChatbotDatasetFileInspector $inspector,
    ) {}

    /**
     * @param  (callable(string, string): void)|null  $report  called with (kind, message); kind: skip|import|would|blocked
     * @return array{new: int, skipped: int, blocked: int}
     */
    public function run(?User $admin, bool $execute, ?callable $report = null): array
    {
        $report ??= static function (string $kind, string $message): void {};
        $root = resource_path('health_docs');
        $counts = ['new' => 0, 'skipped' => 0, 'blocked' => 0];

        foreach (array_keys(ChatbotDatasetCatalog::LANGUAGES) as $language) {
            foreach (array_keys(ChatbotDatasetCatalog::CATEGORIES) as $category) {
                $files = glob($root.DIRECTORY_SEPARATOR.$language.DIRECTORY_SEPARATOR.$category.DIRECTORY_SEPARATOR.'*.txt') ?: [];
                sort($files);
                foreach ($files as $path) {
                    $name = basename($path);
                    $label = "{$language}/{$category}/{$name}";
                    if (preg_match(ChatbotDatasetCatalog::SOURCE_NAME_PATTERN, $name) !== 1) {
                        $report('blocked', "{$label}: file name is not a valid source name");
                        $counts['blocked']++;

                        continue;
                    }

                    $inspection = $this->inspector->inspect((string) file_get_contents($path), $name, $language);
                    if ($inspection['errors'] !== []) {
                        $report('blocked', "{$label}: ".implode(' ', $inspection['errors']));
                        $counts['blocked']++;

                        continue;
                    }
                    if ($this->alreadyImported($language, $category, $name, $inspection['sha256'])) {
                        $report('skip', "{$label} (already listed)");
                        $counts['skipped']++;

                        continue;
                    }

                    $report($execute ? 'import' : 'would', $label.($inspection['warnings'] !== [] ? ' ('.count($inspection['warnings']).' screening warnings)' : ''));
                    $counts['new']++;
                    if (! $execute) {
                        continue;
                    }

                    try {
                        $dataset = $this->workflow->upload($admin, [
                            'title' => $this->titleFromName($name),
                            'language' => $language,
                            'category' => $category,
                            'source_name' => $name,
                            'source_reference' => 'Existing LMLinga chatbot corpus file resources/health_docs/'.$label.' (imported)',
                            'no_personal_data_confirmed' => true,
                            'original_filename' => $name,
                        ], $inspection, ChatbotDatasetWorkflow::ACTION_IMPORTED);
                        if ($this->workflow->requiresReview()) {
                            $this->workflow->submit($admin, $dataset);
                        }
                    } catch (ChatbotDatasetWorkflowException $e) {
                        $report('blocked', "{$label}: ".$e->getMessage());
                        $counts['new']--;
                        $counts['blocked']++;
                    }
                }
            }
        }

        return $counts;
    }

    /** Valid existing documents that are not listed as datasets yet (cheap: names only, no file content read). */
    public function pendingCount(): int
    {
        $listed = ChatbotDataset::query()->get(['language', 'category', 'source_name'])
            ->mapWithKeys(fn (ChatbotDataset $d): array => ["{$d->language}/{$d->category}/{$d->source_name}" => true])
            ->all();

        $pending = 0;
        $root = resource_path('health_docs');
        foreach (array_keys(ChatbotDatasetCatalog::LANGUAGES) as $language) {
            foreach (array_keys(ChatbotDatasetCatalog::CATEGORIES) as $category) {
                foreach (glob($root.DIRECTORY_SEPARATOR.$language.DIRECTORY_SEPARATOR.$category.DIRECTORY_SEPARATOR.'*.txt') ?: [] as $path) {
                    $name = basename($path);
                    if (preg_match(ChatbotDatasetCatalog::SOURCE_NAME_PATTERN, $name) === 1 && ! isset($listed["{$language}/{$category}/{$name}"])) {
                        $pending++;
                    }
                }
            }
        }

        return $pending;
    }

    private function alreadyImported(string $language, string $category, string $name, string $sha256): bool
    {
        return ChatbotDataset::query()->where(compact('language', 'category') + ['source_name' => $name])->exists()
            || ChatbotDatasetVersion::query()->where('content_sha256', $sha256)->exists();
    }

    private function titleFromName(string $name): string
    {
        $title = trim((string) preg_replace('/[_\s]+/', ' ', substr($name, 0, -4)));

        return mb_substr(mb_strtoupper(mb_substr($title, 0, 1)).mb_substr($title, 1), 0, 160);
    }
}
