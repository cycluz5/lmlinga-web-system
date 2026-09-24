<?php

namespace Tests\Support;

use App\Support\ChildImmunizationErdMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHPUnit-only hybrid Child Immunization tables (not a migration).
 *
 * Matches the repaired live ERD-reference shape:
 * plural child_immunizations (id) + immunization_doses (dose_id / dose_number)
 * + fic_cic_status keyed to child_immunizations.id.
 */
final class HybridChildImmunizationSchema
{
    public static function ensure(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('immunization_doses');
        Schema::dropIfExists('fic_cic_status');
        Schema::dropIfExists('child_immunization');
        Schema::dropIfExists('child_immunizations');

        Schema::create('child_immunizations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('resident_id')->unique();
            $table->json('selected_vaccine_types')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('immunization_doses', function (Blueprint $table): void {
            $table->id('dose_id');
            $table->unsignedBigInteger('child_immunization_id');
            $table->string('vaccine_type', 32);
            $table->unsignedTinyInteger('dose_number');
            $table->date('date_given')->nullable();
            $table->timestamps();
            $table->unique(
                ['child_immunization_id', 'vaccine_type', 'dose_number'],
                'uq_hybrid_immdose'
            );
        });

        Schema::create('fic_cic_status', function (Blueprint $table): void {
            $table->id('fic_cic_id');
            $table->unsignedBigInteger('child_immunization_id')->unique();
            $table->boolean('fic_completed')->default(false);
            $table->boolean('cic_completed')->default(false);
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
        ChildImmunizationErdMode::resetCachedState();
    }
}
