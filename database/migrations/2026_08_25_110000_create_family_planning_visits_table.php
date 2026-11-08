<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-13 Phase 2 — Family Planning visit persistence per resident (hasMany).
 * FK resident_id → residents.id RESTRICT. Public id: visit_no (FP-###).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_planning_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')
                ->constrained('residents')
                ->restrictOnDelete();
            $table->string('visit_no', 16);
            $table->date('visited_at');
            $table->text('remarks')->nullable();
            $table->json('commodities')->nullable();
            $table->timestamps();

            $table->unique('visit_no');
            $table->index('resident_id');
            $table->index(['resident_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_planning_visits');
    }
};
