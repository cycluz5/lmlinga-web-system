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
use Tests\TestCase;

/**
 * FR-07 — Derived dose progress / FIC / CIC display from dated unique slots.
 */
class ChildImmunizationDoseProgressTest extends TestCase
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
    private function seedPersistedChild(array $residentOverrides = [], string $householdNo = 'HH-870', string $memberNo = 'MB-870'): array
    {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 1',
            'street' => 'Imm St.',
        ]);

        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => 'Baby',
            'last_name' => 'Progress',
            'relation' => 'Son',
            'birthday' => now()->subMonths(8)->format('Y-m-d'),
            'sex' => 'Male',
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

    /**
     * @return array<string, mixed>
     */
    private function ficCompletePayload(array $overrides = []): array
    {
        return array_merge([
            'vaccines' => [
                'bcg' => [0 => '2025-01-01'],
                'opv' => [0 => '2025-01-15', 1 => '2025-02-15', 2 => '2025-03-15'],
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
                'mmr' => [0 => '2025-09-01'],
            ],
            'vaccine_types' => ['fic'],
        ], $overrides);
    }

    public function test_dpt_two_of_three_dated_slots_is_incomplete(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => [
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20'],
            ],
        ]);

        $progress = $this->service->forResident($resident->fresh())['progress']['dpt-hib-hepb'];

        $this->assertSame(2, $progress['given']);
        $this->assertSame(3, $progress['required']);
        $this->assertFalse($progress['complete']);
        $this->assertSame('2/3', $progress['display']);
    }

    public function test_dpt_three_of_three_dated_slots_is_complete(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => [
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
            ],
        ]);

        $progress = $this->service->forResident($resident->fresh())['progress']['dpt-hib-hepb'];

        $this->assertSame(3, $progress['given']);
        $this->assertSame(3, $progress['required']);
        $this->assertTrue($progress['complete']);
        $this->assertSame('3/3', $progress['display']);
    }

    public function test_opv_two_of_three_keeps_fic_incomplete(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => [
                'bcg' => [0 => '2025-01-01'],
                'opv' => [0 => '2025-01-15', 1 => '2025-02-15'],
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
                'mmr' => [0 => '2025-09-01'],
            ],
        ]);

        $state = $this->service->forResident($resident->fresh());

        $this->assertSame('2/3', $state['progress']['opv']['display']);
        $this->assertFalse($state['fic']['completed']);
    }

    public function test_fic_complete_payload_derives_fic_complete(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, $this->ficCompletePayload());

        $state = $this->service->forResident($resident->fresh());

        $this->assertTrue($state['fic']['completed']);
        $this->assertSame('3/3', $state['progress']['opv']['display']);
        $this->assertSame('3/3', $state['progress']['dpt-hib-hepb']['display']);
    }

    public function test_fic_complete_with_one_mmr_keeps_cic_incomplete(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, $this->ficCompletePayload());

        $state = $this->service->forResident($resident->fresh());

        $this->assertTrue($state['fic']['completed']);
        $this->assertFalse($state['cic']['completed']);
        $this->assertSame('1/2', $state['progress']['mmr']['display']);
        $this->assertFalse($state['progress']['mmr']['complete']);
    }

    public function test_two_dated_mmr_doses_complete_cic_when_other_fic_doses_exist(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, $this->ficCompletePayload([
            'vaccines' => [
                'bcg' => [0 => '2025-01-01'],
                'opv' => [0 => '2025-01-15', 1 => '2025-02-15', 2 => '2025-03-15'],
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
                'mmr' => [0 => '2025-09-01', 1 => '2025-12-01'],
            ],
            'vaccine_types' => ['cic'],
        ]));

        $state = $this->service->forResident($resident->fresh());

        $this->assertTrue($state['fic']['completed']);
        $this->assertTrue($state['cic']['completed']);
        $this->assertSame('2/2', $state['progress']['mmr']['display']);
    }

    public function test_bcg_one_of_two_ui_progress_still_satisfies_fic_bcg_requirement(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, $this->ficCompletePayload());

        $state = $this->service->forResident($resident->fresh());
        $bcg = $state['progress']['bcg'];

        $this->assertSame(1, $bcg['given']);
        $this->assertSame(2, $bcg['required']);
        $this->assertFalse($bcg['complete']);
        $this->assertSame('1/2', $bcg['display']);
        $this->assertSame(1, $state['fic']['counts']['bcg']);
        $this->assertTrue($state['fic']['completed']);
        $this->assertGreaterThanOrEqual(
            ChildImmunizationService::FIC_DOSE_REQUIREMENTS['bcg'],
            $state['fic']['counts']['bcg']
        );
    }

    public function test_null_date_rows_do_not_increment_given(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => [
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '', 2 => ''],
            ],
        ]);

        $record = ChildImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(3, ImmunizationDose::query()->where('child_immunization_id', $record->id)->count());
        $this->assertSame(
            1,
            ImmunizationDose::query()
                ->where('child_immunization_id', $record->id)
                ->whereNotNull('date_given')
                ->count()
        );

        $progress = $this->service->forResident($resident->fresh())['progress']['dpt-hib-hepb'];
        $this->assertSame(1, $progress['given']);
        $this->assertFalse($progress['complete']);
        $this->assertSame('1/3', $progress['display']);
    }

    public function test_malformed_extra_doses_never_display_beyond_required(): void
    {
        $progress = ChildImmunizationService::vaccineProgress([
            ['vaccine_type' => 'dpt-hib-hepb', 'dose_index' => 0, 'date_given' => '2025-01-01'],
            ['vaccine_type' => 'dpt-hib-hepb', 'dose_index' => 1, 'date_given' => '2025-02-01'],
            ['vaccine_type' => 'dpt-hib-hepb', 'dose_index' => 2, 'date_given' => '2025-03-01'],
            ['vaccine_type' => 'dpt-hib-hepb', 'dose_index' => 2, 'date_given' => '2025-03-02'],
            ['vaccine_type' => 'dpt-hib-hepb', 'dose_index' => 9, 'date_given' => '2025-04-01'],
        ]);

        $this->assertSame(3, $progress['dpt-hib-hepb']['given']);
        $this->assertSame(3, $progress['dpt-hib-hepb']['required']);
        $this->assertSame('3/3', $progress['dpt-hib-hepb']['display']);
        $this->assertStringNotContainsString('4/3', $progress['dpt-hib-hepb']['display']);

        ['resident' => $resident] = $this->seedPersistedChild();
        $this->service->saveForResident($resident, [
            'vaccines' => [
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20'],
            ],
        ]);
        $record = ChildImmunization::query()->where('resident_id', $resident->id)->first();
        ImmunizationDose::factory()->create([
            'child_immunization_id' => $record->id,
            'vaccine_type' => 'dpt-hib-hepb',
            'dose_index' => 9,
            'date_given' => '2025-04-01',
        ]);

        $state = $this->service->forResident($resident->fresh());
        $this->assertSame('2/3', $state['progress']['dpt-hib-hepb']['display']);
        $this->assertFalse($state['progress']['dpt-hib-hepb']['complete']);
    }

    public function test_resident_a_doses_do_not_affect_resident_b_progress(): void
    {
        ['resident' => $residentA] = $this->seedPersistedChild([], 'HH-871', 'MB-871');
        ['resident' => $residentB] = $this->seedPersistedChild([], 'HH-872', 'MB-872');

        $this->service->saveForResident($residentA, [
            'vaccines' => [
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
            ],
        ]);
        $this->service->saveForResident($residentB, [
            'vaccines' => [
                'dpt-hib-hepb' => [0 => '', 1 => '', 2 => ''],
            ],
        ]);

        $stateA = $this->service->forResident($residentA->fresh());
        $stateB = $this->service->forResident($residentB->fresh());

        $this->assertTrue($stateA['progress']['dpt-hib-hepb']['complete']);
        $this->assertSame('3/3', $stateA['progress']['dpt-hib-hepb']['display']);
        $this->assertFalse($stateB['progress']['dpt-hib-hepb']['complete']);
        $this->assertSame('0/3', $stateB['progress']['dpt-hib-hepb']['display']);
        $this->assertFalse($stateB['fic']['completed']);
    }

    public function test_partial_vaccine_renders_two_of_three(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccines' => [
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20'],
            ],
        ]);

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-vaccine-progress="dpt-hib-hepb"[^>]*data-vaccine-progress-display="2\/3"/u',
            $html
        );
        $this->assertStringContainsString('2/3', $html);
        $row = $this->typeRowHtml($html, 'dpt-hib-hepb');
        $this->assertStringNotContainsString('lml-child-imm__type-row--complete', $row);
        $this->assertTypeCheckboxUnchecked($row);
    }

    public function test_complete_vaccine_renders_three_of_three_and_completed_styling(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccines' => [
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
            ],
        ]);

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-vaccine-progress="dpt-hib-hepb"[^>]*data-vaccine-progress-display="3\/3"/u',
            $html
        );
        $row = $this->typeRowHtml($html, 'dpt-hib-hepb');
        $this->assertStringContainsString('lml-child-imm__type-row--complete', $row);
        $this->assertTypeCheckboxChecked($row);
    }

    public function test_fic_and_cic_visibly_render_derived_status(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(
            route('household-profiling.members.child-immunization.store', $params),
            $this->ficCompletePayload()
        );

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-fic-display-status="completed"', $html);
        $this->assertStringContainsString('data-cic-display-status="incomplete"', $html);
        $this->assertStringContainsString('data-fic-type-status="completed"', $html);
        $this->assertStringContainsString('data-cic-type-status="incomplete"', $html);
        $this->assertMatchesRegularExpression('/data-fic-display-status="completed"[^>]*>\s*Completed/u', $html);
        $this->assertMatchesRegularExpression('/data-cic-display-status="incomplete"[^>]*>\s*Incomplete/u', $html);
        $this->assertStringContainsString('data-vaccine-progress-display="1/2"', $html);
        $this->assertTypeCheckboxChecked($this->typeRowHtml($html, 'fic'));
        $this->assertTypeCheckboxUnchecked($this->typeRowHtml($html, 'cic'));
    }

    public function test_db_backed_page_does_not_render_figma_demo_status_constants(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccines' => ['bcg' => [0 => '2025-01-01']],
        ]);

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Demo status:', $html);
        $this->assertStringNotContainsString('lml-child-imm__status--attention', $html);
        $this->assertStringNotContainsString('lml-child-imm__status--pending', $html);
    }

    public function test_view_household_navigation_remains_unchanged(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('household-profiling.members.show', $params), $html);
        $this->assertStringContainsString('Vaccines Type', $html);
        $this->assertStringContainsString('data-child-imm-edit="immunization"', $html);
    }

    public function test_saving_dates_still_writes_the_same_dose_rows(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccines' => [
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20'],
            ],
            'vaccine_types' => ['dpt-hib-hepb'],
            'remarks' => 'Keep persistence',
        ]);

        $record = ChildImmunization::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(['dpt-hib-hepb'], $record->selected_vaccine_types);
        $this->assertSame('Keep persistence', $record->remarks);
        $this->assertSame(2, ImmunizationDose::query()->where('child_immunization_id', $record->id)->count());
        $this->assertSame(
            1,
            ImmunizationDose::query()
                ->where('child_immunization_id', $record->id)
                ->where('vaccine_type', 'dpt-hib-hepb')
                ->where('dose_index', 0)
                ->whereDate('date_given', '2025-01-20')
                ->count()
        );
        $this->assertSame(
            1,
            ImmunizationDose::query()
                ->where('child_immunization_id', $record->id)
                ->where('vaccine_type', 'dpt-hib-hepb')
                ->where('dose_index', 1)
                ->whereDate('date_given', '2025-02-20')
                ->count()
        );
    }

    public function test_dose_only_save_preserves_legacy_selected_vaccine_types(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, [
            'vaccines' => [
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
            ],
            'vaccine_types' => ['bcg'],
        ]);

        $this->service->saveForResident($resident->fresh(), [
            'vaccines' => [
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
                'opv' => [0 => '2025-04-01'],
            ],
        ]);

        $record = ChildImmunization::query()->where('resident_id', $resident->id)->first();
        $state = $this->service->forResident($resident->fresh());

        $this->assertSame(['bcg'], $record->selected_vaccine_types);
        $this->assertTrue($state['progress']['dpt-hib-hepb']['complete']);
        $this->assertSame(['bcg'], $state['selected_vaccine_types']);
    }

    public function test_vaccine_type_checkboxes_follow_derived_completion_status(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccines' => [
                'bcg' => [0 => '2025-01-01', 1 => '2025-01-08'],
                'hepa-b' => [],
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20'],
                'opv' => [0 => '2025-01-15', 1 => '2025-02-15', 2 => '2025-03-15'],
                'ipv' => [0 => '2025-04-01'],
                'pcv' => [0 => '2025-05-01', 1 => '2025-06-01', 2 => '2025-07-01'],
                'mmr' => [],
            ],
        ]);

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        foreach (['bcg', 'opv', 'pcv'] as $key) {
            $row = $this->typeRowHtml($html, $key);
            $this->assertStringContainsString('lml-child-imm__type-row--complete', $row);
            $this->assertTypeCheckboxChecked($row);
            $this->assertMatchesRegularExpression('/\bdisabled\b/i', $row);
            $this->assertStringContainsString('data-child-imm-type-status="'.$key.'"', $row);
            $this->assertStringNotContainsString('data-child-imm-field', $row);
        }

        foreach (['hepa-b', 'dpt-hib-hepb', 'ipv', 'mmr'] as $key) {
            $row = $this->typeRowHtml($html, $key);
            $this->assertStringNotContainsString('lml-child-imm__type-row--complete', $row);
            $this->assertTypeCheckboxUnchecked($row);
        }

        $this->assertTypeCheckboxUnchecked($this->typeRowHtml($html, 'fic'));
        $this->assertTypeCheckboxUnchecked($this->typeRowHtml($html, 'cic'));
    }

    public function test_completing_then_clearing_a_dose_updates_checkbox_status(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccines' => [
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
            ],
        ]);

        $completeHtml = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();
        $this->assertTypeCheckboxChecked($this->typeRowHtml($completeHtml, 'dpt-hib-hepb'));

        $this->post(route('household-profiling.members.child-immunization.store', $params), [
            'vaccines' => [
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => ''],
            ],
        ]);

        $incompleteHtml = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();
        $row = $this->typeRowHtml($incompleteHtml, 'dpt-hib-hepb');
        $this->assertStringNotContainsString('lml-child-imm__type-row--complete', $row);
        $this->assertTypeCheckboxUnchecked($row);
    }

    public function test_cic_complete_renders_checked_status_checkbox(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(
            route('household-profiling.members.child-immunization.store', $params),
            $this->ficCompletePayload([
                'vaccines' => [
                    'bcg' => [0 => '2025-01-01'],
                    'opv' => [0 => '2025-01-15', 1 => '2025-02-15', 2 => '2025-03-15'],
                    'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
                    'mmr' => [0 => '2025-09-01', 1 => '2025-10-01'],
                ],
                'vaccine_types' => ['cic'],
            ])
        );

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertTypeCheckboxChecked($this->typeRowHtml($html, 'fic'));
        $this->assertTypeCheckboxChecked($this->typeRowHtml($html, 'cic'));
    }

    public function test_fic_cic_status_persistence_still_follows_manual_checkboxes(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $this->service->saveForResident($resident, $this->ficCompletePayload([
            'vaccine_types' => ['fic'],
        ]));

        $state = $this->service->forResident($resident->fresh());
        $record = ChildImmunization::query()->where('resident_id', $resident->id)->first();

        $this->assertSame(['fic'], $record->selected_vaccine_types);
        $this->assertTrue($state['fic']['completed']);
        $this->assertContains('fic', $state['selected_vaccine_types']);
    }

    public function test_duplicate_dose_protection_remains_unchanged(): void
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

    public function test_empty_state_exposes_zero_progress_for_every_vaccine(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $state = $this->service->forResident($resident);

        $this->assertFalse($state['persisted']);
        foreach (ChildImmunization::DOSE_SLOT_COUNTS as $vaccine => $required) {
            $this->assertSame(0, $state['progress'][$vaccine]['given']);
            $this->assertSame($required, $state['progress'][$vaccine]['required']);
            $this->assertFalse($state['progress'][$vaccine]['complete']);
            $this->assertSame('0/'.$required, $state['progress'][$vaccine]['display']);
        }
    }

    private function typeRowHtml(string $html, string $vaccineKey): string
    {
        $pattern = '/<label[^>]*for="lml-child-imm-type-'.preg_quote($vaccineKey, '/').'"[^>]*>.*?<\\/label>/su';
        $this->assertMatchesRegularExpression($pattern, $html);
        preg_match($pattern, $html, $matches);

        return $matches[0] ?? '';
    }

    private function assertTypeCheckboxChecked(string $rowHtml): void
    {
        $this->assertMatchesRegularExpression(
            '/<input\b[^>]*type="checkbox"[^>]*\bchecked\b/i',
            $rowHtml
        );
    }

    private function assertTypeCheckboxUnchecked(string $rowHtml): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/<input\b[^>]*type="checkbox"[^>]*\bchecked\b/i',
            $rowHtml
        );
    }
}
