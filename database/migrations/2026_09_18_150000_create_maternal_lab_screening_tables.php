<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #12 — Additional maternal laboratory screening tables (1:1 per maternal_care).
 * Created only when the ERD maternal_care table is present so Laravel JSON
 * databases that use maternal_pregnancies are not broken.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maternal_care')) {
            return;
        }

        if (! Schema::hasTable('urinalysis_screening')) {
            Schema::create('urinalysis_screening', function (Blueprint $table) {
                $table->id('urinalysis_screening_id');
                $table->unsignedBigInteger('maternal_care_id');
                $table->date('date_screened')->nullable();
                $table->timestamps();
                $table->unique('maternal_care_id', 'uq_urinalysis_matcare');
                $table->foreign('maternal_care_id', 'fk_urinalysis_matcare')
                    ->references('maternal_care_id')
                    ->on('maternal_care')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('ultrasound_screening')) {
            Schema::create('ultrasound_screening', function (Blueprint $table) {
                $table->id('ultrasound_screening_id');
                $table->unsignedBigInteger('maternal_care_id');
                $table->date('date_screened')->nullable();
                $table->timestamps();
                $table->unique('maternal_care_id', 'uq_ultrasound_matcare');
                $table->foreign('maternal_care_id', 'fk_ultrasound_matcare')
                    ->references('maternal_care_id')
                    ->on('maternal_care')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('syphilis_screening')) {
            Schema::create('syphilis_screening', function (Blueprint $table) {
                $table->id('syphilis_screening_id');
                $table->unsignedBigInteger('maternal_care_id');
                $table->date('date_screened')->nullable();
                $table->enum('result', ['REACTIVE', 'NON REACTIVE'])->nullable();
                $table->timestamps();
                $table->unique('maternal_care_id', 'uq_syphilis_matcare');
                $table->foreign('maternal_care_id', 'fk_syphilis_matcare')
                    ->references('maternal_care_id')
                    ->on('maternal_care')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('hiv_screening')) {
            Schema::create('hiv_screening', function (Blueprint $table) {
                $table->id('hiv_screening_id');
                $table->unsignedBigInteger('maternal_care_id');
                $table->date('date_screened')->nullable();
                $table->enum('result', ['REACTIVE', 'NON REACTIVE'])->nullable();
                $table->timestamps();
                $table->unique('maternal_care_id', 'uq_hiv_matcare');
                $table->foreign('maternal_care_id', 'fk_hiv_matcare')
                    ->references('maternal_care_id')
                    ->on('maternal_care')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('cvc_screening')) {
            Schema::create('cvc_screening', function (Blueprint $table) {
                $table->id('cvc_screening_id');
                $table->unsignedBigInteger('maternal_care_id');
                $table->decimal('value', 6, 2)->nullable();
                $table->timestamps();
                $table->unique('maternal_care_id', 'uq_cvc_matcare');
                $table->foreign('maternal_care_id', 'fk_cvc_matcare')
                    ->references('maternal_care_id')
                    ->on('maternal_care')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('gestational_screening')) {
            Schema::create('gestational_screening', function (Blueprint $table) {
                $table->id('gestational_screening_id');
                $table->unsignedBigInteger('maternal_care_id');
                $table->decimal('value', 6, 2)->nullable();
                $table->timestamps();
                $table->unique('maternal_care_id', 'uq_gestational_matcare');
                $table->foreign('maternal_care_id', 'fk_gestational_matcare')
                    ->references('maternal_care_id')
                    ->on('maternal_care')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('urinalysis_screening');
        Schema::dropIfExists('ultrasound_screening');
        Schema::dropIfExists('syphilis_screening');
        Schema::dropIfExists('hiv_screening');
        Schema::dropIfExists('cvc_screening');
        Schema::dropIfExists('gestational_screening');
    }
};
