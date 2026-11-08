<?php

namespace App\Console\Commands;

use App\Exceptions\ChatbotDatasetWorkflowException;
use App\Models\ChatbotDataset;
use App\Models\ChatbotDatasetVersion;
use App\Models\User;
use App\Services\ChatbotDatasetWorkflow;
use App\Support\ChatbotDatasetCatalog;
use App\Support\ChatbotDatasetFileInspector;
use App\Support\StaffRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SR-07 (D-S6): copy the existing chatbot corpus (resources/health_docs/{bcl,en,tl}/{category}/*.txt) into
 * chatbot datasets so a Linguist can review it. Dry run by default. With --execute each file becomes a
 * dataset version that is **Submitted, never Approved**. Idempotent: files already imported (same source
 * name or same content) are skipped. The corpus files, health_chunks and the chatbot are not touched.
 */
class ImportExistingChatbotDatasets extends Command
{
    protected $signature = 'chatbot:datasets-import-existing
                            {--execute : Write to the database (default is a dry run)}
                            {--admin= : E-mail of the active Admin recorded as uploader and submitter}
                            {--confirm-no-personal-data : The Admin confirms the corpus contains no resident personal information}';

    protected $description = 'Import the existing chatbot health_docs corpus as datasets awaiting Linguist review (dry run by default)';

    public function handle(ChatbotDatasetWorkflow $workflow, ChatbotDatasetFileInspector $inspector): int
    {
        $execute = (bool) $this->option('execute');
        $connection = DB::connection();
        $this->line(sprintf(
            'Database: %s (%s:%s/%s) — %s',
            $connection->getName(), (string) $connection->getConfig('host'), (string) $connection->getConfig('port'),
            $connection->getDatabaseName(), $execute ? 'EXECUTE' : 'dry run, nothing will be written'
        ));

        $admin = null;
        if ($execute) {
            $admin = User::query()->where('email', (string) $this->option('admin'))->first();
            if (! $admin instanceof User || ! $admin->isActive() || StaffRole::normalize($admin->role) !== StaffRole::ADMIN) {
                $this->error('--admin must be the e-mail of an active Admin account.');

                return self::FAILURE;
            }
            if (! $this->option('confirm-no-personal-data')) {
                $this->error('--confirm-no-personal-data is required with --execute.');

                return self::FAILURE;
            }
            if ($this->input->isInteractive() && ! $this->confirm('Import the corpus as Submitted datasets into this database?')) {
                $this->warn('Cancelled.');

                return self::FAILURE;
            }
        }

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
                        $this->warn("BLOCKED {$label}: file name is not a valid source name");
                        $counts['blocked']++;

                        continue;
                    }

                    $inspection = $inspector->inspect((string) file_get_contents($path), $name, $language);
                    if ($inspection['errors'] !== []) {
                        $this->warn("BLOCKED {$label}: ".implode(' ', $inspection['errors']));
                        $counts['blocked']++;

                        continue;
                    }
                    if ($this->alreadyImported($language, $category, $name, $inspection['sha256'])) {
                        $this->line("skip    {$label} (already imported)");
                        $counts['skipped']++;

                        continue;
                    }

                    $this->line(($execute ? 'import  ' : 'would   ').$label.($inspection['warnings'] !== [] ? ' ('.count($inspection['warnings']).' screening warnings)' : ''));
                    $counts['new']++;
                    if (! $execute) {
                        continue;
                    }

                    try {
                        $dataset = $workflow->upload($admin, [
                            'title' => $this->titleFromName($name),
                            'language' => $language,
                            'category' => $category,
                            'source_name' => $name,
                            'source_reference' => 'Existing LMLinga chatbot corpus file resources/health_docs/'.$label.' (imported for Linguist review)',
                            'no_personal_data_confirmed' => true,
                            'original_filename' => $name,
                        ], $inspection, ChatbotDatasetWorkflow::ACTION_IMPORTED);
                        $workflow->submit($admin, $dataset);
                    } catch (ChatbotDatasetWorkflowException $e) {
                        $this->warn("BLOCKED {$label}: ".$e->getMessage());
                        $counts['new']--;
                        $counts['blocked']++;
                    }
                }
            }
        }

        $this->info(sprintf(
            '%s: %d, already imported: %d, blocked: %d. No dataset was approved; the chatbot corpus is unchanged.',
            $execute ? 'Imported as Submitted' : 'Would import as Submitted', $counts['new'], $counts['skipped'], $counts['blocked']
        ));

        return $counts['blocked'] > 0 ? self::FAILURE : self::SUCCESS;
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
