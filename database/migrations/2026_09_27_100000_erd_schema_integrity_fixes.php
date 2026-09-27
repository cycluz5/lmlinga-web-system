<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ERD-mode schema integrity (mirrors database/erd_schema_fixes.sql).
 *
 * - resident_statuses: replace the dangling death_request_id FK (death_requests
 *   does not exist in the ERD) and the varchar household_no/member_id lookups
 *   with real FKs to residents(resident_id) and death_records(death_record_id).
 * - offline_sync_receipts / announcements: enforce the user/household/resident refs.
 *
 * Legacy mode (death_requests present) is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->erdMode()) {
            return;
        }

        if (! Schema::hasTable('resident_statuses') || Schema::hasColumn('resident_statuses', 'death_request_id')) {
            if (Schema::hasTable('resident_statuses') && DB::table('resident_statuses')->exists()) {
                throw new RuntimeException('resident_statuses has rows; migrate them to resident_id/death_record_id before rebuilding.');
            }

            Schema::dropIfExists('resident_statuses');
            Schema::create('resident_statuses', function (Blueprint $table) {
                $table->id('resident_status_id');
                $table->unsignedBigInteger('resident_id');
                $table->enum('status', ['Active', 'Deceased'])->default('Active');
                $table->unsignedBigInteger('death_record_id')->nullable();
                $table->timestamp('recorded_at')->useCurrent();
                $table->timestamps();

                $table->unique('resident_id', 'uq_resstatus_resident');
                $table->index('status', 'idx_resstatus_status');
                $table->foreign('resident_id', 'fk_resstatus_resident')->references('resident_id')->on('residents');
                $table->foreign('death_record_id', 'fk_resstatus_death')->references('death_record_id')->on('death_records')->nullOnDelete();
            });
        }

        if (Schema::hasTable('offline_sync_receipts')) {
            Schema::table('offline_sync_receipts', function (Blueprint $table) {
                if (! $this->hasForeignKey('offline_sync_receipts', 'actor_user_id')) {
                    $table->foreign('actor_user_id', 'fk_syncrcpt_actor')->references('user_id')->on('user_management');
                }
                if (! $this->hasForeignKey('offline_sync_receipts', 'household_pk')) {
                    $table->foreign('household_pk', 'fk_syncrcpt_household')->references('household_id')->on('households')->nullOnDelete();
                }
                if (! $this->hasForeignKey('offline_sync_receipts', 'resident_pk')) {
                    $table->foreign('resident_pk', 'fk_syncrcpt_resident')->references('resident_id')->on('residents')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('announcements') && ! $this->hasForeignKey('announcements', 'posted_by_user_id')) {
            Schema::table('announcements', function (Blueprint $table) {
                $table->foreign('posted_by_user_id', 'fk_announce_user')->references('user_id')->on('user_management')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! $this->erdMode()) {
            return;
        }

        // resident_statuses keeps its ERD shape: the old FK targeted a table that does not exist.
        foreach ([
            'offline_sync_receipts' => ['fk_syncrcpt_actor', 'fk_syncrcpt_household', 'fk_syncrcpt_resident'],
            'announcements' => ['fk_announce_user'],
        ] as $tableName => $names) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            $existing = array_column(Schema::getForeignKeys($tableName), 'name');
            Schema::table($tableName, function (Blueprint $table) use ($names, $existing) {
                foreach (array_intersect($names, $existing) as $name) {
                    $table->dropForeign($name);
                }
            });
        }
    }

    private function erdMode(): bool
    {
        return Schema::hasTable('death_records') && ! Schema::hasTable('death_requests');
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
