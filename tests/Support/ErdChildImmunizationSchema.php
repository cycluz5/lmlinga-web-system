<?php

namespace Tests\Support;

use App\Support\ChildImmunizationErdMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHPUnit-only ERD child immunization tables (not a migration).
 *
 * Swaps legacy child_immunizations / dose_index for child_immunization + dose_number.
 */
final class ErdChildImmunizationSchema
{
    public static function ensure(): void
    {
        Schema::dropIfExists('immunization_doses');
        Schema::dropIfExists('fic_cic_status');
        Schema::dropIfExists('child_immunizations');
        Schema::dropIfExists('child_immunization');

        Schema::create('child_immunization', function (Blueprint $table): void {
            $table->id('child_immunization_id');
            $table->unsignedBigInteger('resident_id');
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
                'uq_immdose'
            );
        });

        Schema::create('fic_cic_status', function (Blueprint $table): void {
            $table->id('fic_cic_id');
            $table->unsignedBigInteger('child_immunization_id')->unique();
            $table->boolean('fic_completed')->default(false);
            $table->boolean('cic_completed')->default(false);
            $table->timestamps();
        });

        ChildImmunizationErdMode::resetCachedState();
    }
}
