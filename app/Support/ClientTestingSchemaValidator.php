<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Read-only pre-flight checks for ClientTestingProvisioner writes.
 */
final class ClientTestingSchemaValidator
{
    /**
     * @return list<string>
     */
    public static function errors(): array
    {
        $errors = [];

        if (! UserManagementErdMode::isActive()) {
            $errors[] = 'user_management ERD mode is required (user_management table without users).';

            return $errors;
        }

        $errors = array_merge($errors, self::requireColumns('user_management', [
            'user_id', 'first_name', 'last_name', 'email', 'username', 'password', 'status',
        ]));
        $errors = array_merge($errors, self::requireColumns('worker_appointments', [
            'user_id', 'role', 'assigned_barangay', 'assigned_zone', 'date_appointed', 'end_of_appointment',
        ]));
        $errors = array_merge($errors, self::requireColumns('households', [
            'household_id', 'household_no', 'purok', 'latitude', 'longitude', 'household_type', 'date_registered',
        ]));
        $errors = array_merge($errors, self::requireColumns('residents', [
            'resident_id', 'household_id', 'first_name', 'last_name', 'birthday', 'sex',
        ]));

        if (Schema::hasTable('death_records')) {
            $errors = array_merge($errors, self::requireColumns('death_records', [
                'death_record_id', 'resident_id', 'cause_of_death', 'date_of_death',
                'death_certificate_no', 'death_certificate_file_path', 'verification_status', 'submitted_by',
            ]));
        }

        if (Schema::hasTable('maternal_care')) {
            $errors = array_merge($errors, self::requireColumns('maternal_care', [
                'maternal_care_id',
                'resident_id',
                'pregnancy_status',
            ]));

            if (MaternalCareErdMode::isBmiGenerated()) {
                $errors = array_merge($errors, self::requireColumns('maternal_care', [
                    'weight_kg',
                    'height_cm',
                    'bmi',
                ]));

                if (ClientTestingProvisioner::wouldWriteMaternalCareBmi()) {
                    $errors[] = 'ClientTestingProvisioner must not write generated maternal_care.bmi; supply weight_kg and height_cm only.';
                }
            }
        }

        if (Schema::hasTable('environmental_sanitation')) {
            $errors = array_merge($errors, self::requireColumns('environmental_sanitation', [
                'household_id', 'user_id', 'toilet_type',
            ]));
        }

        if (ChildNutritionErdMode::isActive()) {
            $errors = array_merge($errors, self::requireColumns('child_nutrition', [
                'child_nutrition_id', 'resident_id',
            ]));
            $errors = array_merge($errors, self::requireColumns('nutrition_supplementation', [
                'child_nutrition_id', 'supplement_type', 'date_given',
            ]));

            if (Schema::hasTable('nutrition_supplementation')) {
                $errors = array_merge($errors, self::requireColumns('nutrition_supplementation', [
                    'age_group', 'dose_number',
                ]));

                if (NutritionSupplementationErdMode::usesStrictEnumDomain()
                    && ClientTestingProvisioner::wouldWriteInvalidNutritionSupplementation()) {
                    $errors[] = 'ClientTestingProvisioner nutrition_supplementation payload uses values outside the authoritative ENUM domain.';
                }
            }
        }

        if (Schema::hasTable('deworming_records')) {
            $errors = array_merge($errors, self::requireColumns('deworming_records', [
                DewormingErdMode::primaryKey(),
                'resident_id',
                'year',
                DewormingErdMode::roundColumn(),
                'date_given',
            ]));
        }

        if (Schema::hasTable('risk_assessment')) {
            $errors = array_merge($errors, self::requireColumns('risk_assessment', [
                'risk_assessment_id', 'resident_id',
            ]));

            if (RiskAssessmentErdMode::requiresUserId()) {
                $errors = array_merge($errors, self::requireColumns('risk_assessment', [
                    'user_id',
                ]));

                if (! ClientTestingProvisioner::riskAssessmentPayloadIncludesUserId()) {
                    $errors[] = 'ClientTestingProvisioner must supply risk_assessment.user_id from deterministic BHW staff.';
                }
            }

            if (RiskAssessmentErdMode::isBloodPressureStatusGenerated()) {
                $errors = array_merge($errors, self::requireColumns('risk_assessment', [
                    'systolic_blood_pressure',
                    'diastolic_blood_pressure',
                    'blood_pressure_status',
                ]));

                if (ClientTestingProvisioner::wouldWriteGeneratedRiskAssessmentBloodPressureStatus()) {
                    $errors[] = 'ClientTestingProvisioner must not write generated risk_assessment.blood_pressure_status; supply systolic_blood_pressure and diastolic_blood_pressure only.';
                }
            }

            if (ClientTestingProvisioner::wouldWriteInvalidRiskAssessmentPayload()) {
                $errors[] = 'ClientTestingProvisioner risk_assessment payload uses values outside the authoritative schema contract.';
            }
        }

        if (Schema::hasTable('family_planning')) {
            $errors = array_merge($errors, self::requireColumns('family_planning', [
                'resident_id', 'visitation_date',
            ]));
        }

        if (Schema::hasTable('record_requests')) {
            $errors = array_merge($errors, self::requireColumns('record_requests', [
                'request_id', 'first_name_submitted', 'last_name_submitted', 'status',
            ]));

            if (RecordRequestErdMode::requiresAccountId()) {
                $errors = array_merge($errors, self::requireColumns('record_requests', [
                    'account_id',
                ]));

                if (! Schema::hasTable(RecordRequestErdMode::accountForeignTable())) {
                    $errors[] = 'Missing required table: '.RecordRequestErdMode::accountForeignTable();
                } elseif (! ClientTestingProvisioner::recordRequestPayloadIncludesAccountId()) {
                    $errors[] = 'ClientTestingProvisioner must supply record_requests.account_id from a deterministic resident account.';
                } elseif (ClientTestingProvisioner::wouldWriteInvalidRecordRequestPayload()) {
                    $errors[] = 'ClientTestingProvisioner record_requests payload does not satisfy the authoritative account_id contract.';
                }
            }
        }

        if (Schema::hasTable('announcements')) {
            $errors = array_merge($errors, self::requireColumns('announcements', [
                'title', 'message', 'event_date', 'target_group', 'zone_mode', 'posted_at',
            ]));
        }

        return $errors;
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function requireColumns(string $table, array $columns): array
    {
        if (! Schema::hasTable($table)) {
            return ["Missing required table: {$table}"];
        }

        $errors = [];
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $errors[] = "Missing required column: {$table}.{$column}";
            }
        }

        return $errors;
    }
}
