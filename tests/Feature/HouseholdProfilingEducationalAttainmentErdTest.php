<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Services\ResidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class HouseholdProfilingEducationalAttainmentErdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ClientTestingErdSchema::ensure();
        Household::resetResolvedKeyName();
        Resident::resetResolvedKeyName();
    }

    public function test_erd_persists_post_graduate_mapping_on_educational_attainment(): void
    {
        $this->assertFalse(Schema::hasColumn('residents', 'education'));
        $this->assertTrue(Schema::hasColumn('residents', 'educational_attainment'));

        $householdId = (int) DB::table('households')->insertGetId([
            'household_no' => 'HH-865',
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
            'occupation' => 'Teacher',
            'monthly_income' => 'Below 5,000',
            'religion' => 'Roman Catholic',
            'education' => 'Post-Graduate',
            'fp_user' => 'No',
            'philhealth' => null,
            'disability' => ['none'],
            'disability_others' => null,
            'medical_history' => ['none'],
            'medical_others' => null,
        ]);

        $this->assertSame('Post Graduate', $resident->educational_attainment);
        $this->assertArrayNotHasKey('education', $resident->getAttributes());
        $this->assertSame(
            'Post Graduate',
            DB::table('residents')->where('resident_id', $resident->getKey())->value('educational_attainment')
        );

        $updated = app(ResidentService::class)->update($resident, [
            'last_name' => 'Santos',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'relation' => 'Head',
            'birthday' => '1990-03-15',
            'sex' => 'Female',
            'relationship_status' => 'Married',
            'occupation' => 'Teacher',
            'monthly_income' => 'Below 5,000',
            'religion' => 'Roman Catholic',
            'education' => 'College Graduate',
            'fp_user' => 'No',
            'philhealth' => null,
            'disability' => ['none'],
            'disability_others' => null,
            'medical_history' => ['none'],
            'medical_others' => null,
        ]);

        $this->assertSame('College Graduate', $updated->educational_attainment);
    }
}
