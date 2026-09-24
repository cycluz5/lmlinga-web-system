<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\SchoolImmunization;
use App\Models\SchoolImmunizationDose;
use App\Support\SchoolImmunizationService;
use App\Support\StaffRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * DB-09 Phase 2 — School-Based Immunization server-side persistence contract.
 */
class SchoolBasedImmunizationPersistencePhase2Test extends TestCase
{
    use RefreshDatabase;

    private SchoolImmunizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
        $this->service = app(SchoolImmunizationService::class);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedPersistedChild(array $residentOverrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-950',
            'zone' => 'Zone 1',
            'street' => 'SBI St.',
        ]);

        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => 'MB-950',
            'first_name' => 'School',
            'last_name' => 'Child',
            'relation' => 'Daughter',
            'birthday' => now()->subYears(10)->format('Y-m-d'),
            'sex' => 'Female',
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
                'grade-1' => [
                    'td' => '2025-01-15',
                    'mr' => '2025-01-20',
                ],
                'grade-7' => [
                    'td' => '2025-02-01',
                ],
                'hpv' => [
                    '1' => '2025-03-01',
                ],
            ],
            'vaccine_types' => ['grade1_td', 'grade1_mr', 'hpv_1'],
        ], $overrides);
    }

    private function storeRoute(array $params): string
    {
        return route('household-profiling.members.school-based-immunization.store', $params);
    }

    private function showRoute(array $params): string
    {
        return route('household-profiling.members.school-based-immunization', $params);
    }

    public function test_valid_resident_sbi_save_creates_header_and_dose_rows(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload())
            ->assertRedirect($this->showRoute([
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]))
            ->assertSessionHas('status', 'School-based immunization saved.');

        $this->assertDatabaseHas('school_immunizations', [
            'resident_id' => $resident->id,
        ]);

        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(['grade1_td', 'grade1_mr', 'grade7_td', 'hpv_1'], $record->selected_vaccine_types);
        $this->assertSame(4, SchoolImmunizationDose::query()->where('school_immunization_id', $record->id)->count());
    }

    public function test_one_header_per_resident_is_enforced(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        SchoolImmunization::factory()->create(['resident_id' => $resident->id]);

        $this->expectException(QueryException::class);

        SchoolImmunization::factory()->create(['resident_id' => $resident->id]);
    }

    public function test_repeated_save_remains_one_header(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $params = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];

        $this->post($this->storeRoute($params), $this->validPayload())->assertRedirect();
        $this->post($this->storeRoute($params), $this->validPayload())->assertRedirect();
        $this->post($this->storeRoute($params), $this->validPayload())->assertRedirect();

        $this->assertSame(1, SchoolImmunization::query()->where('resident_id', $resident->id)->count());
    }

    public function test_six_authoritative_checkbox_keys_are_accepted(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = $this->service->saveForResident($resident, [
            'vaccine_types' => SchoolImmunization::SELECTABLE_TYPE_KEYS,
        ]);

        $this->assertSame(SchoolImmunization::SELECTABLE_TYPE_KEYS, $record->selected_vaccine_types);
    }

    public function test_unknown_checkbox_key_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'vaccine_types' => ['not-a-real-key'],
        ])->assertSessionHasErrors('vaccine_types.0');
    }

    public function test_checkbox_checked_with_null_date_is_accepted(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = $this->service->saveForResident($resident, [
            'vaccines' => [
                'grade-1' => ['td' => null],
            ],
            'vaccine_types' => ['grade1_td'],
        ]);

        $this->assertSame(['grade1_td'], $record->selected_vaccine_types);

        $dose = SchoolImmunizationDose::query()
            ->where('school_immunization_id', $record->id)
            ->where('slot_group', 'grade-1')
            ->where('slot_key', 'td')
            ->first();

        $this->assertNotNull($dose);
        $this->assertNull($dose->date_given);
    }

    public function test_checkbox_unchecked_with_date_still_derives_selection(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = $this->service->saveForResident($resident, [
            'vaccines' => [
                'grade-1' => ['td' => '2025-04-01'],
            ],
            'vaccine_types' => [],
        ]);

        $this->assertSame(['grade1_td'], $record->selected_vaccine_types);

        $dose = SchoolImmunizationDose::query()
            ->where('school_immunization_id', $record->id)
            ->where('slot_group', 'grade-1')
            ->where('slot_key', 'td')
            ->first();

        $this->assertNotNull($dose);
        $this->assertSame('2025-04-01', $dose->date_given->format('Y-m-d'));
    }

    public function test_all_dates_are_nullable(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $vaccines = [];
        foreach (SchoolImmunization::DOSE_SLOTS as $slot) {
            $vaccines[$slot['slot_group']][$slot['slot_key']] = '';
        }

        $record = $this->service->saveForResident($resident, [
            'vaccines' => $vaccines,
            'vaccine_types' => [],
        ]);

        $this->assertCount(6, $record->doses);
        foreach ($record->doses as $dose) {
            $this->assertNull($dose->date_given);
        }
    }

    public function test_valid_dates_persist_correctly(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = $this->service->saveForResident($resident, $this->validPayload());

        $td = SchoolImmunizationDose::query()
            ->where('school_immunization_id', $record->id)
            ->where('slot_group', 'grade-1')
            ->where('slot_key', 'td')
            ->first();

        $this->assertSame('2025-01-15', $td->date_given->format('Y-m-d'));
    }

    public function test_checkbox_selections_persist_independently(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = $this->service->saveForResident($resident, [
            'vaccines' => [],
            'vaccine_types' => ['grade7_mr', 'hpv_2', 'grade7_mr'],
        ]);

        $this->assertSame(['grade7_mr', 'hpv_2'], $record->selected_vaccine_types);
        $this->assertSame(0, SchoolImmunizationDose::query()->where('school_immunization_id', $record->id)->count());
    }

    public function test_dose_slot_mapping_is_correct(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $payloadVaccines = [];
        foreach (SchoolImmunization::DOSE_SLOTS as $index => $slot) {
            $payloadVaccines[$slot['slot_group']][$slot['slot_key']] = sprintf('2025-0%d-0%d', ($index % 9) + 1, ($index % 9) + 1);
        }

        $record = $this->service->saveForResident($resident, [
            'vaccines' => $payloadVaccines,
            'vaccine_types' => SchoolImmunization::SELECTABLE_TYPE_KEYS,
        ]);

        foreach (SchoolImmunization::DOSE_SLOTS as $slot) {
            $this->assertTrue(
                SchoolImmunizationDose::query()
                    ->where('school_immunization_id', $record->id)
                    ->where('slot_group', $slot['slot_group'])
                    ->where('slot_key', $slot['slot_key'])
                    ->exists()
            );
        }

        $this->assertSame(6, SchoolImmunizationDose::query()->where('school_immunization_id', $record->id)->count());
    }

    public function test_duplicate_dose_rows_are_not_created_on_repeated_save(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $payload = [
            'vaccines' => [
                'hpv' => ['1' => '2025-05-01', '2' => '2025-06-01'],
            ],
            'vaccine_types' => ['hpv_1', 'hpv_2'],
        ];

        $this->service->saveForResident($resident, $payload);
        $this->service->saveForResident($resident, $payload);

        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame(1, SchoolImmunization::query()->where('resident_id', $resident->id)->count());
        $this->assertSame(2, SchoolImmunizationDose::query()->where('school_immunization_id', $record->id)->count());
    }

    public function test_updating_an_existing_date_works(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => ['grade-7' => ['td' => '2025-01-01']],
        ]);

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'vaccines' => ['grade-7' => ['td' => '2025-08-15']],
            'vaccine_types' => [],
        ])->assertRedirect();

        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $dose = SchoolImmunizationDose::query()
            ->where('school_immunization_id', $record->id)
            ->where('slot_group', 'grade-7')
            ->where('slot_key', 'td')
            ->first();

        $this->assertSame('2025-08-15', $dose->date_given->format('Y-m-d'));
        $this->assertSame(1, SchoolImmunizationDose::query()->where('school_immunization_id', $record->id)->count());
    }

    public function test_clearing_an_existing_date_works(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => ['grade-1' => ['mr' => '2025-01-01']],
        ]);

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'vaccines' => ['grade-1' => ['mr' => '']],
        ])->assertRedirect();

        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $dose = SchoolImmunizationDose::query()
            ->where('school_immunization_id', $record->id)
            ->where('slot_group', 'grade-1')
            ->where('slot_key', 'mr')
            ->first();

        $this->assertNotNull($dose);
        $this->assertNull($dose->date_given);
    }

    public function test_changing_checkbox_selections_does_not_incorrectly_alter_dates(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => [
                'grade-1' => ['td' => '2025-01-10'],
                'hpv' => ['2' => '2025-02-10'],
            ],
            'vaccine_types' => ['grade1_td'],
        ]);

        $updated = $this->service->saveForResident($resident, [
            'vaccine_types' => ['grade7_td', 'hpv_2'],
        ]);

        // Dated slots force their keys even when omitted from the new checkbox payload.
        $this->assertSame(['grade1_td', 'grade7_td', 'hpv_2'], $updated->selected_vaccine_types);

        $td = SchoolImmunizationDose::query()
            ->where('school_immunization_id', $updated->id)
            ->where('slot_group', 'grade-1')
            ->where('slot_key', 'td')
            ->first();
        $hpv2 = SchoolImmunizationDose::query()
            ->where('school_immunization_id', $updated->id)
            ->where('slot_group', 'hpv')
            ->where('slot_key', '2')
            ->first();

        $this->assertSame('2025-01-10', $td->date_given->format('Y-m-d'));
        $this->assertSame('2025-02-10', $hpv2->date_given->format('Y-m-d'));
    }

    public function test_changing_dates_derives_checkbox_keys_without_dropping_manual_selections(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => ['grade-1' => ['td' => '2025-01-01']],
            'vaccine_types' => ['grade1_td', 'hpv_1'],
        ]);

        $updated = $this->service->saveForResident($resident, [
            'vaccines' => [
                'grade-1' => ['td' => '2025-09-09'],
                'grade-7' => ['mr' => '2025-10-10'],
            ],
            'vaccine_types' => ['grade1_td', 'hpv_1'],
        ]);

        $this->assertSame(['grade1_td', 'grade7_mr', 'hpv_1'], $updated->selected_vaccine_types);
    }

    public function test_browser_supplied_resident_id_cannot_switch_target_resident(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $other = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-951',
        ]);

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), array_merge($this->validPayload(), [
            'resident_id' => $other->id,
        ]))->assertSessionHasErrors('resident_id');

        $this->assertDatabaseMissing('school_immunizations', [
            'resident_id' => $other->id,
        ]);
        $this->assertSame(0, SchoolImmunization::query()->where('resident_id', $resident->id)->count());
    }

    public function test_browser_supplied_school_immunization_id_cannot_switch_target_record(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $other = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-952',
        ]);
        $foreign = SchoolImmunization::factory()->create([
            'resident_id' => $other->id,
            'selected_vaccine_types' => ['hpv_2'],
        ]);

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), array_merge($this->validPayload(), [
            'school_immunization_id' => $foreign->id,
        ]))->assertSessionHasErrors('school_immunization_id');

        $foreign->refresh();
        $this->assertSame(['hpv_2'], $foreign->selected_vaccine_types);
        $this->assertSame(0, SchoolImmunization::query()->where('resident_id', $resident->id)->count());
    }

    public function test_resident_member_route_context_is_preserved_after_save(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $response = $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload());

        $response->assertRedirect($this->showRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]));

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('data-household-no="'.$household->household_no.'"', false)
            ->assertSee('data-member-id="'.$resident->member_no.'"', false);
    }

    public function test_persisted_record_hydrates_get_backend_data_correctly(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, $this->validPayload());

        $state = $this->service->forResident($resident->fresh());

        $this->assertTrue($state['persisted']);
        $this->assertSame(['grade1_td', 'grade1_mr', 'grade7_td', 'hpv_1'], $state['selected_vaccine_types']);
        $this->assertSame('2025-01-15', $state['vaccines']['grade-1']['td']);
        $this->assertSame('2025-01-20', $state['vaccines']['grade-1']['mr']);
        $this->assertSame('2025-02-01', $state['vaccines']['grade-7']['td']);
        $this->assertSame('', $state['vaccines']['grade-7']['mr']);
        $this->assertSame('2025-03-01', $state['vaccines']['hpv']['1']);
        $this->assertSame('', $state['vaccines']['hpv']['2']);

        // Controller show path supplies immunizationState for Phase 3 (Blade still frozen).
        $this->get($this->showRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk();
    }

    public function test_unrelated_resident_sbi_record_is_not_modified(): void
    {
        $householdA = Household::factory()->create(['household_no' => 'HH-960']);
        $householdB = Household::factory()->create(['household_no' => 'HH-961']);

        $residentA = Resident::factory()->create([
            'household_id' => $householdA->id,
            'member_no' => 'MB-960',
        ]);
        $residentB = Resident::factory()->create([
            'household_id' => $householdB->id,
            'member_no' => 'MB-961',
        ]);

        $this->service->saveForResident($residentB, [
            'vaccines' => ['hpv' => ['1' => '2024-01-01']],
            'vaccine_types' => ['hpv_1'],
        ]);

        $this->post($this->storeRoute([
            'householdNo' => $householdA->household_no,
            'memberId' => $residentA->member_no,
        ]), $this->validPayload())->assertRedirect();

        $b = SchoolImmunization::query()->where('resident_id', $residentB->id)->first();
        $this->assertSame(['hpv_1'], $b->selected_vaccine_types);
        $this->assertSame(
            '2024-01-01',
            SchoolImmunizationDose::query()
                ->where('school_immunization_id', $b->id)
                ->where('slot_group', 'hpv')
                ->where('slot_key', '1')
                ->first()
                ->date_given
                ->format('Y-m-d')
        );
    }

    public function test_non_resident_sbi_route_remains_non_persistent(): void
    {
        $this->assertTrue(Route::has('health-records.child-care.non-residents.school-based-immunization'));
        $this->assertFalse(Route::has('health-records.child-care.non-residents.school-based-immunization.store'));

        $before = SchoolImmunization::query()->count();

        $this->get(route('health-records.child-care.non-residents.school-based-immunization', [
            'childKey' => 'andrei-b-malaya',
        ]))->assertOk();

        $this->assertSame($before, SchoolImmunization::query()->count());
    }

    public function test_db_uniqueness_expectations_remain_intact(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = SchoolImmunization::factory()->create(['resident_id' => $resident->id]);

        SchoolImmunizationDose::factory()->create([
            'school_immunization_id' => $record->id,
            'slot_group' => 'grade-1',
            'slot_key' => 'td',
        ]);

        $this->expectException(QueryException::class);

        SchoolImmunizationDose::factory()->create([
            'school_immunization_id' => $record->id,
            'slot_group' => 'grade-1',
            'slot_key' => 'td',
        ]);
    }

    public function test_empty_state_read_contract_requires_no_database_rows(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $state = $this->service->forResident($resident);

        $this->assertFalse($state['persisted']);
        $this->assertSame([], $state['selected_vaccine_types']);
        $this->assertSame(SchoolImmunizationService::emptyVaccineForm(), $state['vaccines']);
        $this->assertDatabaseCount('school_immunizations', 0);
    }

    public function test_invalid_dose_slot_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'vaccines' => [
                'grade-1' => ['xyz' => '2025-01-01'],
            ],
        ])->assertSessionHasErrors('vaccines.grade-1.xyz');
    }

    public function test_demo_only_member_cannot_create_db_sbi_row(): void
    {
        $this->post($this->storeRoute([
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]), $this->validPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('school_immunizations', 0);
    }

    public function test_cross_household_store_fails_and_creates_no_row(): void
    {
        $householdA = Household::factory()->create(['household_no' => 'HH-970']);
        $householdB = Household::factory()->create(['household_no' => 'HH-971']);
        $resident = Resident::factory()->create([
            'household_id' => $householdA->id,
            'member_no' => 'MB-970',
        ]);

        $this->post($this->storeRoute([
            'householdNo' => $householdB->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('school_immunizations', 0);
    }

    public function test_sbi_store_route_exists_for_server_contract(): void
    {
        $this->assertTrue(Route::has('household-profiling.members.school-based-immunization.store'));
    }

    public function test_transaction_rolls_back_when_dose_write_fails(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        DB::listen(function ($query): void {
            if (str_contains(strtolower($query->sql), 'school_immunization_doses')
                && str_starts_with(strtolower(ltrim($query->sql)), 'insert')) {
                throw new QueryException(
                    $query->connectionName,
                    $query->sql,
                    $query->bindings,
                    new \Exception('Forced dose insert failure')
                );
            }
        });

        try {
            $this->service->saveForResident($resident, $this->validPayload());
            $this->fail('Expected QueryException was not thrown.');
        } catch (QueryException) {
            // expected
        }

        $this->assertDatabaseCount('school_immunizations', 0);
        $this->assertDatabaseCount('school_immunization_doses', 0);
    }

    public function test_service_rejects_invalid_slot_before_persistence(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->expectException(ValidationException::class);

        $this->service->saveForResident($resident, [
            'vaccines' => [
                'unknown-group' => ['td' => '2025-01-01'],
            ],
        ]);
    }

    public function test_physical_resident_deletion_is_restricted_when_sbi_exists(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        SchoolImmunization::factory()->create([
            'resident_id' => $resident->id,
        ]);

        $this->expectException(QueryException::class);

        $resident->forceDelete();
    }
}
