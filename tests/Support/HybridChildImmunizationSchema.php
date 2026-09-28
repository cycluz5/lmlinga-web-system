<?php

namespace Tests\Support;

use App\Support\ChildImmunizationErdMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHPUnit-only hybrid Child Immunization tables (not a migration).
 *
 * Matches the repaired live ERD-reference shape:
 * plural child_immunizations (id) + immunization_doses (dose_id / dose_number).
 * FIC/CIC is derived from doses (no fic_cic_status table).
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

        Schema::enableForeignKeyConstraints();
        ChildImmunizationErdMode::resetCachedState();
    }
}
