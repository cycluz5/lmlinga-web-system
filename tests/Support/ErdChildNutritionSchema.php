<?php

namespace Tests\Support;

use App\Support\ChildNutritionErdMode;
use App\Support\NutritionSupplementationErdMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHPUnit-only live ERD Child Nutrition tables (not a migration).
 *
 * Mirrors lmlinga_erd_reference: child_nutrition header plus
 * nutrition_supplementation, iron_supplementation, and malnutrition_management.
 * Drops Laravel plural nutrition tables so dual-schema detection uses ERD mode.
 */
final class ErdChildNutritionSchema
{
    public static function ensure(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('child_nutrition_sfp_outcomes');
        Schema::dropIfExists('child_nutritions');
        Schema::dropIfExists('nutrition_supplementation');
        Schema::dropIfExists('iron_supplementation');
        Schema::dropIfExists('malnutrition_management');
        Schema::dropIfExists('child_nutrition');

        Schema::create('child_nutrition', function (Blueprint $table): void {
            $table->id('child_nutrition_id');
            $table->unsignedBigInteger('resident_id');
            $table->decimal('length_at_birth_cm', 8, 2)->nullable();
            $table->decimal('weight_at_birth_kg', 8, 2)->nullable();
            $table->date('initiated_breastfeeding_date')->nullable();
            $table->timestamps();
        });

        Schema::create('nutrition_supplementation', function (Blueprint $table): void {
            $table->id('supplementation_id');
            $table->unsignedBigInteger('child_nutrition_id');
            $table->string('supplement_type');
            $table->string('age_group');
            $table->unsignedTinyInteger('dose_number');
            $table->date('date_given')->nullable();
            $table->timestamps();
            $table->unique(
                ['child_nutrition_id', 'supplement_type', 'age_group', 'dose_number'],
                'uq_nutrsupp'
            );
        });

        Schema::create('iron_supplementation', function (Blueprint $table): void {
            $table->id('iron_supp_id');
            $table->unsignedBigInteger('child_nutrition_id');
            $table->string('month_number', 8);
            $table->date('date_given')->nullable();
            $table->timestamps();
            $table->unique(['child_nutrition_id', 'month_number'], 'uq_ironsupp_month');
        });

        Schema::create('malnutrition_management', function (Blueprint $table): void {
            $table->id('malnutrition_mgmt_id');
            $table->unsignedBigInteger('child_nutrition_id');
            $table->string('malnutrition_type', 8);
            $table->string('status_type', 32);
            $table->date('status_date')->nullable();
            $table->boolean('action')->nullable();
            $table->timestamps();
            $table->unique(
                ['child_nutrition_id', 'malnutrition_type', 'status_type'],
                'uq_malnutrmgmt'
            );
        });

        Schema::enableForeignKeyConstraints();
        ChildNutritionErdMode::resetCachedState();
        NutritionSupplementationErdMode::resetCachedState();
    }
}
