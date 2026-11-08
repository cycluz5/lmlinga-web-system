<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-08 Phase 1 — Normalized Child Immunization dose rows.
 *
 * Maps to the frozen HP Child Immunization form fields:
 *   name="vaccines[{vaccine_key}][{dose_index}]"
 *
 * vaccine_type uses UI keys (bcg, hepa-b, dpt-hib-hepb, opv, ipv, pcv, mmr).
 * dose_index is 0-based and matches the Blade dose card order.
 * date_given remains nullable (optional dates per frozen UI rules).
 *
 * Unique (child_immunization_id, vaccine_type, dose_index) prevents duplicate
 * dose slots. Strings used instead of ENUMs for MariaDB 10.4 / SQLite safety.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('immunization_doses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_immunization_id')
                ->constrained('child_immunizations')
                ->cascadeOnDelete();
            $table->string('vaccine_type', 32);
            $table->unsignedTinyInteger('dose_index');
            $table->date('date_given')->nullable();
            $table->timestamps();

            $table->unique(
                ['child_immunization_id', 'vaccine_type', 'dose_index'],
                'immunization_doses_record_vaccine_dose_unique'
            );
            $table->index('child_immunization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('immunization_doses');
    }
};
