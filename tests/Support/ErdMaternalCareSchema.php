<?php

namespace Tests\Support;

use App\Support\MaternalCareErdMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Isolated sqlite mirror of the authoritative ERD maternal_care family.
 * Not a migration. Never applied to lmlinga_erd_reference.
 */
final class ErdMaternalCareSchema
{
    public static function ensure(): void
    {
        Schema::dropIfExists('maternal_pregnancies');
        Schema::dropIfExists('postpartum_vitamin_a_supplementation');
        Schema::dropIfExists('postpartum_ifa_supplementation');
        Schema::dropIfExists('postnatal_care_visits');
        Schema::dropIfExists('delivery_outcomes');
        Schema::dropIfExists('prenatal_visits');
        Schema::dropIfExists('rusf_supplementation');
        Schema::dropIfExists('ifa_supplementation');
        Schema::dropIfExists('mms_supplementation');
        Schema::dropIfExists('cc_supplementation');
        Schema::dropIfExists('deworming_supplementation');
        Schema::dropIfExists('hepatitis_b_screening');
        Schema::dropIfExists('cbc_hgb_hct_screening');
        Schema::dropIfExists('gdm_screening');
        Schema::dropIfExists('urinalysis_screening');
        Schema::dropIfExists('ultrasound_screening');
        Schema::dropIfExists('syphilis_screening');
        Schema::dropIfExists('hiv_screening');
        Schema::dropIfExists('cvc_screening');
        Schema::dropIfExists('gestational_screening');
        Schema::dropIfExists('maternal_care');

        DB::statement('CREATE TABLE maternal_care (
            maternal_care_id INTEGER PRIMARY KEY AUTOINCREMENT,
            resident_id INTEGER NOT NULL,
            lmp_date DATE NULL,
            gravida INTEGER NULL,
            parity INTEGER NULL,
            edd DATE NULL,
            weight_kg REAL NULL,
            height_cm REAL NULL,
            bmi REAL GENERATED ALWAYS AS (weight_kg / ((height_cm / 100.0) * (height_cm / 100.0))) STORED,
            bp_systolic INTEGER NULL,
            bp_diastolic INTEGER NULL,
            pregnancy_status TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');

        DB::statement('CREATE TABLE prenatal_visits (
            prenatal_visit_id INTEGER PRIMARY KEY AUTOINCREMENT,
            maternal_care_id INTEGER NOT NULL,
            trimester TEXT NOT NULL,
            visit_number INTEGER NULL,
            visit_date DATE NULL,
            weight_kg REAL NULL,
            height_cm REAL NULL,
            bmi REAL GENERATED ALWAYS AS (weight_kg / ((height_cm / 100.0) * (height_cm / 100.0))) STORED,
            bp_systolic INTEGER NULL,
            bp_diastolic INTEGER NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');

        Schema::create('ifa_supplementation', function ($table): void {
            $table->id('ifa_supp_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->unsignedTinyInteger('visit_number');
            $table->date('date_given')->nullable();
            $table->unsignedInteger('tablets_given')->nullable();
            $table->timestamps();
        });

        Schema::create('mms_supplementation', function ($table): void {
            $table->id('mms_supp_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->unsignedTinyInteger('visit_number');
            $table->date('date_given')->nullable();
            $table->unsignedInteger('tablets_given')->nullable();
            $table->timestamps();
        });

        Schema::create('cc_supplementation', function ($table): void {
            $table->id('cc_supp_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->unsignedTinyInteger('visit_number');
            $table->date('date_given')->nullable();
            $table->unsignedInteger('tablets_given')->nullable();
            $table->timestamps();
        });

        Schema::create('deworming_supplementation', function ($table): void {
            $table->id('deworming_supp_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->date('date_given')->nullable();
            $table->timestamps();
        });

        Schema::create('rusf_supplementation', function ($table): void {
            $table->id('rusf_supp_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->date('date_given');
            $table->timestamps();
            $table->unique(['maternal_care_id', 'date_given'], 'uq_rusfsupp_date');
        });

        Schema::create('hepatitis_b_screening', function ($table): void {
            $table->id('hep_b_screening_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->date('date_screened')->nullable();
            $table->string('result')->nullable();
            $table->timestamps();
        });

        Schema::create('cbc_hgb_hct_screening', function ($table): void {
            $table->id('cbc_screening_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->date('date_screened')->nullable();
            $table->string('result')->nullable();
            $table->timestamps();
        });

        Schema::create('gdm_screening', function ($table): void {
            $table->id('gdm_screening_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->date('date_screened')->nullable();
            $table->string('result')->nullable();
            $table->timestamps();
        });

        Schema::create('urinalysis_screening', function ($table): void {
            $table->id('urinalysis_screening_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->date('date_screened')->nullable();
            $table->timestamps();
            $table->unique('maternal_care_id', 'uq_urinalysis_matcare');
        });

        Schema::create('ultrasound_screening', function ($table): void {
            $table->id('ultrasound_screening_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->date('date_screened')->nullable();
            $table->timestamps();
            $table->unique('maternal_care_id', 'uq_ultrasound_matcare');
        });

        Schema::create('syphilis_screening', function ($table): void {
            $table->id('syphilis_screening_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->date('date_screened')->nullable();
            $table->string('result')->nullable();
            $table->timestamps();
            $table->unique('maternal_care_id', 'uq_syphilis_matcare');
        });

        Schema::create('hiv_screening', function ($table): void {
            $table->id('hiv_screening_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->date('date_screened')->nullable();
            $table->string('result')->nullable();
            $table->timestamps();
            $table->unique('maternal_care_id', 'uq_hiv_matcare');
        });

        Schema::create('cvc_screening', function ($table): void {
            $table->id('cvc_screening_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->decimal('value', 6, 2)->nullable();
            $table->timestamps();
            $table->unique('maternal_care_id', 'uq_cvc_matcare');
        });

        Schema::create('gestational_screening', function ($table): void {
            $table->id('gestational_screening_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->decimal('value', 6, 2)->nullable();
            $table->timestamps();
            $table->unique('maternal_care_id', 'uq_gestational_matcare');
        });

        Schema::create('delivery_outcomes', function ($table): void {
            $table->id('delivery_outcome_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->string('outcome')->nullable();
            $table->string('delivery_type')->nullable();
            $table->decimal('birth_weight_kg', 5, 2)->nullable();
            $table->string('status')->nullable();
            $table->dateTime('date_time_of_delivery')->nullable();
            $table->date('date_terminated')->nullable();
            $table->string('birth_attendant')->nullable();
            $table->string('birth_attendant_other')->nullable();
            $table->string('place_of_delivery')->nullable();
            $table->string('facility_name')->nullable();
            $table->boolean('bemonc_cemonc_capable')->nullable();
            $table->string('newborn_sex')->nullable();
            $table->string('plurality')->nullable();
            $table->unsignedInteger('plurality_number')->nullable();
            $table->timestamps();
        });

        Schema::create('postnatal_care_visits', function ($table): void {
            $table->id('pnc_visit_id');
            $table->unsignedBigInteger('delivery_outcome_id');
            $table->unsignedTinyInteger('contact_number');
            $table->date('contact_date')->nullable();
            $table->timestamps();
        });

        Schema::create('postpartum_ifa_supplementation', function ($table): void {
            $table->id('postpartum_ifa_id');
            $table->unsignedBigInteger('delivery_outcome_id');
            $table->unsignedTinyInteger('visit_number');
            $table->date('date_given')->nullable();
            $table->unsignedInteger('tablets_given')->nullable();
            $table->timestamps();
        });

        Schema::create('postpartum_vitamin_a_supplementation', function ($table): void {
            $table->id('postpartum_vitamin_a_id');
            $table->unsignedBigInteger('maternal_care_id');
            $table->date('date_given')->nullable();
            $table->timestamps();
            $table->unique('maternal_care_id', 'uq_ppvita_matcare');
        });

        MaternalCareErdMode::resetCachedState();
    }
}
