<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\SchoolImmunization;
use App\Models\SchoolImmunizationDose;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DB-09 Phase 1 — School-Based Immunization persistence foundation
 * (schema, models, relationships, constraints only).
 */
class SchoolBasedImmunizationPersistenceFoundationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedPersistedChild(array $residentOverrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-930',
            'zone' => 'Zone 1',
            'street' => 'SBI St.',
        ]);

        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => 'MB-930',
            'first_name' => 'School',
            'last_name' => 'Child',
            'relation' => 'Son',
            'birthday' => now()->subYears(10)->format('Y-m-d'),
            'sex' => 'Female',
        ], $residentOverrides));

        return ['household' => $household, 'resident' => $resident];
    }

    public function test_school_immunizations_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('school_immunizations'));
    }

    public function test_school_immunizations_header_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('school_immunizations', 'id'));
        $this->assertTrue(Schema::hasColumn('school_immunizations', 'resident_id'));
        $this->assertTrue(Schema::hasColumn('school_immunizations', 'selected_vaccine_types'));
        $this->assertTrue(Schema::hasColumn('school_immunizations', 'created_at'));
        $this->assertTrue(Schema::hasColumn('school_immunizations', 'updated_at'));
    }

    public function test_school_immunization_doses_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('school_immunization_doses'));
    }

    public function test_school_immunization_doses_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('school_immunization_doses', 'id'));
        $this->assertTrue(Schema::hasColumn('school_immunization_doses', 'school_immunization_id'));
        $this->assertTrue(Schema::hasColumn('school_immunization_doses', 'slot_group'));
        $this->assertTrue(Schema::hasColumn('school_immunization_doses', 'slot_key'));
        $this->assertTrue(Schema::hasColumn('school_immunization_doses', 'date_given'));
        $this->assertTrue(Schema::hasColumn('school_immunization_doses', 'created_at'));
        $this->assertTrue(Schema::hasColumn('school_immunization_doses', 'updated_at'));
    }

    public function test_resident_id_references_residents_table(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);

        $this->assertDatabaseHas('school_immunizations', [
            'id' => $record->id,
            'resident_id' => $resident->id,
        ]);
        $this->assertSame($resident->id, $record->fresh()->resident_id);
    }

    public function test_one_school_immunization_header_per_resident(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);

        $this->expectException(QueryException::class);

        SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);
    }

    public function test_dose_foreign_key_references_school_immunizations(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);

        $dose = SchoolImmunizationDose::factory()->create([
            'school_immunization_id' => $record->id,
            'slot_group' => 'grade-1',
            'slot_key' => 'td',
        ]);

        $this->assertDatabaseHas('school_immunization_doses', [
            'id' => $dose->id,
            'school_immunization_id' => $record->id,
        ]);
        $this->assertSame($record->id, $dose->fresh()->school_immunization_id);
    }

    public function test_deleting_school_immunization_header_cascades_dose_rows(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);

        SchoolImmunizationDose::factory()->create([
            'school_immunization_id' => $record->id,
            'slot_group' => 'hpv',
            'slot_key' => '1',
            'date_given' => '2025-06-01',
        ]);

        $recordId = $record->id;
        $record->delete();

        $this->assertDatabaseMissing('school_immunizations', ['id' => $recordId]);
        $this->assertDatabaseMissing('school_immunization_doses', [
            'school_immunization_id' => $recordId,
        ]);
        $this->assertDatabaseHas('residents', ['id' => $resident->id]);
    }

    public function test_duplicate_dose_slot_is_rejected(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);

        SchoolImmunizationDose::factory()->create([
            'school_immunization_id' => $record->id,
            'slot_group' => 'grade-7',
            'slot_key' => 'mr',
            'date_given' => '2025-03-01',
        ]);

        $this->expectException(QueryException::class);

        SchoolImmunizationDose::factory()->create([
            'school_immunization_id' => $record->id,
            'slot_group' => 'grade-7',
            'slot_key' => 'mr',
            'date_given' => '2025-04-01',
        ]);
    }

    public function test_school_immunization_belongs_to_resident(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
            'selected_vaccine_types' => ['grade1_td'],
        ]);

        $this->assertSame($resident->id, $record->resident->id);
        $this->assertInstanceOf(Resident::class, $record->resident);
    }

    public function test_resident_has_one_school_immunization(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
            'selected_vaccine_types' => ['hpv_1', 'hpv_2'],
        ]);

        $resident->refresh();
        $this->assertTrue($resident->schoolImmunization()->exists());
        $this->assertSame($record->id, $resident->schoolImmunization->id);
    }

    public function test_school_immunization_has_many_doses(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);

        SchoolImmunizationDose::factory()->create([
            'school_immunization_id' => $record->id,
            'slot_group' => 'grade-1',
            'slot_key' => 'td',
            'date_given' => null,
        ]);

        SchoolImmunizationDose::factory()->create([
            'school_immunization_id' => $record->id,
            'slot_group' => 'grade-1',
            'slot_key' => 'mr',
            'date_given' => '2025-02-01',
        ]);

        $this->assertCount(2, $record->fresh()->doses);
        $this->assertInstanceOf(SchoolImmunizationDose::class, $record->doses->first());
    }

    public function test_school_immunization_dose_belongs_to_school_immunization(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);

        $dose = SchoolImmunizationDose::factory()->create([
            'school_immunization_id' => $record->id,
            'slot_group' => 'hpv',
            'slot_key' => '2',
        ]);

        $this->assertSame($record->id, $dose->schoolImmunization->id);
        $this->assertInstanceOf(SchoolImmunization::class, $dose->schoolImmunization);
    }

    public function test_date_given_accepts_null(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);

        $dose = SchoolImmunizationDose::factory()->create([
            'school_immunization_id' => $record->id,
            'slot_group' => 'grade-7',
            'slot_key' => 'td',
            'date_given' => null,
        ]);

        $this->assertNull($dose->fresh()->date_given);
    }

    public function test_selected_vaccine_types_accepts_null(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
            'selected_vaccine_types' => null,
        ]);

        $this->assertNull($record->fresh()->selected_vaccine_types);
    }

    public function test_selected_vaccine_types_json_cast_round_trip(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $keys = ['grade1_td', 'grade7_mr', 'hpv_2'];

        $record = SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
            'selected_vaccine_types' => $keys,
        ]);

        $fresh = $record->fresh();
        $this->assertSame($keys, $fresh->selected_vaccine_types);
        $this->assertIsArray($fresh->selected_vaccine_types);
    }

    public function test_all_six_selectable_vaccine_type_keys_can_be_represented(): void
    {
        $this->assertSame(
            [
                'grade1_td',
                'grade1_mr',
                'grade7_td',
                'grade7_mr',
                'hpv_1',
                'hpv_2',
            ],
            SchoolImmunization::SELECTABLE_TYPE_KEYS
        );

        ['resident' => $resident] = $this->seedPersistedChild();

        $record = SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
            'selected_vaccine_types' => SchoolImmunization::SELECTABLE_TYPE_KEYS,
        ]);

        $this->assertSame(
            SchoolImmunization::SELECTABLE_TYPE_KEYS,
            $record->fresh()->selected_vaccine_types
        );
    }

    public function test_all_six_known_dose_slot_combinations_can_be_represented(): void
    {
        $this->assertCount(6, SchoolImmunization::DOSE_SLOTS);

        ['resident' => $resident] = $this->seedPersistedChild();

        $record = SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);

        foreach (SchoolImmunization::DOSE_SLOTS as $slot) {
            SchoolImmunizationDose::factory()->create([
                'school_immunization_id' => $record->id,
                'slot_group' => $slot['slot_group'],
                'slot_key' => $slot['slot_key'],
                'date_given' => null,
            ]);
        }

        $this->assertCount(6, $record->fresh()->doses);

        foreach (SchoolImmunization::DOSE_SLOTS as $slot) {
            $this->assertTrue(
                $record->doses()
                    ->where('slot_group', $slot['slot_group'])
                    ->where('slot_key', $slot['slot_key'])
                    ->exists()
            );
        }
    }

    public function test_checkbox_selection_and_date_given_are_not_db_coupled(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        // Checkboxes selected with no dose rows / dates.
        $checkboxOnly = SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
            'selected_vaccine_types' => ['grade1_td', 'hpv_1'],
        ]);
        $this->assertSame(['grade1_td', 'hpv_1'], $checkboxOnly->fresh()->selected_vaccine_types);
        $this->assertCount(0, $checkboxOnly->fresh()->doses);

        // Dose dates present with empty checkbox selection.
        $otherResident = Resident::factory()->create([
            'household_id' => $resident->household_id,
            'member_no' => 'MB-931',
            'first_name' => 'Other',
            'last_name' => 'Student',
        ]);

        $dateOnly = SchoolImmunization::factory()->create([
            'resident_id' => $otherResident->id,
            'selected_vaccine_types' => null,
        ]);

        SchoolImmunizationDose::factory()->create([
            'school_immunization_id' => $dateOnly->id,
            'slot_group' => 'grade-1',
            'slot_key' => 'td',
            'date_given' => '2025-01-10',
        ]);

        $this->assertNull($dateOnly->fresh()->selected_vaccine_types);
        $this->assertSame(
            '2025-01-10',
            $dateOnly->doses->first()->fresh()->date_given?->format('Y-m-d')
        );
    }

    public function test_ui_slot_constants_match_frozen_dose_structure(): void
    {
        $this->assertSame(['grade-1', 'grade-7', 'hpv'], SchoolImmunization::SLOT_GROUPS);
        $this->assertSame(
            [
                ['slot_group' => 'grade-1', 'slot_key' => 'td'],
                ['slot_group' => 'grade-1', 'slot_key' => 'mr'],
                ['slot_group' => 'grade-7', 'slot_key' => 'td'],
                ['slot_group' => 'grade-7', 'slot_key' => 'mr'],
                ['slot_group' => 'hpv', 'slot_key' => '1'],
                ['slot_group' => 'hpv', 'slot_key' => '2'],
            ],
            SchoolImmunization::DOSE_SLOTS
        );
    }
}
