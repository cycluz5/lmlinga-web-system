<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-05 Phase 1 — Resident / household-member persistence foundation.
 * Public/business identifier: member_no (MB-###), globally unique.
 * FK household_id → households.id uses RESTRICT (no cascade erase).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')
                ->constrained('households')
                ->restrictOnDelete();
            $table->string('member_no', 16);
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('relation', 50);
            $table->date('birthday');
            $table->string('sex', 16);
            $table->string('relationship_status', 50);
            $table->string('occupation', 100);
            $table->string('monthly_income', 50);
            $table->string('religion', 100);
            $table->string('education', 100);
            $table->string('fp_user', 8);
            $table->string('philhealth', 12)->nullable();
            $table->json('disability')->nullable();
            $table->string('disability_others', 255)->nullable();
            $table->json('medical_history')->nullable();
            $table->string('medical_others', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('member_no');
            $table->index('household_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('residents');
    }
};
