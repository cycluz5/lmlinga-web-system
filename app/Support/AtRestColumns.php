<?php

namespace App\Support;

/**
 * Registry of ERD health-record columns stored as AES-256-GCM ciphertext.
 *
 * Each column maps to its plaintext type (used to cast decrypted values) and
 * its original MySQL definition (used to restore the schema on rollback).
 */
final class AtRestColumns
{
    public const BOOL = 'bool';

    public const INT = 'int';

    public const DECIMAL = 'decimal';

    public const TEXT = 'text';

    private const FLAG = [self::BOOL, 'tinyint(1) NOT NULL DEFAULT 0'];

    private const NOTE = [self::TEXT, 'varchar(255) NULL DEFAULT NULL'];

    /** @var array<string, array<string, array{0: string, 1: string}>> */
    private const TABLES = [
        'medical_history' => [
            'no_medical_history' => self::FLAG,
            'diabetes_mellitus' => self::FLAG,
            'heart_disease' => self::FLAG,
            'hypertension' => self::FLAG,
            'kidney_disease' => self::FLAG,
            'tuberculosis' => self::FLAG,
            'other_medical_history' => self::NOTE,
        ],
        'disability_type' => [
            'no_disability' => self::FLAG,
            'intellectual_disability' => self::FLAG,
            'mental_disability' => self::FLAG,
            'physical_disability' => self::FLAG,
            'other_disability' => self::FLAG,
            'other_disability_specify' => self::NOTE,
        ],
        'family_history' => [
            'hypertension' => self::FLAG,
            'stroke' => self::FLAG,
            'heart_disease' => self::FLAG,
            'diabetes_mellitus' => self::FLAG,
            'asthma' => self::FLAG,
            'cancer' => self::FLAG,
            'kidney_disease' => self::FLAG,
            'first_degree_cardio' => self::FLAG,
            'tb' => self::FLAG,
            'mental_problem' => self::FLAG,
            'copd' => self::FLAG,
            'none_family_history' => self::FLAG,
        ],
        'past_medical_history' => [
            'hypertension' => self::FLAG,
            'heart_diseases' => self::FLAG,
            'diabetes' => self::FLAG,
            'cancer' => self::FLAG,
            'copd' => self::FLAG,
            'asthma' => self::FLAG,
            'mental_disorders' => self::FLAG,
            'vision_problems' => self::FLAG,
            'surgical_history' => self::FLAG,
            'thyroid_disorders' => self::FLAG,
            'allergies' => self::FLAG,
            'none_past_medical' => self::FLAG,
        ],
        'red_flags_assessment' => [
            'chest_pain' => self::FLAG,
            'diff_breathing' => self::FLAG,
            'loss_consciousness' => self::FLAG,
            'slurred_speech' => self::FLAG,
            'facial_assym' => self::FLAG,
            'disorientation' => self::FLAG,
            'chest_retract' => self::FLAG,
            'seizure' => self::FLAG,
            'self_harm' => self::FLAG,
            'is_agitated' => self::FLAG,
            'eye_injury' => self::FLAG,
            'weakness_body' => self::FLAG,
            'no_red_flags' => self::FLAG,
        ],
        'visual_screening' => [
            'no_screening_past_year' => self::FLAG,
            'has_blurred_vision' => self::FLAG,
            'blurred_vision_details' => self::NOTE,
        ],
        'risk_assessment' => [
            'tobacco_vape_usage' => [self::TEXT, "enum('Never','Current User','Stopped < 1 year') NULL DEFAULT NULL"],
            'alcohol_intake' => [self::TEXT, "enum('Never','Light (Occasional)','Excessive') NULL DEFAULT NULL"],
            'dietary_habits' => [self::TEXT, "enum('Yes','No') NULL DEFAULT NULL"],
            'physical_activity' => [self::TEXT, "enum('Yes','No') NULL DEFAULT NULL"],
            'height_cm' => [self::DECIMAL, 'decimal(5,2) NULL DEFAULT NULL'],
            'weight_kg' => [self::DECIMAL, 'decimal(5,2) NULL DEFAULT NULL'],
            'waist_circum_cm' => [self::DECIMAL, 'decimal(5,2) NULL DEFAULT NULL'],
            'systolic_blood_pressure' => [self::INT, 'smallint(5) unsigned NULL DEFAULT NULL'],
            'diastolic_blood_pressure' => [self::INT, 'smallint(5) unsigned NULL DEFAULT NULL'],
            'blood_pressure_status' => [self::TEXT, 'varchar(30) NULL DEFAULT NULL'],
        ],
        'death_records' => [
            'cause_of_death' => [self::TEXT, 'text NOT NULL'],
            'death_certificate_no' => [self::TEXT, 'varchar(50) NOT NULL'],
        ],
        'hiv_screening' => [
            'result' => [self::TEXT, "enum('REACTIVE','NON REACTIVE') NULL DEFAULT NULL"],
        ],
        'syphilis_screening' => [
            'result' => [self::TEXT, "enum('REACTIVE','NON REACTIVE') NULL DEFAULT NULL"],
        ],
        'hepatitis_b_screening' => [
            'result' => [self::TEXT, "enum('Reactive','Negative') NULL DEFAULT NULL"],
        ],
    ];

    /**
     * MySQL expression risk_assessment.blood_pressure_status was generated from
     * before encryption; restored on rollback.
     */
    public const BLOOD_PRESSURE_STATUS_EXPRESSION = "case when `systolic_blood_pressure` is null or `diastolic_blood_pressure` is null then NULL when `systolic_blood_pressure` > 180 or `diastolic_blood_pressure` > 120 then 'Hypertensive Crisis' when `systolic_blood_pressure` >= 140 or `diastolic_blood_pressure` >= 90 then 'Hypertension Stage 2' when `systolic_blood_pressure` between 130 and 139 or `diastolic_blood_pressure` between 80 and 89 then 'Hypertension Stage 1' when `systolic_blood_pressure` between 120 and 129 and `diastolic_blood_pressure` < 80 then 'Elevated' when `systolic_blood_pressure` < 120 and `diastolic_blood_pressure` < 80 then 'Normal' else NULL end";

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        return array_keys(self::TABLES);
    }

    /**
     * @return list<string>
     */
    public static function columns(string $table): array
    {
        return array_keys(self::TABLES[$table] ?? []);
    }

    public static function isEncrypted(string $table, string $column): bool
    {
        return isset(self::TABLES[$table][$column]);
    }

    public static function type(string $table, string $column): ?string
    {
        return self::TABLES[$table][$column][0] ?? null;
    }

    public static function originalDefinition(string $table, string $column): ?string
    {
        return self::TABLES[$table][$column][1] ?? null;
    }
}
