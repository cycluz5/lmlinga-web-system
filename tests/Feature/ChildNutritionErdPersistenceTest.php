<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\ChildNutritionErdMode;
use App\Support\HealthRecordsVitaminA;
use App\Support\HouseholdProfilingWriteGuard;
use App\Support\NutritionSupplementationErdMode;
use App\Support\Offline\OfflineOperationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\ErdChildNutritionSchema;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class ChildNutritionErdPersistenceTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(\App\Support\StaffRole::BHW);
        ErdChildNutritionSchema::ensure();
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedChild(string $householdNo = 'HH-1010', string $memberNo = 'MB-1010', array $overrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 1',
        ]);

        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => 'Nutri',
            'last_name' => 'Erd',
            'relation' => 'Son',
            'birthday' => '2024-01-01',
            'sex' => 'Male',
        ], $overrides));

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
    private function fullPayload(array $overrides = []): array
    {
        return array_merge([
            'newborn' => [
                'length' => '48.50',
                'weight' => '2.80',
                'breastfeeding_date' => '2025-01-02',
            ],
            'iron' => [
                '1st' => '2025-02-01',
                '2nd' => '2025-03-01',
                '3rd' => '',
            ],
            'vitamin_a' => [
                'va-6-11' => '2025-06-01',
                'va-12-59-1' => '2025-07-01',
                'va-12-59-2' => '2025-08-01',
            ],
            'mnp' => [
                'mnp-6-11' => '2025-06-15',
                'mnp-12-23' => '2025-07-15',
            ],
            'lns_sq' => [
                'lns-6-11' => '2025-06-20',
                'lns-12-23' => '2025-07-20',
            ],
            'mam' => [
                'identified' => ['date' => '2025-08-01'],
                'enrolled' => ['date' => '2025-08-02'],
                'cured' => ['date' => '2025-08-03'],
                'non-cured' => ['date' => '2025-08-04'],
                'default' => ['date' => '2025-08-05'],
                'died' => ['date' => '2025-08-06'],
            ],
            'mam_identified' => 'yes',
            'mam_enrolled' => 'no',
            'mam_cured' => 'yes',
            'mam_non-cured' => 'no',
            'mam_default' => 'yes',
            'mam_died' => 'no',
            'sam' => [
                'identified' => ['date' => '2025-09-01'],
                'enrolled' => ['date' => '2025-09-02'],
                'cured' => ['date' => '2025-09-03'],
                'non-cured' => ['date' => '2025-09-04'],
                'default' => ['date' => '2025-09-05'],
                'died' => ['date' => '2025-09-06'],
            ],
            'sam_identified' => 'yes',
            'sam_enrolled' => 'yes',
            'sam_cured' => 'no',
            'sam_non-cured' => 'yes',
            'sam_default' => 'no',
            'sam_died' => 'yes',
        ], $overrides);
    }

    public function test_seven_supplementation_slots_persist_exact_enum_keys_and_dates(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();

        $this->post(
            route('household-profiling.members.child-nutrition.store', $this->routeParams($household, $resident)),
            $this->fullPayload()
        )->assertRedirect();

        $headerId = (int) DB::table('child_nutrition')->where('resident_id', $resident->id)->value('child_nutrition_id');
        $this->assertGreaterThan(0, $headerId);
        $this->assertSame(1, DB::table('child_nutrition')->where('resident_id', $resident->id)->count());
        $this->assertSame(7, DB::table('nutrition_supplementation')->where('child_nutrition_id', $headerId)->count());

        $expected = [
            ['Vitamin A', '6-11 Months', 1, '2025-06-01'],
            ['Vitamin A', '12-59 Months', 1, '2025-07-01'],
            ['Vitamin A', '12-59 Months', 2, '2025-08-01'],
            ['MNP', '6-11 Months', 1, '2025-06-15'],
            ['MNP', '12-23 Months', 1, '2025-07-15'],
            ['LNS-SQ', '6-11 Months', 1, '2025-06-20'],
            ['LNS-SQ', '12-23 Months', 1, '2025-07-20'],
        ];

        foreach ($expected as [$type, $age, $dose, $date]) {
            $this->assertSame(
                $date,
                DB::table('nutrition_supplementation')
                    ->where('child_nutrition_id', $headerId)
                    ->where('supplement_type', $type)
                    ->where('age_group', $age)
                    ->where('dose_number', $dose)
                    ->value('date_given')
            );
        }

        $this->assertSame(NutritionSupplementationErdMode::vitaminASupplementType(), 'Vitamin A');
        $this->assertSame(NutritionSupplementationErdMode::mnpSupplementType(), 'MNP');
        $this->assertSame(NutritionSupplementationErdMode::lnsSqSupplementType(), 'LNS-SQ');
    }

    public function test_repeat_save_updates_supplementation_without_duplicates(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-nutrition.store', $params), $this->fullPayload())
            ->assertRedirect();

        $this->post(route('household-profiling.members.child-nutrition.store', $params), $this->fullPayload([
            'vitamin_a' => [
                'va-6-11' => '2025-06-11',
                'va-12-59-1' => '2025-07-01',
                'va-12-59-2' => '2025-08-01',
            ],
        ]))->assertRedirect();

        $headerId = (int) DB::table('child_nutrition')->where('resident_id', $resident->id)->value('child_nutrition_id');
        $this->assertSame(1, DB::table('child_nutrition')->where('resident_id', $resident->id)->count());
        $this->assertSame(7, DB::table('nutrition_supplementation')->where('child_nutrition_id', $headerId)->count());
        $this->assertSame(
            '2025-06-11',
            DB::table('nutrition_supplementation')
                ->where('child_nutrition_id', $headerId)
                ->where('supplement_type', 'Vitamin A')
                ->where('age_group', '6-11 Months')
                ->where('dose_number', 1)
                ->value('date_given')
        );
    }

    public function test_blank_supplementation_date_does_not_clear_or_create_placeholder(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-nutrition.store', $params), $this->fullPayload())
            ->assertRedirect();

        $this->post(route('household-profiling.members.child-nutrition.store', $params), $this->fullPayload([
            'vitamin_a' => [
                'va-6-11' => '',
                'va-12-59-1' => '2025-07-01',
                'va-12-59-2' => '2025-08-01',
            ],
        ]))->assertRedirect();

        $headerId = (int) DB::table('child_nutrition')->where('resident_id', $resident->id)->value('child_nutrition_id');
        $this->assertSame(7, DB::table('nutrition_supplementation')->where('child_nutrition_id', $headerId)->count());
        $this->assertSame(
            '2025-06-01',
            DB::table('nutrition_supplementation')
                ->where('child_nutrition_id', $headerId)
                ->where('supplement_type', 'Vitamin A')
                ->where('age_group', '6-11 Months')
                ->where('dose_number', 1)
                ->value('date_given')
        );
    }

    public function test_get_hydrates_seven_supplementation_dates(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-nutrition.store', $params), $this->fullPayload())
            ->assertRedirect();

        $html = $this->get(route('household-profiling.members.child-nutrition', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="vitamin_a[va-6-11]"', $html);
        $this->assertStringContainsString('value="2025-06-01"', $html);
        $this->assertStringContainsString('value="2025-07-01"', $html);
        $this->assertStringContainsString('value="2025-08-01"', $html);
        $this->assertStringContainsString('value="2025-06-15"', $html);
        $this->assertStringContainsString('value="2025-07-15"', $html);
        $this->assertStringContainsString('value="2025-06-20"', $html);
        $this->assertStringContainsString('value="2025-07-20"', $html);
    }

    public function test_malnutrition_twelve_slots_persist_enums_dates_and_actions(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();

        $this->post(
            route('household-profiling.members.child-nutrition.store', $this->routeParams($household, $resident)),
            $this->fullPayload()
        )->assertRedirect();

        $headerId = (int) DB::table('child_nutrition')->where('resident_id', $resident->id)->value('child_nutrition_id');
        $this->assertSame(12, DB::table('malnutrition_management')->where('child_nutrition_id', $headerId)->count());

        $cases = [
            ['MAM', 'Identified', '2025-08-01', 1],
            ['MAM', 'Enrolled', '2025-08-02', 0],
            ['MAM', 'Cured', '2025-08-03', 1],
            ['MAM', 'Non-Cured', '2025-08-04', 0],
            ['MAM', 'Default', '2025-08-05', 1],
            ['MAM', 'Died', '2025-08-06', 0],
            ['SAM', 'Identified', '2025-09-01', 1],
            ['SAM', 'Enrolled', '2025-09-02', 1],
            ['SAM', 'Cured', '2025-09-03', 0],
            ['SAM', 'Non-Cured', '2025-09-04', 1],
            ['SAM', 'Default', '2025-09-05', 0],
            ['SAM', 'Died', '2025-09-06', 1],
        ];

        foreach ($cases as [$type, $status, $date, $action]) {
            $row = DB::table('malnutrition_management')
                ->where('child_nutrition_id', $headerId)
                ->where('malnutrition_type', $type)
                ->where('status_type', $status)
                ->first();

            $this->assertNotNull($row, $type.' '.$status);
            $this->assertSame($date, $row->status_date);
            $this->assertSame($action, (int) $row->action);
        }
    }

    public function test_malnutrition_absent_action_persists_null_and_repeat_save_does_not_duplicate(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();
        $params = $this->routeParams($household, $resident);

        $payload = $this->fullPayload();
        unset($payload['mam_identified']);

        $this->post(route('household-profiling.members.child-nutrition.store', $params), $payload)
            ->assertRedirect();
        $this->post(route('household-profiling.members.child-nutrition.store', $params), $payload)
            ->assertRedirect();

        $headerId = (int) DB::table('child_nutrition')->where('resident_id', $resident->id)->value('child_nutrition_id');
        $this->assertSame(12, DB::table('malnutrition_management')->where('child_nutrition_id', $headerId)->count());

        $row = DB::table('malnutrition_management')
            ->where('child_nutrition_id', $headerId)
            ->where('malnutrition_type', 'MAM')
            ->where('status_type', 'Identified')
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->action);
        $this->assertSame('2025-08-01', $row->status_date);
    }

    public function test_get_hydrates_mam_and_sam_grid(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-nutrition.store', $params), $this->fullPayload())
            ->assertRedirect();

        $html = $this->get(route('household-profiling.members.child-nutrition', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="mam[identified][date]"', $html);
        $this->assertStringContainsString('value="2025-08-01"', $html);
        $this->assertStringContainsString('value="2025-09-06"', $html);
        $this->assertMatchesRegularExpression('/name="mam_identified"[^>]*value="yes"[^>]*checked/s', $html);
        $this->assertMatchesRegularExpression('/name="mam_enrolled"[^>]*value="no"[^>]*checked/s', $html);
        $this->assertMatchesRegularExpression('/name="sam_died"[^>]*value="yes"[^>]*checked/s', $html);
    }

    public function test_iron_same_form_maps_to_iron_supplementation(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-nutrition.store', $params), $this->fullPayload())
            ->assertRedirect();

        $headerId = (int) DB::table('child_nutrition')->where('resident_id', $resident->id)->value('child_nutrition_id');
        $this->assertSame(2, DB::table('iron_supplementation')->where('child_nutrition_id', $headerId)->count());
        $this->assertSame(
            '2025-02-01',
            DB::table('iron_supplementation')->where('child_nutrition_id', $headerId)->where('month_number', '1')->value('date_given')
        );
        $this->assertSame(
            '2025-03-01',
            DB::table('iron_supplementation')->where('child_nutrition_id', $headerId)->where('month_number', '2')->value('date_given')
        );
        $this->assertNull(
            DB::table('iron_supplementation')->where('child_nutrition_id', $headerId)->where('month_number', '3')->value('date_given')
        );

        $html = $this->get(route('household-profiling.members.child-nutrition', $params))->assertOk()->getContent();
        $this->assertStringContainsString('name="iron[1st]"', $html);
        $this->assertStringContainsString('value="2025-02-01"', $html);
        $this->assertStringContainsString('value="2025-03-01"', $html);
    }

    public function test_newborn_header_is_preserved_on_erd_save(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();

        $this->post(
            route('household-profiling.members.child-nutrition.store', $this->routeParams($household, $resident)),
            $this->fullPayload()
        )->assertRedirect();

        $header = DB::table('child_nutrition')->where('resident_id', $resident->id)->first();
        $this->assertSame('48.50', number_format((float) $header->length_at_birth_cm, 2, '.', ''));
        $this->assertSame('2.80', number_format((float) $header->weight_at_birth_kg, 2, '.', ''));
        $this->assertSame('2025-01-02', $header->initiated_breastfeeding_date);
        $this->assertFalse(Schema::hasTable('child_nutritions'));
        $this->assertFalse(Schema::hasTable('child_nutrition_sfp_outcomes'));
    }

    public function test_resident_isolation_and_prohibited_identity_fields(): void
    {
        $a = $this->seedChild('HH-1011', 'MB-1011');
        $b = $this->seedChild('HH-1012', 'MB-1012', [
            'first_name' => 'Other',
            'last_name' => 'Child',
        ]);

        $this->post(
            route('household-profiling.members.child-nutrition.store', $this->routeParams($a['household'], $a['resident'])),
            $this->fullPayload()
        )->assertRedirect();

        $this->assertSame(0, DB::table('child_nutrition')->where('resident_id', $b['resident']->id)->count());

        $htmlB = $this->get(route(
            'household-profiling.members.child-nutrition',
            $this->routeParams($b['household'], $b['resident'])
        ))->assertOk()->getContent();
        $this->assertStringNotContainsString('value="2025-06-01"', $htmlB);

        $this->post(
            route('household-profiling.members.child-nutrition.store', $this->routeParams($a['household'], $a['resident'])),
            $this->fullPayload([
                'resident_id' => $b['resident']->id,
                'child_nutrition_id' => 999,
                'household_id' => $b['household']->id,
            ])
        )->assertSessionHasErrors(['resident_id', 'child_nutrition_id', 'household_id']);

        $this->assertSame(0, DB::table('child_nutrition')->where('resident_id', $b['resident']->id)->count());

        $this->post(
            route('household-profiling.members.child-nutrition.store', $this->routeParams($b['household'], $b['resident'])),
            $this->fullPayload([
                'vitamin_a' => [
                    'va-6-11' => '2025-01-01',
                    'va-12-59-1' => '',
                    'va-12-59-2' => '',
                ],
            ])
        )->assertRedirect();

        $headerA = (int) DB::table('child_nutrition')->where('resident_id', $a['resident']->id)->value('child_nutrition_id');
        $headerB = (int) DB::table('child_nutrition')->where('resident_id', $b['resident']->id)->value('child_nutrition_id');
        $this->assertNotSame($headerA, $headerB);
        $this->assertSame(
            '2025-06-01',
            DB::table('nutrition_supplementation')
                ->where('child_nutrition_id', $headerA)
                ->where('supplement_type', 'Vitamin A')
                ->where('age_group', '6-11 Months')
                ->where('dose_number', 1)
                ->value('date_given')
        );
        $this->assertSame(
            '2025-01-01',
            DB::table('nutrition_supplementation')
                ->where('child_nutrition_id', $headerB)
                ->where('supplement_type', 'Vitamin A')
                ->where('age_group', '6-11 Months')
                ->where('dose_number', 1)
                ->value('date_given')
        );
    }

    public function test_duplicate_headers_block_save_without_merging(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-1013', 'MB-1013');

        DB::table('child_nutrition')->insert([
            [
                'resident_id' => $resident->id,
                'length_at_birth_cm' => 40,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'resident_id' => $resident->id,
                'length_at_birth_cm' => 41,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->post(
            route('household-profiling.members.child-nutrition.store', $this->routeParams($household, $resident)),
            $this->fullPayload()
        )->assertSessionHasErrors('nutrition');

        $this->assertSame(2, DB::table('child_nutrition')->where('resident_id', $resident->id)->count());
        $this->assertSame(0, DB::table('nutrition_supplementation')->count());
        $this->assertSame(0, DB::table('malnutrition_management')->count());
    }

    public function test_vitamin_a_monitoring_reads_saved_erd_rows(): void
    {
        $birthday = now()->subMonths(8)->toDateString();
        $vaDate = now()->subDays(5)->toDateString();

        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-1014', 'MB-1014', [
            'sex' => 'Female',
            'birthday' => $birthday,
        ]);

        $this->post(
            route('household-profiling.members.child-nutrition.store', $this->routeParams($household, $resident)),
            [
                'vitamin_a' => [
                    'va-6-11' => $vaDate,
                    'va-12-59-1' => '',
                    'va-12-59-2' => '',
                ],
            ]
        )->assertRedirect();

        $row611 = collect(HealthRecordsVitaminA::monitoringRows())->firstWhere('key', '6-11');
        $this->assertIsArray($row611);
        $this->assertSame('1', $row611['va_100k_female']);
        $this->assertSame('1', $row611['va_100k_total']);
    }

    public function test_offline_replay_writes_authoritative_erd_tables(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-1015', 'MB-1015');

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            array_merge($this->fullPayload(), [
                '_health_action' => 'child_nutrition_store',
            ]),
            ['parent_server' => [
                'household_id' => $household->getKey(),
                'household_no' => $household->household_no,
                'resident_id' => $resident->getKey(),
                'member_no' => $resident->member_no,
            ]],
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
        $this->assertSame(1, DB::table('child_nutrition')->where('resident_id', $resident->id)->count());
        $this->assertSame(7, DB::table('nutrition_supplementation')->count());
        $this->assertSame(12, DB::table('malnutrition_management')->count());
        $this->assertSame(2, DB::table('iron_supplementation')->count());
    }

    public function test_incomplete_erd_schema_is_fail_closed(): void
    {
        Schema::dropIfExists('malnutrition_management');
        ChildNutritionErdMode::resetCachedState();
        $this->assertTrue(HouseholdProfilingWriteGuard::isChildNutritionWriteUnsupported());

        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-1016', 'MB-1016');

        $this->post(
            route('household-profiling.members.child-nutrition.store', $this->routeParams($household, $resident)),
            $this->fullPayload()
        )->assertSessionHasErrors('nutrition');

        $this->assertSame(0, DB::table('child_nutrition')->count());
        $this->assertSame(0, DB::table('nutrition_supplementation')->count());
    }

    public function test_iron_only_save_does_not_create_supplementation_or_malnutrition_rows(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-1020', 'MB-1020');
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-nutrition.store', $params), [
            'iron' => [
                '1st' => '2025-02-01',
                '2nd' => '',
                '3rd' => '',
            ],
        ])->assertRedirect();

        $headerId = (int) DB::table('child_nutrition')->where('resident_id', $resident->id)->value('child_nutrition_id');
        $this->assertGreaterThan(0, $headerId);
        $this->assertSame(1, DB::table('iron_supplementation')->where('child_nutrition_id', $headerId)->count());
        $this->assertSame(0, DB::table('nutrition_supplementation')->where('child_nutrition_id', $headerId)->count());
        $this->assertSame(0, DB::table('malnutrition_management')->where('child_nutrition_id', $headerId)->count());
        $this->assertNull(DB::table('child_nutrition')->where('child_nutrition_id', $headerId)->value('length_at_birth_cm'));
    }

    public function test_vitamin_a_only_save_leaves_existing_newborn_and_iron_unchanged(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-1021', 'MB-1021');
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-nutrition.store', $params), [
            'newborn' => [
                'length' => '50.00',
                'weight' => '3.20',
                'breastfeeding_date' => '2025-01-02',
            ],
            'iron' => [
                '1st' => '2025-02-01',
            ],
        ])->assertRedirect();

        $headerId = (int) DB::table('child_nutrition')->where('resident_id', $resident->id)->value('child_nutrition_id');
        $ironBefore = DB::table('iron_supplementation')->where('child_nutrition_id', $headerId)->count();

        $this->post(route('household-profiling.members.child-nutrition.store', $params), [
            'vitamin_a' => [
                'va-6-11' => '2025-06-01',
            ],
        ])->assertRedirect();

        $header = DB::table('child_nutrition')->where('child_nutrition_id', $headerId)->first();
        $this->assertEquals(50.0, (float) $header->length_at_birth_cm);
        $this->assertEquals(3.2, (float) $header->weight_at_birth_kg);
        $this->assertSame('2025-01-02', $header->initiated_breastfeeding_date);
        $this->assertSame($ironBefore, DB::table('iron_supplementation')->where('child_nutrition_id', $headerId)->count());
        $this->assertSame(1, DB::table('nutrition_supplementation')->where('child_nutrition_id', $headerId)->count());
        $this->assertSame(0, DB::table('malnutrition_management')->where('child_nutrition_id', $headerId)->count());
    }

    public function test_empty_save_is_noop_and_creates_no_rows(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-1022', 'MB-1022');
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-nutrition.store', $params), [
            'newborn' => ['length' => '', 'weight' => '', 'breastfeeding_date' => ''],
            'iron' => ['1st' => '', '2nd' => '', '3rd' => ''],
            'vitamin_a' => ['va-6-11' => '', 'va-12-59-1' => '', 'va-12-59-2' => ''],
            'mnp' => ['mnp-6-11' => '', 'mnp-12-23' => ''],
            'lns_sq' => ['lns-6-11' => '', 'lns-12-23' => ''],
        ])->assertRedirect();

        $this->assertSame(0, DB::table('child_nutrition')->where('resident_id', $resident->id)->count());
        $this->assertSame(0, DB::table('nutrition_supplementation')->count());
        $this->assertSame(0, DB::table('iron_supplementation')->count());
        $this->assertSame(0, DB::table('malnutrition_management')->count());
    }

    public function test_neither_supported_schema_is_fail_closed(): void
    {
        Schema::dropIfExists('child_nutrition_sfp_outcomes');
        Schema::dropIfExists('child_nutritions');
        Schema::dropIfExists('nutrition_supplementation');
        Schema::dropIfExists('iron_supplementation');
        Schema::dropIfExists('malnutrition_management');
        Schema::dropIfExists('child_nutrition');
        ChildNutritionErdMode::resetCachedState();

        $this->assertTrue(HouseholdProfilingWriteGuard::isChildNutritionWriteUnsupported());

        try {
            HouseholdProfilingWriteGuard::rejectChildNutritionWrite();
            $this->fail('Expected validation exception.');
        } catch (ValidationException $e) {
            $this->assertSame(
                HouseholdProfilingWriteGuard::MESSAGE,
                $e->errors()['nutrition'][0]
            );
        }
    }
}
