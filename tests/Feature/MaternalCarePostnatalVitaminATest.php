<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\DemoMaternalCare;
use App\Support\MaternalCareErdMode;
use App\Support\Offline\OfflineOperationType;
use App\Support\StaffRole;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ErdMaternalCareSchema;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

/**
 * #14 — Postnatal Vitamin A singleton date (ERD + request + JSON + offline).
 * sqlite :memory: only.
 */
class MaternalCarePostnatalVitaminATest extends TestCase
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
        $this->assertTrue(Schema::hasTable('postpartum_vitamin_a_supplementation'));
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
            'household_no' => $overrides['household_no'] ?? 'HH-1401',
            'zone' => 'Zone 1',
            'street' => 'Vitamin A St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-1401',
            'first_name' => $overrides['first_name'] ?? 'Vera',
            'last_name' => $overrides['last_name'] ?? 'Vita',
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

    private function postnatalUrl(Household $household, Resident $resident): string
    {
        return route('household-profiling.members.maternal-care.postnatal', [
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

    private function register(Household $household, Resident $resident, array $overrides = []): void
    {
        $this->post($this->storeRoute($household, $resident), $this->registerPayload($overrides))
            ->assertRedirect();
    }

    public function test_schema_has_singleton_vitamin_a_columns(): void
    {
        $columns = Schema::getColumnListing('postpartum_vitamin_a_supplementation');
        $this->assertContains('postpartum_vitamin_a_id', $columns);
        $this->assertContains('maternal_care_id', $columns);
        $this->assertContains('date_given', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
        $this->assertNotContains('visit_number', $columns);
        $this->assertNotContains('month_number', $columns);
        $this->assertNotContains('dosage', $columns);
        $this->assertNotContains('result', $columns);
        $this->assertNotContains('tablets_given', $columns);
        $this->assertNotContains('delivery_outcome_id', $columns);
    }

    public function test_ui_has_vitamin_a_date_and_keeps_existing_pnc_and_ifa(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->register($household, $resident);

        $html = $this->get($this->postnatalUrl($household, $resident))->assertOk()->getContent();
        $this->assertStringContainsString('name="vitamin_a[date]"', $html);
        $this->assertStringContainsString('Vitamin A', $html);
        $this->assertSame(1, substr_count($html, 'name="vitamin_a[date]"'));
        $this->assertSame(4, substr_count($html, 'data-mc-contact='));
        $this->assertSame(3, substr_count($html, 'data-mc-pp-supp='));
        $this->assertStringContainsString('name="contacts[c1]"', $html);
        $this->assertStringContainsString('name="contacts[c4]"', $html);
        $this->assertStringContainsString('name="supplementation[v1][date]"', $html);
        $this->assertStringContainsString('name="supplementation[v3][tablets]"', $html);
        $this->assertStringContainsString('0 of 3 Visits', $html);
        $this->assertStringNotContainsString('name="vitamin_a[dosage]"', $html);
        $this->assertStringNotContainsString('name="vitamin_a[result]"', $html);
        $this->assertStringContainsString('data-mc-edit-for="postnatal"', $html);
    }

    public function test_valid_date_is_accepted_and_invalid_date_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1402',
            'member_no' => 'MB-1402',
        ]);
        $this->register($household, $resident);
        $from = $this->postnatalUrl($household, $resident);

        Carbon::setTestNow(Carbon::parse('2026-11-02')->startOfDay());
        $this->from($from)->put($this->updateRoute($household, $resident, 'postnatal'), [
            'vitamin_a' => ['date' => '2026-11-02'],
        ])->assertRedirect()->assertSessionDoesntHaveErrors('vitamin_a.date');
        $this->assertSame('2026-11-02', DB::table('postpartum_vitamin_a_supplementation')->value('date_given'));

        $this->from($from)->put($this->updateRoute($household, $resident, 'postnatal'), [
            'vitamin_a' => ['date' => 'not-a-date'],
        ])->assertRedirect()->assertSessionHasErrors('vitamin_a.date');

        $this->putJson($this->updateRoute($household, $resident, 'postnatal'), [
            'vitamin_a' => ['date' => 'not-a-date'],
        ])->assertStatus(422)->assertJsonValidationErrors('vitamin_a.date');

        $this->assertSame('2026-11-02', DB::table('postpartum_vitamin_a_supplementation')->value('date_given'));

        ['household' => $blankHh, 'resident' => $blankResident] = $this->seedMember([
            'household_no' => 'HH-1412',
            'member_no' => 'MB-1412',
        ]);
        $this->register($blankHh, $blankResident);
        $this->from($this->postnatalUrl($blankHh, $blankResident))
            ->put($this->updateRoute($blankHh, $blankResident, 'postnatal'), [
                'vitamin_a' => ['date' => ''],
            ])->assertRedirect()->assertSessionDoesntHaveErrors('vitamin_a.date');
        $this->assertSame(0, DB::table('postpartum_vitamin_a_supplementation')->where(
            'maternal_care_id',
            $this->maternalCareId($blankResident)
        )->count());
    }

    public function test_json_valid_date_omitted_key_and_blank_clear_preserve_siblings(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1403',
            'member_no' => 'MB-1403',
        ]);
        $this->register($household, $resident);

        Carbon::setTestNow(Carbon::parse('2026-10-22')->startOfDay());
        $this->put($this->updateRoute($household, $resident, 'postnatal'), [
            'contacts' => [
                'c1' => '2026-10-20',
                'c2' => '',
                'c3' => '',
                'c4' => '',
            ],
            'supplementation' => [
                'v1' => ['date' => '2026-10-21', 'tablets' => 30],
            ],
            'vitamin_a' => ['date' => '2026-10-22'],
        ])->assertRedirect();

        $page = $this->get($this->postnatalUrl($household, $resident))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="vitamin_a\[date\]"[^>]*value="2026-10-22"/', $page);
        $this->assertMatchesRegularExpression('/name="contacts\[c1\]"[^>]*value="2026-10-20"/', $page);
        $this->assertMatchesRegularExpression('/name="supplementation\[v1\]\[date\]"[^>]*value="2026-10-21"/', $page);

        Carbon::setTestNow(Carbon::parse('2026-10-23')->startOfDay());
        $this->put($this->updateRoute($household, $resident, 'postnatal'), [
            'contacts' => [
                'c2' => '2026-10-23',
            ],
        ])->assertRedirect();

        $this->assertSame('2026-10-22', DB::table('postpartum_vitamin_a_supplementation')->value('date_given'));
        $this->assertSame('2026-10-20', DB::table('postnatal_care_visits')->where('contact_number', 1)->value('contact_date'));
        $this->assertSame('2026-10-23', DB::table('postnatal_care_visits')->where('contact_number', 2)->value('contact_date'));
        $this->assertSame('2026-10-21', DB::table('postpartum_ifa_supplementation')->value('date_given'));
        $this->assertSame(30, (int) DB::table('postpartum_ifa_supplementation')->value('tablets_given'));

        $this->put($this->updateRoute($household, $resident, 'postnatal'), [
            'vitamin_a' => ['date' => ''],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('postpartum_vitamin_a_supplementation')->count());
        $this->assertNull(DB::table('postpartum_vitamin_a_supplementation')->value('date_given'));
        $this->assertSame('2026-10-20', DB::table('postnatal_care_visits')->where('contact_number', 1)->value('contact_date'));
        $this->assertSame(30, (int) DB::table('postpartum_ifa_supplementation')->value('tablets_given'));
    }

    public function test_demo_json_fallback_sparse_merge(): void
    {
        $hh = 'HH-14-JSON';
        $mb = 'MB-14-JSON';
        DemoMaternalCare::register($hh, $mb, $this->registerPayload());

        DemoMaternalCare::updateSection($hh, $mb, 'postnatal', [
            'contacts' => ['c1' => '2026-10-20'],
            'supplementation' => ['v1' => ['date' => '2026-10-21', 'tablets' => 30]],
            'vitamin_a' => ['date' => '2026-10-22'],
        ]);
        $pregnancy = DemoMaternalCare::activePregnancy($hh, $mb);
        $this->assertNotNull($pregnancy);
        $this->assertSame('2026-10-22', $pregnancy['postnatal']['vitamin_a']['date']);
        $this->assertSame('2026-10-20', $pregnancy['postnatal']['contacts']['c1']);
        $this->assertSame('2026-10-21', $pregnancy['postnatal']['supplementation']['v1']['date']);
        $this->assertSame('30', (string) $pregnancy['postnatal']['supplementation']['v1']['tablets']);

        DemoMaternalCare::updateSection($hh, $mb, 'postnatal', [
            'contacts' => ['c2' => '2026-10-23'],
        ]);
        $pregnancy = DemoMaternalCare::activePregnancy($hh, $mb);
        $this->assertSame('2026-10-22', $pregnancy['postnatal']['vitamin_a']['date']);
        $this->assertSame('2026-10-20', $pregnancy['postnatal']['contacts']['c1']);
        $this->assertSame('2026-10-23', $pregnancy['postnatal']['contacts']['c2']);

        DemoMaternalCare::updateSection($hh, $mb, 'postnatal', [
            'vitamin_a' => ['date' => ''],
        ]);
        $pregnancy = DemoMaternalCare::activePregnancy($hh, $mb);
        $this->assertSame('', $pregnancy['postnatal']['vitamin_a']['date']);
        $this->assertSame('2026-10-20', $pregnancy['postnatal']['contacts']['c1']);
        $this->assertSame('2026-10-21', $pregnancy['postnatal']['supplementation']['v1']['date']);
    }

    public function test_erd_inserts_one_row_updates_same_row_and_respects_unique(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1404',
            'member_no' => 'MB-1404',
        ]);
        $this->register($household, $resident);
        $careId = $this->maternalCareId($resident);

        Carbon::setTestNow(Carbon::parse('2026-11-01')->startOfDay());
        $this->put($this->updateRoute($household, $resident, 'postnatal'), [
            'vitamin_a' => ['date' => '2026-11-01'],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('postpartum_vitamin_a_supplementation')->count());
        $id = (int) DB::table('postpartum_vitamin_a_supplementation')->value('postpartum_vitamin_a_id');
        $this->assertSame($careId, (int) DB::table('postpartum_vitamin_a_supplementation')->value('maternal_care_id'));
        $this->assertSame(0, DB::table('delivery_outcomes')->count());

        Carbon::setTestNow(Carbon::parse('2026-11-15')->startOfDay());
        $this->put($this->updateRoute($household, $resident, 'postnatal'), [
            'vitamin_a' => ['date' => '2026-11-15'],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('postpartum_vitamin_a_supplementation')->count());
        $this->assertSame($id, (int) DB::table('postpartum_vitamin_a_supplementation')->value('postpartum_vitamin_a_id'));
        $this->assertSame('2026-11-15', DB::table('postpartum_vitamin_a_supplementation')->value('date_given'));

        $this->expectException(QueryException::class);
        DB::table('postpartum_vitamin_a_supplementation')->insert([
            'maternal_care_id' => $careId,
            'date_given' => '2026-12-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_blank_date_does_not_insert_and_clears_existing_without_delivery_stub(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1405',
            'member_no' => 'MB-1405',
        ]);
        $this->register($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'postnatal'), [
            'vitamin_a' => ['date' => ''],
        ])->assertRedirect();
        $this->assertSame(0, DB::table('postpartum_vitamin_a_supplementation')->count());
        $this->assertSame(0, DB::table('delivery_outcomes')->count());

        Carbon::setTestNow(Carbon::parse('2026-11-04')->startOfDay());
        $this->put($this->updateRoute($household, $resident, 'postnatal'), [
            'vitamin_a' => ['date' => '2026-11-04'],
        ])->assertRedirect();
        $id = (int) DB::table('postpartum_vitamin_a_supplementation')->value('postpartum_vitamin_a_id');

        $this->put($this->updateRoute($household, $resident, 'postnatal'), [
            'vitamin_a' => ['date' => ''],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('postpartum_vitamin_a_supplementation')->count());
        $this->assertSame($id, (int) DB::table('postpartum_vitamin_a_supplementation')->value('postpartum_vitamin_a_id'));
        $this->assertNull(DB::table('postpartum_vitamin_a_supplementation')->value('date_given'));
        $this->assertSame(0, DB::table('delivery_outcomes')->count());
        $this->assertSame(0, DB::table('postnatal_care_visits')->count());
        $this->assertSame(0, DB::table('postpartum_ifa_supplementation')->count());
    }

    public function test_history_shows_stored_date_read_only(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1406',
            'member_no' => 'MB-1406',
        ]);
        $this->register($household, $resident);

        Carbon::setTestNow(Carbon::parse('2026-11-06')->startOfDay());
        $this->put($this->updateRoute($household, $resident, 'postnatal'), [
            'vitamin_a' => ['date' => '2026-11-06'],
        ])->assertRedirect();

        Carbon::setTestNow(Carbon::parse('2026-11-20')->startOfDay());
        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Postnatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2026-11-20',
        ])->assertRedirect();

        $history = $this->get(route('household-profiling.members.maternal-care.history.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'pregnancyId' => sprintf('MC-%03d', $this->maternalCareId($resident)),
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-mc-readonly="true"', $history);
        $this->assertMatchesRegularExpression('/name="vitamin_a\[date\]"[^>]*value="2026-11-06"/', $history);
        $this->assertStringNotContainsString('data-mc-edit-for="postnatal"', $history);
    }

    public function test_completed_episode_still_allows_postnatal_vitamin_a(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1407',
            'member_no' => 'MB-1407',
        ]);
        $this->register($household, $resident);

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'delivery_type' => 'VD',
            'datetime' => '2026-10-20T08:30',
            'status' => 'Live birth',
        ])->assertRedirect();
        $this->assertSame(MaternalCareErdMode::STATUS_COMPLETED, DB::table('maternal_care')->value('pregnancy_status'));

        $this->from($this->updateRoute($household, $resident, 'laboratory'))
            ->put($this->updateRoute($household, $resident, 'laboratory'), [
                'urinalysis' => ['date' => '2026-10-21'],
            ])->assertForbidden();

        Carbon::setTestNow(Carbon::parse('2026-10-22')->startOfDay());
        $this->put($this->updateRoute($household, $resident, 'postnatal'), [
            'vitamin_a' => ['date' => '2026-10-22'],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('postpartum_vitamin_a_supplementation')->count());
        $this->assertSame('2026-10-22', DB::table('postpartum_vitamin_a_supplementation')->value('date_given'));
        $this->assertTrue(DemoMaternalCare::sectionAllowsCompletedEpisode('postnatal'));
    }

    public function test_offline_postnatal_section_update_carries_vitamin_a_date(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1408',
            'member_no' => 'MB-1408',
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
                '_health_section' => 'postnatal',
                'vitamin_a' => ['date' => ''],
            ],
            $parent,
        ));
        $empty->assertOk();
        $this->assertSame(0, DB::table('postpartum_vitamin_a_supplementation')->count());

        Carbon::setTestNow(Carbon::parse('2026-11-08')->startOfDay());
        $saved = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'postnatal',
                'vitamin_a' => ['date' => '2026-11-08'],
            ],
            $parent,
        ));
        $saved->assertOk()->assertJsonPath('code', 'SYNCED');
        $this->assertSame(1, DB::table('postpartum_vitamin_a_supplementation')->count());
        $this->assertSame('2026-11-08', DB::table('postpartum_vitamin_a_supplementation')->value('date_given'));
        $this->assertSame(0, DB::table('delivery_outcomes')->count());
    }

    public function test_cross_resident_isolation_and_forged_ids_are_rejected(): void
    {
        ['household' => $firstHh, 'resident' => $first] = $this->seedMember([
            'household_no' => 'HH-1409',
            'member_no' => 'MB-1409',
            'first_name' => 'First',
        ]);
        ['household' => $secondHh, 'resident' => $second] = $this->seedMember([
            'household_no' => 'HH-1410',
            'member_no' => 'MB-1410',
            'first_name' => 'Second',
        ]);
        $this->register($firstHh, $first);
        $this->register($secondHh, $second);

        Carbon::setTestNow(Carbon::parse('2026-11-09')->startOfDay());
        $this->put($this->updateRoute($firstHh, $first, 'postnatal'), [
            'vitamin_a' => ['date' => '2026-11-09'],
        ])->assertRedirect();
        $firstCare = $this->maternalCareId($first);

        Carbon::setTestNow(Carbon::parse('2026-11-10')->startOfDay());
        $this->put($this->updateRoute($secondHh, $second, 'postnatal'), [
            'vitamin_a' => ['date' => '2026-11-10'],
        ])->assertRedirect();

        $this->from($this->postnatalUrl($firstHh, $first))
            ->put($this->updateRoute($firstHh, $first, 'postnatal'), [
                'maternal_care_id' => $this->maternalCareId($second),
                'postpartum_vitamin_a_id' => 888,
                'vitamin_a' => [
                    'id' => 888,
                    'postpartum_vitamin_a_id' => 888,
                    'maternal_care_id' => $this->maternalCareId($second),
                    'date' => '2026-12-31',
                ],
            ])->assertRedirect()->assertSessionHasErrors([
                'maternal_care_id',
                'postpartum_vitamin_a_id',
                'vitamin_a.id',
                'vitamin_a.postpartum_vitamin_a_id',
                'vitamin_a.maternal_care_id',
            ]);

        $this->assertSame(
            '2026-11-09',
            DB::table('postpartum_vitamin_a_supplementation')->where('maternal_care_id', $firstCare)->value('date_given')
        );
        $this->assertSame(
            '2026-11-10',
            DB::table('postpartum_vitamin_a_supplementation')->where('maternal_care_id', $this->maternalCareId($second))->value('date_given')
        );
        $this->assertSame(2, DB::table('postpartum_vitamin_a_supplementation')->count());
    }

    public function test_existing_pnc_contacts_and_postpartum_ifa_still_work(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1411',
            'member_no' => 'MB-1411',
        ]);
        $this->register($household, $resident);

        Carbon::setTestNow(Carbon::parse('2026-11-21')->startOfDay());
        $this->put($this->updateRoute($household, $resident, 'postnatal'), [
            'contacts' => [
                'c1' => '2026-10-20',
                'c2' => '2026-10-23',
            ],
            'supplementation' => [
                'v1' => ['date' => '2026-10-21', 'tablets' => 30],
                'v2' => ['date' => '2026-11-21', 'tablets' => 15],
            ],
            'vitamin_a' => ['date' => '2026-10-24'],
        ])->assertRedirect();

        $this->assertSame(2, DB::table('postnatal_care_visits')->count());
        $this->assertSame(2, DB::table('postpartum_ifa_supplementation')->count());
        $this->assertSame(1, DB::table('postpartum_vitamin_a_supplementation')->count());
        $this->assertSame(1, DB::table('delivery_outcomes')->count());
        $this->assertSame('2026-10-20', DB::table('postnatal_care_visits')->where('contact_number', 1)->value('contact_date'));
        $this->assertSame(15, (int) DB::table('postpartum_ifa_supplementation')->where('visit_number', 2)->value('tablets_given'));
    }
}
