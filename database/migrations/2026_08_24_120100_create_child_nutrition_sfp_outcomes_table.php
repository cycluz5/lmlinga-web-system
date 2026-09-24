<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-10 Phase 2 — Normalized Supplementary Feeding Program (MAM/SAM) outcome slots.
 *
 * Maps to the frozen HH Child Nutrition form:
 *   date:  name="{program}[{outcome}][date]"
 *   yes/no radios: name="{program}_{outcome}" value="yes"|"no"
 *
 * program uses UI keys: mam, sam.
 * outcome uses UI keys: identified, enrolled, cured, non-cured, default, died.
 *
 * Unique (child_nutrition_id, program, outcome) prevents duplicate logical slots.
 * Strings used instead of ENUMs for MariaDB 10.4 / SQLite safety.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_nutrition_sfp_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_nutrition_id')
                ->constrained('child_nutritions')
                ->cascadeOnDelete();
            $table->string('program', 8);
            $table->string('outcome', 16);
            $table->date('outcome_date')->nullable();
            $table->string('action_yes_no', 8)->nullable();
            $table->timestamps();

            $table->unique(
                ['child_nutrition_id', 'program', 'outcome'],
                'child_nut_sfp_record_program_outcome_unique'
            );
            $table->index('child_nutrition_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_nutrition_sfp_outcomes');
    }
};
