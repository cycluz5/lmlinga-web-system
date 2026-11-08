<?php

namespace Tests\Support;

use App\Support\SchoolImmunizationErdMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHPUnit-only live ERD School-Based Immunization tables (not a migration).
 *
 * Mirrors lmlinga_erd_reference: school_immunization + hpv_immunization.
 * Drops the Laravel plural SBI tables so dual-schema detection uses ERD mode.
 */
final class ErdSchoolImmunizationSchema
{
    public static function ensure(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('school_immunization_doses');
        Schema::dropIfExists('school_immunizations');
        Schema::dropIfExists('hpv_immunization');
        Schema::dropIfExists('school_immunization');

        Schema::create('school_immunization', function (Blueprint $table): void {
            $table->id('school_immunization_id');
            $table->unsignedBigInteger('resident_id');
            $table->string('grade_level', 16);
            $table->date('td_date')->nullable();
            $table->date('mr_date')->nullable();
            $table->timestamps();
            $table->unique(['resident_id', 'grade_level'], 'uq_schoolimm_grade');
        });

        Schema::create('hpv_immunization', function (Blueprint $table): void {
            $table->id('hpv_immunization_id');
            $table->unsignedBigInteger('resident_id');
            $table->string('dose_number', 16);
            $table->date('date_given')->nullable();
            $table->timestamps();
            $table->unique(['resident_id', 'dose_number'], 'uq_hpvimm_dose');
        });

        Schema::enableForeignKeyConstraints();
        SchoolImmunizationErdMode::resetCachedState();
    }
}
