<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1B — Soft-deletion tombstone for staff identity.
 * Adds nullable deleted_at to whichever staff table exists (users and/or user_management).
 * Does not add a Deleted status value and does not alter appointment FKs.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'user_management'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->timestamp('deleted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'user_management'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('deleted_at');
            });
        }
    }
};
