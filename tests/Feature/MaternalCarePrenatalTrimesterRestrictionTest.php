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
 * FR-22 — Active prenatal trimester restrictions.
 * Runs on sqlite :memory: only. Does not touch lmlinga_erd_reference.
 */
class MaternalCarePrenatalTrimesterRestrictionTest extends TestCase
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
            'household_no' => $overrides['household_no'] ?? 'HH-2201',
            'zone' => 'Zone 1',
            'street' => 'FR-22 St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-2201',
            'first_name' => $overrides['first_name'] ?? 'Lina',
            'last_name' => $overrides['last_name'] ?? 'Trimester',
            'middle_name' => null,
            'relation' => 'Daughter',
            'sex' => $overrides['sex'] ?? 'Female',
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

    private function prenatalUrl(Household $household, Resident $resident): string
    {
        return route('household-profiling.members.maternal-care.prenatal', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);
    }

    public function test_helper_matches_schedule_week_boundaries(): void
    {
        Carbon::setTestNow(Carbon::parse(self::AS_OF)->startOfDay());
        $this->assertSame('first', DemoMaternalCare::gestationalInfo('2026-08-01')['trimester_key']);
        $this->assertSame('second', DemoMaternalCare::gestationalInfo('2026-05-01')['trimester_key']);
        $this->assertSame('third', DemoMaternalCare::gestationalInfo('2026-01-15')['trimester_key']);
        $this->assertSame('', DemoMaternalCare::gestationalInfo(null)['trimester_key']);
        $this->assertTrue(DemoMaternalCare::allowsNewPrenatalSlot('2026-08-01', 't1_v1'));
        $this->assertFalse(DemoMaternalCare::allowsNewPrenatalSlot('2026-08-01', 't2_v1'));
        $this->assertFalse(DemoMaternalCare::allowsNewPrenatalSlot('2026-08-01', 't3_v1'));
        $this->assertTrue(DemoMaternalCare::allowsNewPrenatalSlot('2026-05-01', 't2_v1'));
        $this->assertFalse(DemoMaternalCare::allowsNewPrenatalSlot('2026-05-01', 't3_v1'));
        $this->assertTrue(DemoMaternalCare::allowsNewPrenatalSlot('2026-01-15', 't3_v5'));
        $this->assertFalse(DemoMaternalCare::allowsNewPrenatalSlot(null, 't2_v1'));
        $this->assertTrue(DemoMaternalCare::allowsNewPrenatalSlot(null, 't1_v1'));
    }

    public function test_first_trimester_allows_t1_and_blocks_t2_t3(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();
        $this->assertSame(1, DB::table('prenatal_visits')->count());

        $this->from($this->prenatalUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'prenatal'), [
                'visits' => [
                    't1_v1' => ['date' => '2026-08-20', 'weight' => '56', 'height' => '160'],
                ],
            ])->assertRedirect();
        $this->assertSame(1, DB::table('prenatal_visits')->where('trimester', '1st')->count());

        $timbangBefore = TimbangRecord::query()->count();
        $this->from($this->prenatalUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'prenatal'), [
                'visits' => [
                    't2_v1' => ['date' => '2026-09-01', 'weight' => '57', 'height' => '160'],
                ],
            ])->assertRedirect()->assertSessionHasErrors('visits.t2_v1');

        $this->from($this->prenatalUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'prenatal'), [
                'visits' => [
                    't3_v1' => ['date' => '2026-09-02', 'weight' => '58', 'height' => '160'],
                ],
            ])->assertRedirect()->assertSessionHasErrors('visits.t3_v1');

        $this->assertSame(0, DB::table('prenatal_visits')->where('trimester', '2nd')->count());
        $this->assertSame(0, DB::table('prenatal_visits')->where('trimester', '3rd')->count());
        $this->assertSame($timbangBefore, TimbangRecord::query()->count());

        $html = $this->get($this->prenatalUrl($household, $resident))->assertOk()->getContent();
        $this->assertStringContainsString('data-mc-trimester-locked="true"', $html);
        $this->assertStringContainsString('data-mc-trimester-unavailable="second"', $html);
        $this->assertStringContainsString('data-mc-trimester-unavailable="third"', $html);
        $this->assertStringContainsString('Available when the pregnancy reaches the 2nd trimester.', $html);
        $this->assertStringContainsString('Available when the pregnancy reaches the 3rd trimester.', $html);
        $this->assertStringContainsString('data-mc-visit="t2_v1"', $html);
        $this->assertStringContainsString('data-mc-visit-locked="true"', $html);
        $this->assertStringContainsString('data-mc-visit="t1_v1"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-mc-visit="t1_v1"[^>]*data-mc-visit-locked="true"/',
            preg_replace('/\s+/', ' ', $html) ?? $html
        );
    }

    public function test_second_trimester_allows_t1_correction_and_t2_blocks_t3(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2202',
            'member_no' => 'MB-2202',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload([
            'lmp' => '2026-05-01',
            'edd' => '2027-02-05',
        ]))->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => ['date' => '2026-05-20', 'weight' => '54', 'height' => '160'],
            ],
        ])->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't2_v1' => ['date' => '2026-08-01', 'weight' => '56', 'height' => '160'],
            ],
        ])->assertRedirect();
        $this->assertSame(1, DB::table('prenatal_visits')->where('trimester', '2nd')->count());

        $before = TimbangRecord::query()->count();
        $this->from($this->prenatalUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'prenatal'), [
                'visits' => [
                    't3_v1' => ['date' => '2026-09-01', 'weight' => '57', 'height' => '160'],
                ],
            ])->assertRedirect()->assertSessionHasErrors('visits.t3_v1');
        $this->assertSame(0, DB::table('prenatal_visits')->where('trimester', '3rd')->count());
        $this->assertSame($before, TimbangRecord::query()->count());
    }

    public function test_third_trimester_allows_historical_and_t3(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2203',
            'member_no' => 'MB-2203',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload([
            'lmp' => '2026-01-15',
            'edd' => '2026-10-22',
        ]))->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't2_v1' => ['date' => '2026-05-01', 'weight' => '56', 'height' => '160'],
                't3_v1' => ['date' => '2026-08-01', 'weight' => '58', 'height' => '160'],
            ],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('prenatal_visits')->where('trimester', '1st')->count());
        $this->assertSame(1, DB::table('prenatal_visits')->where('trimester', '2nd')->count());
        $this->assertSame(1, DB::table('prenatal_visits')->where('trimester', '3rd')->count());

        $html = $this->get($this->prenatalUrl($household, $resident))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-mc-trimester-locked="true"', $html);
    }

    public function test_historical_t1_edit_remains_allowed_in_third_trimester(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2204',
            'member_no' => 'MB-2204',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-20')->startOfDay());
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();
        $this->assertSame('first', DemoMaternalCare::gestationalInfo('2026-08-01')['trimester_key']);

        Carbon::setTestNow(Carbon::parse(self::AS_OF)->startOfDay());
        $this->assertSame('first', DemoMaternalCare::gestationalInfo('2026-08-01')['trimester_key']);

        Carbon::setTestNow(Carbon::parse('2027-03-01')->startOfDay());
        $this->assertSame('third', DemoMaternalCare::gestationalInfo('2026-08-01')['trimester_key']);

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => ['date' => '2026-08-20', 'weight' => '57', 'height' => '161'],
            ],
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $t1 = DB::table('prenatal_visits')->where('trimester', '1st')->where('visit_number', 1)->first();
        $this->assertEqualsWithDelta(57.0, (float) $t1->weight_kg, 0.001);
        $this->assertEqualsWithDelta(161.0, (float) $t1->height_cm, 0.001);
        $this->assertSame(1, DB::table('prenatal_visits')->where('trimester', '1st')->count());
    }

    public function test_missing_lmp_rejects_future_creates_and_keeps_history_readable(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2205',
            'member_no' => 'MB-2205',
        ]);
        $this->post($this->storeRoute($household, $resident), [
            'gravida' => 1,
            'parity' => 0,
            'weight' => 55,
            'height' => 160,
        ])->assertRedirect();

        $this->assertNull(DB::table('maternal_care')->value('lmp_date'));
        $this->assertSame(1, DB::table('prenatal_visits')->count());

        $html = $this->get($this->prenatalUrl($household, $resident))->assertOk()->getContent();
        $this->assertStringContainsString('data-mc-visit="t1_v1"', $html);
        $this->assertStringContainsString('Available when the pregnancy reaches the 2nd trimester.', $html);

        $this->from($this->prenatalUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'prenatal'), [
                'visits' => [
                    't2_v1' => ['date' => '2026-09-01', 'weight' => '56', 'height' => '160'],
                ],
            ])->assertRedirect()->assertSessionHasErrors('visits.t2_v1');

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => ['date' => self::AS_OF, 'weight' => '56', 'height' => '160'],
            ],
        ])->assertRedirect();

        $this->assertSame(0, DB::table('prenatal_visits')->where('trimester', '2nd')->count());
        $this->assertSame(1, DB::table('prenatal_visits')->count());
    }

    public function test_completed_pregnancy_blocks_t3_without_timbang(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2206',
            'member_no' => 'MB-2206',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload([
            'lmp' => '2026-01-15',
            'edd' => '2026-10-22',
        ]))->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't3_v1' => ['date' => '2026-08-01', 'weight' => '58', 'height' => '160'],
            ],
        ])->assertRedirect();

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
        ])->assertRedirect();

        $prenatal = DB::table('prenatal_visits')->count();
        $timbang = TimbangRecord::query()->count();

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't3_v2' => ['date' => '2026-10-21', 'weight' => '59', 'height' => '160'],
            ],
        ])->assertForbidden();

        $this->assertSame($prenatal, DB::table('prenatal_visits')->count());
        $this->assertSame($timbang, TimbangRecord::query()->count());
        $this->assertSame(
            MaternalCareErdMode::STATUS_COMPLETED,
            DB::table('maternal_care')->value('pregnancy_status')
        );
    }

    public function test_offline_future_write_rejected_current_write_persists(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2207',
            'member_no' => 'MB-2207',
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
        $this->assertSame(1, TimbangRecord::query()->count());

        $blocked = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'prenatal',
                'visits' => [
                    't2_v1' => ['date' => '2026-09-01', 'weight' => '57', 'height' => '160'],
                ],
            ],
            $parent,
        ));
        $blocked->assertStatus(422);
        $this->assertSame(0, DB::table('prenatal_visits')->where('trimester', '2nd')->count());
        $this->assertSame(1, TimbangRecord::query()->count());

        $allowed = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'prenatal',
                'visits' => [
                    't1_v1' => ['date' => self::AS_OF, 'weight' => '56', 'height' => '160'],
                ],
            ],
            $parent,
        ));
        $allowed->assertOk();
        $this->assertSame(2, TimbangRecord::query()->count());
        $this->assertEqualsWithDelta(56.0, (float) DB::table('prenatal_visits')->value('weight_kg'), 0.001);
    }

    public function test_trimester_is_taken_from_the_active_episode_not_another_pregnancy(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2208',
            'member_no' => 'MB-2208',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload([
            'lmp' => '2026-01-15',
            'edd' => '2026-10-22',
        ]))->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't3_v1' => ['date' => '2026-08-01', 'weight' => '58', 'height' => '160'],
            ],
        ])->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2026-09-01',
        ])->assertRedirect();

        $historicalT3 = DB::table('prenatal_visits')->where('trimester', '3rd')->count();

        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();
        $activeLmp = DB::table('maternal_care')
            ->where('pregnancy_status', MaternalCareErdMode::STATUS_ACTIVE)
            ->value('lmp_date');
        $this->assertSame('2026-08-01', $activeLmp);

        $this->from($this->prenatalUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'prenatal'), [
                'visits' => [
                    't3_v1' => ['date' => '2026-09-10', 'weight' => '70', 'height' => '160'],
                ],
            ])->assertRedirect()->assertSessionHasErrors('visits.t3_v1');

        $this->assertSame($historicalT3, DB::table('prenatal_visits')->where('trimester', '3rd')->count());

        ['household' => $otherHh, 'resident' => $other] = $this->seedMember([
            'household_no' => 'HH-2209',
            'member_no' => 'MB-2209',
            'first_name' => 'Other',
        ]);
        $this->post($this->storeRoute($otherHh, $other), $this->validRegisterPayload([
            'lmp' => '2026-01-15',
            'edd' => '2026-10-22',
        ]))->assertRedirect();
        $this->put($this->updateRoute($otherHh, $other, 'prenatal'), [
            'visits' => [
                't3_v1' => ['date' => '2026-08-01', 'weight' => '60', 'height' => '160'],
            ],
        ])->assertRedirect();
        $this->assertSame(
            0,
            DB::table('prenatal_visits')
                ->where('maternal_care_id', DB::table('maternal_care')
                    ->where('resident_id', $resident->getKey())
                    ->where('pregnancy_status', MaternalCareErdMode::STATUS_ACTIVE)
                    ->value('maternal_care_id'))
                ->where('trimester', '3rd')
                ->count()
        );
    }
}
