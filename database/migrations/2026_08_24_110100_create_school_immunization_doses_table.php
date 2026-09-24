<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-09 Phase 1 — Normalized School-Based Immunization dose slots.
 *
 * Maps to the frozen HP SBI form fields:
 *   name="vaccines[{slot_group}][{slot_key}]"
 *
 * slot_group uses UI keys: grade-1, grade-7, hpv.
 * slot_key uses UI keys: td, mr (grades) or 1, 2 (hpv).
 * date_given remains nullable (optional dates per frozen UI rules).
 *
 * Unique (school_immunization_id, slot_group, slot_key) prevents duplicate
 * dose slots. Strings used instead of ENUMs for MariaDB 10.4 / SQLite safety.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_immunization_doses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_immunization_id')
                ->constrained('school_immunizations')
                ->cascadeOnDelete();
            $table->string('slot_group', 16);
            $table->string('slot_key', 8);
            $table->date('date_given')->nullable();
            $table->timestamps();

            $table->unique(
                ['school_immunization_id', 'slot_group', 'slot_key'],
                'school_imm_doses_record_slot_unique'
            );
            $table->index('school_immunization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_immunization_doses');
    }
};
