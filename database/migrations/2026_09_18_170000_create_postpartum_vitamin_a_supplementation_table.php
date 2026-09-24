<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #14 — Singleton postnatal Vitamin A date per maternal_care episode.
 * Created only when the ERD maternal_care table is present so Laravel JSON
 * databases that use maternal_pregnancies are not broken.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maternal_care') || Schema::hasTable('postpartum_vitamin_a_supplementation')) {
            return;
        }

        Schema::create('postpartum_vitamin_a_supplementation', function (Blueprint $table) {
            $table->id('postpartum_vitamin_a_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->date('date_given')->nullable();
            $table->timestamps();

            $table->unique('maternal_care_id', 'uq_ppvita_matcare');
            $table->foreign('maternal_care_id', 'fk_ppvita_matcare')
                ->references('maternal_care_id')
                ->on('maternal_care')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postpartum_vitamin_a_supplementation');
    }
};
