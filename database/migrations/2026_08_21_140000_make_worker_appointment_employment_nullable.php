<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slim Create Account assigns role only. Employment fields are completed via Edit.
 * Makes assigned_barangay / assigned_zone / date_appointed nullable when an older
 * schema already created them as NOT NULL (fresh installs use the updated create migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('worker_appointments')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite cannot reliably ALTER COLUMN nullability without table rebuild.
            // Fresh RefreshDatabase installs already get nullable columns from create migration.
            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE worker_appointments MODIFY assigned_barangay VARCHAR(100) NULL');
            DB::statement('ALTER TABLE worker_appointments MODIFY assigned_zone VARCHAR(20) NULL');
            DB::statement('ALTER TABLE worker_appointments MODIFY date_appointed DATE NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('worker_appointments')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("UPDATE worker_appointments SET assigned_barangay = '' WHERE assigned_barangay IS NULL");
            DB::statement("UPDATE worker_appointments SET assigned_zone = '' WHERE assigned_zone IS NULL");
            DB::statement("UPDATE worker_appointments SET date_appointed = CURRENT_DATE WHERE date_appointed IS NULL");
            DB::statement('ALTER TABLE worker_appointments MODIFY assigned_barangay VARCHAR(100) NOT NULL');
            DB::statement('ALTER TABLE worker_appointments MODIFY assigned_zone VARCHAR(20) NOT NULL');
            DB::statement('ALTER TABLE worker_appointments MODIFY date_appointed DATE NOT NULL');
        }
    }
};
