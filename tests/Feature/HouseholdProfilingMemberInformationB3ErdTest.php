<?php

namespace Tests\Feature;

use App\Models\DisabilityType;
use App\Models\Household;
use App\Models\MedicalHistory;
use App\Models\Resident;
use App\Services\ResidentService;
use App\Support\HouseholdProfilingPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class HouseholdProfilingMemberInformationB3ErdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ClientTestingErdSchema::ensure();
        Household::resetResolvedKeyName();
        Resident::resetResolvedKeyName();
    }

    public function test_erd_other_philhealth_and_health_tables_persist(): void
    {
        $occupationOtherId = (int) DB::table('occupation')->insertGetId(
            ['occupation_name' => 'Other'],
            'occupation_id'
        );
        $religionOtherId = (int) DB::table('religion')->insertGetId(
            ['religion_name' => 'Other'],
            'religion_id'
        );
        $householdId = (int) DB::table('households')->insertGetId([
            'household_no' => 'HH-B3E1',
            'purok' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'household_id');

        $household = Household::query()->findOrFail($householdId);

        $resident = app(ResidentService::class)->create($household, [
            'last_name' => 'Santos',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'relation' => 'Head',
            'birthday' => '1990-03-15',
            'sex' => 'Female',
            'relationship_status' => 'Married',
            'occupation' => 'Other',
            'occupation_other' => 'Basket weaver',
            'monthly_income' => 'Below 5,000',
            'religion' => 'Other',
            'religion_other' => 'Seventh-day Adventist',
            'education' => 'High School Graduate',
            'fp_user' => 'Yes',
            'philhealth' => '555566667777',
            'disability' => ['Physical Disability (PD)', 'others'],
            'disability_others' => 'Hearing impairment',
            'medical_history' => ['Hypertension'],
            'medical_others' => null,
        ]);

        $this->assertSame($occupationOtherId, (int) $resident->occupation_id);
        $this->assertSame('Basket weaver', $resident->occupation_other);
        $this->assertSame($religionOtherId, (int) $resident->religion_id);
        $this->assertSame('Seventh-day Adventist', $resident->religion_other);
        $this->assertSame('555566667777', $resident->philhealth_number);
        $this->assertTrue((bool) $resident->is_fp_user);

        $disability = DisabilityType::query()->where('resident_id', $resident->getKey())->first();
        $this->assertNotNull($disability);
        $this->assertTrue((bool) $disability->physical_disability);
        $this->assertTrue((bool) $disability->other_disability);
        $this->assertSame('Hearing impairment', $disability->other_disability_specify);
        $this->assertFalse((bool) $disability->no_disability);

        $medical = MedicalHistory::query()->where('resident_id', $resident->getKey())->first();
        $this->assertNotNull($medical);
        $this->assertTrue((bool) $medical->hypertension);
        $this->assertFalse((bool) $medical->no_medical_history);

        $presentation = HouseholdProfilingPresenter::memberFromModel($resident->fresh());
        $this->assertSame('Basket weaver', $presentation['occupation']);
        $this->assertSame('Other', $presentation['occupation_select']);
        $this->assertSame('Seventh-day Adventist', $presentation['religion']);
        $this->assertSame('555566667777', $presentation['philhealth']);
        $this->assertSame('Yes', $presentation['fp_user']);
        $this->assertContains('Physical Disability (PD)', $presentation['disability']);
        $this->assertContains('others', $presentation['disability']);
        $this->assertSame('Hearing impairment', $presentation['disability_others']);
        $this->assertSame(['Hypertension'], $presentation['medical_history']);
    }

    public function test_erd_other_without_lookup_row_clears_stale_fk_and_hydrates(): void
    {
        $farmerId = (int) DB::table('occupation')->insertGetId(
            ['occupation_name' => 'Farmer'],
            'occupation_id'
        );
        $catholicId = (int) DB::table('religion')->insertGetId(
            ['religion_name' => 'Roman Catholic'],
            'religion_id'
        );
        $householdId = (int) DB::table('households')->insertGetId([
            'household_no' => '121',
            'purok' => '2',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'household_id');
        $household = Household::query()->findOrFail($householdId);
        $service = app(ResidentService::class);

        $resident = $service->create($household, $this->erdMemberPayload([
            'occupation' => 'Farmer',
            'religion' => 'Roman Catholic',
            'philhealth' => '',
        ]));

        $this->assertSame($farmerId, (int) $resident->occupation_id);
        $this->assertNull($resident->occupation_other);
        $this->assertSame($catholicId, (int) $resident->religion_id);
        $this->assertNull($resident->philhealth_number);

        $resident = $service->update($resident, $this->erdMemberPayload([
            'occupation' => 'Other',
            'occupation_other' => 'Basket weaver',
            'religion' => 'Other',
            'religion_other' => 'Seventh-day Adventist',
            'philhealth' => '555566667777',
            'disability' => ['none'],
            'medical_history' => ['others'],
            'medical_others' => 'Asthma',
        ]));

        $this->assertNull($resident->occupation_id);
        $this->assertSame('Basket weaver', $resident->occupation_other);
        $this->assertNull($resident->religion_id);
        $this->assertSame('Seventh-day Adventist', $resident->religion_other);
        $this->assertSame('555566667777', $resident->philhealth_number);

        $presentation = HouseholdProfilingPresenter::memberFromModel($resident->fresh());
        $this->assertSame('Other', $presentation['occupation_select']);
        $this->assertSame('Basket weaver', $presentation['occupation_other']);
        $this->assertSame('Basket weaver', $presentation['occupation']);
        $this->assertSame('Other', $presentation['religion_select']);
        $this->assertSame('Seventh-day Adventist', $presentation['religion_other']);
        $this->assertContains('others', $presentation['medical_history']);
        $this->assertSame('Asthma', $presentation['medical_others']);

        $medical = MedicalHistory::query()->where('resident_id', $resident->getKey())->first();
        $this->assertNotNull($medical);
        $this->assertSame('Asthma', $medical->other_medical_history);
        $this->assertFalse((bool) $medical->no_medical_history);

        $resident = $service->update($resident, $this->erdMemberPayload([
            'occupation' => 'Teacher',
            'occupation_other' => 'Basket weaver',
            'religion' => 'Protestant',
            'religion_other' => 'Seventh-day Adventist',
            'philhealth' => '',
            'disability' => ['none'],
            'medical_history' => ['none'],
        ]));

        $this->assertNull($resident->occupation_id);
        $this->assertSame('Teacher', $resident->occupation_other);
        $this->assertNull($resident->religion_id);
        $this->assertSame('Protestant', $resident->religion_other);
        $this->assertNull($resident->philhealth_number);

        $presentation = HouseholdProfilingPresenter::memberFromModel($resident->fresh());
        $this->assertSame('Teacher', $presentation['occupation_select']);
        $this->assertSame('', $presentation['occupation_other']);
        $this->assertSame('Protestant', $presentation['religion_select']);
        $this->assertSame('', $presentation['religion_other']);
        $this->assertSame('Teacher', $presentation['occupation']);
        $this->assertSame('Protestant', $presentation['religion']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function erdMemberPayload(array $overrides = []): array
    {
        return array_merge([
            'last_name' => 'Santos',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'relation' => 'Head',
            'birthday' => '1990-03-15',
            'sex' => 'Female',
            'relationship_status' => 'Married',
            'occupation' => 'Farmer',
            'monthly_income' => 'Below 5,000',
            'religion' => 'Roman Catholic',
            'education' => 'High School Graduate',
            'fp_user' => 'No',
            'philhealth' => null,
            'disability' => ['none'],
            'disability_others' => null,
            'medical_history' => ['none'],
            'medical_others' => null,
        ], $overrides);
    }
}
