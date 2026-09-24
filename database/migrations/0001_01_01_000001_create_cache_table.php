<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Phase 1 infrastructure cleanup.
 *
 * CACHE_STORE=file — database cache tables are not required.
 * Kept as a no-op so existing migration history remains valid.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
