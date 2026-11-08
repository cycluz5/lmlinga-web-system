<?php

namespace Tests\Feature;

use App\Models\DeathRequest;
use App\Models\DisabilityType;
use App\Models\Household;
use App\Models\MedicalHistory;
use App\Models\Resident;
use App\Models\User;
use App\Support\AtRestColumnMigrator;
use App\Support\AtRestNarrativeField;
use App\Support\AtRestRecord;
use App\Support\DeathRecordService;
use App\Support\DeathRecordsErdMode;
use App\Support\HealthRecordsDeath;
use App\Support\ResidentMemberIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class AtRestHealthRecordColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('death_certificates');
        ClientTestingErdSchema::ensure();
        DeathRecordsErdMode::resetCachedState();
    }

    public function test_medical_history_and_disability_are_ciphertext_at_rest_and_booleans_in_eloquent(): void
    {
        $resident = $this->seedResident();

        MedicalHistory::query()->create([
            'resident_id' => $resident->getKey(),
            'no_medical_history' => false,
            'diabetes_mellitus' => true,
            'heart_disease' => false,
            'hypertension' => true,
            'kidney_disease' => false,
            'tuberculosis' => false,
            'other_medical_history' => 'Asthma since childhood',
        ]);
        DisabilityType::query()->create([
            'resident_id' => $resident->getKey(),
            'no_disability' => false,
            'intellectual_disability' => false,
            'mental_disability' => true,
            'physical_disability' => false,
            'other_disability' => true,
            'other_disability_specify' => 'Hearing impairment',
        ]);

        $medicalRaw = DB::table('medical_history')->first();
        $disabilityRaw = DB::table('disability_type')->first();
        foreach (['no_medical_history', 'diabetes_mellitus', 'hypertension', 'other_medical_history'] as $column) {
            $this->assertTrue(AtRestNarrativeField::isSealed($medicalRaw->{$column}), "medical_history.{$column}");
        }
        foreach (['mental_disability', 'physical_disability', 'other_disability_specify'] as $column) {
            $this->assertTrue(AtRestNarrativeField::isSealed($disabilityRaw->{$column}), "disability_type.{$column}");
        }
        $this->assertStringNotContainsString('Asthma', $medicalRaw->other_medical_history);

        $medical = MedicalHistory::query()->firstOrFail();
        $this->assertTrue($medical->diabetes_mellitus);
        $this->assertTrue($medical->hypertension);
        $this->assertFalse($medical->tuberculosis);
        $this->assertSame('Asthma since childhood', $medical->other_medical_history);

        $disability = DisabilityType::query()->firstOrFail();
        $this->assertTrue($disability->mental_disability);
        $this->assertFalse($disability->physical_disability);
        $this->assertSame('Hearing impairment', $disability->other_disability_specify);
    }

    public function test_legacy_plaintext_medical_history_rows_still_read(): void
    {
        $resident = $this->seedResident();
        DB::table('medical_history')->insert([
            'resident_id' => $resident->getKey(),
            'no_medical_history' => 0,
            'diabetes_mellitus' => 1,
            'heart_disease' => 0,
            'hypertension' => 0,
            'kidney_disease' => 0,
            'tuberculosis' => 0,
            'other_medical_history' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $medical = MedicalHistory::query()->firstOrFail();
        $this->assertTrue($medical->diabetes_mellitus);
        $this->assertFalse($medical->hypertension);
        $this->assertNull($medical->other_medical_history);
    }

    public function test_death_cause_and_registry_number_are_ciphertext_and_cause_filter_matches(): void
    {
        $record = $this->submitDeathRecord($this->seedResident(), 'Cardiorespiratory Arrest', 'DC-2026-0001');
        $other = $this->submitDeathRecord($this->seedResident('Lito', 'Garcia'), 'Pneumonia', 'DC-2026-0002');

        $raw = DB::table('death_records')->where('death_record_id', $record->getKey())->first();
        $this->assertTrue(AtRestNarrativeField::isSealed($raw->cause_of_death));
        $this->assertTrue(AtRestNarrativeField::isSealed($raw->death_certificate_no));
        $this->assertStringNotContainsString('Cardiorespiratory', $raw->cause_of_death);

        $record = DeathRequest::query()->findOrFail($record->getKey());
        $this->assertSame('Cardiorespiratory Arrest', $record->cause_of_death);
        $this->assertSame('DC-2026-0001', $record->registry_no);
        $this->assertSame('DC-2026-0001', $record->displayRegistryNo());

        $filtered = HealthRecordsDeath::applyListingFilters(HealthRecordsDeath::listingQuery(), [
            'cause' => 'cardiorespiratory arrest',
        ])->get();
        $this->assertSame([$record->getKey()], $filtered->map->getKey()->all());

        $pneumonia = HealthRecordsDeath::applyListingFilters(HealthRecordsDeath::listingQuery(), [
            'cause' => 'Pneumonia',
        ])->get();
        $this->assertSame([$other->getKey()], $pneumonia->map->getKey()->all());
    }

    public function test_death_rejection_reason_is_plaintext(): void
    {
        $record = $this->submitDeathRecord($this->seedResident(), 'Cardiorespiratory Arrest', 'DC-2026-0003');

        app(DeathRecordService::class)->reject($record, 'Registry number is unreadable.');

        $this->assertSame(
            'Registry number is unreadable.',
            DB::table('death_records')->where('death_record_id', $record->getKey())->value('rejection_reason')
        );
    }

    public function test_migrator_encrypts_legacy_rows_and_rollback_restores_plaintext(): void
    {
        $resident = $this->seedResident();
        DB::table('medical_history')->insert([
            'resident_id' => $resident->getKey(),
            'no_medical_history' => 0,
            'diabetes_mellitus' => 1,
            'heart_disease' => 0,
            'hypertension' => 1,
            'kidney_disease' => 0,
            'tuberculosis' => 0,
            'other_medical_history' => 'Gout',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migrator = new AtRestColumnMigrator;
        $migrator->encrypt('medical_history');
        $migrator->encrypt('medical_history');

        $sealed = DB::table('medical_history')->first();
        $this->assertTrue(AtRestNarrativeField::isSealed($sealed->hypertension));
        $this->assertTrue(AtRestNarrativeField::isSealed($sealed->other_medical_history));
        $opened = AtRestRecord::openRow('medical_history', $sealed);
        $this->assertSame(1, $opened->hypertension);
        $this->assertSame(0, $opened->tuberculosis);
        $this->assertSame('Gout', $opened->other_medical_history);

        $migrator->decrypt('medical_history');

        $plain = DB::table('medical_history')->first();
        $this->assertSame('1', (string) $plain->hypertension);
        $this->assertSame('0', (string) $plain->tuberculosis);
        $this->assertSame('Gout', $plain->other_medical_history);
    }

    public function test_phase_zero_migration_decrypts_retired_narratives(): void
    {
        $resident = $this->seedResident();
        // Live ERD shape (fp_id + remarks); the client-testing harness table is narrower.
        Schema::dropIfExists('family_planning');
        Schema::create('family_planning', function (Blueprint $table): void {
            $table->id('fp_id');
            $table->unsignedBigInteger('resident_id');
            $table->date('visitation_date');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
        DB::table('family_planning')->insert([
            'resident_id' => $resident->getKey(),
            'visitation_date' => '2026-08-01',
            'remarks' => AtRestNarrativeField::seal('Counseling provided', 'family_planning', 'remarks'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $record = $this->submitDeathRecord($resident, 'Stroke', 'DC-2026-0004');
        DB::table('death_records')->where('death_record_id', $record->getKey())->update([
            'rejection_reason' => AtRestNarrativeField::seal('Blurred scan', 'death_records', 'rejection_reason'),
        ]);

        $migration = require database_path('migrations/2026_09_24_100000_decrypt_family_planning_remarks_and_death_rejection_reason.php');
        $migration->up();

        $this->assertSame('Counseling provided', DB::table('family_planning')->value('remarks'));
        $this->assertSame('Blurred scan', DB::table('death_records')->value('rejection_reason'));

        $migration->down();

        $this->assertTrue(AtRestNarrativeField::isSealed(DB::table('family_planning')->value('remarks')));
        $this->assertTrue(AtRestNarrativeField::isSealed(DB::table('death_records')->value('rejection_reason')));
    }

    public function test_verify_command_reports_plaintext_until_encrypted(): void
    {
        $resident = $this->seedResident();
        DB::table('disability_type')->insert([
            'resident_id' => $resident->getKey(),
            'no_disability' => 1,
            'intellectual_disability' => 0,
            'mental_disability' => 0,
            'physical_disability' => 0,
            'other_disability' => 0,
            'other_disability_specify' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('lmlinga:at-rest:verify')->assertFailed();

        (new AtRestColumnMigrator)->encryptAll();

        $this->artisan('lmlinga:at-rest:verify')->assertSuccessful();
    }

    private function seedResident(string $firstName = 'Ramon', string $lastName = 'Bautista'): Resident
    {
        $household = Household::query()->create([
            'household_no' => 'HH-'.str_pad((string) random_int(100, 999), 3, '0', STR_PAD_LEFT),
            'purok' => '3',
        ]);

        return Resident::query()->create([
            'household_id' => $household->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birthday' => '1985-01-15',
            'sex' => 'Male',
            'civil_status' => 'Single',
        ]);
    }

    private function submitDeathRecord(Resident $resident, string $cause, string $registryNo): DeathRequest
    {
        $this->actingAsErdStaff();
        $resident->load('household');

        return app(DeathRecordService::class)->submit(
            ['householdNo' => $resident->household->household_no],
            ['id' => ResidentMemberIdentity::memberIdFor($resident)],
            [
                'cause_of_death' => $cause,
                'date_of_death' => '2026-08-15',
                'registry_no' => $registryNo,
            ],
            UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
            $resident
        );
    }

    private function actingAsErdStaff(): void
    {
        $userId = (int) DB::table('user_management')->insertGetId([
            'first_name' => 'Angela',
            'last_name' => 'Reyes',
            'email' => fake()->unique()->safeEmail(),
            'username' => fake()->unique()->userName(),
            'password' => bcrypt('password'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs(User::query()->findOrFail($userId));
    }
}
