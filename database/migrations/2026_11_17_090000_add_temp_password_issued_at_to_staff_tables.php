<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks staff accounts whose current password is an admin-issued temporary password
 * (new account or admin password reset). Legacy rows stay NULL and are never forced
 * through the first-login email-code + new-password step.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'user_management'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'temp_password_issued_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->timestamp('temp_password_issued_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'user_management'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'temp_password_issued_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('temp_password_issued_at');
            });
        }
    }
};
