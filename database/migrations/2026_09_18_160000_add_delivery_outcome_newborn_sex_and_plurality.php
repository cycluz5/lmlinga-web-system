<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #13 — Newborn sex and plurality on existing delivery_outcomes.
 * No-op when the ERD table is absent (JSON leftover maternal_pregnancies).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_outcomes')) {
            return;
        }

        if (! Schema::hasColumn('delivery_outcomes', 'newborn_sex')) {
            Schema::table('delivery_outcomes', function (Blueprint $table): void {
                $table->enum('newborn_sex', ['Female', 'Male'])->nullable();
            });
        }

        if (! Schema::hasColumn('delivery_outcomes', 'plurality')) {
            Schema::table('delivery_outcomes', function (Blueprint $table): void {
                $table->enum('plurality', ['Single', 'Twins', 'Multiple'])->nullable();
            });
        }

        if (! Schema::hasColumn('delivery_outcomes', 'plurality_number')) {
            Schema::table('delivery_outcomes', function (Blueprint $table): void {
                $table->unsignedInteger('plurality_number')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('delivery_outcomes')) {
            return;
        }

        Schema::table('delivery_outcomes', function (Blueprint $table): void {
            if (Schema::hasColumn('delivery_outcomes', 'newborn_sex')) {
                $table->dropColumn('newborn_sex');
            }
            if (Schema::hasColumn('delivery_outcomes', 'plurality')) {
                $table->dropColumn('plurality');
            }
            if (Schema::hasColumn('delivery_outcomes', 'plurality_number')) {
                $table->dropColumn('plurality_number');
            }
        });
    }
};
