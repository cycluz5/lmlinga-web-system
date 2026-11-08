<?php

namespace App\Console\Commands;

use App\Models\HealthChunk;
use App\Services\RagService;
use Illuminate\Console\Command;

class IndexHealthDocs extends Command
{
    protected $signature = 'health:index
                            {--if-empty : Only index when the health_chunks table has no rows}';
    protected $description = 'Embed and index all files in resources/health_docs into the health_chunks table';

    public function handle(RagService $rag): int
    {
        if ($this->option('if-empty') && HealthChunk::query()->exists()) {
            $this->info('health_chunks already populated; skipping. Run without --if-empty to re-index.');
            return self::SUCCESS;
        }

        $this->info('Indexing health documents... this may take a few minutes.');

        $log = $rag->indexAllDocuments();

        foreach ($log as $line) {
            $this->line($line);
        }

        $this->info('Indexing complete.');
        return self::SUCCESS;
    }
}
