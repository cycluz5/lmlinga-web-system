<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SR-06: allow the "Linguist" staff role in the ERD enum worker_appointments.role.
 *
 * MySQL/MariaDB only, and only when the column is an enum without 'Linguist'. Existing enum values,
 * order, nullability and every stored row are kept. Legacy/SQLite schemas store the role as a string
 * and need no change. down() refuses while any Linguist appointment exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $values = $this->enumValues();
        if ($values === null || in_array('Linguist', $values, true)) {
            return;
        }

        $this->modifyEnum(array_merge($values, ['Linguist']));
    }

    public function down(): void
    {
        $values = $this->enumValues();
        if ($values === null || ! in_array('Linguist', $values, true)) {
            return;
        }

        if (DB::table('worker_appointments')->where('role', 'Linguist')->exists()) {
            throw new RuntimeException('Cannot remove the Linguist role: Linguist appointments exist. Reassign or remove them first.');
        }

        $this->modifyEnum(array_values(array_filter($values, static fn (string $v): bool => $v !== 'Linguist')));
    }

    /**
     * @return list<string>|null enum values, or null when not applicable (no table, non-MySQL, not an enum)
     */
    private function enumValues(): ?array
    {
        if (! Schema::hasTable('worker_appointments') || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        $column = DB::selectOne(
            'SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['worker_appointments', 'role']
        );
        $type = (string) ($column->type ?? '');
        if (! str_starts_with(strtolower($type), 'enum(')) {
            return null;
        }

        preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", substr($type, 5, -1), $matches);

        return array_map(static fn (string $v): string => str_replace("''", "'", $v), $matches[1]);
    }

    /**
     * @param  list<string>  $values
     */
    private function modifyEnum(array $values): void
    {
        $column = DB::selectOne(
            'SELECT IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['worker_appointments', 'role']
        );
        $quoted = implode(',', array_map(static fn (string $v): string => DB::getPdo()->quote($v), $values));
        $null = (($column->nullable ?? 'NO') === 'YES') ? 'NULL' : 'NOT NULL';

        DB::statement("ALTER TABLE `worker_appointments` MODIFY `role` ENUM({$quoted}) {$null}");
    }
};
