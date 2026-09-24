<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #11 — Optional monthly RUSF dates per maternal_care episode.
 * Created only when the ERD maternal_care table is present so Laravel JSON
 * databases that use maternal_pregnancies are not broken.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maternal_care') || Schema::hasTable('rusf_supplementation')) {
            return;
        }

        Schema::create('rusf_supplementation', function (Blueprint $table) {
            $table->id('rusf_supp_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->date('date_given');
            $table->timestamps();

            $table->unique(['maternal_care_id', 'date_given'], 'uq_rusfsupp_date');
            $table->foreign('maternal_care_id', 'fk_rusfsupp_matcare')
                ->references('maternal_care_id')
                ->on('maternal_care')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rusf_supplementation');
    }
};
