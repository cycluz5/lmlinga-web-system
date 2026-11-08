<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHPUnit-only live ERD Risk Assessment child tables (not a migration).
 *
 * Mirrors lmlinga_erd_reference:
 * red_flags_assessment, past_medical_history, family_history
 * keyed uniquely by risk_assessment_id.
 */
final class ErdRiskAssessmentChildSchema
{
    public static function drop(): void
    {
        Schema::dropIfExists('red_flags_assessment');
        Schema::dropIfExists('past_medical_history');
        Schema::dropIfExists('family_history');
    }

    public static function create(): void
    {
        Schema::create('red_flags_assessment', function (Blueprint $table): void {
            $table->id('red_flag_id');
            $table->unsignedBigInteger('risk_assessment_id')->unique();
            $table->boolean('chest_pain')->default(false);
            $table->boolean('diff_breathing')->default(false);
            $table->boolean('loss_consciousness')->default(false);
            $table->boolean('slurred_speech')->default(false);
            $table->boolean('facial_assym')->default(false);
            $table->boolean('disorientation')->default(false);
            $table->boolean('chest_retract')->default(false);
            $table->boolean('seizure')->default(false);
            $table->boolean('self_harm')->default(false);
            $table->boolean('is_agitated')->default(false);
            $table->boolean('eye_injury')->default(false);
            $table->boolean('weakness_body')->default(false);
            $table->boolean('no_red_flags')->default(false);
            $table->timestamps();
        });

        Schema::create('past_medical_history', function (Blueprint $table): void {
            $table->id('past_med_id');
            $table->unsignedBigInteger('risk_assessment_id')->unique();
            $table->boolean('hypertension')->default(false);
            $table->boolean('heart_diseases')->default(false);
            $table->boolean('diabetes')->default(false);
            $table->boolean('cancer')->default(false);
            $table->boolean('copd')->default(false);
            $table->boolean('asthma')->default(false);
            $table->boolean('mental_disorders')->default(false);
            $table->boolean('vision_problems')->default(false);
            $table->boolean('surgical_history')->default(false);
            $table->boolean('thyroid_disorders')->default(false);
            $table->boolean('allergies')->default(false);
            $table->boolean('none_past_medical')->default(false);
            $table->timestamps();
        });

        Schema::create('family_history', function (Blueprint $table): void {
            $table->id('fam_history_id');
            $table->unsignedBigInteger('risk_assessment_id')->unique();
            $table->boolean('hypertension')->default(false);
            $table->boolean('stroke')->default(false);
            $table->boolean('heart_disease')->default(false);
            $table->boolean('diabetes_mellitus')->default(false);
            $table->boolean('asthma')->default(false);
            $table->boolean('cancer')->default(false);
            $table->boolean('kidney_disease')->default(false);
            $table->boolean('first_degree_cardio')->default(false);
            $table->boolean('tb')->default(false);
            $table->boolean('mental_problem')->default(false);
            $table->boolean('copd')->default(false);
            $table->boolean('none_family_history')->default(false);
            $table->timestamps();
        });
    }
}
