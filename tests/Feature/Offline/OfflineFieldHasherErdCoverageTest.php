<?php

namespace Tests\Feature\Offline;

use App\Models\DisabilityType;
use App\Models\Household;
use App\Models\MedicalHistory;
use App\Models\Resident;
use App\Support\Offline\OfflineFieldHasher;
use App\Support\UserManagementErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class OfflineFieldHasherErdCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ClientTestingErdSchema::ensure();
        Household::resetResolvedKeyName();
        Resident::resetResolvedKeyName();
        UserManagementErdMode::resetCachedState();
    }

    public function test_erd_occupation_id_change_changes_the_resident_hash(): void
    {
        $teacherId = (int) DB::table('occupation')->insertGetId(
            ['occupation_name' => 'Teacher'],
            'occupation_id'
        );
        $farmerId = (int) DB::table('occupation')->insertGetId(
            ['occupation_name' => 'Farmer'],
            'occupation_id'
        );
        $resident = $this->createErdResident([
            'occupation_id' => $teacherId,
        ]);

        $before = OfflineFieldHasher::resident($resident);
        $snapshot = OfflineFieldHasher::residentSnapshot($resident);
        $this->assertSame($teacherId, $snapshot['occupation']);

        DB::table('residents')->where('resident_id', $resident->getKey())->update([
            'occupation_id' => $farmerId,
        ]);

        $this->assertNotSame($before, OfflineFieldHasher::resident($resident->fresh()));
    }

    public function test_erd_disability_and_medical_related_rows_are_included_in_the_hash(): void
    {
        $resident = $this->createErdResident();
        DisabilityType::query()->create([
            'resident_id' => $resident->getKey(),
            'no_disability' => true,
            'intellectual_disability' => false,
            'mental_disability' => false,
            'physical_disability' => false,
            'other_disability' => false,
        ]);
        MedicalHistory::query()->create([
            'resident_id' => $resident->getKey(),
            'no_medical_history' => true,
            'diabetes_mellitus' => false,
            'heart_disease' => false,
            'hypertension' => false,
            'kidney_disease' => false,
            'tuberculosis' => false,
        ]);

        $before = OfflineFieldHasher::resident($resident->fresh());

        DisabilityType::query()->where('resident_id', $resident->getKey())->update([
            'no_disability' => false,
            'physical_disability' => true,
        ]);
        $this->assertNotSame($before, OfflineFieldHasher::resident($resident->fresh()));

        $afterDisability = OfflineFieldHasher::resident($resident->fresh());
        MedicalHistory::query()->where('resident_id', $resident->getKey())->update([
            'no_medical_history' => false,
            'hypertension' => true,
        ]);
        $this->assertNotSame($afterDisability, OfflineFieldHasher::resident($resident->fresh()));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createErdResident(array $overrides = []): Resident
    {
        $householdId = (int) DB::table('households')->insertGetId([
            'household_no' => 'HH-HF1',
            'purok' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'household_id');

        $residentId = (int) DB::table('residents')->insertGetId(array_merge([
            'household_id' => $householdId,
            'first_name' => 'Ana',
            'last_name' => 'Santos',
            'middle_name' => 'Cruz',
            'relation_to_household_head' => 'Spouse',
            'birthday' => '1990-03-15',
            'sex' => 'Female',
            'civil_status' => 'Married',
            'monthly_income' => 'Below 5,000',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'resident_id');

        return Resident::query()->findOrFail($residentId);
    }
}
