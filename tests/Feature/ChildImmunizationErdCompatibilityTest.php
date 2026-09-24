<?php

namespace Tests\Feature;

use App\Models\ChildImmunization;
use App\Models\Household;
use App\Models\ImmunizationDose;
use App\Models\Resident;
use App\Support\ChildImmunizationErdMode;
use App\Support\ChildImmunizationService;
use App\Support\HouseholdProfilingWriteGuard;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\ErdChildImmunizationSchema;
use Tests\TestCase;

/**
 * Child Immunization save/read against authoritative ERD table shapes (sqlite only).
 */
class ChildImmunizationErdCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private ChildImmunizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        ErdChildImmunizationSchema::ensure();
        $this->service = app(ChildImmunizationService::class);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedChild(): array
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-901',
            'zone' => 'Zone 1',
        ]);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-901',
            'first_name' => 'Sofia',
            'last_name' => 'Dela Cruz',
            'birthday' => now()->subMonths(8)->format('Y-m-d'),
            'sex' => 'Female',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    public function test_erd_schema_has_no_dose_index_and_uses_singular_header(): void
    {
        $this->assertTrue(ChildImmunizationErdMode::isActive());
        $this->assertSame('child_immunization', ChildImmunizationErdMode::headerTable());
        $this->assertSame('child_immunization_id', ChildImmunizationErdMode::headerPrimaryKey());
        $this->assertTrue(ChildImmunizationErdMode::usesDoseNumberSchema());
        $this->assertTrue(ChildImmunizationErdMode::usesDoseIdPrimaryKey());
        $this->assertSame('dose_number', ChildImmunizationErdMode::doseSequenceColumn());
        $this->assertSame('dose_id', ChildImmunizationErdMode::dosePrimaryKey());
        $this->assertTrue(ChildImmunizationErdMode::usesFicCicStatusTable());
        $this->assertTrue(Schema::hasTable('child_immunization'));
        $this->assertFalse(Schema::hasTable('child_immunizations'));
        $this->assertTrue(Schema::hasTable('immunization_doses'));
        $this->assertFalse(Schema::hasColumn('immunization_doses', 'dose_index'));
        $this->assertTrue(Schema::hasColumn('immunization_doses', 'dose_number'));
        $this->assertTrue(Schema::hasColumn('immunization_doses', 'dose_id'));
        $this->assertTrue(Schema::hasColumn('child_immunization', 'child_immunization_id'));
        $this->assertFalse(Schema::hasColumn('child_immunization', 'selected_vaccine_types'));
        $this->assertFalse(Schema::hasColumn('child_immunization', 'remarks'));
    }

    public function test_write_guard_allows_save_when_singular_header_exists(): void
    {
        HouseholdProfilingWriteGuard::rejectChildImmunizationWrite();

        $this->assertTrue(true);
    }

    public function test_save_bcg_slot_0_writes_enum_and_dose_number_1(): void
    {
        ['resident' => $resident] = $this->seedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => ['bcg' => [0 => '2026-08-29']],
            'vaccine_types' => ['bcg'],
        ]);

        $this->assertDatabaseCount('child_immunization', 1);
        $this->assertDatabaseCount('immunization_doses', 1);

        $row = DB::table('immunization_doses')->first();
        $this->assertNotNull($row);
        $this->assertSame('BCG', $row->vaccine_type);
        $this->assertSame(1, (int) $row->dose_number);
        $this->assertSame('2026-08-29', substr((string) $row->date_given, 0, 10));
        $this->assertFalse(isset($row->dose_index));
    }

    public function test_save_slot_1_writes_dose_number_2(): void
    {
        ['resident' => $resident] = $this->seedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => ['bcg' => [1 => '2026-09-01']],
        ]);

        $row = DB::table('immunization_doses')->first();
        $this->assertSame(2, (int) $row->dose_number);
        $this->assertSame('BCG', $row->vaccine_type);
    }

    public function test_repeat_save_does_not_duplicate_the_same_dose(): void
    {
        ['resident' => $resident] = $this->seedChild();

        $payload = [
            'vaccines' => ['bcg' => [0 => '2026-08-29']],
            'vaccine_types' => ['bcg', 'fic'],
        ];

        $this->service->saveForResident($resident, $payload);
        $this->service->saveForResident($resident, [
            'vaccines' => ['bcg' => [0 => '2026-08-30']],
            'vaccine_types' => ['bcg', 'fic'],
        ]);

        $this->assertSame(1, ChildImmunization::query()->count());
        $this->assertDatabaseCount('immunization_doses', 1);
        $this->assertSame('2026-08-30', substr((string) DB::table('immunization_doses')->value('date_given'), 0, 10));
        $this->assertDatabaseCount('fic_cic_status', 1);
        $this->assertSame(1, (int) DB::table('fic_cic_status')->value('fic_completed'));
    }

    public function test_read_maps_dose_number_1_to_form_index_0(): void
    {
        ['resident' => $resident] = $this->seedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => ['bcg' => [0 => '2026-08-29']],
        ]);

        $state = $this->service->forResident($resident);

        $this->assertTrue($state['persisted']);
        $this->assertSame('2026-08-29', $state['vaccines']['bcg'][0]);
        $this->assertSame('', $state['vaccines']['bcg'][1]);
        $this->assertSame('', $state['remarks']);
        $this->assertContains('bcg', $state['selected_vaccine_types']);
    }

    public function test_erd_vaccine_enum_mapping_round_trips(): void
    {
        ['resident' => $resident] = $this->seedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => [
                'hepa-b' => [0 => '2026-01-01'],
                'dpt-hib-hepb' => [0 => '2026-01-02'],
                'opv' => [0 => '2026-01-03'],
                'ipv' => [0 => '2026-01-04'],
                'pcv' => [0 => '2026-01-05'],
                'mmr' => [0 => '2026-01-06'],
            ],
        ]);

        $stored = DB::table('immunization_doses')->orderBy('vaccine_type')->pluck('vaccine_type')->all();
        sort($stored);
        $this->assertSame(
            ['DPT-HIB-HepB', 'Hepa B', 'IPV', 'MMR', 'OPV', 'PCV'],
            $stored
        );

        $state = $this->service->forResident($resident);
        $this->assertSame('2026-01-01', $state['vaccines']['hepa-b'][0]);
        $this->assertSame('2026-01-02', $state['vaccines']['dpt-hib-hepb'][0]);
        $this->assertSame('2026-01-03', $state['vaccines']['opv'][0]);
        $this->assertSame('2026-01-04', $state['vaccines']['ipv'][0]);
        $this->assertSame('2026-01-05', $state['vaccines']['pcv'][0]);
        $this->assertSame('2026-01-06', $state['vaccines']['mmr'][0]);
    }

    public function test_http_store_does_not_write_dose_index_in_erd_mode(): void
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

        foreach ($sql as $statement) {
            $this->assertStringNotContainsStringIgnoringCase('dose_index', $statement);
        }

        $this->assertSame('BCG', DB::table('immunization_doses')->value('vaccine_type'));
        $this->assertSame(1, (int) DB::table('immunization_doses')->value('dose_number'));
    }

    public function test_dose_model_accessor_exposes_ui_index_without_dose_index_column(): void
    {
        ['resident' => $resident] = $this->seedChild();
        $this->service->saveForResident($resident, [
            'vaccines' => ['mmr' => [1 => '2026-12-01']],
        ]);

        $dose = ImmunizationDose::query()->first();
        $this->assertNotNull($dose);
        $this->assertSame(1, $dose->dose_index);
        $this->assertSame('mmr', $dose->vaccine_type);
        $this->assertSame(2, (int) $dose->getAttributes()['dose_number']);
    }

    public function test_legacy_write_guard_still_rejects_when_neither_header_table_exists(): void
    {
        Schema::dropIfExists('immunization_doses');
        Schema::dropIfExists('fic_cic_status');
        Schema::dropIfExists('child_immunization');
        ChildImmunizationErdMode::resetCachedState();

        $this->assertFalse(ChildImmunizationErdMode::isActive());
        $this->assertFalse(Schema::hasTable('child_immunizations'));

        $this->expectException(ValidationException::class);
        HouseholdProfilingWriteGuard::rejectChildImmunizationWrite();
    }
}
