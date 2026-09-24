<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employment / role history for staff users.
 * Authoritative role truth for UiRole is the current appointment (`is_current = true`).
 *
 * At-most-one-current-appointment invariant:
 * - SQLite: partial unique index on user_id WHERE is_current = 1
 * - MySQL / MariaDB 10.4+: STORED generated column current_user_id
 *   (= user_id when current, else NULL) + UNIQUE(current_user_id)
 *
 * Functional expression indexes (MySQL 8.0.13+ style) are NOT used — MariaDB 10.4.32
 * rejects CREATE UNIQUE INDEX (... (CASE ...)).
 *
 * REF-22: create the table only when missing; always apply guarded post-create DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('worker_appointments')) {
            Schema::create('worker_appointments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role', 16);
                $table->string('assigned_barangay', 100)->nullable();
                $table->string('assigned_zone', 20)->nullable();
                $table->date('date_appointed')->nullable();
                $table->date('end_of_appointment')->nullable();
                $table->boolean('is_current')->default(false);
                $table->timestamps();

                $table->index('user_id');
                $table->index('role');
                $table->index(['user_id', 'is_current']);
            });
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            if (! Schema::hasIndex('worker_appointments', 'worker_appointments_one_current')) {
                DB::statement(
                    'CREATE UNIQUE INDEX worker_appointments_one_current
                     ON worker_appointments (user_id)
                     WHERE is_current = 1'
                );
            }

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            if (! Schema::hasColumn('worker_appointments', 'current_user_id')) {
                // Raw DDL avoids Laravel emitting "STORED NULL", which MariaDB 10.4 rejects.
                DB::statement(
                    'ALTER TABLE worker_appointments
                     ADD COLUMN current_user_id BIGINT UNSIGNED
                     AS (CASE WHEN `is_current` = 1 THEN `user_id` ELSE NULL END) STORED'
                );
            }

            if (! Schema::hasIndex('worker_appointments', 'worker_appointments_one_current')) {
                DB::statement(
                    'CREATE UNIQUE INDEX worker_appointments_one_current
                     ON worker_appointments (current_user_id)'
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_appointments');
    }
};
