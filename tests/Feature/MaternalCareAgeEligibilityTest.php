<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\MaternalPregnancy;
use App\Models\Resident;
use App\Support\MaternalCareEligibility;
use App\Support\MaternalCareErdMode;
use App\Support\MaternalPregnancyService;
use App\Support\Offline\OfflineOperationType;
use App\Support\StaffRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\ErdMaternalCareSchema;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class MaternalCareAgeEligibilityTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    private const AS_OF = '2026-09-12';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::AS_OF)->startOfDay());
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
    private function seedMember(string $birthday, array $overrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => $overrides['household_no'] ?? 'HH-1919',
            'zone' => 'Zone 1',
        ]);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-1919',
            'first_name' => $overrides['first_name'] ?? 'Age',
            'last_name' => $overrides['last_name'] ?? 'Gate',
            'birthday' => $birthday,
            'sex' => $overrides['sex'] ?? 'Female',
            'relation' => 'Daughter',
        ]);

        return compact('household', 'resident');
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
    private function validRegisterPayload(): array
    {
        return [
            'lmp' => '2026-01-15',
            'gravida' => 1,
            'parity' => 0,
            'edd' => '2026-10-22',
            'weight' => 52,
            'height' => 155,
            'blood_pressure' => '110/70',
        ];
    }

    private function maternalRowCount(): int
    {
        $count = 0;
        if (Schema::hasTable('maternal_pregnancies')) {
            $count += (int) DB::table('maternal_pregnancies')->count();
        }
        if (Schema::hasTable('maternal_care')) {
            $count += (int) DB::table('maternal_care')->count();
        }

        return $count;
    }

    public function test_tenth_birthday_is_eligible_and_day_before_is_not(): void
    {
        $this->assertTrue(MaternalCareEligibility::meetsMinimumAge('2016-09-12'));
        $this->assertTrue(MaternalCareEligibility::allowsWorkflow('Female', '2016-09-12'));
        $this->assertFalse(MaternalCareEligibility::meetsMinimumAge('2016-09-13'));
        $this->assertFalse(MaternalCareEligibility::allowsWorkflow('Female', '2016-09-13'));
        $this->assertFalse(MaternalCareEligibility::allowsWorkflow('Female', null));
        $this->assertFalse(MaternalCareEligibility::allowsWorkflow('Female', ''));
        $this->assertFalse(MaternalCareEligibility::allowsWorkflow('Male', '2016-09-12'));
    }

    public function test_female_on_tenth_birthday_can_register(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2016-09-12', [
            'household_no' => 'HH-1901',
            'member_no' => 'MB-1901',
        ]);
        $params = $this->routeParams($household, $resident);

        $this->get(route('household-profiling.members.maternal-care.register', $params))
            ->assertOk()
            ->assertSee('data-mc-register-form', false);

        $this->post(route('household-profiling.members.maternal-care.store', $params), $this->validRegisterPayload())
            ->assertRedirect(route('household-profiling.members.maternal-care.index', $params));

        $this->assertSame(1, $this->maternalRowCount());
    }

    public function test_female_day_before_tenth_cannot_register(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2016-09-13', [
            'household_no' => 'HH-1902',
            'member_no' => 'MB-1902',
        ]);
        $params = $this->routeParams($household, $resident);
        $before = $this->maternalRowCount();

        $this->get(route('household-profiling.members.maternal-care.register', $params))
            ->assertForbidden();
        $this->post(route('household-profiling.members.maternal-care.store', $params), $this->validRegisterPayload())
            ->assertForbidden();

        $this->assertSame($before, $this->maternalRowCount());
        $this->assertSame(0, MaternalPregnancy::query()->where('resident_id', $resident->id)->count());
    }

    public function test_under_10_landing_has_no_register_cta(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2019-01-01', [
            'household_no' => 'HH-1903',
            'member_no' => 'MB-1903',
        ]);
        $params = $this->routeParams($household, $resident);

        $html = $this->get(route('household-profiling.members.maternal-care.index', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-lml-mc-mode="landing"', $html);
        $this->assertStringContainsString('data-mc-write-eligible="false"', $html);
        $this->assertStringContainsString('data-mc-age-ineligible', $html);
        $this->assertStringContainsString(MaternalCareEligibility::WORKFLOW_INELIGIBLE_MESSAGE, $html);
        $this->assertStringNotContainsString('data-mc-register-cta', $html);
    }

    public function test_member_card_female_under_10_without_history_is_unavailable(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2019-01-01', [
            'household_no' => 'HH-1904',
            'member_no' => 'MB-1904',
        ]);
        $params = $this->routeParams($household, $resident);

        $html = $this->get(route('household-profiling.members.show', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-hh-member-maternal-care-unavailable', $html);
        $this->assertStringContainsString(MaternalCareEligibility::WORKFLOW_INELIGIBLE_MESSAGE, $html);
        $this->assertStringNotContainsString('data-hh-member-maternal-care"', $html);
        $this->assertStringNotContainsString(
            route('household-profiling.members.maternal-care.index', $params),
            $html
        );
    }

    public function test_member_card_female_10_plus_keeps_maternal_link(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2016-09-12', [
            'household_no' => 'HH-1905',
            'member_no' => 'MB-1905',
        ]);
        $params = $this->routeParams($household, $resident);

        $this->get(route('household-profiling.members.show', $params))
            ->assertOk()
            ->assertSee('data-hh-member-maternal-care', false)
            ->assertSee(
                'href="'.e(route('household-profiling.members.maternal-care.index', $params)).'"',
                false
            );
    }

    public function test_male_restriction_is_unchanged(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2000-01-01', [
            'household_no' => 'HH-1906',
            'member_no' => 'MB-1906',
            'sex' => 'Male',
        ]);
        $params = $this->routeParams($household, $resident);
        $before = $this->maternalRowCount();

        $html = $this->get(route('household-profiling.members.show', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('data-hh-member-maternal-care', $html);

        $this->get(route('household-profiling.members.maternal-care.index', $params))->assertForbidden();
        $this->post(route('household-profiling.members.maternal-care.store', $params), $this->validRegisterPayload())
            ->assertForbidden();
        $this->assertSame($before, $this->maternalRowCount());
    }

    public function test_under_10_active_section_write_is_rejected_without_mutating_row(): void
    {
        ErdMaternalCareSchema::ensure();
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2019-02-01', [
            'household_no' => 'HH-1907',
            'member_no' => 'MB-1907',
        ]);
        $params = $this->routeParams($household, $resident);

        $id = DB::table('maternal_care')->insertGetId([
            'resident_id' => $resident->getKey(),
            'lmp_date' => '2026-01-01',
            'gravida' => 1,
            'parity' => 0,
            'edd' => '2026-10-08',
            'weight_kg' => 50,
            'height_cm' => 150,
            'pregnancy_status' => MaternalCareErdMode::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'maternal_care_id');

        $this->put(route('household-profiling.members.maternal-care.update', $params + [
            'section' => 'prenatal',
        ]), [
            'visits' => [
                't1_v1' => [
                    'date' => '2026-02-01',
                    'weight' => 51,
                    'height' => 150,
                ],
            ],
        ])->assertForbidden();

        $row = DB::table('maternal_care')->where('maternal_care_id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame(MaternalCareErdMode::STATUS_ACTIVE, $row->pregnancy_status);
        $this->assertSame(0, DB::table('prenatal_visits')->count());
        $this->assertSame(1, DB::table('maternal_care')->count());
    }

    public function test_under_10_completed_history_remains_viewable(): void
    {
        ErdMaternalCareSchema::ensure();
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2019-02-01', [
            'household_no' => 'HH-1908',
            'member_no' => 'MB-1908',
        ]);
        $params = $this->routeParams($household, $resident);

        $id = DB::table('maternal_care')->insertGetId([
            'resident_id' => $resident->getKey(),
            'lmp_date' => '2025-01-01',
            'gravida' => 1,
            'parity' => 0,
            'edd' => '2025-10-08',
            'weight_kg' => 48,
            'height_cm' => 148,
            'pregnancy_status' => MaternalCareErdMode::STATUS_COMPLETED,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subMonths(6),
        ], 'maternal_care_id');
        DB::table('delivery_outcomes')->insert([
            'maternal_care_id' => $id,
            'outcome' => 'FT',
            'date_time_of_delivery' => now()->subMonths(6),
            'created_at' => now()->subMonths(6),
            'updated_at' => now()->subMonths(6),
        ]);
        $pregnancyId = sprintf('MC-%03d', $id);

        $index = $this->get(route('household-profiling.members.maternal-care.index', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-mc-has-history', $index);
        $this->assertStringContainsString('data-mc-history-link', $index);
        $this->assertStringNotContainsString('data-mc-register-cta', $index);

        $history = $this->get(route('household-profiling.members.maternal-care.history', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-mc-history-status="completed"', $history);
        $this->assertStringContainsString($pregnancyId, $history);

        $this->get(route('household-profiling.members.maternal-care.history.show', $params + [
            'pregnancyId' => $pregnancyId,
        ]))->assertOk()->assertSee('data-lml-mc-mode="history-show"', false);

        $card = $this->get(route('household-profiling.members.show', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-hh-member-maternal-care', $card);
        $this->assertStringContainsString(
            route('household-profiling.members.maternal-care.index', $params),
            $card
        );

        $this->assertSame(
            MaternalCareErdMode::STATUS_COMPLETED,
            DB::table('maternal_care')->where('maternal_care_id', $id)->value('pregnancy_status')
        );
        $this->get(route('household-profiling.members.maternal-care.register', $params))->assertForbidden();
    }

    public function test_resident_isolation_unchanged_for_eligible_members(): void
    {
        $a = $this->seedMember('1990-01-01', ['household_no' => 'HH-1909', 'member_no' => 'MB-1909']);
        $b = $this->seedMember('1988-01-01', ['household_no' => 'HH-1910', 'member_no' => 'MB-1910']);

        $this->post(
            route('household-profiling.members.maternal-care.store', $this->routeParams($a['household'], $a['resident'])),
            $this->validRegisterPayload()
        )->assertRedirect();

        $this->assertSame(1, MaternalPregnancy::query()->where('resident_id', $a['resident']->id)->count());
        $this->assertSame(0, MaternalPregnancy::query()->where('resident_id', $b['resident']->id)->count());
    }

    public function test_service_rejects_under_10_and_missing_birthday_without_500(): void
    {
        ['resident' => $tooYoung] = $this->seedMember('2020-01-01', [
            'household_no' => 'HH-1911',
            'member_no' => 'MB-1911',
        ]);
        ['resident' => $noDob] = $this->seedMember('1990-01-01', [
            'household_no' => 'HH-1912',
            'member_no' => 'MB-1912',
        ]);
        $noDob->setAttribute('birthday', null);

        foreach ([$tooYoung, $noDob] as $resident) {
            try {
                app(MaternalPregnancyService::class)->createForResident($resident, $this->validRegisterPayload());
                $this->fail('Expected ineligible create to throw.');
            } catch (ValidationException $e) {
                $this->assertSame(
                    [MaternalCareEligibility::WORKFLOW_INELIGIBLE_MESSAGE],
                    $e->errors()['lmp']
                );
            }
        }

        $this->assertSame(0, $this->maternalRowCount());
    }

    public function test_offline_replay_rejects_under_10_register_and_section_update(): void
    {
        $this->actingAsFieldStaff();
        ErdMaternalCareSchema::ensure();
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2019-03-01', [
            'household_no' => 'HH-1913',
            'member_no' => 'MB-1913',
        ]);

        $register = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            array_merge($this->validRegisterPayload(), [
                '_health_action' => 'maternal_register',
            ]),
            ['parent_server' => [
                'household_id' => $household->getKey(),
                'household_no' => $household->household_no,
                'resident_id' => $resident->getKey(),
                'member_no' => $resident->member_no,
            ]],
        ));
        $register->assertStatus(422);
        $this->assertSame(0, DB::table('maternal_care')->count());

        $id = DB::table('maternal_care')->insertGetId([
            'resident_id' => $resident->getKey(),
            'lmp_date' => '2026-01-01',
            'weight_kg' => 50,
            'height_cm' => 150,
            'pregnancy_status' => MaternalCareErdMode::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'maternal_care_id');

        $update = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'prenatal',
                'visits' => [
                    't1_v1' => ['date' => '2026-02-01', 'weight' => 51, 'height' => 150],
                ],
            ],
            ['parent_server' => [
                'household_id' => $household->getKey(),
                'household_no' => $household->household_no,
                'resident_id' => $resident->getKey(),
                'member_no' => $resident->member_no,
            ]],
        ));
        $update->assertStatus(422);
        $this->assertSame(0, DB::table('prenatal_visits')->count());
        $this->assertSame(
            MaternalCareErdMode::STATUS_ACTIVE,
            DB::table('maternal_care')->where('maternal_care_id', $id)->value('pregnancy_status')
        );
    }
}
