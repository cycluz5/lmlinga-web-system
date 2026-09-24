<?php

namespace App\Console\Commands;

use App\Support\AtRestColumnMigrator;
use App\Support\AtRestColumns;
use App\Support\AtRestEncrypter;
use App\Support\AtRestEncryptionException;
use App\Support\AtRestNarrativeField;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class VerifyAtRestEncryption extends Command
{
    protected $signature = 'lmlinga:at-rest:verify';

    protected $description = 'Report encrypted / plaintext / undecryptable values for every AES-256-GCM health-record column.';

    public function handle(AtRestColumnMigrator $migrator): int
    {
        $rows = [];
        $problems = 0;

        foreach (AtRestColumns::tables() as $table) {
            $columns = $migrator->presentColumns($table);
            if ($columns === []) {
                $rows[] = [$table, '(table not present)', '-', '-', '-'];

                continue;
            }

            $counts = array_fill_keys($columns, ['sealed' => 0, 'plaintext' => 0, 'failed' => 0]);
            $pk = AtRestColumnMigrator::primaryKey($table);

            DB::table($table)->select(array_merge([$pk], $columns))->orderBy($pk)->chunkById(500, function ($chunk) use ($table, $columns, &$counts): void {
                foreach ($chunk as $row) {
                    foreach ($columns as $column) {
                        $value = $row->{$column};
                        if ($value === null) {
                            continue;
                        }

                        if (! AtRestEncrypter::looksEncrypted($value)) {
                            $counts[$column]['plaintext']++;

                            continue;
                        }

                        try {
                            AtRestNarrativeField::openOrFail($value, $table, $column);
                            $counts[$column]['sealed']++;
                        } catch (AtRestEncryptionException) {
                            $counts[$column]['failed']++;
                        }
                    }
                }
            }, $pk);

            foreach ($counts as $column => $count) {
                $problems += $count['plaintext'] + $count['failed'];
                $rows[] = [$table, $column, $count['sealed'], $count['plaintext'], $count['failed']];
            }
        }

        $this->table(['Table', 'Column', 'Encrypted', 'Plaintext', 'Undecryptable'], $rows);

        if ($problems > 0) {
            $this->error("{$problems} value(s) are plaintext or cannot be decrypted with the configured keys.");

            return self::FAILURE;
        }

        $this->info('All registered health-record columns are encrypted and decryptable.');

        return self::SUCCESS;
    }
}
