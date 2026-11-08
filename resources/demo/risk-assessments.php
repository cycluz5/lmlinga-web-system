<?php

/**
 * Demo Risk Assessment history keyed by household member.
 *
 * UI-preview catalog only — no persistence.
 * Absence of entries for a member is a valid empty state (assessment is optional).
 *
 * Presentation BP/BMI values are fixture display data, not clinical calculations.
 *
 * @return array<string, array<string, list<array<string, mixed>>>>
 */
return [
    'HH-151' => [
        'MB-001' => [
            [
                'id' => 'RA-001',
                'conducted_at' => '2026-06-08',
                'bp_reading' => '120/80',
                'bmi_label' => 'Normal',
                'red_flags' => ['none'],
                'past_medical' => ['none'],
                'family_history' => ['hypertension'],
                'tobacco' => 'never',
                'alcohol' => 'never',
                'dietary' => 'yes',
                'physical_activity' => 'yes',
                'height_cm' => '165',
                'weight_kg' => '58',
                'bmi' => '21.3',
                'waist_cm' => '72',
                'systolic' => '120',
                'diastolic' => '80',
                'bp_status' => 'Normal',
                'visual_no_screening' => false,
                'visual_blurred' => false,
                'visual_blurred_note' => '',
            ],
            [
                'id' => 'RA-002',
                'conducted_at' => '2026-05-01',
                'bp_reading' => '140/90',
                'bmi_label' => 'Overweight',
                'red_flags' => ['chest_pain'],
                'past_medical' => ['hypertension'],
                'family_history' => ['hypertension', 'diabetes_mellitus'],
                'tobacco' => 'current',
                'alcohol' => 'light',
                'dietary' => 'no',
                'physical_activity' => 'no',
                'height_cm' => '165',
                'weight_kg' => '72',
                'bmi' => '26.4',
                'waist_cm' => '88',
                'systolic' => '140',
                'diastolic' => '90',
                'bp_status' => 'Elevated',
                'visual_no_screening' => true,
                'visual_blurred' => false,
                'visual_blurred_note' => '',
            ],
            [
                'id' => 'RA-003',
                'conducted_at' => '2025-10-08',
                'bp_reading' => '130/85',
                'bmi_label' => 'Normal',
                'red_flags' => ['none'],
                'past_medical' => ['allergies'],
                'family_history' => ['none'],
                'tobacco' => 'never',
                'alcohol' => 'never',
                'dietary' => 'yes',
                'physical_activity' => 'yes',
                'height_cm' => '165',
                'weight_kg' => '60',
                'bmi' => '22.0',
                'waist_cm' => '74',
                'systolic' => '130',
                'diastolic' => '85',
                'bp_status' => 'Normal',
                'visual_no_screening' => false,
                'visual_blurred' => true,
                'visual_blurred_note' => 'Occasional',
            ],
        ],
        // MB-002 intentionally omitted — empty history for optional-assessment UX.
    ],
];
