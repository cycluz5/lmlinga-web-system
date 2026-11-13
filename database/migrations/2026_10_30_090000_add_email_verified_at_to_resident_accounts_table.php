<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel refinement SR-03: when a resident proved ownership of the account email (6-digit email OTP).
 *
 * Additive and nullable: existing accounts, household links (resident_id) and records are untouched;
 * no backfill. Idempotent for partially prepared schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resident_accounts') || Schema::hasColumn('resident_accounts', 'email_verified_at')) {
            return;
        }

        Schema::table('resident_accounts', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('resident_accounts') || ! Schema::hasColumn('resident_accounts', 'email_verified_at')) {
            return;
        }

        Schema::table('resident_accounts', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });
    }
};
