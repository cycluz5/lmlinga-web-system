<?php

namespace Tests\Feature;

use App\Support\AtRestRecord;
use App\Models\Household;
use App\Models\Resident;
use App\Models\TimbangRecord;
use App\Support\MaternalCareErdMode;
use App\Support\Offline\OfflineOperationType;
use App\Support\StaffRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ErdMaternalCareSchema;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

/**
 * FR-24 — skip meaningless new maternal child rows. sqlite :memory: only.
 */
class MaternalCareEmptyChildRowSuppressionTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    private const AS_OF = '2026-09-12';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::AS_OF)->startOfDay());
        ErdMaternalCareSchema::ensure();
        if (! Schema::hasTable('timbang_records')) {
            $migration = include database_path('migrations/2026_09_12_100000_create_timbang_records_table.php');
            $migration->up();
        }
        $this->assertTrue(MaternalCareErdMode::isPersistenceActive());
        $this->actingAsStaff(StaffRole::BHW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedMember(array $overrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => $overrides['household_no'] ?? 'HH-2401',
            'zone' => 'Zone 1',
            'street' => 'FR-24 St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-2401',
            'first_name' => $overrides['first_name'] ?? 'Nora',
            'last_name' => $overrides['last_name'] ?? 'Emptyrow',
            'middle_name' => null,
            'relation' => 'Daughter',
            'sex' => 'Female',
            'birthday' => '1992-03-15',
            'relationship_status' => 'Married',
            'fp_user' => 'No',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    /**
     * @return array<string, mixed>
     */
    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'lmp' => '2026-01-15',
            'gravida' => 1,
            'parity' => 0,
            'edd' => '2026-10-22',
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

    public function test_registration_without_measurements_still_creates_t1v1_not_timbang(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();

        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->assertSame(1, DB::table('maternal_care')->count());
        $this->assertSame(1, DB::table('prenatal_visits')->count());
        $visit = DB::table('prenatal_visits')->first();
        $this->assertSame('1st', $visit->trimester);
        $this->assertSame(1, (int) $visit->visit_number);
        $this->assertSame(self::AS_OF, substr((string) $visit->visit_date, 0, 10));
        $this->assertNull($visit->weight_kg);
        $this->assertNull($visit->height_cm);
        $this->assertSame(0, TimbangRecord::query()->count());
        $this->assertSame(0, DB::table('ifa_supplementation')->count());
        $this->assertSame(0, DB::table('deworming_supplementation')->count());
    }

    public function test_empty_non_t1v1_prenatal_slot_does_not_insert_or_sync_timbang(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2402',
            'member_no' => 'MB-2402',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload([
            'weight' => 55,
            'height' => 160,
        ]))->assertRedirect();
        $before = DB::table('prenatal_visits')->count();
        $timbang = TimbangRecord::query()->count();
        $this->assertSame(1, $before);
        $this->assertSame(1, $timbang);

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't2_v1' => ['date' => '', 'height' => '', 'weight' => '', 'bp' => ''],
                't3_v1' => ['date' => '', 'weight' => ''],
            ],
        ])->assertRedirect();

        $this->assertSame($before, DB::table('prenatal_visits')->count());
        $this->assertSame($timbang, TimbangRecord::query()->count());
        $this->assertSame(0, DB::table('prenatal_visits')->where('trimester', '2nd')->count());

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't2_v1' => ['date' => '2026-05-01', 'height' => '160', 'weight' => '57'],
            ],
        ])->assertRedirect();
        $this->assertSame(2, DB::table('prenatal_visits')->count());
        $this->assertSame(2, TimbangRecord::query()->count());
    }

    public function test_existing_prenatal_row_is_not_deleted_when_optional_fields_cleared(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2403',
            'member_no' => 'MB-2403',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload([
            'weight' => 55,
            'height' => 160,
            'blood_pressure' => '110/70',
        ]))->assertRedirect();
        $id = (int) DB::table('prenatal_visits')->value('prenatal_visit_id');

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'date' => self::AS_OF,
                    'height' => '',
                    'weight' => '',
                    'bp' => '',
                ],
            ],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('prenatal_visits')->count());
        $row = DB::table('prenatal_visits')->first();
        $this->assertSame($id, (int) $row->prenatal_visit_id);
        $this->assertSame(self::AS_OF, substr((string) $row->visit_date, 0, 10));
        $this->assertNull($row->weight_kg);
        $this->assertNull($row->height_cm);
        $this->assertNull($row->bp_systolic);
    }

    public function test_empty_supplement_slots_do_not_insert_populated_future_still_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2404',
            'member_no' => 'MB-2404',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();
        $timbang = TimbangRecord::query()->count();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => [
                'v1' => ['date' => '', 'tablets' => ''],
            ],
            'mms' => [
                'v1' => ['date' => '', 'tablets' => ''],
            ],
            'calcium' => [
                'v1' => ['date' => '', 'tablets' => ''],
            ],
            'rusf' => [
                ['date' => ''],
            ],
        ])->assertRedirect();
        $this->assertSame(0, DB::table('ifa_supplementation')->count());
        $this->assertSame(0, DB::table('mms_supplementation')->count());
        $this->assertSame(0, DB::table('cc_supplementation')->count());
        $this->assertSame(0, DB::table('rusf_supplementation')->count());
        $this->assertSame($timbang, TimbangRecord::query()->count());

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => ['v1' => ['date' => '2026-02-01', 'tablets' => 30]],
            'mms' => ['v1' => ['date' => '2026-02-02', 'tablets' => 0]],
            'calcium' => ['v1' => ['date' => '2026-02-03', 'tablets' => 10]],
        ])->assertRedirect();
        $this->assertSame(1, DB::table('ifa_supplementation')->count());
        $this->assertSame(1, DB::table('mms_supplementation')->count());
        $this->assertSame(1, DB::table('cc_supplementation')->count());
        $this->assertSame(0, (int) DB::table('mms_supplementation')->value('tablets_given'));
        $this->assertSame($timbang, TimbangRecord::query()->count());

        ['household' => $firstHh, 'resident' => $first] = $this->seedMember([
            'household_no' => 'HH-2405',
            'member_no' => 'MB-2405',
        ]);
        $this->post($this->storeRoute($firstHh, $first), $this->registerPayload([
            'lmp' => '2026-08-01',
            'edd' => '2027-05-08',
        ]))->assertRedirect();
        $this->from(route('household-profiling.members.maternal-care.supplementations', [
            'householdNo' => $firstHh->household_no,
            'memberId' => $first->member_no,
        ]))->put($this->updateRoute($firstHh, $first, 'supplementations'), [
            'ifa' => ['v2' => ['date' => '2026-09-01', 'tablets' => 30]],
        ])->assertRedirect()->assertSessionHasErrors('ifa.v2');
        $this->assertSame(0, DB::table('ifa_supplementation')->where('visit_number', 2)->count());
    }

    public function test_empty_deworming_does_not_insert_existing_row_is_preserved(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2406',
            'member_no' => 'MB-2406',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'deworming_date' => '',
        ])->assertRedirect();
        $this->assertSame(0, DB::table('deworming_supplementation')->count());

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'deworming_date' => '2026-05-01',
        ])->assertRedirect();
        $this->assertSame(1, DB::table('deworming_supplementation')->count());
        $id = (int) DB::table('deworming_supplementation')->value('deworming_supp_id');

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'deworming_date' => '',
        ])->assertRedirect();
        $this->assertSame(1, DB::table('deworming_supplementation')->count());
        $this->assertSame($id, (int) DB::table('deworming_supplementation')->value('deworming_supp_id'));
        $this->assertNull(DB::table('deworming_supplementation')->value('date_given'));
    }

    public function test_empty_lab_rows_are_skipped_negative_result_persists(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2407',
            'member_no' => 'MB-2407',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'hepatitis_b' => ['date' => '', 'result' => ''],
            'cbc' => ['date' => '', 'result' => ''],
            'gdm' => ['date' => '', 'result' => ''],
        ])->assertRedirect();
        $this->assertSame(0, DB::table('hepatitis_b_screening')->count());
        $this->assertSame(0, DB::table('cbc_hgb_hct_screening')->count());
        $this->assertSame(0, DB::table('gdm_screening')->count());

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'hepatitis_b' => ['date' => '', 'result' => 'Negative'],
            'cbc' => ['date' => '2026-03-01', 'result' => 'Without Anemia'],
        ])->assertRedirect();
        $this->assertSame(1, DB::table('hepatitis_b_screening')->count());
        $this->assertSame('Negative', AtRestRecord::open(DB::table('hepatitis_b_screening')->value('result'), 'hepatitis_b_screening', 'result'));
        $this->assertNull(DB::table('hepatitis_b_screening')->value('date_screened'));
        $this->assertSame(1, DB::table('cbc_hgb_hct_screening')->count());
        $this->assertSame(0, DB::table('gdm_screening')->count());
        $this->assertSame(0, DB::table('urinalysis_screening')->count());
        $this->assertSame(0, DB::table('cvc_screening')->count());
        $this->assertSame(0, DB::table('syphilis_screening')->count());
    }

    public function test_empty_new_lab_screens_are_skipped_and_numeric_zero_persists(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2410',
            'member_no' => 'MB-2410',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'urinalysis' => ['date' => ''],
            'ultrasound' => ['date' => ''],
            'syphilis' => ['date' => '', 'result' => ''],
            'hiv' => ['date' => '', 'result' => ''],
            'cvc' => ['value' => ''],
            'gestational' => ['value' => ''],
        ])->assertRedirect();
        $this->assertSame(0, DB::table('urinalysis_screening')->count());
        $this->assertSame(0, DB::table('ultrasound_screening')->count());
        $this->assertSame(0, DB::table('syphilis_screening')->count());
        $this->assertSame(0, DB::table('hiv_screening')->count());
        $this->assertSame(0, DB::table('cvc_screening')->count());
        $this->assertSame(0, DB::table('gestational_screening')->count());

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'urinalysis' => ['date' => '2026-03-02'],
            'syphilis' => ['date' => '', 'result' => 'REACTIVE'],
            'cvc' => ['value' => '0'],
        ])->assertRedirect();
        $this->assertSame(1, DB::table('urinalysis_screening')->count());
        $this->assertSame('2026-03-02', DB::table('urinalysis_screening')->value('date_screened'));
        $this->assertSame(1, DB::table('syphilis_screening')->count());
        $this->assertSame('REACTIVE', AtRestRecord::open(DB::table('syphilis_screening')->value('result'), 'syphilis_screening', 'result'));
        $this->assertNull(DB::table('syphilis_screening')->value('date_screened'));
        $this->assertSame(1, DB::table('cvc_screening')->count());
        $this->assertEqualsWithDelta(0.0, (float) DB::table('cvc_screening')->value('value'), 0.001);
        $this->assertSame(0, DB::table('ultrasound_screening')->count());
        $this->assertSame(0, DB::table('gestational_screening')->count());
        $this->assertSame(0, DB::table('gdm_screening')->count());
        $this->assertSame(0, DB::table('cbc_hgb_hct_screening')->count());
    }

    public function test_empty_postnatal_does_not_create_stub_delivery_or_contacts(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2408',
            'member_no' => 'MB-2408',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'postnatal'), [
            'contacts' => [
                'c1' => '',
                'c2' => '',
                'c3' => '',
                'c4' => '',
            ],
            'supplementation' => [
                'v1' => ['date' => '', 'tablets' => ''],
            ],
            'vitamin_a' => ['date' => ''],
        ])->assertRedirect();
        $this->assertSame(0, DB::table('delivery_outcomes')->count());
        $this->assertSame(0, DB::table('postnatal_care_visits')->count());
        $this->assertSame(0, DB::table('postpartum_ifa_supplementation')->count());
        $this->assertSame(0, DB::table('postpartum_vitamin_a_supplementation')->count());
    }

    public function test_empty_delivery_submit_creates_no_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2411',
            'member_no' => 'MB-2411',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => '',
            'delivery_type' => '',
            'birth_weight' => '',
            'delivery_status' => '',
            'datetime' => '',
            'date_terminated' => '',
            'birth_attendant' => '',
            'birth_attendant_other' => '',
            'place' => '',
            'facility_name' => '',
            'bemonc_cemonc' => '',
            'newborn_sex' => '',
            'plurality' => '',
        ])->assertRedirect();

        $this->assertSame(0, DB::table('delivery_outcomes')->count());
        $this->assertSame(
            MaternalCareErdMode::STATUS_ACTIVE,
            DB::table('maternal_care')->value('pregnancy_status')
        );
    }

    public function test_delivery_outcome_and_existing_history_are_not_suppressed(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2409',
            'member_no' => 'MB-2409',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload([
            'weight' => 55,
            'height' => 160,
        ]))->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't2_v1' => ['date' => '2026-05-01', 'weight' => '57', 'height' => '160'],
            ],
        ])->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => ['v1' => ['date' => '2026-02-01', 'tablets' => 30]],
        ])->assertRedirect();

        $prenatal = DB::table('prenatal_visits')->count();
        $ifa = DB::table('ifa_supplementation')->count();
        $timbang = TimbangRecord::query()->count();

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('delivery_outcomes')->count());
        $this->assertSame('FT', DB::table('delivery_outcomes')->value('outcome'));
        $this->assertSame(
            MaternalCareErdMode::STATUS_COMPLETED,
            DB::table('maternal_care')->value('pregnancy_status')
        );
        $this->assertSame($prenatal, DB::table('prenatal_visits')->count());
        $this->assertSame($ifa, DB::table('ifa_supplementation')->count());
        $this->assertSame($timbang, TimbangRecord::query()->count());
    }

    public function test_offline_empty_slots_skip_rows_future_populated_still_rejected(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2410',
            'member_no' => 'MB-2410',
        ]);
        $parent = [
            'parent_server' => [
                'household_id' => $household->getKey(),
                'household_no' => $household->household_no,
                'resident_id' => $resident->getKey(),
                'member_no' => $resident->member_no,
            ],
        ];

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            array_merge($this->registerPayload(['lmp' => '2026-08-01', 'edd' => '2027-05-08']), [
                '_health_action' => 'maternal_register',
            ]),
            $parent,
        ))->assertOk()->assertJsonPath('code', 'SYNCED');
        $this->assertSame(1, DB::table('prenatal_visits')->count());

        $empty = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'prenatal',
                'visits' => [
                    't1_v1' => ['date' => self::AS_OF, 'weight' => '', 'height' => ''],
                    't2_v1' => ['date' => '', 'weight' => ''],
                ],
            ],
            $parent,
        ));
        $empty->assertOk();
        $this->assertSame(1, DB::table('prenatal_visits')->count());
        $this->assertSame(0, TimbangRecord::query()->count());

        $emptySupp = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'supplementations',
                'ifa' => ['v1' => ['date' => '', 'tablets' => '']],
            ],
            $parent,
        ));
        $emptySupp->assertOk();
        $this->assertSame(0, DB::table('ifa_supplementation')->count());

        $future = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'supplementations',
                'ifa' => ['v2' => ['date' => '2026-09-01', 'tablets' => 30]],
            ],
            $parent,
        ));
        $future->assertStatus(422);
        $this->assertSame(0, DB::table('ifa_supplementation')->count());

        $allowed = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'supplementations',
                'ifa' => ['v1' => ['date' => self::AS_OF, 'tablets' => 30]],
            ],
            $parent,
        ));
        $allowed->assertOk();
        $this->assertSame(1, DB::table('ifa_supplementation')->count());
    }
}
