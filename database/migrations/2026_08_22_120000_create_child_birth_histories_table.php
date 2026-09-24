<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-06 Phase 2 — Shared Child Care persistence foundation.
 * One birth-history record per resident (1:1). FK resident_id → residents.id RESTRICT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_birth_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')
                ->unique()
                ->constrained('residents')
                ->restrictOnDelete();
            $table->decimal('birth_weight_kg', 5, 2)->nullable();
            $table->decimal('birth_length_cm', 5, 2)->nullable();
            $table->string('status', 50)->nullable();
            $table->string('pcab', 64)->nullable();
            $table->date('breastfeeding_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_birth_histories');
    }
};
