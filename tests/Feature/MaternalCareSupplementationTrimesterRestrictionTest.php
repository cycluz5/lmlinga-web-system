<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\TimbangRecord;
use App\Support\DemoMaternalCare;
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
 * FR-23 — Maternal supplementation trimester restrictions.
 * Runs on sqlite :memory: only. Does not touch lmlinga_erd_reference.
 */
class MaternalCareSupplementationTrimesterRestrictionTest extends TestCase
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
            'household_no' => $overrides['household_no'] ?? 'HH-2301',
            'zone' => 'Zone 1',
            'street' => 'FR-23 St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-2301',
            'first_name' => $overrides['first_name'] ?? 'Sari',
            'last_name' => $overrides['last_name'] ?? 'Supplement',
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
            'lmp' => '2026-08-01',
            'gravida' => 1,
            'parity' => 0,
            'edd' => '2027-05-08',
            'weight' => 55,
            'height' => 160,
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

    private function suppUrl(Household $household, Resident $resident): string
    {
        return route('household-profiling.members.maternal-care.supplementations', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);
    }

    public function test_ifa_mms_visit_keys_map_to_schedule_trimesters(): void
    {
        $this->assertSame('first', DemoMaternalCare::supplementationVisitTrimesterKey('ifa', 'v1'));
        $this->assertSame('second', DemoMaternalCare::supplementationVisitTrimesterKey('ifa', 'v2'));
        $this->assertSame('second', DemoMaternalCare::supplementationVisitTrimesterKey('mms', 'v3'));
        $this->assertSame('third', DemoMaternalCare::supplementationVisitTrimesterKey('mms', 'v4'));
        $this->assertSame('', DemoMaternalCare::supplementationVisitTrimesterKey('calcium', 'v1'));
        $this->assertTrue(DemoMaternalCare::allowsNewSupplementationSlot('2026-08-01', 'ifa', 'v1'));
        $this->assertFalse(DemoMaternalCare::allowsNewSupplementationSlot('2026-08-01', 'ifa', 'v2'));
        $this->assertFalse(DemoMaternalCare::allowsNewSupplementationSlot('2026-08-01', 'ifa', 'v4'));
        $this->assertTrue(DemoMaternalCare::allowsNewSupplementationSlot('2026-05-01', 'ifa', 'v2'));
        $this->assertFalse(DemoMaternalCare::allowsNewSupplementationSlot('2026-05-01', 'ifa', 'v4'));
        $this->assertTrue(DemoMaternalCare::allowsNewSupplementationSlot('2026-01-15', 'ifa', 'v6'));
        $this->assertTrue(DemoMaternalCare::allowsNewSupplementationSlot('2026-08-01', 'calcium', 'v1'));
    }

    public function test_first_trimester_allows_v1_and_blocks_v2_v4(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();
        $this->assertSame(0, DB::table('ifa_supplementation')->count());
        $timbangAfterRegister = TimbangRecord::query()->count();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => [
                'v1' => ['date' => '2026-08-20', 'tablets' => 30],
            ],
        ])->assertRedirect();
        $this->assertSame(1, DB::table('ifa_supplementation')->where('visit_number', 1)->count());
        $this->assertSame($timbangAfterRegister, TimbangRecord::query()->count());

        $this->from($this->suppUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'supplementations'), [
                'ifa' => [
                    'v2' => ['date' => '2026-09-01', 'tablets' => 30],
                ],
            ])->assertRedirect()->assertSessionHasErrors('ifa.v2');

        $this->from($this->suppUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'supplementations'), [
                'mms' => [
                    'v4' => ['date' => '2026-09-02', 'tablets' => 30],
                ],
            ])->assertRedirect()->assertSessionHasErrors('mms.v4');

        $this->assertSame(0, DB::table('ifa_supplementation')->where('visit_number', 2)->count());
        $this->assertSame(0, DB::table('mms_supplementation')->count());
        $this->assertSame($timbangAfterRegister, TimbangRecord::query()->count());

        $html = $this->get($this->suppUrl($household, $resident))->assertOk()->getContent();
        $this->assertStringContainsString('data-mc-supp-unavailable="ifa-v2"', $html);
        $this->assertStringContainsString('Available when the pregnancy reaches the 2nd trimester.', $html);
        $this->assertStringContainsString('Available when the pregnancy reaches the 3rd trimester.', $html);
    }

    public function test_second_trimester_allows_v1_correction_and_v2_blocks_v4(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2302',
            'member_no' => 'MB-2302',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload([
            'lmp' => '2026-05-01',
            'edd' => '2027-02-05',
        ]))->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => [
                'v1' => ['date' => '2026-05-20', 'tablets' => 30],
            ],
        ])->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => [
                'v2' => ['date' => '2026-08-01', 'tablets' => 30],
            ],
        ])->assertRedirect();
        $this->assertSame(2, DB::table('ifa_supplementation')->count());

        $this->from($this->suppUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'supplementations'), [
                'ifa' => [
                    'v4' => ['date' => '2026-09-01', 'tablets' => 30],
                ],
            ])->assertRedirect()->assertSessionHasErrors('ifa.v4');
        $this->assertSame(0, DB::table('ifa_supplementation')->where('visit_number', 4)->count());

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => [
                'v1' => ['date' => '2026-05-21', 'tablets' => 28],
            ],
        ])->assertRedirect();
        $this->assertSame(1, DB::table('ifa_supplementation')->where('visit_number', 1)->count());
        $this->assertSame(28, (int) DB::table('ifa_supplementation')->where('visit_number', 1)->value('tablets_given'));
    }

    public function test_third_trimester_allows_historical_and_v4(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2303',
            'member_no' => 'MB-2303',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload([
            'lmp' => '2026-01-15',
            'edd' => '2026-10-22',
        ]))->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => [
                'v1' => ['date' => '2026-02-01', 'tablets' => 30],
                'v2' => ['date' => '2026-05-01', 'tablets' => 30],
                'v4' => ['date' => '2026-08-01', 'tablets' => 30],
            ],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('ifa_supplementation')->where('visit_number', 1)->count());
        $this->assertSame(1, DB::table('ifa_supplementation')->where('visit_number', 2)->count());
        $this->assertSame(1, DB::table('ifa_supplementation')->where('visit_number', 4)->count());
    }

    public function test_historical_v1_edit_in_third_trimester_updates_same_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2304',
            'member_no' => 'MB-2304',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-20')->startOfDay());
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => [
                'v1' => ['date' => '2026-08-20', 'tablets' => 30],
            ],
        ])->assertRedirect();
        $id = (int) DB::table('ifa_supplementation')->value('ifa_supp_id');

        Carbon::setTestNow(Carbon::parse('2027-03-01')->startOfDay());
        $this->assertSame('third', DemoMaternalCare::gestationalInfo('2026-08-01')['trimester_key']);

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => [
                'v1' => ['date' => '2026-08-21', 'tablets' => 28],
            ],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('ifa_supplementation')->count());
        $this->assertSame($id, (int) DB::table('ifa_supplementation')->value('ifa_supp_id'));
        $this->assertSame(28, (int) DB::table('ifa_supplementation')->value('tablets_given'));
        $this->assertSame('2026-08-21', DB::table('ifa_supplementation')->value('date_given'));
    }

    public function test_empty_future_keys_are_skipped_populated_future_keys_fail(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2305',
            'member_no' => 'MB-2305',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => [
                'v1' => ['date' => '2026-08-20', 'tablets' => 30],
                'v2' => ['date' => '', 'tablets' => ''],
                'v4' => ['date' => '', 'tablets' => ''],
            ],
        ])->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->assertSame(1, DB::table('ifa_supplementation')->count());
        $this->assertSame(1, (int) DB::table('ifa_supplementation')->value('visit_number'));

        $this->from($this->suppUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'supplementations'), [
                'ifa' => [
                    'v2' => ['date' => '2026-09-01', 'tablets' => 30],
                ],
            ])->assertRedirect()->assertSessionHasErrors('ifa.v2');
        $this->assertSame(1, DB::table('ifa_supplementation')->count());
    }

    public function test_missing_lmp_rejects_future_creates(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2306',
            'member_no' => 'MB-2306',
        ]);
        $this->post($this->storeRoute($household, $resident), [
            'gravida' => 1,
            'parity' => 0,
            'weight' => 55,
            'height' => 160,
        ])->assertRedirect();

        $html = $this->get($this->suppUrl($household, $resident))->assertOk()->getContent();
        $this->assertStringContainsString('data-mc-supp-visit="ifa-v1"', $html);
        $this->assertStringContainsString('Available when the pregnancy reaches the 2nd trimester.', $html);

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => [
                'v1' => ['date' => self::AS_OF, 'tablets' => 30],
            ],
        ])->assertRedirect();

        $this->from($this->suppUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'supplementations'), [
                'ifa' => [
                    'v2' => ['date' => self::AS_OF, 'tablets' => 30],
                ],
            ])->assertRedirect()->assertSessionHasErrors('ifa.v2');

        $this->assertSame(1, DB::table('ifa_supplementation')->count());
        $this->assertSame(1, (int) DB::table('ifa_supplementation')->value('visit_number'));
    }

    public function test_completed_pregnancy_blocks_supplementation_without_timbang(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2307',
            'member_no' => 'MB-2307',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload([
            'lmp' => '2026-01-15',
            'edd' => '2026-10-22',
        ]))->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => [
                'v4' => ['date' => '2026-08-01', 'tablets' => 30],
            ],
        ])->assertRedirect();

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
        ])->assertRedirect();

        $supp = DB::table('ifa_supplementation')->count();
        $timbang = TimbangRecord::query()->count();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => [
                'v5' => ['date' => '2026-10-21', 'tablets' => 30],
            ],
        ])->assertForbidden();

        $this->assertSame($supp, DB::table('ifa_supplementation')->count());
        $this->assertSame($timbang, TimbangRecord::query()->count());
    }

    public function test_offline_future_write_rejected_allowed_write_persists(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2308',
            'member_no' => 'MB-2308',
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
            array_merge($this->validRegisterPayload(), [
                '_health_action' => 'maternal_register',
            ]),
            $parent,
        ))->assertOk()->assertJsonPath('code', 'SYNCED');
        $timbang = TimbangRecord::query()->count();

        $blocked = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'supplementations',
                'ifa' => [
                    'v2' => ['date' => '2026-09-01', 'tablets' => 30],
                ],
            ],
            $parent,
        ));
        $blocked->assertStatus(422);
        $this->assertSame(0, DB::table('ifa_supplementation')->count());
        $this->assertSame($timbang, TimbangRecord::query()->count());

        $allowed = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'supplementations',
                'ifa' => [
                    'v1' => ['date' => self::AS_OF, 'tablets' => 30],
                ],
            ],
            $parent,
        ));
        $allowed->assertOk();
        $this->assertSame(1, DB::table('ifa_supplementation')->count());
        $this->assertSame($timbang, TimbangRecord::query()->count());
    }

    public function test_stage_comes_from_active_episode_not_another_pregnancy(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2309',
            'member_no' => 'MB-2309',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload([
            'lmp' => '2026-01-15',
            'edd' => '2026-10-22',
        ]))->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'ifa' => [
                'v4' => ['date' => '2026-08-01', 'tablets' => 30],
            ],
        ])->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2026-09-01',
        ])->assertRedirect();

        $historicalV4 = DB::table('ifa_supplementation')->where('visit_number', 4)->count();

        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();

        $this->from($this->suppUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'supplementations'), [
                'ifa' => [
                    'v4' => ['date' => '2026-09-10', 'tablets' => 40],
                ],
            ])->assertRedirect()->assertSessionHasErrors('ifa.v4');

        $this->assertSame($historicalV4, DB::table('ifa_supplementation')->where('visit_number', 4)->count());

        ['household' => $otherHh, 'resident' => $other] = $this->seedMember([
            'household_no' => 'HH-2310',
            'member_no' => 'MB-2310',
            'first_name' => 'Other',
        ]);
        $this->post($this->storeRoute($otherHh, $other), $this->validRegisterPayload([
            'lmp' => '2026-01-15',
            'edd' => '2026-10-22',
        ]))->assertRedirect();
        $this->put($this->updateRoute($otherHh, $other, 'supplementations'), [
            'ifa' => [
                'v4' => ['date' => '2026-08-01', 'tablets' => 30],
            ],
        ])->assertRedirect();

        $activeId = (int) DB::table('maternal_care')
            ->where('resident_id', $resident->getKey())
            ->where('pregnancy_status', MaternalCareErdMode::STATUS_ACTIVE)
            ->value('maternal_care_id');
        $this->assertSame(
            0,
            DB::table('ifa_supplementation')
                ->where('maternal_care_id', $activeId)
                ->where('visit_number', 4)
                ->count()
        );
    }
}
