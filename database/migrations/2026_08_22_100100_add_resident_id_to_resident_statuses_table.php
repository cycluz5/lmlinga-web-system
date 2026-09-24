<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-05 Phase 3 — nullable resident_id FK bridge on resident_statuses.
 * Preserves household_no + member_id string identifiers.
 * FK index satisfies the Phase 3 indexing requirement.
 *
 * REF-22: guard the column and add the FK if the column exists without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('resident_statuses', 'resident_id')) {
            Schema::table('resident_statuses', function (Blueprint $table) {
                $table->foreignId('resident_id')
                    ->nullable()
                    ->after('member_id')
                    ->constrained('residents')
                    ->restrictOnDelete();
            });

            return;
        }

        if (! $this->hasForeignKey('resident_statuses', 'resident_id')) {
            Schema::table('resident_statuses', function (Blueprint $table) {
                $table->foreign('resident_id')->references('id')->on('residents')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('resident_statuses') || ! Schema::hasColumn('resident_statuses', 'resident_id')) {
            return;
        }

        Schema::table('resident_statuses', function (Blueprint $table) {
            if ($this->hasForeignKey('resident_statuses', 'resident_id')) {
                $table->dropConstrainedForeignId('resident_id');
            } else {
                $table->dropColumn('resident_id');
            }
        });
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $columns = $foreignKey['columns'] ?? [];
            if (in_array($column, $columns, true)) {
                return true;
            }
        }

        return false;
    }
};
