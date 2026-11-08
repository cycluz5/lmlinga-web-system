<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-14 Phase 2 — Maternal Care pregnancy persistence per resident (hasMany history).
 * FK resident_id → residents.id RESTRICT. Public id: pregnancy_no (MC-###).
 * Nested clinical sections stored as JSON to preserve the approved UI payload shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maternal_pregnancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')
                ->constrained('residents')
                ->restrictOnDelete();
            $table->string('pregnancy_no', 16);
            $table->unsignedInteger('pregnancy_number');
            $table->string('status', 32);
            $table->date('registered_at')->nullable();
            $table->date('lmp')->nullable();
            $table->unsignedSmallInteger('gravida')->nullable();
            $table->unsignedSmallInteger('parity')->nullable();
            $table->date('edd')->nullable();
            $table->decimal('weight', 6, 2)->nullable();
            $table->decimal('height', 6, 2)->nullable();
            $table->decimal('bmi', 5, 1)->nullable();
            $table->string('blood_pressure', 32)->nullable();
            $table->json('prenatal')->nullable();
            $table->json('immunizations')->nullable();
            $table->json('supplementations')->nullable();
            $table->json('laboratory')->nullable();
            $table->json('delivery')->nullable();
            $table->json('postnatal')->nullable();
            $table->json('trans_out')->nullable();
            $table->timestamps();

            $table->unique('pregnancy_no');
            $table->index('resident_id');
            $table->index(['resident_id', 'status']);
            $table->index(['resident_id', 'pregnancy_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maternal_pregnancies');
    }
};
