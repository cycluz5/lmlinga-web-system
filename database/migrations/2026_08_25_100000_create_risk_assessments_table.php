<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-12 Phase 2 — Risk Assessment persistence per resident (hasMany).
 * FK resident_id → residents.id RESTRICT. Public id: assessment_no (RA-###).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')
                ->constrained('residents')
                ->restrictOnDelete();
            $table->string('assessment_no', 16);
            $table->date('conducted_at');

            $table->json('red_flags')->nullable();
            $table->json('past_medical')->nullable();
            $table->json('family_history')->nullable();
            $table->json('dietary')->nullable();

            $table->string('tobacco', 32)->nullable();
            $table->string('alcohol', 32)->nullable();
            $table->string('physical_activity', 32)->nullable();

            $table->decimal('height_cm', 8, 2)->nullable();
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->decimal('bmi', 8, 2)->nullable();
            $table->decimal('waist_cm', 8, 2)->nullable();
            $table->unsignedSmallInteger('systolic')->nullable();
            $table->unsignedSmallInteger('diastolic')->nullable();

            $table->string('bp_status', 64)->nullable();
            $table->string('bp_reading', 16)->nullable();
            $table->string('bmi_label', 32)->nullable();

            $table->boolean('visual_no_screening')->default(false);
            $table->boolean('visual_blurred')->default(false);
            $table->string('visual_blurred_note', 255)->nullable();

            $table->timestamps();

            $table->unique('assessment_no');
            $table->index('resident_id');
            $table->index(['resident_id', 'conducted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};
