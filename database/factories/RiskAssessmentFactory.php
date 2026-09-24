<?php

namespace Database\Factories;

use App\Models\Resident;
use App\Models\RiskAssessment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RiskAssessment>
 */
class RiskAssessmentFactory extends Factory
{
    protected $model = RiskAssessment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'assessment_no' => 'RA-'.fake()->unique()->numerify('###'),
            'conducted_at' => fake()->date(),
            'red_flags' => ['none'],
            'past_medical' => ['none'],
            'family_history' => ['none'],
            'dietary' => 'yes',
            'tobacco' => 'never',
            'alcohol' => 'never',
            'physical_activity' => 'yes',
            'height_cm' => 165,
            'weight_kg' => 58,
            'bmi' => 21.3,
            'waist_cm' => 72,
            'systolic' => 120,
            'diastolic' => 80,
            'bp_status' => 'Normal',
            'bp_reading' => '120/80',
            'bmi_label' => null,
            'visual_no_screening' => false,
            'visual_blurred' => false,
            'visual_blurred_note' => null,
        ];
    }
}
