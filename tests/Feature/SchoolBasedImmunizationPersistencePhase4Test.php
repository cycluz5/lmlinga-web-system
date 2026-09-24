<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\SchoolImmunization;
use App\Models\SchoolImmunizationDose;
use App\Support\SchoolImmunizationService;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DB-09 Phase 4 — DATE EXISTS → Vaccines Type checkbox derivation.
 */
class SchoolBasedImmunizationPersistencePhase4Test extends TestCase
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
            'household_no' => 'HH-990',
            'zone' => 'Zone 1',
            'street' => 'SBI Phase4 St.',
        ]);

        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => 'MB-990',
            'first_name' => 'Phase',
            'last_name' => 'Four',
            'relation' => 'Daughter',
            'birthday' => now()->subYears(12)->format('Y-m-d'),
            'sex' => 'Female',
        ], $residentOverrides));

        return ['household' => $household, 'resident' => $resident];
    }

    /**
     * @return array{householdNo: string, memberId: string}
     */
    private function routeParams(Household $household, Resident $resident): array
    {
        return [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];
    }

    private function showRoute(array $params): string
    {
        return route('household-profiling.members.school-based-immunization', $params);
    }

    private function storeRoute(array $params): string
    {
        return route('household-profiling.members.school-based-immunization.store', $params);
    }

    /**
     * @return list<array{group: string, key: string, type: string, date: string}>
     */
    private function sixSlotCases(): array
    {
        return [
            ['group' => 'grade-1', 'key' => 'td', 'type' => 'grade1_td', 'date' => '2025-01-11'],
            ['group' => 'grade-1', 'key' => 'mr', 'type' => 'grade1_mr', 'date' => '2025-01-12'],
            ['group' => 'grade-7', 'key' => 'td', 'type' => 'grade7_td', 'date' => '2025-02-11'],
            ['group' => 'grade-7', 'key' => 'mr', 'type' => 'grade7_mr', 'date' => '2025-02-12'],
            ['group' => 'hpv', 'key' => '1', 'type' => 'hpv_1', 'date' => '2025-03-11'],
            ['group' => 'hpv', 'key' => '2', 'type' => 'hpv_2', 'date' => '2025-03-12'],
        ];
    }

    public function test_date_present_derives_corresponding_selected_vaccine_key(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = $this->service->saveForResident($resident, [
            'vaccines' => ['grade-1' => ['td' => '2025-04-04']],
            'vaccine_types' => [],
        ]);

        $this->assertSame(['grade1_td'], $record->selected_vaccine_types);
    }

    public function test_all_six_mappings_derive_from_dates(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $vaccines = [];
        foreach ($this->sixSlotCases() as $case) {
            $vaccines[$case['group']][$case['key']] = $case['date'];
        }

        $record = $this->service->saveForResident($resident, [
            'vaccines' => $vaccines,
            'vaccine_types' => [],
        ]);

        $this->assertSame(SchoolImmunization::SELECTABLE_TYPE_KEYS, $record->selected_vaccine_types);
        $this->assertSame(6, SchoolImmunizationDose::query()->where('school_immunization_id', $record->id)->count());
    }

    public function test_clearing_date_removes_auto_derived_selection_when_checkbox_omitted(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), [
            'vaccines' => ['grade-1' => ['td' => '2025-05-05']],
            'vaccine_types' => [],
        ])->assertRedirect();

        $this->assertSame(
            ['grade1_td'],
            SchoolImmunization::query()->where('resident_id', $resident->id)->first()->selected_vaccine_types
        );

        // Browser clear-date unchecks the mapped box before submit.
        $this->post($this->storeRoute($params), [
            'vaccines' => ['grade-1' => ['td' => '']],
            'vaccine_types' => [],
        ])->assertRedirect();

        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame([], $record->selected_vaccine_types);
        $this->assertNull(
            SchoolImmunizationDose::query()
                ->where('school_immunization_id', $record->id)
                ->where('slot_group', 'grade-1')
                ->where('slot_key', 'td')
                ->value('date_given')
        );

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/id="lml-sbi-type-g1-td"[^>]*\bchecked\b/u', $html);
    }

    public function test_one_slot_cannot_affect_another(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => [
                'grade-1' => ['td' => '2025-01-01', 'mr' => '2025-01-02'],
                'grade-7' => ['td' => '2025-02-01', 'mr' => '2025-02-02'],
                'hpv' => ['1' => '2025-03-01', '2' => '2025-03-02'],
            ],
            'vaccine_types' => [],
        ]);

        $updated = $this->service->saveForResident($resident, [
            'vaccines' => [
                'grade-1' => ['td' => '2025-09-09'],
            ],
            'vaccine_types' => SchoolImmunization::SELECTABLE_TYPE_KEYS,
        ]);

        $this->assertSame(SchoolImmunization::SELECTABLE_TYPE_KEYS, $updated->selected_vaccine_types);

        $mr = SchoolImmunizationDose::query()
            ->where('school_immunization_id', $updated->id)
            ->where('slot_group', 'grade-1')
            ->where('slot_key', 'mr')
            ->first();
        $hpv1 = SchoolImmunizationDose::query()
            ->where('school_immunization_id', $updated->id)
            ->where('slot_group', 'hpv')
            ->where('slot_key', '1')
            ->first();

        $this->assertSame('2025-01-02', $mr->date_given->format('Y-m-d'));
        $this->assertSame('2025-03-01', $hpv1->date_given->format('Y-m-d'));
        $this->assertSame(
            '2025-09-09',
            SchoolImmunizationDose::query()
                ->where('school_immunization_id', $updated->id)
                ->where('slot_group', 'grade-1')
                ->where('slot_key', 'td')
                ->first()
                ->date_given
                ->format('Y-m-d')
        );
    }

    public function test_manual_checkbox_without_date_does_not_invent_a_date(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), [
            'vaccine_types' => ['grade1_mr', 'hpv_2'],
        ])->assertRedirect();

        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame(['grade1_mr', 'hpv_2'], $record->selected_vaccine_types);
        $this->assertSame(0, SchoolImmunizationDose::query()->where('school_immunization_id', $record->id)->count());

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="lml-sbi-type-g1-mr"[^>]*\bchecked\b/u', $html);
        $this->assertMatchesRegularExpression('/id="lml-sbi-type-hpv-2"[^>]*\bchecked\b/u', $html);
        $this->assertStringNotContainsString('lml-sbi__status--recorded', $html);
    }

    public function test_manual_checkbox_only_selection_survives_unrelated_date_edit(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), [
            'vaccine_types' => ['grade7_mr'],
        ])->assertRedirect();

        $this->post($this->storeRoute($params), [
            'vaccines' => ['grade-1' => ['td' => '2025-08-08']],
            'vaccine_types' => ['grade7_mr'],
        ])->assertRedirect();

        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame(['grade1_td', 'grade7_mr'], $record->selected_vaccine_types);

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="lml-sbi-type-g7-mr"[^>]*\bchecked\b/u', $html);
        $this->assertMatchesRegularExpression('/name="vaccines\[grade-7\]\[mr\]"[^>]*value=""/u', $html);
        $this->assertMatchesRegularExpression('/id="lml-sbi-type-g1-td"[^>]*\bchecked\b/u', $html);
        $this->assertStringContainsString('value="2025-08-08"', $html);
    }

    public function test_repeated_save_remains_idempotent(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $payload = [
            'vaccines' => [
                'grade-1' => ['td' => '2025-06-01'],
                'hpv' => ['1' => '2025-06-15'],
            ],
            'vaccine_types' => [],
        ];

        $this->post($this->storeRoute($params), $payload)->assertRedirect();
        $this->post($this->storeRoute($params), $payload)->assertRedirect();
        $this->post($this->storeRoute($params), $payload)->assertRedirect();

        $this->assertSame(1, SchoolImmunization::query()->where('resident_id', $resident->id)->count());
        $record = SchoolImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertSame(['grade1_td', 'hpv_1'], $record->selected_vaccine_types);
        $this->assertSame(2, SchoolImmunizationDose::query()->where('school_immunization_id', $record->id)->count());
    }

    public function test_reload_hydration_reflects_persisted_date_derived_checkbox(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post($this->storeRoute($params), [
            'vaccines' => ['grade-7' => ['mr' => '2025-07-07']],
            'vaccine_types' => [],
        ])->assertRedirect()->assertSessionHas('status', 'School-based immunization saved.');

        $html = $this->get($this->showRoute($params))->assertOk()->getContent();
        $this->assertStringContainsString('value="2025-07-07"', $html);
        $this->assertMatchesRegularExpression('/id="lml-sbi-type-g7-mr"[^>]*\bchecked\b/u', $html);
        $this->assertStringContainsString('lml-sbi__status--recorded', $html);

        // Even if JSON column were empty, read/hydration still derives from dated doses.
        DB::table('school_immunizations')
            ->where('resident_id', $resident->id)
            ->update(['selected_vaccine_types' => json_encode([])]);

        $htmlAgain = $this->get($this->showRoute($params))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="lml-sbi-type-g7-mr"[^>]*\bchecked\b/u', $htmlAgain);
    }

    public function test_invalid_payload_still_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->from($this->showRoute($params))
            ->post($this->storeRoute($params), [
                'vaccines' => ['grade-1' => ['td' => 'not-a-date']],
                'vaccine_types' => [],
            ])
            ->assertRedirect($this->showRoute($params))
            ->assertSessionHasErrors();

        $this->assertSame(0, SchoolImmunization::query()->where('resident_id', $resident->id)->count());
    }

    public function test_browser_supplied_ids_cannot_retarget_or_hijack_record(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $other = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-991',
        ]);

        $foreign = SchoolImmunization::factory()->create([
            'resident_id' => $other->id,
            'selected_vaccine_types' => ['hpv_2'],
        ]);

        $this->post($this->storeRoute([
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'resident_id' => $other->id,
            'school_immunization_id' => $foreign->id,
            'vaccines' => ['hpv' => ['1' => '2025-08-08']],
            'vaccine_types' => [],
        ])->assertSessionHasErrors('resident_id');

        $this->assertDatabaseMissing('school_immunizations', [
            'resident_id' => $resident->id,
        ]);
        $this->assertSame(
            ['hpv_2'],
            SchoolImmunization::query()->where('id', $foreign->id)->first()->selected_vaccine_types
        );
        $this->assertSame(0, SchoolImmunizationDose::query()->where('school_immunization_id', $foreign->id)->count());
    }

    public function test_preview_demo_mode_performs_zero_database_writes(): void
    {
        $beforeHeaders = SchoolImmunization::query()->count();
        $beforeDoses = SchoolImmunizationDose::query()->count();

        $html = $this->get(route('household-profiling.members.school-based-immunization', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('No record found.', $html);
        $this->assertStringNotContainsString('data-persistence="db"', $html);
        $this->assertSame($beforeHeaders, SchoolImmunization::query()->count());
        $this->assertSame($beforeDoses, SchoolImmunizationDose::query()->count());
    }

    public function test_type_key_for_slot_covers_all_six_mappings(): void
    {
        foreach ($this->sixSlotCases() as $case) {
            $this->assertSame(
                $case['type'],
                SchoolImmunization::typeKeyForSlot($case['group'], $case['key'])
            );
        }
    }

    public function test_empty_date_submission_preserves_manual_checkbox_when_still_posted(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = $this->service->saveForResident($resident, [
            'vaccines' => [
                'grade-1' => ['td' => ''],
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
}
