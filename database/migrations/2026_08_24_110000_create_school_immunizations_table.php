<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-09 Phase 1 — School-Based Immunization header persistence foundation.
 *
 * One immunization record per resident (1:1). Canonical identity is residents.id.
 * Manual Vaccines Type checkbox selections are stored as selected_vaccine_types JSON.
 * Vaccine dose dates live in school_immunization_doses.
 *
 * Intentionally omits reference-schema fields absent from the frozen SBI UI
 * (school_name, grade_level, date_of_registration, family_serial_no,
 * unregistered_child_id, remarks, hpv_completed, hpv_completed_date).
 *
 * Compatible with MariaDB 10.4 and SQLite (no MySQL 8-only features / ENUMs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_immunizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')
                ->unique()
                ->constrained('residents')
                ->restrictOnDelete();
            $table->json('selected_vaccine_types')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_immunizations');
    }
};
