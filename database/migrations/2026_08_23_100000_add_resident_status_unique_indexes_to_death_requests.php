<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB-07 Phase 2A — MariaDB 10.4 / SQLite-compatible uniqueness
 * for one pending and one approved death request per resident_id.
 *
 * MariaDB 10.4 does not support MySQL 8 functional/expression indexes.
 * Instead: VIRTUAL generated columns that are NULL unless the row is
 * pending/approved with a resident_id; UNIQUE on those columns allows
 * multiple rejected (NULL) rows while enforcing one active row per resident.
 *
 * SQLite keeps WHERE partial unique indexes (same semantics).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('death_requests') || ! Schema::hasColumn('death_requests', 'resident_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // Legacy household+member partials block resubmits after rejection.
            DB::statement('DROP INDEX IF EXISTS death_requests_one_pending');
            DB::statement('DROP INDEX IF EXISTS death_requests_one_approved');

            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS death_requests_one_pending_resident
                 ON death_requests (resident_id)
                 WHERE status = \'pending\' AND resident_id IS NOT NULL'
            );
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS death_requests_one_approved_resident
                 ON death_requests (resident_id)
                 WHERE status = \'approved\' AND resident_id IS NOT NULL'
            );

            return;
        }

        if ($driver !== 'mysql') {
            return;
        }

        // Laravel's mysql driver also serves MariaDB (DEV: 10.4.x).
        $this->dropMysqlIndexIfExists('death_requests_one_pending_resident');
        $this->dropMysqlIndexIfExists('death_requests_one_approved_resident');

        if (! Schema::hasColumn('death_requests', 'pending_resident_key')) {
            DB::statement(
                'ALTER TABLE death_requests
                 ADD COLUMN pending_resident_key BIGINT UNSIGNED
                 AS (CASE WHEN `status` = \'pending\' AND `resident_id` IS NOT NULL THEN `resident_id` ELSE NULL END)
                 VIRTUAL'
            );
        }

        if (! Schema::hasColumn('death_requests', 'approved_resident_key')) {
            DB::statement(
                'ALTER TABLE death_requests
                 ADD COLUMN approved_resident_key BIGINT UNSIGNED
                 AS (CASE WHEN `status` = \'approved\' AND `resident_id` IS NOT NULL THEN `resident_id` ELSE NULL END)
                 VIRTUAL'
            );
        }

        $existing = $this->mysqlIndexNames();

        if (! in_array('death_requests_one_pending_resident', $existing, true)) {
            DB::statement(
                'CREATE UNIQUE INDEX death_requests_one_pending_resident
                 ON death_requests (pending_resident_key)'
            );
        }

        if (! in_array('death_requests_one_approved_resident', $existing, true)) {
            DB::statement(
                'CREATE UNIQUE INDEX death_requests_one_approved_resident
                 ON death_requests (approved_resident_key)'
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('death_requests')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS death_requests_one_pending_resident');
            DB::statement('DROP INDEX IF EXISTS death_requests_one_approved_resident');

            return;
        }

        if ($driver !== 'mysql') {
            return;
        }

        $this->dropMysqlIndexIfExists('death_requests_one_pending_resident');
        $this->dropMysqlIndexIfExists('death_requests_one_approved_resident');

        if (Schema::hasColumn('death_requests', 'pending_resident_key')) {
            Schema::table('death_requests', function ($table): void {
                $table->dropColumn('pending_resident_key');
            });
        }

        if (Schema::hasColumn('death_requests', 'approved_resident_key')) {
            Schema::table('death_requests', function ($table): void {
                $table->dropColumn('approved_resident_key');
            });
        }
    }

    /**
     * @return list<string>
     */
    private function mysqlIndexNames(): array
    {
        return collect(DB::select('SHOW INDEX FROM death_requests'))
            ->pluck('Key_name')
            ->unique()
            ->values()
            ->all();
    }

    private function dropMysqlIndexIfExists(string $indexName): void
    {
        if (! in_array($indexName, $this->mysqlIndexNames(), true)) {
            return;
        }

        DB::statement('DROP INDEX `'.$indexName.'` ON death_requests');
    }
};
