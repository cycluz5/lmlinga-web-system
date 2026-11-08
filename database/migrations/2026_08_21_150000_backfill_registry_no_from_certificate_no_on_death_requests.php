<?php

use App\Support\DeathRequestRegistryBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * D-01: Backfill blank registry_no from historical certificate_no.
 *
 * The earlier add_registry_no migration only introduced the column with an
 * empty default. Existing databases that already ran that migration would
 * never re-execute it, so this forward migration performs the data repair.
 */
return new class extends Migration
{
    public function up(): void
    {
        DeathRequestRegistryBackfill::run();
    }

    public function down(): void
    {
        // Irreversible data repair: do not clear backfilled registry_no values.
    }
};
