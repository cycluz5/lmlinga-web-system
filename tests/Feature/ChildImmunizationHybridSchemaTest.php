<?php

namespace Tests\Feature;

use App\Models\ChildImmunization;
use App\Models\Household;
use App\Models\ImmunizationDose;
use App\Models\Resident;
use App\Support\ChildImmunizationErdMode;
use App\Support\ChildImmunizationService;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\HybridChildImmunizationSchema;
use Tests\TestCase;

/**
 * Hybrid live schema: plural header + dose_id/dose_number + fic_cic_status.
 */
class ChildImmunizationHybridSchemaTest extends TestCase
{
    use RefreshDatabase;

    private ChildImmunizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        HybridChildImmunizationSchema::ensure();
        $this->service = app(ChildImmunizationService::class);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedChild(): array
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-911',
            'zone' => 'Zone 1',
        ]);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-911',
            'first_name' => 'Luna',
            'last_name' => 'Santos',
            'birthday' => now()->subMonths(8)->format('Y-m-d'),
            'sex' => 'Female',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    public function test_hybrid_header_stays_plural_while_doses_use_erd_columns(): void
    {
        $this->assertFalse(ChildImmunizationErdMode::isActive());
        $this->assertSame('child_immunizations', ChildImmunizationErdMode::headerTable());
        $this->assertSame('id', ChildImmunizationErdMode::headerPrimaryKey());
        $this->assertTrue(ChildImmunizationErdMode::usesDoseNumberSchema());
        $this->assertTrue(ChildImmunizationErdMode::usesDoseIdPrimaryKey());
        $this->assertSame('dose_number', ChildImmunizationErdMode::doseSequenceColumn());
        $this->assertSame('dose_id', ChildImmunizationErdMode::dosePrimaryKey());
        $this->assertTrue(ChildImmunizationErdMode::usesFicCicStatusTable());
        $this->assertTrue(ChildImmunizationErdMode::usesErdVaccineTypeLabels());
        $this->assertFalse(Schema::hasTable('child_immunization'));
        $this->assertFalse(Schema::hasColumn('immunization_doses', 'dose_index'));
    }

    public function test_first_ui_slot_stores_dose_number_1_and_bcg_enum(): void
    {
        ['resident' => $resident] = $this->seedChild();

        $record = $this->service->saveForResident($resident, [
            'vaccines' => ['bcg' => [0 => '2026-08-29']],
            'vaccine_types' => ['bcg'],
        ]);

        $this->assertDatabaseCount('child_immunizations', 1);
        $this->assertFalse(Schema::hasTable('child_immunization'));
        $this->assertSame($record->getKey(), DB::table('child_immunizations')->value('id'));

        $row = DB::table('immunization_doses')->first();
        $this->assertNotNull($row);
        $this->assertSame($record->getKey(), (int) $row->child_immunization_id);
        $this->assertSame('BCG', $row->vaccine_type);
        $this->assertSame(1, (int) $row->dose_number);
        $this->assertSame('2026-08-29', substr((string) $row->date_given, 0, 10));
        $this->assertFalse(isset($row->dose_index));
    }

    public function test_second_ui_slot_stores_dose_number_2(): void
    {
        ['resident' => $resident] = $this->seedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => ['bcg' => [1 => '2026-09-01']],
        ]);

        $row = DB::table('immunization_doses')->first();
        $this->assertSame(2, (int) $row->dose_number);
        $this->assertSame('BCG', $row->vaccine_type);
    }

    public function test_read_maps_dose_number_1_to_ui_index_0(): void
    {
        ['resident' => $resident] = $this->seedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => ['bcg' => [0 => '2026-08-29']],
            'vaccine_types' => ['bcg'],
        ]);

        $state = $this->service->forResident($resident);
        $this->assertSame('2026-08-29', $state['vaccines']['bcg'][0]);
        $this->assertSame('', $state['vaccines']['bcg'][1]);

        $dose = ImmunizationDose::query()->first();
        $this->assertSame(0, $dose->dose_index);
        $this->assertSame('bcg', $dose->vaccine_type);
        $this->assertSame(1, (int) $dose->getAttributes()['dose_number']);
    }

    public function test_fic_cic_status_is_usable_with_plural_header(): void
    {
        ['resident' => $resident] = $this->seedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => ['bcg' => [0 => '2026-08-29']],
            'vaccine_types' => ['bcg', 'fic', 'cic'],
        ]);

        $headerId = (int) DB::table('child_immunizations')->value('id');
        $status = DB::table('fic_cic_status')->first();

        $this->assertNotNull($status);
        $this->assertSame($headerId, (int) $status->child_immunization_id);
        $this->assertSame(1, (int) $status->fic_completed);
        $this->assertSame(1, (int) $status->cic_completed);

        $state = $this->service->forResident($resident);
        $this->assertContains('fic', $state['selected_vaccine_types']);
        $this->assertContains('cic', $state['selected_vaccine_types']);
        $this->assertContains('bcg', $state['selected_vaccine_types']);
    }

    public function test_http_save_succeeds_without_querying_dose_index(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();
        $this->actingAsStaff(StaffRole::BHW);

        $sql = [];
        DB::listen(static function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $this->post(route('household-profiling.members.child-immunization.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'vaccines' => ['bcg' => [0 => '2026-08-29']],
            'vaccine_types' => ['bcg'],
        ])->assertRedirect(route('household-profiling.members.child-immunization', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]));

        $this->assertNotEmpty($sql);
        foreach ($sql as $statement) {
            $this->assertStringNotContainsStringIgnoringCase('dose_index', $statement);
        }

        $this->assertSame('BCG', DB::table('immunization_doses')->value('vaccine_type'));
        $this->assertSame(1, (int) DB::table('immunization_doses')->value('dose_number'));
        $this->assertSame(
            (int) DB::table('child_immunizations')->value('id'),
            (int) DB::table('immunization_doses')->value('child_immunization_id')
        );
        $this->assertFalse(Schema::hasTable('child_immunization'));
    }
}
