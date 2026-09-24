<?php

namespace App\Console\Commands;

use App\Services\RagService;
use Illuminate\Console\Command;

class IndexHealthDocs extends Command
{
    protected $signature = 'health:index';
    protected $description = 'Embed and index all files in storage/app/health_docs into the health_chunks table';

    public function handle(RagService $rag): int
    {
        $this->info('Indexing health documents... this may take a few minutes.');

        $log = $rag->indexAllDocuments();

        foreach ($log as $line) {
            $this->line($line);
        }

        $this->info('Indexing complete.');
        return self::SUCCESS;
    }
}