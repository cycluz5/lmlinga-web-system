<?php

use App\Support\AtRestEncrypter;
use App\Support\AtRestNarrativeField;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire SEC-3 narrative encryption: family_planning.remarks and
 * death_records.rejection_reason (death_requests in non-ERD installs) are
 * stored as plaintext again. Rollback re-seals them.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string}> table, primary key, column */
    private const TARGETS = [
        ['family_planning', 'fp_id', 'remarks'],
        ['death_records', 'death_record_id', 'rejection_reason'],
        ['death_requests', 'id', 'rejection_reason'],
    ];

    public function up(): void
    {
        $this->rewrite(static fn (string $value, string $table, string $column): string => AtRestEncrypter::looksEncrypted($value)
            ? AtRestNarrativeField::openOrFail($value, $table, $column)
            : $value);
    }

    public function down(): void
    {
        $this->rewrite(static fn (string $value, string $table, string $column): ?string => AtRestNarrativeField::seal($value, $table, $column));
    }

    /**
     * @param  callable(string, string, string): ?string  $transform
     */
    private function rewrite(callable $transform): void
    {
        foreach (self::TARGETS as [$table, $pk, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $pk) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::table($table)
                ->select([$pk, $column])
                ->whereNotNull($column)
                ->orderBy($pk)
                ->chunkById(200, function ($rows) use ($table, $pk, $column, $transform): void {
                    foreach ($rows as $row) {
                        $value = (string) $row->{$column};
                        if ($value === '') {
                            continue;
                        }

                        $next = $transform($value, $table, $column);
                        if ($next !== $value) {
                            DB::table($table)->where($pk, $row->{$pk})->update([$column => $next]);
                        }
                    }
                }, $pk);
        }
    }
};
