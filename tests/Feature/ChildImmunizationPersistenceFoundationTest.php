<?php

namespace Tests\Feature;

use App\Models\ChildImmunization;
use App\Models\Household;
use App\Models\ImmunizationDose;
use App\Models\Resident;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DB-08 Phase 1 — Child Immunization persistence foundation
 * (schema, models, relationships, constraints only).
 */
class ChildImmunizationPersistenceFoundationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedPersistedChild(array $residentOverrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-830',
            'zone' => 'Zone 1',
            'street' => 'Imm St.',
        ]);

        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => 'MB-830',
            'first_name' => 'Baby',
            'last_name' => 'Immune',
            'relation' => 'Son',
            'birthday' => now()->subMonths(8)->format('Y-m-d'),
            'sex' => 'Male',
        ], $residentOverrides));

        return ['household' => $household, 'resident' => $resident];
    }

    public function test_child_immunizations_schema_contract(): void
    {
        $this->assertTrue(Schema::hasTable('child_immunizations'));
        $this->assertTrue(Schema::hasColumn('child_immunizations', 'resident_id'));
        $this->assertTrue(Schema::hasColumn('child_immunizations', 'selected_vaccine_types'));
        $this->assertTrue(Schema::hasColumn('child_immunizations', 'remarks'));
        $this->assertTrue(Schema::hasColumn('child_immunizations', 'created_at'));
        $this->assertTrue(Schema::hasColumn('child_immunizations', 'updated_at'));
    }

    public function test_immunization_doses_schema_contract(): void
    {
        $this->assertTrue(Schema::hasTable('immunization_doses'));
        $this->assertTrue(Schema::hasColumn('immunization_doses', 'child_immunization_id'));
        $this->assertTrue(Schema::hasColumn('immunization_doses', 'vaccine_type'));
        $this->assertTrue(Schema::hasColumn('immunization_doses', 'dose_index'));
        $this->assertTrue(Schema::hasColumn('immunization_doses', 'date_given'));
        $this->assertTrue(Schema::hasColumn('immunization_doses', 'created_at'));
        $this->assertTrue(Schema::hasColumn('immunization_doses', 'updated_at'));
    }

    public function test_resident_has_one_child_immunization_relationship(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = ChildImmunization::factory()->create([
            'resident_id' => $resident->id,
            'selected_vaccine_types' => ['bcg', 'fic'],
        ]);

        $resident->refresh();
        $this->assertTrue($resident->childImmunization()->exists());
        $this->assertSame($record->id, $resident->childImmunization->id);
        $this->assertSame($resident->id, $record->resident->id);
    }

    public function test_persisted_immunization_belongs_to_correct_resident(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $other = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-831',
            'first_name' => 'Other',
            'last_name' => 'Child',
        ]);

        $record = ChildImmunization::factory()->create([
            'resident_id' => $resident->id,
            'selected_vaccine_types' => ['mmr', 'cic'],
        ]);

        $this->assertDatabaseHas('child_immunizations', [
            'id' => $record->id,
            'resident_id' => $resident->id,
        ]);
        $this->assertSame($resident->id, $record->fresh()->resident_id);
        $this->assertNotSame($other->id, $record->fresh()->resident_id);
        $this->assertNull($other->fresh()->childImmunization);
    }

    public function test_vaccine_dose_rows_persist_with_optional_null_dates(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = ChildImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);

        $withDate = ImmunizationDose::factory()->create([
            'child_immunization_id' => $record->id,
            'vaccine_type' => 'bcg',
            'dose_index' => 0,
            'date_given' => '2025-01-15',
        ]);

        $withoutDate = ImmunizationDose::factory()->create([
            'child_immunization_id' => $record->id,
            'vaccine_type' => 'mmr',
            'dose_index' => 0,
            'date_given' => null,
        ]);

        $this->assertDatabaseHas('immunization_doses', [
            'id' => $withDate->id,
            'child_immunization_id' => $record->id,
            'vaccine_type' => 'bcg',
            'dose_index' => 0,
        ]);
        // SQLite may store date columns with a time component; assert via cast.
        $this->assertSame(
            '2025-01-15',
            $withDate->fresh()->date_given?->format('Y-m-d')
        );

        $this->assertDatabaseHas('immunization_doses', [
            'id' => $withoutDate->id,
            'child_immunization_id' => $record->id,
            'vaccine_type' => 'mmr',
            'dose_index' => 0,
        ]);
        $this->assertNull($withoutDate->fresh()->date_given);
        $this->assertCount(2, $record->fresh()->doses);
    }

    public function test_duplicate_dose_slot_is_rejected(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = ChildImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);

        ImmunizationDose::factory()->create([
            'child_immunization_id' => $record->id,
            'vaccine_type' => 'opv',
            'dose_index' => 1,
            'date_given' => '2025-03-01',
        ]);

        $this->expectException(QueryException::class);

        ImmunizationDose::factory()->create([
            'child_immunization_id' => $record->id,
            'vaccine_type' => 'opv',
            'dose_index' => 1,
            'date_given' => '2025-04-01',
        ]);
    }

    public function test_one_child_immunization_header_per_resident(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        ChildImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);

        $this->expectException(QueryException::class);

        ChildImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);
    }

    public function test_household_member_change_does_not_transfer_immunization_ownership(): void
    {
        $householdA = Household::factory()->create([
            'household_no' => 'HH-840',
            'zone' => 'Zone 2',
        ]);
        $householdB = Household::factory()->create([
            'household_no' => 'HH-841',
            'zone' => 'Zone 3',
        ]);

        $residentA = Resident::factory()->create([
            'household_id' => $householdA->id,
            'member_no' => 'MB-840',
            'first_name' => 'Alpha',
            'last_name' => 'One',
        ]);
        $residentB = Resident::factory()->create([
            'household_id' => $householdB->id,
            'member_no' => 'MB-841',
            'first_name' => 'Beta',
            'last_name' => 'Two',
        ]);

        $record = ChildImmunization::factory()->create([
            'resident_id' => $residentA->id,
            'selected_vaccine_types' => ['pcv'],
        ]);

        ImmunizationDose::factory()->create([
            'child_immunization_id' => $record->id,
            'vaccine_type' => 'pcv',
            'dose_index' => 0,
            'date_given' => '2025-05-01',
        ]);

        // Unrelated household update must not reassign immunization ownership.
        $householdA->update(['street' => 'Changed Street']);
        $residentA->update(['member_no' => 'MB-899']);

        $record->refresh();
        $this->assertSame($residentA->id, $record->resident_id);
        $this->assertNotSame($residentB->id, $record->resident_id);
        $this->assertTrue(
            ImmunizationDose::query()
                ->where('child_immunization_id', $record->id)
                ->where('vaccine_type', 'pcv')
                ->where('dose_index', 0)
                ->exists()
        );
        $this->assertNull($residentB->fresh()->childImmunization);
    }

    public function test_deleting_immunization_header_cascades_doses_only(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = ChildImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);

        ImmunizationDose::factory()->create([
            'child_immunization_id' => $record->id,
            'vaccine_type' => 'ipv',
            'dose_index' => 0,
            'date_given' => null,
        ]);

        $recordId = $record->id;
        $record->delete();

        $this->assertDatabaseMissing('child_immunizations', ['id' => $recordId]);
        $this->assertDatabaseMissing('immunization_doses', [
            'child_immunization_id' => $recordId,
        ]);
        $this->assertDatabaseHas('residents', ['id' => $resident->id]);
    }

    public function test_ui_vaccine_slot_constants_match_frozen_dose_structure(): void
    {
        $this->assertSame(
            ['bcg', 'hepa-b', 'dpt-hib-hepb', 'opv', 'ipv', 'pcv', 'mmr'],
            ChildImmunization::VACCINE_TYPES
        );
        $this->assertContains('fic', ChildImmunization::SELECTABLE_TYPE_KEYS);
        $this->assertContains('cic', ChildImmunization::SELECTABLE_TYPE_KEYS);
        $this->assertSame(2, ChildImmunization::DOSE_SLOT_COUNTS['mmr']);
        $this->assertSame(3, ChildImmunization::DOSE_SLOT_COUNTS['opv']);
        $this->assertSame(3, ChildImmunization::DOSE_SLOT_COUNTS['dpt-hib-hepb']);
        $this->assertSame(2, ChildImmunization::DOSE_SLOT_COUNTS['ipv']);
    }
}
