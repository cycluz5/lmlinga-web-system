<?php

namespace App\Console\Commands;

use App\Models\ChatbotDataset;
use App\Services\ChatbotDatasetPublisher;
use Illuminate\Console\Command;

/**
 * One-off: send the live text of Admin-edited datasets to the chatbot (write the file and index it).
 *
 * Datasets that were still waiting for review when review was removed are promoted by a migration, but the
 * migration does not touch the chatbot's files or index. This command does, for every live dataset that does
 * not come from the built-in corpus (the imported rows already match the built-in files). Safe to run again.
 */
class RepublishChatbotDatasets extends Command
{
    private const IMPORTED_SOURCE_PREFIX = 'Existing LMLinga chatbot corpus file';

    protected $signature = 'lmlinga:datasets-republish';

    protected $description = 'Write and index the live text of Admin-edited chatbot datasets (not the built-in corpus)';

    public function handle(ChatbotDatasetPublisher $publisher): int
    {
        $failed = 0;
        $done = 0;

        ChatbotDataset::query()->whereNull('retired_at')->with('versions')->orderBy('id')->each(function (ChatbotDataset $dataset) use ($publisher, &$failed, &$done): void {
            $live = $dataset->approvedVersion();
            if ($live === null || str_starts_with((string) $live->source_reference, self::IMPORTED_SOURCE_PREFIX)) {
                return;
            }

            $label = "{$dataset->language}/{$dataset->category}/{$dataset->source_name}";
            $result = $publisher->publish($dataset);
            if ($result['indexed']) {
                $this->line('indexed  '.$label);
                $done++;
            } elseif ($result['written']) {
                $this->warn('written, not indexed  '.$label.': '.$result['error']);
                $failed++;
            } else {
                $this->error('failed   '.$label.': '.$result['error']);
                $failed++;
            }
        });

        $this->info("Republished: {$done}, needing another try: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}