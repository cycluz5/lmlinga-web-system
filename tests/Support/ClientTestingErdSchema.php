<?php

namespace Tests\Support;

use App\Support\ChildNutritionErdMode;
use App\Support\DeathRecordsErdMode;
use App\Support\DewormingErdMode;
use App\Support\MaternalCareErdMode;
use App\Support\NutritionSupplementationErdMode;
use App\Support\RecordRequestErdMode;
use App\Support\RiskAssessmentErdMode;
use App\Support\UserManagementErdMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHPUnit-only authoritative ERD tables for ClientTestingProvisioner tests.
 *
 * Not a migration — mirrors lmlinga_erd_reference shapes used by the provisioner.
 */
final class ClientTestingErdSchema
{
    public static function ensure(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('death_requests');
        Schema::dropIfExists('child_nutritions');
        Schema::dropIfExists('death_records');
        Schema::dropIfExists('record_requests');
        Schema::dropIfExists('resident_accounts');
        Schema::dropIfExists('nutrition_supplementation');
        Schema::dropIfExists('child_nutrition');
        Schema::dropIfExists('deworming_records');
        // adult_immunization's FK is baked against the real migration's
        // residents.id at migration time — drop it before residents gets
        // rebuilt below with resident_id as its PK (recreated against the
        // new key in ensureClinicalTables()), or the dangling reference to
        // the now-gone "id" column makes SQLite reject any later DELETE
        // against residents with "foreign key mismatch".
        Schema::dropIfExists('adult_immunization');
        ErdRiskAssessmentChildSchema::drop();
        Schema::dropIfExists('risk_assessment');
        Schema::dropIfExists('family_planning');
        Schema::dropIfExists('maternal_care');
        Schema::dropIfExists('environmental_sanitation');
        Schema::dropIfExists('medical_history');
        Schema::dropIfExists('disability_type');
        Schema::dropIfExists('timbang_records');
        Schema::dropIfExists('residents');
        Schema::dropIfExists('households');
        Schema::dropIfExists('maternal_pregnancies');
        Schema::dropIfExists('household_environmental_profiles');
        Schema::dropIfExists('worker_appointment_zones');
        Schema::dropIfExists('worker_appointments');
        Schema::dropIfExists('user_management');
        Schema::dropIfExists('operation_timbang_measurements');

        self::ensureUserManagement();
        self::ensureHouseholds();
        self::ensureResidents();
        self::ensureClinicalTables();
        self::ensureResidentAccounts();
        self::ensureRecordRequests();
        self::ensureTimbangRecords();

        ChildNutritionErdMode::resetCachedState();
        DewormingErdMode::resetCachedState();
        MaternalCareErdMode::resetCachedState();
        NutritionSupplementationErdMode::resetCachedState();
        RiskAssessmentErdMode::resetCachedState();
        RecordRequestErdMode::resetCachedState();
        UserManagementErdMode::resetCachedState();
        DeathRecordsErdMode::resetCachedState();
    }

    private static function ensureTimbangRecords(): void
    {
        $migration = include database_path('migrations/2026_09_12_100000_create_timbang_records_table.php');
        $migration->up();
    }

    private static function ensureResidentAccounts(): void
    {
        Schema::create('resident_accounts', function (Blueprint $table): void {
            $table->id('account_id');
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('zone_purok', 20)->nullable();
            $table->string('email', 150)->unique();
            $table->string('password', 255);
            $table->timestamps();
        });
    }

