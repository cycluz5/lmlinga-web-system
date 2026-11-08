<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove MySQL DEFAULT/ON UPDATE CURRENT_TIMESTAMP from record_request_otps.expires_at.
 *
 * markSent() updates last_sent_at; ON UPDATE was resetting expires_at to "now",
 * collapsing the 5-minute OTP window to zero remaining seconds.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('record_request_otps') || ! Schema::hasColumn('record_request_otps', 'expires_at')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            // DATETIME: application-owned expiry only — no ON UPDATE CURRENT_TIMESTAMP.
            DB::statement('ALTER TABLE record_request_otps MODIFY expires_at DATETIME NOT NULL');

            return;
        }

        // SQLite and others do not apply MySQL TIMESTAMP ON UPDATE semantics.
    }

    public function down(): void
    {
        if (! Schema::hasTable('record_request_otps') || ! Schema::hasColumn('record_request_otps', 'expires_at')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            // Restore prior MySQL TIMESTAMP shape (may reintroduce ON UPDATE — rollback only).
            DB::statement('ALTER TABLE record_request_otps MODIFY expires_at TIMESTAMP NOT NULL');

            return;
        }
    }
};
