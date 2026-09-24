<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-08 Phase 1 — Child Immunization header persistence foundation.
 *
 * One immunization record per resident (1:1). Canonical identity is residents.id.
 * Manual Vaccines Type checkbox selections (including FIC/CIC) are stored as
 * selected_vaccine_types JSON. Vaccine dose dates live in immunization_doses.
 *
 * Intentionally omits reference-schema fields that duplicate other modules or
 * are out of Phase 1 scope (unregistered_child_id, mother_name/CPAB → birth
 * history / member presentation, fic_cic_status table).
 *
 * Compatible with MariaDB 10.4 and SQLite (no MySQL 8-only features / ENUMs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_immunizations', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('resident_id')
                ->unique()
                ->constrained('residents', 'resident_id')
                ->restrictOnDelete();
            $table->json('selected_vaccine_types')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_immunizations');
    }
};