    private static function ensureRecordRequests(): void
    {
        Schema::create('record_requests', function (Blueprint $table): void {
            $table->id('request_id');
            $table->unsignedBigInteger('account_id');
            $table->string('household_no_submitted', 50);
            $table->string('zone_submitted', 20);
            $table->string('relationship_submitted', 50);
            $table->string('first_name_submitted', 100);
            $table->string('middle_name_submitted', 100);
            $table->string('last_name_submitted', 100);
            $table->string('mobile_number_submitted', 20);
            $table->string('email_submitted', 150);
            $table->string('submitter_ip', 45)->nullable();
            $table->unsignedBigInteger('matched_resident_id')->nullable();
            $table->string('status');
            $table->text('decision_reason')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    private static function ensureUserManagement(): void
    {
        Schema::create('user_management', function (Blueprint $table): void {
            $table->id('user_id');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->string('sex')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('civil_status')->nullable();
            $table->string('nationality')->nullable();
            $table->string('mobile_number', 32)->nullable();
            $table->string('email')->unique();
            $table->string('username')->unique();
            $table->string('house_no')->nullable();
            $table->string('street')->nullable();
            $table->string('purok_zone')->nullable();
            $table->string('barangay')->nullable();
            $table->string('municipality_city')->nullable();
            $table->string('province')->nullable();
            $table->string('zip_code')->nullable();
            $table->string('password');
            $table->string('status')->default('Active');
            $table->boolean('must_change_password')->default(false);
            $table->string('photo_path')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('worker_appointments', function (Blueprint $table): void {
            $table->id('appointment_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->string('assigned_barangay')->nullable();
            $table->string('assigned_zone')->nullable();
            $table->date('date_appointed')->nullable();
            $table->date('end_of_appointment')->nullable();
            $table->timestamps();
        });

        self::ensureWorkerAppointmentZones();
    }

    public static function ensureWorkerAppointmentZones(): void
    {
        if (! Schema::hasTable('worker_appointments') || Schema::hasTable('worker_appointment_zones')) {
            return;
        }

        $appointmentKey = Schema::hasColumn('worker_appointments', 'appointment_id')
            ? 'appointment_id'
            : 'id';

        Schema::create('worker_appointment_zones', function (Blueprint $table) use ($appointmentKey): void {
            $table->id('worker_appointment_zone_id');
            $table->unsignedBigInteger('appointment_id');
            $table->string('assigned_zone', 20);
            $table->timestamps();

            $table->unique(['appointment_id', 'assigned_zone'], 'uq_worker_appt_zone');
            $table->foreign('appointment_id', 'fk_worker_appt_zones_appt')
                ->references($appointmentKey)
                ->on('worker_appointments')
                ->cascadeOnDelete();
        });
    }

    private static function ensureHouseholds(): void
    {
        Schema::create('households', function (Blueprint $table): void {
                $table->id('household_id');
                $table->string('household_no')->unique();
                $table->string('purok')->nullable();
                $table->decimal('latitude', 10, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->string('household_type')->nullable();
                $table->date('date_registered')->nullable();
                $table->timestamps();
            });
    }

    private static function ensureResidents(): void
    {
        if (! Schema::hasTable('occupation')) {
            Schema::create('occupation', function (Blueprint $table): void {
                $table->id('occupation_id');
                $table->string('occupation_name');
            });
        }

        if (! Schema::hasTable('religion')) {
            Schema::create('religion', function (Blueprint $table): void {
                $table->id('religion_id');
                $table->string('religion_name');
            });
        }

        Schema::create('residents', function (Blueprint $table): void {
                $table->id('resident_id');
                $table->unsignedBigInteger('household_id');
                $table->string('first_name');
                $table->string('middle_name')->nullable();
                $table->string('last_name');
                $table->string('relation_to_household_head')->nullable();
                $table->date('birthday');
                $table->string('sex');
                $table->string('civil_status');
                $table->unsignedBigInteger('occupation_id')->nullable();
                $table->string('occupation_other')->nullable();
                $table->unsignedBigInteger('religion_id')->nullable();
                $table->string('religion_other')->nullable();
                $table->string('educational_attainment')->nullable();
                $table->string('monthly_income')->nullable();
                $table->string('philhealth_number', 12)->nullable();
                $table->boolean('is_fp_user')->default(false);
                $table->timestamps();
            });
    }

    private static function ensureClinicalTables(): void
    {
        Schema::create('disability_type', function (Blueprint $table): void {
            $table->id('disability_type_id');
            $table->unsignedBigInteger('resident_id');
            $table->boolean('no_disability')->default(false);
            $table->boolean('intellectual_disability')->default(false);
            $table->boolean('mental_disability')->default(false);
            $table->boolean('physical_disability')->default(false);
            $table->boolean('other_disability')->default(false);
            $table->string('other_disability_specify')->nullable();
            $table->timestamps();
        });

        Schema::create('medical_history', function (Blueprint $table): void {
            $table->id('medical_history_id');
            $table->unsignedBigInteger('resident_id');
            $table->boolean('no_medical_history')->default(false);
            $table->boolean('diabetes_mellitus')->default(false);
            $table->boolean('heart_disease')->default(false);
            $table->boolean('hypertension')->default(false);
            $table->boolean('kidney_disease')->default(false);
            $table->boolean('tuberculosis')->default(false);
            $table->string('other_medical_history')->nullable();
            $table->timestamps();
        });

        Schema::create('child_nutrition', function (Blueprint $table): void {
            $table->id('child_nutrition_id');
            $table->unsignedBigInteger('resident_id');
            $table->decimal('length_at_birth_cm', 8, 2)->nullable();
            $table->decimal('weight_at_birth_kg', 8, 2)->nullable();
            $table->date('initiated_breastfeeding_date')->nullable();
            $table->timestamps();
        });

        Schema::create('nutrition_supplementation', function (Blueprint $table): void {
            $table->id('supplementation_id');
            $table->unsignedBigInteger('child_nutrition_id');
            $table->string('supplement_type')->nullable();
            $table->string('age_group')->nullable();
            $table->unsignedTinyInteger('dose_number')->nullable();
            $table->date('date_given')->nullable();
            $table->timestamps();
        });

        Schema::create('deworming_records', function (Blueprint $table): void {
            $table->id('deworming_id');
            $table->unsignedBigInteger('resident_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('deworming_round');
            $table->date('date_given')->nullable();
            $table->string('remarks')->nullable();
            $table->timestamps();
            $table->unique(['resident_id', 'year', 'deworming_round']);
        });

        Schema::create('adult_immunization', function (Blueprint $table): void {
            $table->id('adult_immunization_id');
            $table->unsignedBigInteger('resident_id');
            $table->string('vaccine_type', 64);
            $table->date('date_given');
            $table->timestamps();
            $table->unique(['resident_id', 'vaccine_type']);
        });

        Schema::create('risk_assessment', function (Blueprint $table): void {
            $table->id('risk_assessment_id');
            $table->unsignedBigInteger('resident_id');
            $table->unsignedBigInteger('user_id');
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->unsignedSmallInteger('systolic_blood_pressure')->nullable();
            $table->unsignedSmallInteger('diastolic_blood_pressure')->nullable();
            $table->string('tobacco_vape_usage')->nullable();
            $table->string('alcohol_intake')->nullable();
            $table->string('dietary_habits')->nullable();
            $table->string('physical_activity')->nullable();
            $table->timestamps();
        });

        ErdRiskAssessmentChildSchema::create();

        Schema::create('family_planning', function (Blueprint $table): void {
            $table->id('family_planning_id');
            $table->unsignedBigInteger('resident_id');
            $table->date('visitation_date')->nullable();
            $table->timestamps();
        });

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

        Schema::create('environmental_sanitation', function (Blueprint $table): void {
            $table->id('environmental_sanitation_id');
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('user_id');
            $table->string('water_supply_status')->nullable();
            $table->string('water_source_location')->nullable();
            $table->unsignedTinyInteger('water_availability')->nullable();
            $table->date('microbiological_validation_date')->nullable();
            $table->string('microbio_result')->nullable();
            $table->date('physico_chem_test_date')->nullable();
            $table->string('physico_chem_result')->nullable();
            $table->string('toilet_type')->nullable();
            $table->unsignedTinyInteger('open_defecation_place')->nullable();
            $table->unsignedTinyInteger('shared_toilet')->nullable();
            $table->string('sewage_disposal_method')->nullable();
            $table->timestamps();
        });

        Schema::create('death_records', function (Blueprint $table): void {
            $table->id('death_record_id');
            $table->unsignedBigInteger('resident_id')->unique('uq_death_resident');
            $table->text('cause_of_death');
            $table->date('date_of_death');
            $table->string('death_certificate_no', 50);
            $table->string('death_certificate_file_path', 255);
            $table->string('verification_status')->default('Pending Verification');
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('submitted_by');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }
}
