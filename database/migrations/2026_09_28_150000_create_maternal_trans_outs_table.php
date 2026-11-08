<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maternal Care Trans-Out details (3NF schema only).
 *
 * The Trans-Out form collects facility, stage, reason and date, but
 * maternal_care only has pregnancy_status, so those fields were dropped.
 * One row per pregnancy (1:1 with maternal_care).
 *
 * Also enforces one td_immunization row per resident + dose number, which the
 * Immunizations section now writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maternal_care') || ! Schema::hasColumn('maternal_care', 'maternal_care_id')) {
            return;
        }

        if (! Schema::hasTable('maternal_trans_outs')) {
            Schema::create('maternal_trans_outs', function (Blueprint $table) {
                $table->id('trans_out_id');
                $table->unsignedBigInteger('maternal_care_id');
                $table->string('to_facility', 160)->nullable();
                $table->string('occurred_at_stage', 120)->nullable();
                $table->string('reason', 255)->nullable();
                $table->date('date_transferred_out')->nullable();
                $table->timestamps();

                $table->unique('maternal_care_id', 'uq_transout_matcare');
                $table->foreign('maternal_care_id', 'fk_transout_matcare')
                    ->references('maternal_care_id')
                    ->on('maternal_care')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('td_immunization') && ! $this->hasTdDoseUnique()) {
            Schema::table('td_immunization', function (Blueprint $table) {
                $table->unique(['resident_id', 'dose_number'], 'uq_tdimm_resident_dose');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('td_immunization') && $this->hasTdDoseUnique()) {
            Schema::table('td_immunization', function (Blueprint $table) {
                $table->dropUnique('uq_tdimm_resident_dose');
            });
        }

        Schema::dropIfExists('maternal_trans_outs');
    }

    private function hasTdDoseUnique(): bool
    {
        foreach (Schema::getIndexes('td_immunization') as $index) {
            if ($index['name'] === 'uq_tdimm_resident_dose') {
                return true;
            }
        }

        return false;
    }
};
