<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
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
 * #11 — Optional monthly RUSF supplementation (ERD + request + offline).
 * sqlite :memory: only.
 */
class MaternalCareRusfSupplementationTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    private const AS_OF = '2026-09-12';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::AS_OF)->startOfDay());
        ErdMaternalCareSchema::ensure();
        $this->assertTrue(MaternalCareErdMode::isPersistenceActive());
        $this->assertTrue(Schema::hasTable('rusf_supplementation'));
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
            'household_no' => $overrides['household_no'] ?? 'HH-1101',
            'zone' => 'Zone 1',
            'street' => 'RUSF St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-1101',
            'first_name' => $overrides['first_name'] ?? 'Rosa',
            'last_name' => $overrides['last_name'] ?? 'Rusf',
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

    private function suppUrl(Household $household, Resident $resident): string
    {
        return route('household-profiling.members.maternal-care.supplementations', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);
    }

    private function maternalCareId(Resident $resident): int
    {
        return (int) DB::table('maternal_care')
            ->where('resident_id', $resident->getKey())
            ->orderByDesc('maternal_care_id')
            ->value('maternal_care_id');
    }

    public function test_schema_has_date_only_rusf_columns(): void
    {
        $columns = Schema::getColumnListing('rusf_supplementation');
        $this->assertContains('rusf_supp_id', $columns);
        $this->assertContains('maternal_care_id', $columns);
        $this->assertContains('date_given', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
        $this->assertNotContains('visit_number', $columns);
        $this->assertNotContains('month_number', $columns);
        $this->assertNotContains('tablets_given', $columns);
    }

    public function test_empty_rusf_creates_no_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => ''],
            ],
        ])->assertRedirect();

        $this->assertSame(0, DB::table('rusf_supplementation')->count());
    }

    public function test_blank_extra_field_creates_no_row_after_existing_date(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1102',
            'member_no' => 'MB-1102',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => '2026-02-01'],
                ['date' => ''],
            ],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('rusf_supplementation')->count());
        $this->assertSame('2026-02-01', DB::table('rusf_supplementation')->value('date_given'));
    }

    public function test_one_rusf_date_persists(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1103',
            'member_no' => 'MB-1103',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => '2026-02-15'],
            ],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('rusf_supplementation')->count());
        $row = DB::table('rusf_supplementation')->first();
        $this->assertSame($this->maternalCareId($resident), (int) $row->maternal_care_id);
        $this->assertSame('2026-02-15', $row->date_given);
    }

    public function test_two_monthly_dates_persist_without_overwriting(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1104',
            'member_no' => 'MB-1104',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => '2026-01-15'],
            ],
        ])->assertRedirect();
        $firstId = (int) DB::table('rusf_supplementation')->value('rusf_supp_id');

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => '2026-02-12'],
            ],
        ])->assertRedirect();

        $rows = DB::table('rusf_supplementation')->orderBy('date_given')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-01-15', $rows[0]->date_given);
        $this->assertSame('2026-02-12', $rows[1]->date_given);
        $this->assertSame($firstId, (int) $rows[0]->rusf_supp_id);
    }

    public function test_duplicate_same_day_in_payload_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1105',
            'member_no' => 'MB-1105',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->from($this->suppUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'supplementations'), [
                'rusf' => [
                    ['date' => '2026-03-01'],
                    ['date' => '2026-03-01'],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('rusf.1.date');

        $this->assertSame(0, DB::table('rusf_supplementation')->count());
    }

    public function test_duplicate_existing_date_does_not_insert_second_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1106',
            'member_no' => 'MB-1106',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => '2026-03-10'],
            ],
        ])->assertRedirect();
        $id = (int) DB::table('rusf_supplementation')->value('rusf_supp_id');

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => '2026-03-10'],
            ],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('rusf_supplementation')->count());
        $this->assertSame($id, (int) DB::table('rusf_supplementation')->value('rusf_supp_id'));
    }

    public function test_invalid_date_returns_422(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1107',
            'member_no' => 'MB-1107',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->from($this->suppUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'supplementations'), [
                'rusf' => [
                    ['date' => 'not-a-date'],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('rusf.0.date');

        $this->assertSame(0, DB::table('rusf_supplementation')->count());
    }

    public function test_invalid_date_error_is_visible_on_the_page(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1113',
            'member_no' => 'MB-1113',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => 'not-a-date'],
            ],
        ])->assertRedirect();

        $html = $this->get($this->suppUrl($household, $resident))->assertOk()->getContent();
        $this->assertStringContainsString('lml-mc__form-errors', $html);
    }

    public function test_existing_id_updates_the_same_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1108',
            'member_no' => 'MB-1108',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => '2026-04-01'],
            ],
        ])->assertRedirect();
        $id = (int) DB::table('rusf_supplementation')->value('rusf_supp_id');

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['id' => $id, 'date' => '2026-04-08'],
            ],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('rusf_supplementation')->count());
        $this->assertSame($id, (int) DB::table('rusf_supplementation')->value('rusf_supp_id'));
        $this->assertSame('2026-04-08', DB::table('rusf_supplementation')->value('date_given'));
    }

    public function test_blank_date_with_existing_id_does_not_null_the_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1109',
            'member_no' => 'MB-1109',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => '2026-05-01'],
            ],
        ])->assertRedirect();
        $id = (int) DB::table('rusf_supplementation')->value('rusf_supp_id');

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['id' => $id, 'date' => ''],
            ],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('rusf_supplementation')->count());
        $this->assertSame('2026-05-01', DB::table('rusf_supplementation')->value('date_given'));
    }

    public function test_missing_rusf_key_preserves_existing_rows(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1110',
            'member_no' => 'MB-1110',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())
            ->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => '2026-02-01'],
                ['date' => '2026-03-01'],
            ],
            'deworming_date' => '2026-02-10',
        ])->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'deworming_date' => '2026-02-11',
        ])->assertRedirect();

        $this->assertSame(2, DB::table('rusf_supplementation')->count());
        $this->assertSame('2026-02-11', DB::table('deworming_supplementation')->value('date_given'));
        $dates = DB::table('rusf_supplementation')->orderBy('date_given')->pluck('date_given')->all();
        $this->assertSame(['2026-02-01', '2026-03-01'], $dates);
    }

    public function test_guessed_id_from_another_episode_cannot_update(): void
    {
        ['household' => $firstHh, 'resident' => $first] = $this->seedMember([
            'household_no' => 'HH-1111',
            'member_no' => 'MB-1111',
            'first_name' => 'First',
        ]);
        ['household' => $secondHh, 'resident' => $second] = $this->seedMember([
            'household_no' => 'HH-1112',
            'member_no' => 'MB-1112',
            'first_name' => 'Second',
        ]);

        $this->post($this->storeRoute($firstHh, $first), $this->registerPayload())->assertRedirect();
        $this->post($this->storeRoute($secondHh, $second), $this->registerPayload())->assertRedirect();

        $this->put($this->updateRoute($firstHh, $first, 'supplementations'), [
            'rusf' => [
                ['date' => '2026-02-20'],
            ],
        ])->assertRedirect();
        $foreignId = (int) DB::table('rusf_supplementation')->value('rusf_supp_id');
        $firstCareId = $this->maternalCareId($first);

        $this->put($this->updateRoute($secondHh, $second, 'supplementations'), [
            'rusf' => [
                ['id' => $foreignId, 'date' => '2026-06-01'],
            ],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('rusf_supplementation')->count());
        $this->assertSame('2026-02-20', DB::table('rusf_supplementation')->value('date_given'));
        $this->assertSame($firstCareId, (int) DB::table('rusf_supplementation')->value('maternal_care_id'));
        $this->assertSame(0, DB::table('rusf_supplementation')->where('maternal_care_id', $this->maternalCareId($second))->count());
    }

    public function test_completed_episode_cannot_be_edited(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1113',
            'member_no' => 'MB-1113',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())->assertRedirect();
        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => '2026-03-15'],
            ],
        ])->assertRedirect();

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
        ])->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => '2026-10-21'],
            ],
        ])->assertForbidden();

        $this->assertSame(1, DB::table('rusf_supplementation')->count());
        $this->assertSame('2026-03-15', DB::table('rusf_supplementation')->value('date_given'));
    }

    public function test_rusf_has_no_trimester_restriction(): void
    {
        $this->assertSame('', DemoMaternalCare::supplementationVisitTrimesterKey('rusf', 'v1'));
        $this->assertArrayNotHasKey('visits', DemoMaternalCare::supplementationSchedule()['rusf']);
        $this->assertArrayNotHasKey('max', DemoMaternalCare::supplementationSchedule()['rusf']);

        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1114',
            'member_no' => 'MB-1114',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload([
            'lmp' => '2026-08-01',
            'edd' => '2027-05-08',
        ]))->assertRedirect();

        $this->from($this->suppUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'supplementations'), [
                'ifa' => [
                    'v2' => ['date' => '2026-09-01', 'tablets' => 30],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('ifa.v2');

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => '2026-09-10'],
            ],
        ])->assertRedirect();

        $this->assertSame(0, DB::table('ifa_supplementation')->count());
        $this->assertSame(1, DB::table('rusf_supplementation')->count());
        $this->assertSame('2026-09-10', DB::table('rusf_supplementation')->value('date_given'));
    }

    public function test_ifa_mms_calcium_deworming_remain_unchanged_when_saving_rusf(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1115',
            'member_no' => 'MB-1115',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'deworming_date' => '2026-02-10',
            'ifa' => ['v1' => ['date' => '2026-02-11', 'tablets' => 30]],
            'mms' => ['v1' => ['date' => '2026-02-12', 'tablets' => 30]],
            'calcium' => ['v1' => ['date' => '2026-02-13', 'tablets' => 10]],
        ])->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => '2026-02-14'],
            ],
        ])->assertRedirect();

        $this->assertSame('2026-02-10', DB::table('deworming_supplementation')->value('date_given'));
        $this->assertSame(1, DB::table('ifa_supplementation')->count());
        $this->assertSame(30, (int) DB::table('ifa_supplementation')->value('tablets_given'));
        $this->assertSame(1, DB::table('mms_supplementation')->count());
        $this->assertSame(1, DB::table('cc_supplementation')->count());
        $this->assertSame(1, DB::table('rusf_supplementation')->count());
    }

    public function test_ui_and_history_display_rusf_dates_read_only(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1116',
            'member_no' => 'MB-1116',
        ]);
        $this->post($this->storeRoute($household, $resident), $this->registerPayload())->assertRedirect();

        $page = $this->get($this->suppUrl($household, $resident))->assertOk()->getContent();
        $this->assertStringContainsString('data-mc-supp="rusf"', $page);
        $this->assertStringContainsString('Ready-to-Use Supplementary Food (RUSF)', $page);
        $this->assertStringContainsString('0 given', $page);
        $this->assertStringContainsString('data-mc-supp-visit="rusf-new"', $page);
        $this->assertStringNotContainsString('name="rusf[0][tablets]"', $page);

        $this->put($this->updateRoute($household, $resident, 'supplementations'), [
            'rusf' => [
                ['date' => '2026-02-15'],
                ['date' => '2026-03-15'],
            ],
        ])->assertRedirect();

        $active = $this->get($this->suppUrl($household, $resident))->assertOk()->getContent();
        $this->assertStringContainsString('2 given', $active);
        $this->assertStringContainsString('value="2026-02-15"', $active);
        $this->assertStringContainsString('value="2026-03-15"', $active);
        $this->assertStringContainsString('name="rusf[0][id]"', $active);
        $this->assertStringContainsString('data-mc-edit-for="supplementations"', $active);

        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2026-06-01',
        ])->assertRedirect();

        $id = $this->maternalCareId($resident);
        $history = $this->get(route('household-profiling.members.maternal-care.history.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'pregnancyId' => sprintf('MC-%03d', $id),
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-mc-readonly="true"', $history);
        $this->assertStringContainsString('value="2026-02-15"', $history);
        $this->assertStringContainsString('value="2026-03-15"', $history);
        $this->assertStringContainsString('2 given', $history);
        $this->assertStringNotContainsString('data-mc-edit-for="supplementations"', $history);
    }

    public function test_offline_maternal_section_update_accepts_rusf(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1117',
            'member_no' => 'MB-1117',
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
            array_merge($this->registerPayload(), [
                '_health_action' => 'maternal_register',
            ]),
            $parent,
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $empty = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'supplementations',
                'rusf' => [
                    ['date' => ''],
                ],
            ],
            $parent,
        ));
        $empty->assertOk();
        $this->assertSame(0, DB::table('rusf_supplementation')->count());

        $saved = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'supplementations',
                'rusf' => [
                    ['date' => '2026-04-02'],
                    ['date' => '2026-05-02'],
                ],
            ],
            $parent,
        ));
        $saved->assertOk()->assertJsonPath('code', 'SYNCED');
        $this->assertSame(2, DB::table('rusf_supplementation')->count());
    }
}
