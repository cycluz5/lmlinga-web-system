<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-10 Phase 2 — Child Nutrition worksheet persistence foundation.
 *
 * One Child Nutrition record per resident (1:1). Canonical identity is residents.id.
 * Stores the HH Child Nutrition form worksheet (New Born + supplementation dates).
 * MAM/SAM program outcomes live in child_nutrition_sfp_outcomes.
 *
 * Does not store hardcoded status-panel demo stamps (Overall Status, MUAC, BMI,
 * Latest Assessment, COMPLETED chips). Those remain presentation-only.
 *
 * Compatible with MariaDB 10.4 and SQLite (no MySQL 8-only features / ENUMs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_nutritions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')
                ->unique()
                ->constrained('residents')
                ->restrictOnDelete();

            $table->decimal('newborn_length_cm', 5, 2)->nullable();
            $table->decimal('newborn_weight_kg', 5, 2)->nullable();
            $table->date('newborn_breastfeeding_date')->nullable();

            $table->date('iron_1st_date')->nullable();
            $table->date('iron_2nd_date')->nullable();
            $table->date('iron_3rd_date')->nullable();

            $table->date('vitamin_a_va_6_11_date')->nullable();
            $table->date('vitamin_a_va_12_59_1_date')->nullable();
            $table->date('vitamin_a_va_12_59_2_date')->nullable();

            $table->date('mnp_6_11_date')->nullable();
            $table->date('mnp_12_23_date')->nullable();

            $table->date('lns_sq_6_11_date')->nullable();
            $table->date('lns_sq_12_23_date')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_nutritions');
    }
};
