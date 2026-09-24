<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\OfflineSyncReceipt;
use App\Models\Resident;
use App\Support\MaternalCareErdMode;
use App\Support\MaternalPregnancyService;
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
 * FR-21 — Registration initializes prenatal Visit 1.
 * Runs on sqlite :memory: only. Does not touch lmlinga_erd_reference.
 */
class MaternalCareFirstPrenatalVisitTest extends TestCase
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
            'household_no' => $overrides['household_no'] ?? 'HH-2101',
            'zone' => 'Zone 1',
            'street' => 'FR-21 St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-2101',
            'first_name' => $overrides['first_name'] ?? 'Pia',
            'last_name' => $overrides['last_name'] ?? 'Visit',
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
            'weight' => 58.5,
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

    public function test_registration_creates_active_episode_and_first_prenatal_visit(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();

        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();

        $this->assertSame(1, DB::table('maternal_care')->count());
        $this->assertSame(1, DB::table('prenatal_visits')->count());

        $care = DB::table('maternal_care')->first();
        $visit = DB::table('prenatal_visits')->first();
        $this->assertSame(MaternalCareErdMode::STATUS_ACTIVE, $care->pregnancy_status);
        $this->assertSame((int) $care->maternal_care_id, (int) $visit->maternal_care_id);
        $this->assertSame('1st', $visit->trimester);
        $this->assertSame(1, (int) $visit->visit_number);
        $this->assertSame(self::AS_OF, substr((string) $visit->visit_date, 0, 10));
        $this->assertNotSame('2026-01-15', substr((string) $visit->visit_date, 0, 10));
        $this->assertSame(substr((string) $care->created_at, 0, 10), substr((string) $visit->visit_date, 0, 10));
    }

    public function test_first_visit_copies_weight_height_and_blood_pressure(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2102',
            'member_no' => 'MB-2102',
        ]);

        $sql = [];
        DB::listen(static function ($query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });

        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();

        $care = DB::table('maternal_care')->first();
        $visit = DB::table('prenatal_visits')->first();
        $this->assertEqualsWithDelta(58.5, (float) $visit->weight_kg, 0.01);
        $this->assertEqualsWithDelta(160.0, (float) $visit->height_cm, 0.01);
        $this->assertSame(110, (int) $visit->bp_systolic);
        $this->assertSame(70, (int) $visit->bp_diastolic);
        $this->assertEqualsWithDelta((float) $care->weight_kg, (float) $visit->weight_kg, 0.01);
        $this->assertEqualsWithDelta((float) $care->height_cm, (float) $visit->height_cm, 0.01);
        $this->assertSame((int) $care->bp_systolic, (int) $visit->bp_systolic);
        $this->assertSame((int) $care->bp_diastolic, (int) $visit->bp_diastolic);
        $this->assertEqualsWithDelta(22.9, (float) $visit->bmi, 0.1);

        foreach ($sql as $statement) {
            if (! str_contains($statement, 'prenatal_visits')) {
                continue;
            }
            if (! str_contains($statement, 'insert') && ! str_contains($statement, 'update')) {
                continue;
            }
            $this->assertStringNotContainsString('bmi', $statement, $statement);
        }
    }

    public function test_first_visit_exists_when_optional_measurements_are_missing(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2103',
            'member_no' => 'MB-2103',
        ]);

        $this->post($this->storeRoute($household, $resident), [
            'lmp' => '2026-01-15',
            'gravida' => 1,
            'parity' => 0,
            'edd' => '2026-10-22',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('maternal_care')->count());
        $this->assertSame(1, DB::table('prenatal_visits')->count());
        $visit = DB::table('prenatal_visits')->first();
        $this->assertSame('1st', $visit->trimester);
        $this->assertSame(1, (int) $visit->visit_number);
        $this->assertSame(self::AS_OF, substr((string) $visit->visit_date, 0, 10));
        $this->assertNull($visit->weight_kg);
        $this->assertNull($visit->height_cm);
        $this->assertNull($visit->bp_systolic);
        $this->assertNull($visit->bp_diastolic);
    }

    public function test_registration_does_not_precreate_future_prenatal_slots(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2104',
            'member_no' => 'MB-2104',
        ]);

        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();

        $this->assertSame(1, DB::table('prenatal_visits')->count());
        $this->assertSame(0, DB::table('prenatal_visits')->where('trimester', '2nd')->count());
        $this->assertSame(0, DB::table('prenatal_visits')->where('trimester', '3rd')->count());
        $this->assertSame(0, DB::table('prenatal_visits')->where('visit_number', '>', 1)->count());
    }

    public function test_prenatal_section_hydrates_and_updates_the_same_t1v1_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2105',
            'member_no' => 'MB-2105',
        ]);

        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();

        $original = DB::table('prenatal_visits')->first();
        $this->assertNotNull($original);

        $html = $this->get(route('household-profiling.members.maternal-care.prenatal', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('1 of 8 Visits', $html);
        $this->assertStringContainsString('value="'.self::AS_OF.'"', $html);
        $this->assertStringContainsString('value="58.5"', $html);
        $this->assertStringContainsString('value="110/70"', $html);

        $this->put($this->updateRoute($household, $resident, 'prenatal'), [
            'visits' => [
                't1_v1' => [
                    'date' => '2026-02-01',
                    'height' => '161',
                    'weight' => '59',
                    'bp' => '112/72',
                ],
            ],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('prenatal_visits')->where('trimester', '1st')->where('visit_number', 1)->count());
        $this->assertSame(1, DB::table('prenatal_visits')->count());
        $updated = DB::table('prenatal_visits')->first();
        $this->assertSame((int) $original->prenatal_visit_id, (int) $updated->prenatal_visit_id);
        $this->assertSame('2026-02-01', substr((string) $updated->visit_date, 0, 10));
        $this->assertEqualsWithDelta(59.0, (float) $updated->weight_kg, 0.01);
        $this->assertSame(112, (int) $updated->bp_systolic);
    }

    public function test_under_10_and_non_female_registration_create_no_rows(): void
    {
        ['household' => $youngHh, 'resident' => $young] = $this->seedMember([
            'household_no' => 'HH-2106',
            'member_no' => 'MB-2106',
            'birthday' => '2019-03-01',
            'first_name' => 'Young',
        ]);
        $this->post($this->storeRoute($youngHh, $young), $this->validRegisterPayload())
            ->assertForbidden();

        ['household' => $maleHh, 'resident' => $male] = $this->seedMember([
            'household_no' => 'HH-2107',
            'member_no' => 'MB-2107',
            'sex' => 'Male',
            'first_name' => 'Mark',
        ]);
        $this->post($this->storeRoute($maleHh, $male), $this->validRegisterPayload())
            ->assertForbidden();

        $this->assertSame(0, DB::table('maternal_care')->count());
        $this->assertSame(0, DB::table('prenatal_visits')->count());
    }

    public function test_future_lmp_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();

        $this->post(
            $this->storeRoute($household, $resident),
            $this->validRegisterPayload(['lmp' => '2026-09-13'])
        )->assertSessionHasErrors('lmp');

        $this->assertSame(0, DB::table('maternal_care')->count());
    }

    public function test_first_visit_insert_failure_rolls_back_maternal_care(): void
    {
        ['resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2108',
            'member_no' => 'MB-2108',
        ]);

        Schema::dropIfExists('prenatal_visits');

        try {
            app(MaternalPregnancyService::class)->createForResident($resident, $this->validRegisterPayload());
            $this->fail('Expected first-visit initialization to fail without prenatal_visits.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('prenatal_visits', $e->getMessage());
        }

        $this->assertSame(0, DB::table('maternal_care')->count());
    }

    public function test_offline_maternal_register_creates_one_episode_and_t1v1_and_is_idempotent(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2109',
            'member_no' => 'MB-2109',
        ]);

        $operationId = (string) Str::uuid();
        $envelope = $this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            array_merge($this->validRegisterPayload(), [
                '_health_action' => 'maternal_register',
            ]),
            [
                'operation_id' => $operationId,
                'parent_server' => [
                    'household_id' => $household->getKey(),
                    'household_no' => $household->household_no,
                    'resident_id' => $resident->getKey(),
                    'member_no' => $resident->member_no,
                ],
            ],
        );

        $first = $this->postOfflineSync($envelope);
        $first->assertOk();
        $first->assertJsonPath('code', 'SYNCED');

        $this->assertSame(1, DB::table('maternal_care')->count());
        $this->assertSame(1, DB::table('prenatal_visits')->count());
        $visit = DB::table('prenatal_visits')->first();
        $this->assertSame('1st', $visit->trimester);
        $this->assertSame(1, (int) $visit->visit_number);

        $replay = $this->postOfflineSync($envelope);
        $replay->assertOk();
        $replay->assertJsonPath('code', 'ALREADY_APPLIED');
        $this->assertSame(1, DB::table('maternal_care')->count());
        $this->assertSame(1, DB::table('prenatal_visits')->count());
        $this->assertSame(1, OfflineSyncReceipt::query()->where('operation_id', $operationId)->count());
    }

    public function test_auto_first_visit_survives_delivery_completion(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2110',
            'member_no' => 'MB-2110',
        ]);

        $this->post($this->storeRoute($household, $resident), $this->validRegisterPayload())
            ->assertRedirect();
        $visitId = (int) DB::table('prenatal_visits')->value('prenatal_visit_id');

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
        ])->assertRedirect();

        $this->assertSame(MaternalCareErdMode::STATUS_COMPLETED, DB::table('maternal_care')->value('pregnancy_status'));
        $this->assertSame(1, DB::table('prenatal_visits')->count());
        $this->assertSame($visitId, (int) DB::table('prenatal_visits')->value('prenatal_visit_id'));

        $id = (int) DB::table('maternal_care')->value('maternal_care_id');
        Carbon::setTestNow(Carbon::parse('2026-12-01')->startOfDay());
        $html = $this->get(route('household-profiling.members.maternal-care.history.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'pregnancyId' => sprintf('MC-%03d', $id),
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('value="'.self::AS_OF.'"', $html);
        $this->assertStringContainsString('1 of 8 Visits', $html);
    }

    public function test_client_prenatal_visit_id_is_prohibited_on_register(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-2111',
            'member_no' => 'MB-2111',
        ]);

        $this->from(route('household-profiling.members.maternal-care.register', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->post($this->storeRoute($household, $resident), $this->validRegisterPayload([
            'prenatal_visit_id' => 99,
            'maternal_care_id' => 88,
            'timbang_id' => 77,
        ]))->assertSessionHasErrors(['prenatal_visit_id', 'maternal_care_id', 'timbang_id']);

        $this->assertSame(0, DB::table('maternal_care')->count());
        $this->assertSame(0, DB::table('prenatal_visits')->count());
    }
}
