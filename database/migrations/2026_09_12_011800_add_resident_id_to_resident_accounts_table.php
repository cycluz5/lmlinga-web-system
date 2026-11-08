<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 reconciliation: nullable official-resident link on chatbot accounts.
 *
 * Live MySQL uses residents.resident_id (bigint unsigned) as PK.
 * Idempotent for partially prepared schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resident_accounts')) {
            return;
        }

        if (! Schema::hasColumn('resident_accounts', 'resident_id')) {
            Schema::table('resident_accounts', function (Blueprint $table) {
                $table->unsignedBigInteger('resident_id')->nullable()->after('account_id');
            });
        }

        if (! $this->hasUniqueOnResidentId()) {
            Schema::table('resident_accounts', function (Blueprint $table) {
                $table->unique('resident_id', 'uq_resident_accounts_resident_id');
            });
        }

        $ownerKey = $this->residentsPrimaryKeyColumn();

        if (! Schema::hasTable('residents') || ! Schema::hasColumn('residents', $ownerKey)) {
            return;
        }

        if ($this->hasForeignKeyOnResidentId($ownerKey)) {
            return;
        }

        Schema::table('resident_accounts', function (Blueprint $table) use ($ownerKey) {
            $table->foreign('resident_id', 'fk_resident_accounts_resident_id')
                ->references($ownerKey)
                ->on('residents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('resident_accounts') || ! Schema::hasColumn('resident_accounts', 'resident_id')) {
            return;
        }

        Schema::table('resident_accounts', function (Blueprint $table) {
            foreach (Schema::getForeignKeys('resident_accounts') as $foreignKey) {
                if (($foreignKey['columns'] ?? []) === ['resident_id']) {
                    $table->dropForeign($foreignKey['name']);
                }
            }

            foreach (Schema::getIndexes('resident_accounts') as $index) {
                if (! empty($index['unique']) && ($index['columns'] ?? []) === ['resident_id']) {
                    $table->dropUnique($index['name']);
                }
            }

            $table->dropColumn('resident_id');
        });
    }

    private function residentsPrimaryKeyColumn(): string
    {
        if (Schema::hasTable('residents') && Schema::hasColumn('residents', 'resident_id')) {
            return 'resident_id';
        }

        return 'id';
    }

    private function hasUniqueOnResidentId(): bool
    {
        foreach (Schema::getIndexes('resident_accounts') as $index) {
            if (! empty($index['unique']) && ($index['columns'] ?? []) === ['resident_id']) {
                return true;
            }
        }

        return false;
    }

    private function hasForeignKeyOnResidentId(string $ownerKey): bool
    {
        foreach (Schema::getForeignKeys('resident_accounts') as $foreignKey) {
            if (
                ($foreignKey['columns'] ?? []) === ['resident_id']
                && ($foreignKey['foreign_table'] ?? null) === 'residents'
                && ($foreignKey['foreign_columns'] ?? []) === [$ownerKey]
            ) {
                return true;
            }
        }

        return false;
    }
};
