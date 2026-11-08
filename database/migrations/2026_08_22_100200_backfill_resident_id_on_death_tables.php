<?php

use App\Support\DeathRequestResidentBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * DB-05 Phase 3 — safe, idempotent resident_id backfill.
 * Unmatched / demo-only rows remain NULL. Does not create residents.
 */
return new class extends Migration
{
    public function up(): void
    {
        DeathRequestResidentBackfill::run();
    }

    public function down(): void
    {
        // Intentionally empty — backfill is data repair, not schema.
    }
};
