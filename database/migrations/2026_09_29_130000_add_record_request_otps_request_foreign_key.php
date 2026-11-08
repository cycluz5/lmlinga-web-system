<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integrity — record_request_otps.request_id → record_requests.request_id.
 *
 * The create migration declares this foreign key, but the live table only kept the
 * index. Restore the constraint (RESTRICT, as originally designed).
 */
return new class extends Migration
{
    private const FOREIGN_KEY = 'fk_record_request_otps_request_id';

    public function up(): void
    {
        if (! Schema::hasTable('record_request_otps') || ! Schema::hasTable('record_requests')) {
            return;
        }

        if ($this->hasRequestForeignKey()) {
            return;
        }

        $orphans = DB::table('record_request_otps')
            ->leftJoin('record_requests', 'record_requests.request_id', '=', 'record_request_otps.request_id')
            ->whereNull('record_requests.request_id')
            ->pluck('record_request_otps.otp_id');

        if ($orphans->isNotEmpty()) {
            throw new RuntimeException(
                'record_request_otps row(s) '.$orphans->implode(', ')
                .' point to a record request that does not exist. Delete them before running this migration.'
            );
        }

        Schema::table('record_request_otps', function (Blueprint $table) {
            $table->foreign('request_id', self::FOREIGN_KEY)
                ->references('request_id')
                ->on('record_requests')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('record_request_otps') || ! $this->hasRequestForeignKey()) {
            return;
        }

        // SQLite can only drop a foreign key by its column list.
        $foreignKey = Schema::getConnection()->getDriverName() === 'sqlite'
            ? ['request_id']
            : self::FOREIGN_KEY;

        Schema::table('record_request_otps', function (Blueprint $table) use ($foreignKey) {
            $table->dropForeign($foreignKey);
        });
    }

    private function hasRequestForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('record_request_otps') as $foreignKey) {
            if ($foreignKey['columns'] === ['request_id']) {
                return true;
            }
        }

        return false;
    }
};
