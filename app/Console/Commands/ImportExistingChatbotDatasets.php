<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ChatbotDatasetImporter;
use App\Support\StaffRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SR-07 (D-S6): list the existing chatbot corpus (resources/health_docs/{bcl,en,tl}/{category}/*.txt) as chatbot
 * datasets. Dry run by default. With --execute each file becomes a dataset (Submitted when Linguist review is on,
 * Live otherwise; never approved by this command's own authority). Idempotent. The corpus files, health_chunks and
 * the chatbot are not touched. The logic lives in ChatbotDatasetImporter, shared with the Admin button.
 */
class ImportExistingChatbotDatasets extends Command
{
    protected $signature = 'chatbot:datasets-import-existing
                            {--execute : Write to the database (default is a dry run)}
                            {--admin= : E-mail of the active Admin recorded as uploader}
                            {--confirm-no-personal-data : The Admin confirms the corpus contains no resident personal information}';

    protected $description = 'List the existing chatbot health_docs corpus as datasets in Chatbot Datasets (dry run by default)';

    public function handle(ChatbotDatasetImporter $importer): int
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
            if ($this->input->isInteractive() && ! $this->confirm('List the corpus as datasets in this database?')) {
                $this->warn('Cancelled.');

                return self::FAILURE;
            }
        }

        $counts = $importer->run($admin, $execute, function (string $kind, string $message): void {
            match ($kind) {
                'blocked' => $this->warn('BLOCKED '.$message),
                'skip' => $this->line('skip    '.$message),
                'import' => $this->line('import  '.$message),
                default => $this->line('would   '.$message),
            };
        });

        $this->info(sprintf(
            '%s: %d, already listed: %d, blocked: %d. The chatbot corpus is unchanged.',
            $execute ? 'Listed as datasets' : 'Would list as datasets', $counts['new'], $counts['skipped'], $counts['blocked']
        ));

        return $counts['blocked'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
