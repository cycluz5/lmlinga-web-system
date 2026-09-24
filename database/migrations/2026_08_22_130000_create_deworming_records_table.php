<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-06 Phase 3 — Deworming administration records per resident (hasMany).
 * FK resident_id → residents.id RESTRICT. One row per resident/year/round.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deworming_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')
                ->constrained('residents')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('round');
            $table->string('se_status', 16);
            $table->date('date_given');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['resident_id', 'year', 'round']);
            $table->index('resident_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deworming_records');
    }
};
