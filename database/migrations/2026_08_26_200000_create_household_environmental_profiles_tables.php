<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-17 Phase 2B — Household environmental / amenities persistence.
 *
 * One current profile per household (UNIQUE household_id).
 * FK to households.id uses RESTRICT (aligns with resident/health protection;
 * household deletion remains out of scope).
 *
 * Machine values match the existing UI/session vocabulary (level_i, yes/no, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_environmental_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')
                ->constrained('households')
                ->restrictOnDelete();
            $table->string('household_type', 50)->nullable();
            $table->string('water_supply_status', 32)->nullable();
            $table->string('specify_water_source', 255)->nullable();
            $table->string('water_source_location', 8)->nullable();
            $table->string('water_availability', 8)->nullable();
            $table->string('basic_safe_water_status', 32)->nullable();
            $table->date('microbiological_test_date')->nullable();
            $table->string('microbiological_result', 16)->nullable();
            $table->date('physicochemical_test_date')->nullable();
            $table->string('physicochemical_result', 16)->nullable();
            $table->string('toilet_type', 64)->nullable();
            $table->string('toilet_status', 32)->nullable();
            $table->string('open_defecation_practiced', 8)->nullable();
            $table->string('shared_toilet', 8)->nullable();
            $table->string('sewage_disposal_method', 64)->nullable();
            $table->string('management_status', 32)->nullable();
            $table->string('solid_waste_status', 32)->nullable();
            $table->unsignedTinyInteger('completed_step')->default(0);
            $table->timestamps();

            $table->unique('household_id');
        });

        Schema::create('household_solid_waste_practices', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('household_environmental_profile_id');

            $table->foreign(
                'household_environmental_profile_id',
                'hh_solid_waste_profile_fk'
            )
                ->references('id')
                ->on('household_environmental_profiles')
                ->cascadeOnDelete();

            $table->boolean('waste_segregation')->default(false);
            $table->boolean('backyard_composting')->default(false);
            $table->boolean('recycling_reuse')->default(false);
            $table->boolean('municipal_collection')->default(false);
            $table->timestamps();

            $table->unique('household_environmental_profile_id', 'uq_solid_waste_env_profile');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_solid_waste_practices');
        Schema::dropIfExists('household_environmental_profiles');
    }
};
