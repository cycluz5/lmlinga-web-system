<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The National Nutrition Council (DOH) Child Growth Standards reference
 * (resources/growth-references/nnc_growth_standards.json) legitimately
 * classifies Weight-for-Age as far as "Overweight" and Height-for-Age as
 * far as "Tall" — outcomes the original paper-ERD ENUM (3 values each) did
 * not anticipate. Widens both ENUMs to add exactly those two labels.
 *
 * Additive only — no existing label is removed, no data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('timbang_records')) {
            return;
        }

        Schema::table('timbang_records', function (Blueprint $table): void {
            $table->enum('weight_for_age', [
                'Severely Underweight',
                'Underweight',
                'Normal',
                'Overweight',
            ])->nullable()->change();

            $table->enum('height_for_age', [
                'Severely Stunted',
                'Stunted',
                'Normal',
                'Tall',
            ])->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('timbang_records')) {
            return;
        }

        Schema::table('timbang_records', function (Blueprint $table): void {
            $table->enum('weight_for_age', [
                'Severely Underweight',
                'Underweight',
                'Normal',
            ])->nullable()->change();

            $table->enum('height_for_age', [
                'Severely Stunted',
                'Stunted',
                'Normal',
            ])->nullable()->change();
        });
    }
};
