<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-16 — Operation Timbang longitudinal weigh-in measurements per resident.
 *
 * One row per weighing event (hasMany). Canonical identity is residents.id.
 * Display fields (name, age label, zone, sex) are derived from residents/households.
 * Nutritional classification is NOT stored — no approved clinical algorithm exists.
 *
 * Compatible with MariaDB 10.4 and SQLite (no MySQL 8-only features / ENUMs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_timbang_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')
                ->constrained('residents')
                ->restrictOnDelete();
            $table->date('weighed_at');
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->decimal('muac_cm', 5, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('resident_id');
            $table->index('weighed_at');
            $table->index(['resident_id', 'weighed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_timbang_measurements');
    }
};
