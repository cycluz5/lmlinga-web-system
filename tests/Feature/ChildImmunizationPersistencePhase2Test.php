<?php

namespace Tests\Feature;

use App\Models\ChildImmunization;
use App\Models\Household;
use App\Models\ImmunizationDose;
use App\Models\Resident;
use App\Support\ChildImmunizationService;
use App\Support\StaffRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * DB-08 Phase 2 — Child Immunization server-side persistence contract.
 */
class ChildImmunizationPersistencePhase2Test extends TestCase
{
    use RefreshDatabase;

    private ChildImmunizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
        $this->service = app(ChildImmunizationService::class);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedPersistedChild(array $residentOverrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-850',
            'zone' => 'Zone 1',
            'street' => 'Imm St.',
        ]);

        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => 'MB-850',
            'first_name' => 'Baby',
            'last_name' => 'Immune',
            'relation' => 'Son',
            'birthday' => now()->subMonths(8)->format('Y-m-d'),
            'sex' => 'Male',
        ], $residentOverrides));

        return ['household' => $household, 'resident' => $resident];
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'vaccines' => [
                'bcg' => [0 => '2025-01-15'],
                'mmr' => [0 => '2025-09-01'],
            ],
            'vaccine_types' => ['bcg', 'mmr', 'fic'],
            'remarks' => 'Initial save',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function ficCompletePayload(): array
    {
        return [
            'vaccines' => [
                'bcg' => [0 => '2025-01-01'],
                'opv' => [0 => '2025-01-15', 1 => '2025-02-15', 2 => '2025-03-15'],
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
                'mmr' => [0 => '2025-09-01'],
            ],
            'vaccine_types' => ['fic'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cicCompletePayload(): array
    {
        return [
            'vaccines' => [
                'bcg' => [0 => '2025-01-01'],
                'opv' => [0 => '2025-01-15', 1 => '2025-02-15', 2 => '2025-03-15'],
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
                'mmr' => [0 => '2025-09-01', 1 => '2025-12-01'],
            ],
            'vaccine_types' => ['cic'],
        ];
    }

    private function storeRoute(array $params): string
    {
        return route('household-profiling.members.child-immunization.store', $params);
    }

    public function test_create_persistence_creates_header_and_dose_rows(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload())
            ->assertRedirect(route('household-profiling.members.child-immunization', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]));

        $this->assertDatabaseHas('child_immunizations', [
            'resident_id' => $resident->id,
            'remarks' => 'Initial save',
        ]);

        $record = ChildImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(['bcg', 'mmr', 'fic'], $record->selected_vaccine_types);
        $this->assertSame(2, ImmunizationDose::query()->where('child_immunization_id', $record->id)->count());
    }

    public function test_update_persistence_updates_existing_header_and_doses(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, $this->validPayload());

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload([
            'vaccines' => [
                'bcg' => [0 => '2025-02-01'],
                'opv' => [0 => '2025-03-01'],
            ],
            'vaccine_types' => ['opv', 'cic'],
            'remarks' => 'Updated save',
        ]))->assertRedirect();

        $this->assertSame(1, ChildImmunization::query()->where('resident_id', $resident->id)->count());
        $this->assertDatabaseHas('child_immunizations', [
            'resident_id' => $resident->id,
            'remarks' => 'Updated save',
        ]);

        $record = ChildImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame(['opv', 'cic'], $record->selected_vaccine_types);

        $bcg = ImmunizationDose::query()
            ->where('child_immunization_id', $record->id)
            ->where('vaccine_type', 'bcg')
            ->where('dose_index', 0)
            ->first();
        $this->assertNotNull($bcg);
        $this->assertSame('2025-02-01', $bcg->date_given->format('Y-m-d'));
    }

    public function test_optional_null_dates_are_persisted(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = $this->service->saveForResident($resident, [
            'vaccines' => [
                'mmr' => [0 => null, 1 => ''],
            ],
            'vaccine_types' => [],
        ]);

        $dose = ImmunizationDose::query()
            ->where('child_immunization_id', $record->id)
            ->where('vaccine_type', 'mmr')
            ->where('dose_index', 0)
            ->first();

        $this->assertNotNull($dose);
        $this->assertNull($dose->date_given);
    }

    public function test_clearing_an_existing_date_persists_null(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => ['bcg' => [0 => '2025-01-01']],
        ]);

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'vaccines' => ['bcg' => [0 => '']],
        ])->assertRedirect();

        $record = ChildImmunization::query()->where('resident_id', $resident->id)->first();
        $dose = ImmunizationDose::query()
            ->where('child_immunization_id', $record->id)
            ->where('vaccine_type', 'bcg')
            ->where('dose_index', 0)
            ->first();

        $this->assertNotNull($dose);
        $this->assertNull($dose->date_given);
    }

    public function test_manual_checkbox_selections_are_persisted(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = $this->service->saveForResident($resident, [
            'vaccine_types' => ['cic', 'bcg', 'fic', 'bcg'],
        ]);

        $this->assertSame(['bcg', 'fic', 'cic'], $record->selected_vaccine_types);
    }

    public function test_checkbox_and_date_inputs_remain_independent(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = $this->service->saveForResident($resident, [
            'vaccines' => [],
            'vaccine_types' => ['mmr', 'fic'],
        ]);

        $this->assertSame(['mmr', 'fic'], $record->selected_vaccine_types);
        $this->assertSame(0, ImmunizationDose::query()->where('child_immunization_id', $record->id)->count());

        $updated = $this->service->saveForResident($resident, [
            'vaccines' => ['mmr' => [0 => '2025-09-01']],
            'vaccine_types' => [],
        ]);

        $this->assertSame([], $updated->selected_vaccine_types);
        $this->assertSame(1, ImmunizationDose::query()->where('child_immunization_id', $updated->id)->count());
    }

    public function test_unsupported_vaccine_key_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'vaccines' => ['invalid-vaccine' => [0 => '2025-01-01']],
        ])->assertSessionHasErrors('vaccines.invalid-vaccine');

        $this->assertDatabaseCount('child_immunizations', 0);
    }

    public function test_invalid_dose_index_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'vaccines' => ['bcg' => [9 => '2025-01-01']],
        ])->assertSessionHasErrors('vaccines.bcg.9');

        $this->assertDatabaseCount('child_immunizations', 0);
    }

    public function test_repeated_save_does_not_duplicate_header(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $params = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];

        $this->post($this->storeRoute($params), $this->validPayload())->assertRedirect();
        $this->post($this->storeRoute($params), $this->validPayload(['remarks' => 'Second save']))->assertRedirect();

        $this->assertSame(1, ChildImmunization::query()->where('resident_id', $resident->id)->count());
    }

    public function test_repeated_save_does_not_duplicate_dose_slot(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $payload = ['vaccines' => ['bcg' => [0 => '2025-01-01']]];

        $this->service->saveForResident($resident, $payload);
        $this->service->saveForResident($resident, ['vaccines' => ['bcg' => [0 => '2025-02-01']]]);

        $record = ChildImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame(
            1,
            ImmunizationDose::query()
                ->where('child_immunization_id', $record->id)
                ->where('vaccine_type', 'bcg')
                ->where('dose_index', 0)
                ->count()
        );
    }

    public function test_resident_a_cannot_modify_resident_b_immunization_via_route(): void
    {
        ['household' => $household, 'resident' => $residentA] = $this->seedPersistedChild();

        $residentB = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-851',
        ]);

        $this->service->saveForResident($residentB, [
            'vaccines' => ['bcg' => [0 => '2025-04-01']],
        ]);

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $residentA->member_no,
        ]), [
            'vaccines' => ['bcg' => [0 => '2025-05-01']],
            'resident_id' => $residentB->id,
        ])->assertSessionHasErrors('resident_id');

        $recordB = ChildImmunization::query()->where('resident_id', $residentB->id)->first();
        $doseB = ImmunizationDose::query()
            ->where('child_immunization_id', $recordB->id)
            ->where('vaccine_type', 'bcg')
            ->where('dose_index', 0)
            ->first();

        $this->assertSame('2025-04-01', $doseB->date_given->format('Y-m-d'));
        $this->assertNull($residentA->fresh()->childImmunization);
    }

    public function test_household_member_presentation_change_does_not_transfer_ownership(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => ['bcg' => [0 => '2025-01-01']],
        ]);

        $household->update(['street' => 'Changed Street']);
        $resident->update(['member_no' => 'MB-899']);

        $record = ChildImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($record);
        $this->assertSame($resident->id, $record->resident_id);
    }

    public function test_transaction_rolls_back_when_dose_write_fails(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $invocations = 0;
        ImmunizationDose::creating(function () use (&$invocations): void {
            $invocations++;

            if ($invocations >= 2) {
                throw new \RuntimeException('Simulated dose persistence failure.');
            }
        });

        try {
            $this->service->saveForResident($resident, [
                'vaccines' => [
                    'bcg' => [0 => '2025-01-01'],
                    'opv' => [0 => '2025-02-01'],
                ],
            ]);
            $this->fail('Expected simulated dose persistence failure.');
        } catch (\RuntimeException) {
            // expected
        } finally {
            ImmunizationDose::flushEventListeners();
        }

        $this->assertDatabaseCount('child_immunizations', 0);
        $this->assertDatabaseCount('immunization_doses', 0);
    }

    public function test_empty_state_read_contract_requires_no_database_rows(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $state = $this->service->forResident($resident);

        $this->assertFalse($state['persisted']);
        $this->assertSame([], $state['selected_vaccine_types']);
        $this->assertSame('', $state['remarks']);
        $this->assertSame(ChildImmunizationService::emptyVaccineForm(), $state['vaccines']);
        $this->assertFalse($state['fic']['completed']);
        $this->assertFalse($state['cic']['completed']);
    }

    public function test_persisted_state_read_contract_hydrates_all_slots(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => [
                'bcg' => [0 => '2025-01-01'],
                'mmr' => [0 => '2025-09-01', 1 => '2025-12-01'],
            ],
            'vaccine_types' => ['bcg', 'cic'],
            'remarks' => 'Read back',
        ]);

        $state = $this->service->forResident($resident->fresh());

        $this->assertTrue($state['persisted']);
        $this->assertSame(['bcg', 'cic'], $state['selected_vaccine_types']);
        $this->assertSame('Read back', $state['remarks']);
        $this->assertSame('2025-01-01', $state['vaccines']['bcg'][0]);
        $this->assertSame('', $state['vaccines']['bcg'][1]);
        $this->assertSame('2025-09-01', $state['vaccines']['mmr'][0]);
        $this->assertSame('2025-12-01', $state['vaccines']['mmr'][1]);
    }

    public function test_fic_positive_case_is_derived_from_dated_doses(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, $this->ficCompletePayload());

        $state = $this->service->forResident($resident->fresh());

        $this->assertTrue($state['fic']['completed']);
        $this->assertFalse($state['cic']['completed']);
        $this->assertSame(1, $state['fic']['counts']['mmr']);
    }

    public function test_fic_negative_incomplete_case_is_derived(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => [
                'bcg' => [0 => '2025-01-01'],
                'opv' => [0 => '2025-01-15', 1 => '2025-02-15'],
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
            ],
        ]);

        $state = $this->service->forResident($resident->fresh());

        $this->assertFalse($state['fic']['completed']);
        $this->assertFalse($state['cic']['completed']);
        $this->assertSame(2, $state['fic']['counts']['opv']);
    }

    public function test_cic_positive_case_requires_mmr_two_dated_doses(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, $this->cicCompletePayload());

        $state = $this->service->forResident($resident->fresh());

        $this->assertTrue($state['cic']['completed']);
        $this->assertTrue($state['fic']['completed']);
        $this->assertSame(2, $state['cic']['counts']['mmr']);
    }

    public function test_mmr_one_dose_meets_fic_but_not_cic(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $payload = $this->ficCompletePayload();
        $this->service->saveForResident($resident, $payload);

        $state = $this->service->forResident($resident->fresh());

        $this->assertTrue($state['fic']['completed']);
        $this->assertFalse($state['cic']['completed']);
        $this->assertSame(1, $state['fic']['counts']['mmr']);
        $this->assertSame(1, $state['cic']['counts']['mmr']);
    }

    public function test_unsupported_vaccine_type_checkbox_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'vaccine_types' => ['not-a-real-key'],
        ])->assertSessionHasErrors('vaccine_types.0');
    }

    public function test_child_immunization_id_cannot_be_spoofed(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), array_merge($this->validPayload(), [
            'child_immunization_id' => 999,
        ]))->assertSessionHasErrors('child_immunization_id');
    }

    public function test_demo_only_member_cannot_create_db_immunization_row(): void
    {
        $this->post($this->storeRoute([
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]), $this->validPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('child_immunizations', 0);
    }

    public function test_cross_household_store_fails_and_creates_no_row(): void
    {
        $householdA = Household::factory()->create(['household_no' => 'HH-852']);
        $householdB = Household::factory()->create(['household_no' => 'HH-853']);
        $resident = Resident::factory()->create([
            'household_id' => $householdA->id,
            'member_no' => 'MB-852',
        ]);

        $this->post($this->storeRoute([
            'householdNo' => $householdB->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('child_immunizations', 0);
    }

    public function test_immunization_store_route_exists_for_server_contract(): void
    {
        $this->assertTrue(Route::has('household-profiling.members.child-immunization.store'));
    }

    public function test_physical_resident_deletion_is_restricted_when_immunization_exists(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        ChildImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);

        $this->expectException(QueryException::class);

        $resident->forceDelete();
    }

    public function test_service_rejects_invalid_vaccine_key_before_persistence(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        try {
            $this->service->saveForResident($resident, [
                'vaccines' => ['unknown' => [0 => '2025-01-01']],
            ]);
            $this->fail('Expected validation exception.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertDatabaseCount('child_immunizations', 0);
        $this->assertDatabaseCount('immunization_doses', 0);
    }
}
