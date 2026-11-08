<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\OfflineSyncReceipt;
use App\Models\Resident;
use App\Models\TimbangRecord;
use App\Support\MaternalCareErdMode;
use App\Support\Offline\OfflineOperationType;
use App\Support\StaffRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\ErdMaternalCareSchema;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

/**
 * FR-20 — Maternal measurements → timbang_records (Option B).
 * Runs on sqlite :memory: only. Does not touch lmlinga_erd_reference.
 */
class MaternalCareTimbangSyncTest extends TestCase
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
            'household_no' => $overrides['household_no'] ?? 'HH-2001',
            'zone' => 'Zone 1',
            'street' => 'FR-20 St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-2001',
            'first_name' => $overrides['first_name'] ?? 'Mira',
            'last_name' => $overrides['last_name'] ?? 'Timbang',
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
            'lmp' => '2026-01-15',
            'gravida' => 2,
            'parity' => 1,
            'edd' => '2026-10-22',
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

    public function test_registration_with_weight_and_height_writes_one_timbang_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();

        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();

        $this->assertSame(1, DB::table('maternal_care')->count());
        $this->assertSame(1, DB::table('prenatal_visits')->count());
        $this->assertSame(1, TimbangRecord::query()->count());

        $row = TimbangRecord::query()->first();
        $this->assertSame((int) $resident->getKey(), (int) $row->resident_id);
        $this->assertEqualsWithDelta(55.0, (float) $row->weight_kg, 0.001);
        $this->assertEqualsWithDelta(160.0, (float) $row->height_cm, 0.001);
        $this->assertSame(self::AS_OF, $row->measurement_date->toDateString());
        $this->assertNotSame('2026-01-15', $row->measurement_date->toDateString());
        $this->assertFalse(Schema::hasColumn('timbang_records', 'bmi'));
        $this->assertNull($row->muac_cm);
        $this->assertNull($row->weight_for_age);
        $this->assertNull($row->height_for_age);
        $this->assertNull($row->weight_for_height);
        $this->assertNull($row->remarks);
    }

    public function test_registration_without_measurements_writes_zero_timbang_rows(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2002',
            'member_no' => 'MB-2002',
        ]);

        $this->post($this->storeRoute($household, $resident), [
            'lmp' => '2026-01-15',
            'gravida' => 1,
            'parity' => 0,
            'edd' => '2026-10-22',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('maternal_care')->count());
        $this->assertSame(1, DB::table('prenatal_visits')->count());
        $this->assertSame(0, TimbangRecord::query()->count());
    }

    public function test_t1v1_unchanged_save_does_not_duplicate_and_change_adds_one_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2003',
            'member_no' => 'MB-2003',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();
        $this->assertSame(1, TimbangRecord::query()->count());

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'date' => self::AS_OF,
                    'height' => '160.00',
                    'weight' => '55.0',
                    'bp' => '110/70',
                ],
            ],
        ])->assertRedirect();
        $this->assertSame(1, TimbangRecord::query()->count());

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'date' => self::AS_OF,
                    'height' => '160',
                    'weight' => '56',
                ],
            ],
        ])->assertRedirect();
        $this->assertSame(2, TimbangRecord::query()->count());

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'date' => self::AS_OF,
                    'height' => '161',
                    'weight' => '57',
                ],
            ],
        ])->assertRedirect();
        $this->assertSame(3, TimbangRecord::query()->count());
        $latest = TimbangRecord::query()->orderByDesc('timbang_id')->first();
        $this->assertEqualsWithDelta(57.0, (float) $latest->weight_kg, 0.001);
        $this->assertEqualsWithDelta(161.0, (float) $latest->height_cm, 0.001);
    }

    public function test_later_prenatal_visits_are_distinct_events_even_with_same_weight(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2004',
            'member_no' => 'MB-2004',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't2_v1' => [
                    'date' => '2026-04-01',
                    'height' => '160',
                    'weight' => '55',
                ],
            ],
        ])->assertRedirect();
        $this->assertSame(2, TimbangRecord::query()->count());
        $t2 = TimbangRecord::query()->orderByDesc('timbang_id')->first();
        $this->assertSame('2026-04-01', $t2->measurement_date->toDateString());
        $this->assertEqualsWithDelta(55.0, (float) $t2->weight_kg, 0.001);

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't2_v1' => [
                    'date' => '2026-04-01',
                    'height' => '160',
                    'weight' => '55',
                ],
            ],
        ])->assertRedirect();
        $this->assertSame(2, TimbangRecord::query()->count());

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't2_v2' => [
                    'date' => '2026-05-15',
                    'height' => '160',
                    'weight' => '56',
                ],
            ],
        ])->assertRedirect();
        $this->assertSame(3, TimbangRecord::query()->count());
        $this->assertSame('2026-05-15', TimbangRecord::query()->orderByDesc('timbang_id')->first()->measurement_date->toDateString());
    }

    public function test_timbang_failure_rolls_back_registration(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2005',
            'member_no' => 'MB-2005',
        ]);

        Schema::dropIfExists('timbang_records');
        $this->from(route('household-profiling.members.maternal-care.register', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect()
            ->assertSessionHasErrors('measurement');

        $this->assertSame(0, DB::table('maternal_care')->count());
        $this->assertSame(0, DB::table('prenatal_visits')->count());
    }

    public function test_timbang_failure_rolls_back_prenatal_measurement_update(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2015',
            'member_no' => 'MB-2015',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();
        $this->assertEqualsWithDelta(55.0, (float) DB::table('prenatal_visits')->value('weight_kg'), 0.001);

        Schema::dropIfExists('timbang_records');
        $this->from(route('household-profiling.members.maternal-care.prenatal', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'date' => self::AS_OF,
                    'weight' => '60',
                    'height' => '160',
                ],
            ],
        ])->assertRedirect()->assertSessionHasErrors('measurement');

        $this->assertEqualsWithDelta(55.0, (float) DB::table('prenatal_visits')->value('weight_kg'), 0.001);
    }

    public function test_completed_pregnancy_blocks_prenatal_and_does_not_add_timbang(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2006',
            'member_no' => 'MB-2006',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();
        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
        ])->assertRedirect();

        $before = TimbangRecord::query()->count();
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => ['date' => '2026-11-01', 'weight' => '70', 'height' => '160'],
            ],
        ])->assertForbidden();

        $this->assertSame($before, TimbangRecord::query()->count());
        $this->assertEqualsWithDelta(55.0, (float) TimbangRecord::query()->first()->weight_kg, 0.001);
    }

    public function test_under_10_registration_writes_neither_maternal_nor_timbang(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2007',
            'member_no' => 'MB-2007',
            'birthday' => '2019-03-01',
        ]);

        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertForbidden();

        $this->assertSame(0, DB::table('maternal_care')->count());
        $this->assertSame(0, DB::table('prenatal_visits')->count());
        $this->assertSame(0, TimbangRecord::query()->count());
    }

    public function test_offline_register_and_prenatal_follow_server_side_sync(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2008',
            'member_no' => 'MB-2008',
        ]);

        $parent = [
            'parent_server' => [
                'household_id' => $household->getKey(),
                'household_no' => $household->household_no,
                'resident_id' => $resident->getKey(),
                'member_no' => $resident->member_no,
            ],
        ];
        $operationId = (string) Str::uuid();
        $register = $this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            array_merge($this->validRegisterPayload(), [
                '_health_action' => 'maternal_register',
            ]),
            array_merge($parent, ['operation_id' => $operationId]),
        );

        $this->postOfflineSync($register)->assertOk()->assertJsonPath('code', 'SYNCED');
        $this->assertSame(1, DB::table('maternal_care')->count());
        $this->assertSame(1, DB::table('prenatal_visits')->count());
        $this->assertSame(1, TimbangRecord::query()->count());

        $this->postOfflineSync($register)->assertOk()->assertJsonPath('code', 'ALREADY_APPLIED');
        $this->assertSame(1, TimbangRecord::query()->count());
        $this->assertSame(1, OfflineSyncReceipt::query()->where('operation_id', $operationId)->count());

        $unchanged = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'prenatal',
                'visits' => [
                    't1_v1' => ['date' => self::AS_OF, 'weight' => '55', 'height' => '160'],
                ],
            ],
            $parent,
        ));
        $unchanged->assertOk();
        $this->assertSame(1, TimbangRecord::query()->count());

        $changed = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'prenatal',
                'visits' => [
                    't1_v1' => ['date' => self::AS_OF, 'weight' => '57', 'height' => '160'],
                ],
            ],
            $parent,
        ));
        $changed->assertOk();
        $this->assertSame(2, TimbangRecord::query()->count());
    }

    public function test_nutritional_status_shows_maternal_weight_progress_without_maternal_query(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2009',
            'member_no' => 'MB-2009',
        ]);
        $params = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];

        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();

        $baseline = $this->get(route('household-profiling.members.nutritional-status', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('55 kg', $baseline);
        $this->assertStringContainsString('data-weight-progress-baseline="true"', $baseline);

        Carbon::setTestNow(Carbon::parse('2026-10-01')->startOfDay());
        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'date' => '2026-10-01',
                    'weight' => '57',
                    'height' => '160',
                ],
            ],
        ])->assertRedirect();

        $html = $this->get(route('household-profiling.members.nutritional-status', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('57 kg', $html);
        $this->assertStringContainsString('data-weight-progress="+2 kg"', $html);
        $this->assertStringNotContainsString('maternal_care', $html);
        $this->assertStringNotContainsString('prenatal_visits', $html);
    }
}
