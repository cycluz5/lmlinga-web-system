<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-05 Phase 1 — Household persistence foundation.
 * Public/business identifier: household_no (HH-###). Surrogate PK: id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('household_no', 16);
            $table->string('zone', 32);
            $table->string('street', 150);
            $table->date('date_registered');
            $table->string('address', 255)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('accomplished_by', 160)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('household_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('households');
    }
};
