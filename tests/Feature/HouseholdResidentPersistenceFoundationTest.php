<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DB-05 Phase 1 — households / residents persistence foundation.
 * Does not cut over DemoCatalog or Household Profiling UI.
 */
class HouseholdResidentPersistenceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_households_and_residents_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('households'));
        $this->assertTrue(Schema::hasTable('residents'));
    }

    public function test_household_persists_required_core_contract(): void
    {
        $household = Household::query()->create([
            'household_no' => 'HH-151',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-21',
            'address' => 'Layuan St., Brgy. La Medalla',
            'latitude' => 13.38110000,
            'longitude' => 123.43060000,
            'accomplished_by' => 'Lani Magistrado (BHW)',
        ]);

        $this->assertDatabaseHas('households', [
            'id' => $household->id,
            'household_no' => 'HH-151',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'accomplished_by' => 'Lani Magistrado (BHW)',
        ]);

        $this->assertTrue($household->date_registered->equalTo('2026-01-21'));
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);
    }

    public function test_household_no_is_unique(): void
    {
        Household::factory()->create(['household_no' => 'HH-200']);

        $this->expectException(QueryException::class);

        Household::factory()->create(['household_no' => 'HH-200']);
    }

    public function test_latitude_and_longitude_may_be_null(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-201',
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->assertNull($household->fresh()->latitude);
        $this->assertNull($household->fresh()->longitude);
    }

    public function test_resident_persists_belonging_to_household(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-151']);

        $resident = Resident::query()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-001',
            'last_name' => 'Reyes',
            'first_name' => 'Kristine',
            'middle_name' => null,
            'relation' => 'Head',
            'birthday' => '1991-05-04',
            'sex' => 'Male',
            'relationship_status' => 'Married',
            'occupation' => 'Nurse',
            'monthly_income' => '30,000 – 49,999',
            'religion' => 'Roman Catholic',
            'education' => 'College Graduate',
            'fp_user' => 'No',
            'philhealth' => null,
            'disability' => ['none'],
            'disability_others' => null,
            'medical_history' => ['none'],
            'medical_others' => null,
        ]);

        $this->assertDatabaseHas('residents', [
            'id' => $resident->id,
            'household_id' => $household->id,
            'member_no' => 'MB-001',
            'last_name' => 'Reyes',
            'first_name' => 'Kristine',
            'relation' => 'Head',
            'fp_user' => 'No',
        ]);
    }

    public function test_member_no_is_globally_unique(): void
    {
        $first = Household::factory()->create(['household_no' => 'HH-210']);
        $second = Household::factory()->create(['household_no' => 'HH-211']);

        Resident::factory()->create([
            'household_id' => $first->id,
            'member_no' => 'MB-050',
        ]);

        $this->expectException(QueryException::class);

        Resident::factory()->create([
            'household_id' => $second->id,
            'member_no' => 'MB-050',
        ]);
    }

    public function test_resident_belongs_to_household_relationship(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-220']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-060',
        ]);

        $this->assertTrue($resident->household->is($household));
        $this->assertSame('HH-220', $resident->household->household_no);
    }

    public function test_household_has_many_residents_relationship(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-221']);

        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-061',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-062',
        ]);

        $this->assertCount(2, $household->residents);
        $this->assertEqualsCanonicalizing(
            ['MB-061', 'MB-062'],
            $household->residents->pluck('member_no')->all()
        );
    }

    public function test_birthday_is_cast_to_date(): void
    {
        $resident = Resident::factory()->create([
            'birthday' => '2020-11-03',
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $resident->birthday);
        $this->assertTrue($resident->birthday->equalTo('2020-11-03'));
    }

    public function test_disability_json_is_cast_to_array(): void
    {
        $resident = Resident::factory()->create([
            'disability' => ['Intellectual Disability (ID)', 'others'],
            'disability_others' => 'Specify example',
        ]);

        $fresh = $resident->fresh();
        $this->assertIsArray($fresh->disability);
        $this->assertSame(['Intellectual Disability (ID)', 'others'], $fresh->disability);
        $this->assertSame('Specify example', $fresh->disability_others);
    }

    public function test_medical_history_json_is_cast_to_array(): void
    {
        $resident = Resident::factory()->create([
            'medical_history' => ['Hypertension', 'Diabetes Mellitus'],
            'medical_others' => null,
        ]);

        $fresh = $resident->fresh();
        $this->assertIsArray($fresh->medical_history);
        $this->assertSame(['Hypertension', 'Diabetes Mellitus'], $fresh->medical_history);
    }

    public function test_soft_deleting_resident_does_not_physically_remove_row(): void
    {
        $this->assertFalse(
            in_array(SoftDeletes::class, class_uses_recursive(Resident::class), true)
        );

        $this->markTestSkipped(
            'Household/resident archival requires Eloquent SoftDeletes. Live ERD has no deleted_at and models do not use SoftDeletes.'
        );
    }

    public function test_soft_deleting_household_does_not_physically_remove_row(): void
    {
        $this->assertFalse(
            in_array(SoftDeletes::class, class_uses_recursive(Household::class), true)
        );

        $this->markTestSkipped(
            'Household/resident archival requires Eloquent SoftDeletes. Live ERD has no deleted_at and models do not use SoftDeletes.'
        );
    }

    public function test_physical_household_deletion_is_restricted_when_residents_exist(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-240']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-080',
        ]);

        $this->expectException(QueryException::class);

        $household->forceDelete();
    }

    public function test_philhealth_may_be_null(): void
    {
        $resident = Resident::factory()->create([
            'member_no' => 'MB-081',
            'philhealth' => null,
        ]);

        $this->assertNull($resident->fresh()->philhealth);
        $this->assertDatabaseHas('residents', [
            'member_no' => 'MB-081',
            'philhealth' => null,
        ]);
    }

    public function test_fp_user_preserves_na_without_boolean_coercion(): void
    {
        $resident = Resident::factory()->create([
            'member_no' => 'MB-082',
            'fp_user' => 'N/A',
        ]);

        $fresh = $resident->fresh();
        $this->assertSame('N/A', $fresh->fp_user);
        $this->assertIsString($fresh->fp_user);
        $this->assertNotSame(true, $fresh->fp_user);
        $this->assertNotSame(false, $fresh->fp_user);
    }

    public function test_derived_age_and_duplicate_household_head_state_are_not_persisted(): void
    {
        $this->assertFalse(Schema::hasColumn('residents', 'age'));
        $this->assertFalse(Schema::hasColumn('households', 'houseHead'));
        $this->assertFalse(Schema::hasColumn('residents', 'is_household_head'));
    }
}
