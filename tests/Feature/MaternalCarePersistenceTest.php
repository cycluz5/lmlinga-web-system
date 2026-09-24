<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\MaternalPregnancy;
use App\Models\Resident;
use App\Models\TimbangRecord;
use App\Support\DemoMaternalCare;
use App\Support\RiskAssessmentClinicalValues;
use App\Support\UiRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DB-14 Phase 2 — Maternal Care pregnancy database persistence.
 */
class MaternalCarePersistenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedPersistedResident(array $overrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => $overrides['household_no'] ?? 'HH-960',
            'zone' => 'Zone 1',
            'street' => 'Maternal St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-960',
            'first_name' => $overrides['first_name'] ?? 'Ana',
            'last_name' => $overrides['last_name'] ?? 'Maternal',
            'middle_name' => null,
            'relation' => 'Daughter',
            'sex' => 'Female',
            'birthday' => $overrides['birthday'] ?? '1992-03-15',
            'relationship_status' => 'Married',
            'fp_user' => 'No',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    /**
     * @return array<string, mixed>
     */
    private function validRegisterPayload(array $overrides = []): array
    {
        return array_merge([
            'lmp' => '2026-01-15',
            'gravida' => 2,
            'parity' => 1,
            'edd' => '2026-10-22',
            'weight' => 58.5,
            'height' => 160,
            'bmi' => 22.9,
            'blood_pressure' => '110/70',
        ], $overrides);
    }

    private function storeRoute(Household $household, Resident $resident): string
    {
        return route('household-profiling.members.maternal-care.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);
    }

    private function updateRoute(Household $household, Resident $resident, string $section): string
    {
        return route('household-profiling.members.maternal-care.update', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'section' => $section,
        ]);
    }

    private function registerPregnancy(Household $household, Resident $resident, array $overrides = []): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload($overrides))
            ->assertRedirect(route('household-profiling.members.maternal-care.index', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]));
    }

    public function test_maternal_pregnancies_schema_contract(): void
    {
        $this->assertTrue(Schema::hasTable('maternal_pregnancies'));
        foreach ([
            'resident_id', 'pregnancy_no', 'pregnancy_number', 'status', 'registered_at',
            'lmp', 'gravida', 'parity', 'edd', 'weight', 'height', 'bmi', 'blood_pressure',
            'prenatal', 'immunizations', 'supplementations', 'laboratory', 'delivery',
            'postnatal', 'trans_out', 'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('maternal_pregnancies', $column), $column);
        }
    }

    public function test_store_creates_pregnancy_with_route_resolved_resident_id(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->registerPregnancy($household, $resident);

        $this->assertSame(1, MaternalPregnancy::query()->count());
        $row = MaternalPregnancy::query()->first();
        $this->assertNotNull($row);
        $this->assertSame($resident->id, $row->resident_id);
        $this->assertSame('MC-001', $row->pregnancy_no);
        $this->assertSame(1, $row->pregnancy_number);
        $this->assertSame(MaternalPregnancy::STATUS_ACTIVE, $row->status);
        $this->assertSame('2026-01-15', $row->lmp->toDateString());
        $this->assertSame(2, $row->gravida);
        $this->assertSame(1, $row->parity);
        $this->assertSame('110/70', $row->blood_pressure);
        $this->assertIsArray($row->prenatal);
        $this->assertArrayHasKey('t1_v1', $row->prenatal);
    }

    public function test_browser_supplied_resident_id_and_pregnancy_no_are_prohibited(): void
    {
        ['household' => $h1, 'resident' => $r1] = $this->seedPersistedResident([
            'household_no' => 'HH-961',
            'member_no' => 'MB-961',
        ]);
        ['resident' => $r2] = $this->seedPersistedResident([
            'household_no' => 'HH-962',
            'member_no' => 'MB-962',
            'first_name' => 'Other',
            'last_name' => 'Person',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $this->from(route('household-profiling.members.maternal-care.register', [
            'householdNo' => $h1->household_no,
            'memberId' => $r1->member_no,
        ]))->post($this->storeRoute($h1, $r1), $this->validRegisterPayload([
            'resident_id' => $r2->id,
            'pregnancy_no' => 'MC-999',
            'pregnancy_number' => 9,
            'status' => 'transferred_out',
        ]))->assertSessionHasErrors(['resident_id', 'pregnancy_no', 'pregnancy_number', 'status']);

        $this->assertSame(0, MaternalPregnancy::query()->count());
    }

    public function test_active_pregnancy_and_history_load_from_database(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);

        $html = $this->get(route('household-profiling.members.maternal-care.index', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-lml-mc-mode="overview"', $html);
        $this->assertStringContainsString('Active Pregnancy', $html);
        $this->assertStringContainsString('data-persistence="database"', $html);
        $this->assertStringContainsString('data-demo="false"', $html);
        $this->assertStringContainsString('Maternal record saved.', $html);

        $history = $this->get(route('household-profiling.members.maternal-care.history', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('data-mc-history-empty', $history);
    }

    public function test_section_updates_persist_nested_payload_round_trip(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'date' => '2026-02-01',
                    'height' => '160',
                    'weight' => '59',
                    'bmi' => '23.0',
                    'bp' => '112/70',
                ],
            ],
        ])->assertRedirect(route('household-profiling.members.maternal-care.prenatal', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]));

        $this->put($this->updateRoute($household, $resident, 'immunizations'), [
            'td1' => '2026-02-05',
            'td2' => '2026-03-05',
            'td3' => '',
            'td4' => '',
            'td5' => '',
        ])->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'deworming_date' => '2026-02-10',
            'ifa' => [
                'v1' => ['date' => '2026-02-11', 'tablets' => 30],
            ],
            'mms' => [
                'v1' => ['date' => '2026-02-12', 'tablets' => 30],
            ],
            'calcium' => [
                'v1' => ['date' => '2026-02-13', 'tablets' => 10],
            ],
            'rusf' => [
                ['date' => '2026-02-14'],
                ['date' => '2026-03-14'],
            ],
        ])->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'hepatitis_b' => ['date' => '2026-02-15', 'result' => 'Negative'],
            'cbc' => ['date' => '2026-02-16', 'result' => 'Without Anemia'],
            'gdm' => ['date' => '2026-02-17', 'result' => 'Negative'],
            'urinalysis' => ['date' => '2026-02-18'],
            'ultrasound' => ['date' => '2026-02-19'],
            'syphilis' => ['date' => '2026-02-20', 'result' => 'NON REACTIVE'],
            'hiv' => ['date' => '2026-02-21', 'result' => 'REACTIVE'],
            'cvc' => ['value' => '12.5'],
            'gestational' => ['value' => '0'],
        ])->assertRedirect();

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'delivery_type' => 'VD',
            'birth_weight' => '3.2',
            'status' => 'Live birth',
            'datetime' => '2026-10-20T08:30',
            'date_terminated' => '2026-10-20',
            'birth_attendant' => 'MW',
            'place' => 'public',
            'facility_name' => 'RHU La Medalla',
            'bemonc_cemonc' => 'Yes',
        ])->assertRedirect();

        Carbon::setTestNow(Carbon::parse('2026-10-23')->startOfDay());
        $this->put($this->updateRoute($household, $resident, 'postnatal'), [
            'contacts' => [
                'c1' => '2026-10-20',
                'c2' => '2026-10-23',
                'c3' => '',
                'c4' => '',
            ],
            'supplementation' => [
                'v1' => ['date' => '2026-10-21', 'tablets' => 30],
            ],
        ])->assertRedirect();

        $row = MaternalPregnancy::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('2026-02-01', $row->prenatal['t1_v1']['date']);
        $this->assertSame('112/70', $row->prenatal['t1_v1']['bp']);
        $this->assertSame('2026-02-05', $row->immunizations['td1']);
        $this->assertSame('2026-02-10', $row->supplementations['deworming_date']);
        $this->assertSame(30, (int) $row->supplementations['ifa']['v1']['tablets']);
        $this->assertSame('2026-02-14', $row->supplementations['rusf'][0]['date']);
        $this->assertSame('2026-03-14', $row->supplementations['rusf'][1]['date']);
        $this->assertArrayNotHasKey('id', $row->supplementations['rusf'][0]);
        $this->assertSame('Negative', $row->laboratory['hepatitis_b']['result']);
        $this->assertSame('2026-02-18', $row->laboratory['urinalysis']['date']);
        $this->assertSame('2026-02-19', $row->laboratory['ultrasound']['date']);
        $this->assertSame('NON REACTIVE', $row->laboratory['syphilis']['result']);
        $this->assertSame('REACTIVE', $row->laboratory['hiv']['result']);
        $this->assertSame('12.5', $row->laboratory['cvc']['value']);
        $this->assertSame('0', $row->laboratory['gestational']['value']);
        $this->assertArrayNotHasKey('result', $row->laboratory['urinalysis']);
        $this->assertArrayNotHasKey('date', $row->laboratory['cvc']);
        $this->assertSame('FT', $row->delivery['outcome']);
        $this->assertSame('VD', $row->delivery['delivery_type']);
        $this->assertSame('Live birth', $row->delivery['status']);
        $this->assertSame('2026-10-20', $row->postnatal['contacts']['c1']);
        $this->assertSame(MaternalPregnancy::STATUS_COMPLETED, $row->status);

        $this->get(route('household-profiling.members.maternal-care.prenatal', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertRedirect(route('household-profiling.members.maternal-care.index', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]));

        $index = $this->get(route('household-profiling.members.maternal-care.index', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('data-lml-mc-mode="landing"', $index);
        $this->assertStringNotContainsString('data-lml-mc-mode="overview"', $index);

        Carbon::setTestNow(Carbon::parse('2026-12-01')->startOfDay());
        $historyShow = $this->get(route('household-profiling.members.maternal-care.history.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'pregnancyId' => $row->pregnancy_no,
        ]))->assertOk()->getContent();
        Carbon::setTestNow();
        $this->assertStringContainsString('1 of 8 Visits', $historyShow);
        $this->assertStringContainsString('value="2026-02-01"', $historyShow);

        $deliveryHtml = $this->get(route('household-profiling.members.maternal-care.delivery', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('value="2026-10-20T08:30"', $deliveryHtml);
    }

    public function test_trans_out_moves_pregnancy_to_history(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU La Medalla',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Non-resident/Moved',
            'date_transferred_out' => '2026-06-01',
        ])->assertRedirect(route('household-profiling.members.maternal-care.history', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]));

        $row = MaternalPregnancy::query()->first();
        $this->assertNotNull($row);
        $this->assertSame(MaternalPregnancy::STATUS_TRANSFERRED_OUT, $row->status);
        $this->assertSame('RHU La Medalla', $row->trans_out['to_facility']);

        $index = $this->get(route('household-profiling.members.maternal-care.index', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('data-lml-mc-mode="landing"', $index);

        $history = $this->get(route('household-profiling.members.maternal-care.history', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('data-mc-history-list', $history);
        $this->assertStringContainsString('Pregnancy 1', $history);
    }

    public function test_cross_resident_history_does_not_leak_and_update_stays_scoped(): void
    {
        ['household' => $h1, 'resident' => $r1] = $this->seedPersistedResident([
            'household_no' => 'HH-963',
            'member_no' => 'MB-963',
            'first_name' => 'Owner',
            'last_name' => 'One',
        ]);
        ['household' => $h2, 'resident' => $r2] = $this->seedPersistedResident([
            'household_no' => 'HH-964',
            'member_no' => 'MB-964',
            'first_name' => 'Other',
            'last_name' => 'Two',
        ]);

        $this->registerPregnancy($h1, $r1, ['blood_pressure' => '120/80']);
        $this->registerPregnancy($h2, $r2, [
            'lmp' => '2026-02-01',
            'blood_pressure' => '100/60',
        ]);

        $this->put($this->updateRoute($h2, $r2, 'immunizations'), [
            'td1' => '2026-03-01',
            'td2' => '',
            'td3' => '',
            'td4' => '',
            'td5' => '',
        ])->assertRedirect();

        $owner = MaternalPregnancy::query()->where('resident_id', $r1->id)->first();
        $other = MaternalPregnancy::query()->where('resident_id', $r2->id)->first();
        $this->assertNotNull($owner);
        $this->assertNotNull($other);
        $this->assertSame('120/80', $owner->blood_pressure);
        $this->assertSame('', (string) ($owner->immunizations['td1'] ?? ''));
        $this->assertSame('2026-03-01', $other->immunizations['td1']);

        $htmlOther = $this->get(route('household-profiling.members.maternal-care.index', [
            'householdNo' => $h2->household_no,
            'memberId' => $r2->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('Other Two', $htmlOther);
        $this->assertStringNotContainsString('Owner One', $htmlOther);
    }

    public function test_cross_resident_section_update_without_active_is_forbidden(): void
    {
        ['household' => $h1, 'resident' => $r1] = $this->seedPersistedResident([
            'household_no' => 'HH-965',
            'member_no' => 'MB-965',
        ]);
        ['household' => $h2, 'resident' => $r2] = $this->seedPersistedResident([
            'household_no' => 'HH-966',
            'member_no' => 'MB-966',
            'first_name' => 'Other',
            'last_name' => 'Person',
        ]);

        $this->registerPregnancy($h1, $r1);

        $this->put($this->updateRoute($h2, $r2, 'immunizations'), [
            'td1' => '2026-03-01',
        ])->assertForbidden();

        $this->assertSame(
            '',
            (string) (MaternalPregnancy::query()->where('resident_id', $r1->id)->first()?->immunizations['td1'] ?? '')
        );
    }

    public function test_demo_member_cannot_create_or_mutate_db_rows(): void
    {
        $before = MaternalPregnancy::query()->count();

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('household-profiling.members.maternal-care.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), $this->validRegisterPayload())
            ->assertRedirect(route('household-profiling.members.maternal-care.index', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]));

        $this->assertSame($before, MaternalPregnancy::query()->count());
        $this->assertNull(DemoMaternalCare::activePregnancy('HH-151', 'MB-002'));

        $this->put(route('household-profiling.members.maternal-care.update', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-002',
            'section' => 'immunizations',
        ]), [
            'td1' => '2026-02-01',
            'td2' => '',
            'td3' => '',
            'td4' => '',
            'td5' => '',
        ])->assertForbidden();

        $this->assertSame($before, MaternalPregnancy::query()->count());
        $this->assertNull(DemoMaternalCare::activePregnancy('HH-151', 'MB-002'));
    }

    public function test_ui_still_renders_and_navigation_markers_remain(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);

        $html = $this->get(route('household-profiling.members.maternal-care.prenatal', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-mc-trimester="first"', $html);
        $this->assertStringContainsString('data-mc-edit-for="prenatal"', $html);
        $this->assertStringContainsString(
            'href="'.e(route('household-profiling.members.maternal-care.index', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ])).'"',
            $html
        );

        $memberView = $this->get(route('household-profiling.members.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('data-hh-member-maternal-care', $memberView);
    }

    public function test_pregnancy_no_is_server_generated_and_unique_across_sequence(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->registerPregnancy($household, $resident);
        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2026-06-01',
        ])->assertRedirect();

        $this->registerPregnancy($household, $resident, [
            'lmp' => '2026-07-01',
            'edd' => '2027-04-07',
        ]);

        $numbers = MaternalPregnancy::query()->orderBy('id')->pluck('pregnancy_no')->all();
        $this->assertSame(['MC-001', 'MC-002'], $numbers);
        $sequences = MaternalPregnancy::query()->orderBy('id')->pluck('pregnancy_number')->all();
        $this->assertSame([1, 2], $sequences);
    }

    public function test_store_and_update_routes_remain_ui_role_protected(): void
    {
        $store = \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('household-profiling.members.maternal-care.store');
        $this->assertNotNull($store);
        $this->assertContains('POST', $store->methods());
        $this->assertContains('ui.role', $store->gatherMiddleware());

        $update = \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('household-profiling.members.maternal-care.update');
        $this->assertNotNull($update);
        $this->assertContains('PUT', $update->methods());
        $this->assertContains('ui.role', $update->gatherMiddleware());
    }

    public function test_service_refuses_second_active_pregnancy_for_same_resident(): void
    {
        ['resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-970',
            'member_no' => 'MB-970',
        ]);

        $service = app(\App\Support\MaternalPregnancyService::class);
        $first = $service->createForResident($resident, $this->validRegisterPayload());

        $this->assertSame(MaternalPregnancy::STATUS_ACTIVE, $first->status);
        $this->assertSame(1, MaternalPregnancy::query()
            ->where('resident_id', $resident->id)
            ->where('status', MaternalPregnancy::STATUS_ACTIVE)
            ->count());

        try {
            $service->createForResident($resident, $this->validRegisterPayload([
                'lmp' => '2026-08-01',
                'edd' => '2027-05-08',
            ]));
            $this->fail('Expected ValidationException for duplicate active pregnancy.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('lmp', $e->errors());
        }

        $this->assertSame(1, MaternalPregnancy::query()->where('resident_id', $resident->id)->count());
        $this->assertSame(1, MaternalPregnancy::query()
            ->where('resident_id', $resident->id)
            ->where('status', MaternalPregnancy::STATUS_ACTIVE)
            ->count());
    }

    public function test_new_pregnancy_after_trans_out_increments_and_keeps_history(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-971',
            'member_no' => 'MB-971',
        ]);

        $this->registerPregnancy($household, $resident);
        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU La Medalla',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2026-06-01',
        ])->assertRedirect();

        $this->registerPregnancy($household, $resident, [
            'lmp' => '2026-07-01',
            'edd' => '2027-04-07',
        ]);

        $rows = MaternalPregnancy::query()->where('resident_id', $resident->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);

        $first = $rows[0];
        $second = $rows[1];

        $this->assertSame(MaternalPregnancy::STATUS_TRANSFERRED_OUT, $first->status);
        $this->assertSame(1, $first->pregnancy_number);
        $this->assertSame('MC-001', $first->pregnancy_no);
        $this->assertSame('RHU La Medalla', $first->trans_out['to_facility']);

        $this->assertSame(MaternalPregnancy::STATUS_ACTIVE, $second->status);
        $this->assertSame(2, $second->pregnancy_number);
        $this->assertSame('MC-002', $second->pregnancy_no);
        $this->assertNotSame($first->pregnancy_no, $second->pregnancy_no);

        $this->assertSame(1, MaternalPregnancy::query()
            ->where('resident_id', $resident->id)
            ->where('status', MaternalPregnancy::STATUS_ACTIVE)
            ->count());
    }

    public function test_partial_prenatal_update_preserves_sibling_visits(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-972',
            'member_no' => 'MB-972',
        ]);
        $this->registerPregnancy($household, $resident);

        $row = MaternalPregnancy::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($row);
        $prenatal = is_array($row->prenatal) ? $row->prenatal : [];
        $prenatal['t1_v1'] = [
            'date' => '2026-02-01',
            'height' => '160',
            'weight' => '59',
            'bmi' => '23.0',
            'bp' => '112/70',
        ];
        $prenatal['t1_v2'] = [
            'date' => '2026-02-15',
            'height' => '160',
            'weight' => '60',
            'bmi' => '23.4',
            'bp' => '114/72',
        ];
        $row->prenatal = $prenatal;
        $row->save();

        // Partial / API-style: update only t1_v1.bp — sibling t1_v2 must survive.
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'bp' => '118/76',
                ],
            ],
        ])->assertRedirect();

        $row->refresh();
        $this->assertSame('118/76', $row->prenatal['t1_v1']['bp']);
        $this->assertSame('2026-02-01', $row->prenatal['t1_v1']['date']);
        $this->assertSame('160', $row->prenatal['t1_v1']['height']);
        $this->assertSame('2026-02-15', $row->prenatal['t1_v2']['date']);
        $this->assertSame('114/72', $row->prenatal['t1_v2']['bp']);
        $this->assertSame('60', $row->prenatal['t1_v2']['weight']);
        $this->assertSame('23.0', $row->prenatal['t1_v1']['bmi']);
        $this->assertSame('23.4', $row->prenatal['t1_v2']['bmi']);
    }

    public function test_prenatal_visit_bmi_is_derived_from_that_visit_height_and_weight(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-974',
            'member_no' => 'MB-974',
        ]);
        $this->registerPregnancy($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'date' => '2026-02-01',
                    'height' => '160',
                    'weight' => '59',
                    'bmi' => '99.9',
                    'bp' => '110/70',
                ],
                't2_v1' => [
                    'height' => '160',
                    'weight' => '55',
                    'bmi' => '1.0',
                ],
            ],
        ])->assertRedirect();

        $row = MaternalPregnancy::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(
            RiskAssessmentClinicalValues::calculateBmi('160', '59'),
            $row->prenatal['t1_v1']['bmi']
        );
        $this->assertSame(
            RiskAssessmentClinicalValues::calculateBmi('160', '55'),
            $row->prenatal['t2_v1']['bmi']
        );
        $this->assertSame('23.0', $row->prenatal['t1_v1']['bmi']);
        $this->assertSame('21.5', $row->prenatal['t2_v1']['bmi']);
        $this->assertSame('160', $row->prenatal['t1_v1']['height']);
        $this->assertSame('59', $row->prenatal['t1_v1']['weight']);
        $this->assertCount(8, $row->prenatal);
        $this->assertSame(
            ['t1_v1', 't2_v1', 't2_v2', 't3_v1', 't3_v2', 't3_v3', 't3_v4', 't3_v5'],
            array_keys($row->prenatal)
        );

        $html = $this->get(route('household-profiling.members.maternal-care.prenatal', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertSame(8, substr_count($html, 'data-mc-visit='));
        $this->assertSame(24, substr_count($html, 'data-mc-bmi'));
        $this->assertSame(8, substr_count($html, 'data-mc-derived="bmi"'));
        $this->assertStringContainsString('Height (cm)', $html);
        $this->assertStringContainsString('Weight (kg)', $html);
        $this->assertStringContainsString('>23.0<', $html);
        $this->assertStringContainsString('>21.5<', $html);
        $this->assertStringContainsString('data-bmi-status="normal"', $html);
        $this->assertDoesNotMatchRegularExpression('/name="visits\[[^\]]+\]\[bmi\]"/', $html);
        $this->assertDoesNotMatchRegularExpression('/\bname="bmi"/', $html);
    }

    public function test_prenatal_bmi_is_empty_when_height_or_weight_is_missing(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-975',
            'member_no' => 'MB-975',
        ]);
        $this->registerPregnancy($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'height' => '',
                    'weight' => '59',
                    'bmi' => '22.0',
                ],
                't2_v1' => [
                    'height' => '160',
                    'weight' => '',
                    'bmi' => '22.0',
                ],
            ],
        ])->assertRedirect();

        $row = MaternalPregnancy::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('', $row->prenatal['t1_v1']['bmi']);
        $this->assertSame('', $row->prenatal['t2_v1']['bmi']);
        $this->assertSame('', $row->prenatal['t1_v1']['height']);
        $this->assertSame('59', $row->prenatal['t1_v1']['weight']);
        $this->assertSame('160', $row->prenatal['t2_v1']['height']);
        $this->assertSame('', $row->prenatal['t2_v1']['weight']);
    }

    public function test_unknown_nested_prenatal_keys_do_not_persist(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-973',
            'member_no' => 'MB-973',
        ]);
        $this->registerPregnancy($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'date' => '2026-02-01',
                    'bp' => '110/70',
                    'evil_injected' => 'should-not-persist',
                    'admin' => true,
                ],
            ],
            'evil_section_key' => 'nope',
        ])->assertRedirect();

        $row = MaternalPregnancy::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('2026-02-01', $row->prenatal['t1_v1']['date']);
        $this->assertSame('110/70', $row->prenatal['t1_v1']['bp']);
        $this->assertArrayNotHasKey('evil_injected', $row->prenatal['t1_v1']);
        $this->assertArrayNotHasKey('admin', $row->prenatal['t1_v1']);
        $this->assertArrayNotHasKey('evil_section_key', $row->getAttributes());
        $this->assertSame(
            ['date', 'height', 'weight', 'bmi', 'bp'],
            array_keys($row->prenatal['t1_v1'])
        );
    }

    public function test_section_update_cannot_override_lifecycle_or_ownership_fields(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-974',
            'member_no' => 'MB-974',
        ]);
        ['resident' => $other] = $this->seedPersistedResident([
            'household_no' => 'HH-975',
            'member_no' => 'MB-975',
            'first_name' => 'Other',
            'last_name' => 'Resident',
        ]);

        $this->registerPregnancy($household, $resident);
        $row = MaternalPregnancy::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($row);

        $originalResidentId = $row->resident_id;
        $originalNo = $row->pregnancy_no;
        $originalNumber = $row->pregnancy_number;
        $originalStatus = $row->status;
        $originalRegisteredAt = $row->registered_at?->toDateString();

        $this->from(route('household-profiling.members.maternal-care.prenatal', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->put($this->updateRoute($household, $resident, 'prenatal'), [
            'resident_id' => $other->id,
            'pregnancy_no' => 'MC-999',
            'pregnancy_number' => 99,
            'status' => 'transferred_out',
            'registered_at' => '1999-01-01',
            'visits' => [
                't1_v1' => [
                    'date' => '2026-02-01',
                ],
            ],
        ])->assertSessionHasErrors([
            'resident_id',
            'pregnancy_no',
            'pregnancy_number',
            'status',
            'registered_at',
        ]);

        $row->refresh();
        $this->assertSame($originalResidentId, $row->resident_id);
        $this->assertSame($originalNo, $row->pregnancy_no);
        $this->assertSame($originalNumber, $row->pregnancy_number);
        $this->assertSame($originalStatus, $row->status);
        $this->assertSame($originalRegisteredAt, $row->registered_at?->toDateString());
        $this->assertNotSame('2026-02-01', $row->prenatal['t1_v1']['date'] ?? null);
    }

    public function test_mass_assignment_rejects_lifecycle_fields_on_model(): void
    {
        ['resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-976',
            'member_no' => 'MB-976',
        ]);

        $pregnancy = new MaternalPregnancy;
        $pregnancy->resident_id = $resident->id;
        $pregnancy->pregnancy_no = 'MC-700';
        $pregnancy->pregnancy_number = 1;
        $pregnancy->status = MaternalPregnancy::STATUS_ACTIVE;
        $pregnancy->registered_at = '2026-01-10';
        $pregnancy->fill([
            'pregnancy_number' => 99,
            'status' => 'transferred_out',
            'registered_at' => '1999-01-01',
            'pregnancy_no' => 'MC-HACK',
            'resident_id' => 999999,
            'blood_pressure' => '120/80',
        ]);
        $pregnancy->save();

        $pregnancy->refresh();
        $this->assertSame(1, $pregnancy->pregnancy_number);
        $this->assertSame(MaternalPregnancy::STATUS_ACTIVE, $pregnancy->status);
        $this->assertSame('2026-01-10', $pregnancy->registered_at?->toDateString());
        $this->assertSame('MC-700', $pregnancy->pregnancy_no);
        $this->assertSame($resident->id, $pregnancy->resident_id);
        $this->assertSame('120/80', $pregnancy->blood_pressure);
    }

    public function test_registration_bmi_uses_shared_calculator_and_ignores_submitted_value(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-976',
            'member_no' => 'MB-976',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $registerHtml = $this->get(route('household-profiling.members.maternal-care.register', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('data-mc-bmi', $registerHtml);
        $this->assertStringContainsString('id="lml-mc-bmi"', $registerHtml);
        $this->assertDoesNotMatchRegularExpression('/\bname="bmi"/', $registerHtml);

        $this->registerPregnancy($household, $resident, [
            'weight' => 60,
            'height' => 161,
            'bmi' => '99.9',
        ]);

        $row = MaternalPregnancy::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($row);
        $expected = RiskAssessmentClinicalValues::calculateBmi(161, 60);
        $this->assertSame($expected, (string) $row->bmi);
        $this->assertNotSame('99.9', (string) $row->bmi);
        $this->assertSame($expected, $row->prenatal['t1_v1']['bmi']);
        $this->assertEqualsWithDelta(60.0, (float) $row->weight, 0.01);
        $this->assertEqualsWithDelta(161.0, (float) $row->height, 0.01);
    }

    public function test_prenatal_bmi_does_not_use_latest_nutritional_status_measurement(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-977',
            'member_no' => 'MB-977',
        ]);
        $this->registerPregnancy($household, $resident);

        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-09-01',
            'weight_kg' => 55,
            'height_cm' => 178,
        ]);

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'date' => '2026-02-01',
                    'height' => '160',
                    'weight' => '59',
                    'bmi' => '17.4',
                ],
            ],
        ])->assertRedirect();

        $row = MaternalPregnancy::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($row);
        $expectedVisit = RiskAssessmentClinicalValues::calculateBmi(160, 59);
        $latestNs = RiskAssessmentClinicalValues::calculateBmi(178, 55);
        $this->assertSame($expectedVisit, $row->prenatal['t1_v1']['bmi']);
        $this->assertSame('23.0', $row->prenatal['t1_v1']['bmi']);
        $this->assertSame('17.4', $latestNs);
        $this->assertNotSame($latestNs, $row->prenatal['t1_v1']['bmi']);
    }
}
