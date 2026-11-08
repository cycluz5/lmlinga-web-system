<?php

namespace Tests\Feature;

use App\Support\AtRestNarrativeField;
use App\Support\AtRestRecord;
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
 * #12 — Maternal laboratory screening (urinalysis, ultrasound, syphilis, HIV, CVC, gestational).
 * sqlite :memory: only.
 */
class MaternalCareLaboratoryScreeningTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    private const AS_OF = '2026-09-12';

    /**
     * @var list<string>
     */
    private const NEW_TABLES = [
        'urinalysis_screening',
        'ultrasound_screening',
        'syphilis_screening',
        'hiv_screening',
        'cvc_screening',
        'gestational_screening',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::AS_OF)->startOfDay());
        ErdMaternalCareSchema::ensure();
        $this->assertTrue(MaternalCareErdMode::isPersistenceActive());
        foreach (self::NEW_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
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
            'household_no' => $overrides['household_no'] ?? 'HH-1201',
            'zone' => 'Zone 1',
            'street' => 'Lab Screen St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-1201',
            'first_name' => $overrides['first_name'] ?? 'Lina',
            'last_name' => $overrides['last_name'] ?? 'Labscreen',
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

    private function labUrl(Household $household, Resident $resident): string
    {
        return route('household-profiling.members.maternal-care.laboratory', [
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

    public function test_schema_has_approved_columns_and_no_visit_fields(): void
    {
        $urinalysis = Schema::getColumnListing('urinalysis_screening');
        $this->assertContains('urinalysis_screening_id', $urinalysis);
        $this->assertContains('maternal_care_id', $urinalysis);
        $this->assertContains('date_screened', $urinalysis);
        $this->assertNotContains('result', $urinalysis);
        $this->assertNotContains('value', $urinalysis);
        $this->assertNotContains('visit_number', $urinalysis);
        $this->assertNotContains('month_number', $urinalysis);
        $this->assertNotContains('trimester', $urinalysis);

        $ultrasound = Schema::getColumnListing('ultrasound_screening');
        $this->assertContains('ultrasound_screening_id', $ultrasound);
        $this->assertContains('date_screened', $ultrasound);
        $this->assertNotContains('result', $ultrasound);
        $this->assertNotContains('visit_number', $ultrasound);

        $syphilis = Schema::getColumnListing('syphilis_screening');
        $this->assertContains('syphilis_screening_id', $syphilis);
        $this->assertContains('date_screened', $syphilis);
        $this->assertContains('result', $syphilis);
        $this->assertNotContains('visit_number', $syphilis);

        $hiv = Schema::getColumnListing('hiv_screening');
        $this->assertContains('hiv_screening_id', $hiv);
        $this->assertContains('date_screened', $hiv);
        $this->assertContains('result', $hiv);

        $cvc = Schema::getColumnListing('cvc_screening');
        $this->assertContains('cvc_screening_id', $cvc);
        $this->assertContains('value', $cvc);
        $this->assertNotContains('date_screened', $cvc);
        $this->assertNotContains('result', $cvc);
        $this->assertNotContains('visit_number', $cvc);
        $this->assertNotContains('month_number', $cvc);

        $gestational = Schema::getColumnListing('gestational_screening');
        $this->assertContains('gestational_screening_id', $gestational);
        $this->assertContains('value', $gestational);
        $this->assertNotContains('date_screened', $gestational);
        $this->assertNotContains('result', $gestational);
        $this->assertNotContains('visit_number', $gestational);

        $this->assertSame(['REACTIVE', 'NON REACTIVE'], DemoMaternalCare::SYPHILIS_RESULTS);
        $this->assertSame(['REACTIVE', 'NON REACTIVE'], DemoMaternalCare::HIV_RESULTS);
        $this->assertSame(['Reactive', 'Negative'], DemoMaternalCare::HEPATITIS_B_RESULTS);
    }

    public function test_empty_payload_creates_no_new_rows(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->register($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'urinalysis' => ['date' => ''],
            'ultrasound' => ['date' => ''],
            'syphilis' => ['date' => '', 'result' => ''],
            'hiv' => ['date' => '', 'result' => ''],
            'cvc' => ['value' => ''],
            'gestational' => ['value' => ''],
        ])->assertRedirect();

        foreach (self::NEW_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame(0, DB::table('hepatitis_b_screening')->count());
        $this->assertSame(0, DB::table('cbc_hgb_hct_screening')->count());
        $this->assertSame(0, DB::table('gdm_screening')->count());
    }

    public function test_each_new_item_persists_only_to_its_own_table(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1202',
            'member_no' => 'MB-1202',
        ]);
        $this->register($household, $resident);
        $careId = $this->maternalCareId($resident);

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'urinalysis' => ['date' => '2026-02-01'],
            'ultrasound' => ['date' => '2026-02-02'],
            'syphilis' => ['date' => '2026-02-03', 'result' => 'NON REACTIVE'],
            'hiv' => ['date' => '2026-02-04', 'result' => 'REACTIVE'],
            'cvc' => ['value' => '12.5'],
            'gestational' => ['value' => '8.25'],
            'hepatitis_b' => ['date' => '2026-02-15', 'result' => 'Negative'],
            'cbc' => ['date' => '2026-02-16', 'result' => 'Without Anemia'],
            'gdm' => ['date' => '2026-02-17', 'result' => 'Negative'],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('urinalysis_screening')->count());
        $this->assertSame($careId, (int) DB::table('urinalysis_screening')->value('maternal_care_id'));
        $this->assertSame('2026-02-01', DB::table('urinalysis_screening')->value('date_screened'));

        $this->assertSame(1, DB::table('ultrasound_screening')->count());
        $this->assertSame('2026-02-02', DB::table('ultrasound_screening')->value('date_screened'));

        $this->assertSame(1, DB::table('syphilis_screening')->count());
        $this->assertSame('2026-02-03', DB::table('syphilis_screening')->value('date_screened'));
        $this->assertSame('NON REACTIVE', AtRestRecord::open(DB::table('syphilis_screening')->value('result'), 'syphilis_screening', 'result'));

        $this->assertSame(1, DB::table('hiv_screening')->count());
        $this->assertSame('2026-02-04', DB::table('hiv_screening')->value('date_screened'));
        $this->assertSame('REACTIVE', AtRestRecord::open(DB::table('hiv_screening')->value('result'), 'hiv_screening', 'result'));

        $this->assertSame(1, DB::table('cvc_screening')->count());
        $this->assertEqualsWithDelta(12.5, (float) DB::table('cvc_screening')->value('value'), 0.001);

        $this->assertSame(1, DB::table('gestational_screening')->count());
        $this->assertEqualsWithDelta(8.25, (float) DB::table('gestational_screening')->value('value'), 0.001);

        $this->assertSame('Negative', AtRestRecord::open(DB::table('hepatitis_b_screening')->value('result'), 'hepatitis_b_screening', 'result'));
        $this->assertSame('Without Anemia', DB::table('cbc_hgb_hct_screening')->value('result'));
        $this->assertSame('Negative', DB::table('gdm_screening')->value('result'));
        $this->assertSame(1, DB::table('cbc_hgb_hct_screening')->count());
        $this->assertSame(1, DB::table('gdm_screening')->count());
    }

    public function test_syphilis_and_hiv_reject_hepatitis_style_and_hyphenated_results(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1203',
            'member_no' => 'MB-1203',
        ]);
        $this->register($household, $resident);
        $from = $this->labUrl($household, $resident);

        $this->from($from)->put($this->updateRoute($household, $resident, 'laboratory'), [
            'syphilis' => ['date' => '2026-03-01', 'result' => 'Reactive'],
        ])->assertRedirect()->assertSessionHasErrors('syphilis.result');

        $this->from($from)->put($this->updateRoute($household, $resident, 'laboratory'), [
            'hiv' => ['result' => 'Negative'],
        ])->assertRedirect()->assertSessionHasErrors('hiv.result');

        $this->from($from)->put($this->updateRoute($household, $resident, 'laboratory'), [
            'syphilis' => ['result' => 'NON-REACTIVE'],
        ])->assertRedirect()->assertSessionHasErrors('syphilis.result');

        $this->assertSame(0, DB::table('syphilis_screening')->count());
        $this->assertSame(0, DB::table('hiv_screening')->count());
    }

    public function test_date_only_and_result_only_syphilis_hiv_persist(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1204',
            'member_no' => 'MB-1204',
        ]);
        $this->register($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'syphilis' => ['date' => '2026-03-02', 'result' => ''],
            'hiv' => ['date' => '', 'result' => 'NON REACTIVE'],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('syphilis_screening')->count());
        $this->assertSame('2026-03-02', DB::table('syphilis_screening')->value('date_screened'));
        $this->assertNull(AtRestRecord::open(DB::table('syphilis_screening')->value('result'), 'syphilis_screening', 'result'));
        $this->assertSame(1, DB::table('hiv_screening')->count());
        $this->assertNull(DB::table('hiv_screening')->value('date_screened'));
        $this->assertSame('NON REACTIVE', AtRestRecord::open(DB::table('hiv_screening')->value('result'), 'hiv_screening', 'result'));
    }

    public function test_hiv_syphilis_and_hepatitis_b_results_are_ciphertext_at_rest(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1210',
            'member_no' => 'MB-1210',
        ]);
        $this->register($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'hepatitis_b' => ['date' => '2026-03-01', 'result' => 'Reactive'],
            'syphilis' => ['date' => '2026-03-02', 'result' => 'REACTIVE'],
            'hiv' => ['date' => '2026-03-03', 'result' => 'NON REACTIVE'],
            'gdm' => ['date' => '2026-03-04', 'result' => 'Negative'],
        ])->assertRedirect();

        foreach (['hepatitis_b_screening', 'syphilis_screening', 'hiv_screening'] as $table) {
            $raw = (string) DB::table($table)->value('result');
            $this->assertTrue(AtRestNarrativeField::isSealed($raw), "{$table}.result should be sealed");
            $this->assertStringNotContainsString('REACTIVE', strtoupper($raw));
        }
        $this->assertSame('2026-03-03', DB::table('hiv_screening')->value('date_screened'));
        $this->assertSame('Negative', DB::table('gdm_screening')->value('result'));

        $this->get($this->labUrl($household, $resident))
            ->assertOk()
            ->assertDontSee('lmlinga:v2:', false);
    }

    public function test_cvc_and_gestational_reject_non_numeric_and_persist_zero(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1205',
            'member_no' => 'MB-1205',
        ]);
        $this->register($household, $resident);
        $from = $this->labUrl($household, $resident);

        $this->from($from)->put($this->updateRoute($household, $resident, 'laboratory'), [
            'cvc' => ['value' => 'abc'],
        ])->assertRedirect()->assertSessionHasErrors('cvc.value');

        $this->from($from)->put($this->updateRoute($household, $resident, 'laboratory'), [
            'gestational' => ['value' => '12weeks'],
        ])->assertRedirect()->assertSessionHasErrors('gestational.value');

        $this->assertSame(0, DB::table('cvc_screening')->count());
        $this->assertSame(0, DB::table('gestational_screening')->count());

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'cvc' => ['value' => '0'],
            'gestational' => ['value' => 0],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('cvc_screening')->count());
        $this->assertEqualsWithDelta(0.0, (float) DB::table('cvc_screening')->value('value'), 0.001);
        $this->assertSame(1, DB::table('gestational_screening')->count());
        $this->assertEqualsWithDelta(0.0, (float) DB::table('gestational_screening')->value('value'), 0.001);
        $this->assertSame(0, DB::table('cbc_hgb_hct_screening')->count());
        $this->assertSame(0, DB::table('gdm_screening')->count());
    }

    public function test_empty_numeric_does_not_create_a_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1206',
            'member_no' => 'MB-1206',
        ]);
        $this->register($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'cvc' => ['value' => ''],
            'gestational' => ['value' => ''],
        ])->assertRedirect();

        $this->assertSame(0, DB::table('cvc_screening')->count());
        $this->assertSame(0, DB::table('gestational_screening')->count());
    }

    public function test_omitted_keys_preserve_existing_values(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1207',
            'member_no' => 'MB-1207',
        ]);
        $this->register($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'urinalysis' => ['date' => '2026-04-01'],
            'syphilis' => ['date' => '2026-04-02', 'result' => 'REACTIVE'],
            'cvc' => ['value' => '4.5'],
            'hepatitis_b' => ['date' => '2026-04-03', 'result' => 'Negative'],
        ])->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'ultrasound' => ['date' => '2026-04-10'],
        ])->assertRedirect();

        $this->assertSame('2026-04-01', DB::table('urinalysis_screening')->value('date_screened'));
        $this->assertSame('REACTIVE', AtRestRecord::open(DB::table('syphilis_screening')->value('result'), 'syphilis_screening', 'result'));
        $this->assertEqualsWithDelta(4.5, (float) DB::table('cvc_screening')->value('value'), 0.001);
        $this->assertSame('Negative', AtRestRecord::open(DB::table('hepatitis_b_screening')->value('result'), 'hepatitis_b_screening', 'result'));
        $this->assertSame('2026-04-10', DB::table('ultrasound_screening')->value('date_screened'));
    }

    public function test_existing_row_posted_blank_nulls_fields(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1208',
            'member_no' => 'MB-1208',
        ]);
        $this->register($household, $resident);

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'urinalysis' => ['date' => '2026-05-01'],
            'syphilis' => ['date' => '2026-05-02', 'result' => 'REACTIVE'],
            'cvc' => ['value' => '9'],
        ])->assertRedirect();
        $urineId = (int) DB::table('urinalysis_screening')->value('urinalysis_screening_id');
        $syphilisId = (int) DB::table('syphilis_screening')->value('syphilis_screening_id');
        $cvcId = (int) DB::table('cvc_screening')->value('cvc_screening_id');

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'urinalysis' => ['date' => ''],
            'syphilis' => ['date' => '', 'result' => ''],
            'cvc' => ['value' => ''],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('urinalysis_screening')->count());
        $this->assertSame($urineId, (int) DB::table('urinalysis_screening')->value('urinalysis_screening_id'));
        $this->assertNull(DB::table('urinalysis_screening')->value('date_screened'));
        $this->assertSame($syphilisId, (int) DB::table('syphilis_screening')->value('syphilis_screening_id'));
        $this->assertNull(DB::table('syphilis_screening')->value('date_screened'));
        $this->assertNull(AtRestRecord::open(DB::table('syphilis_screening')->value('result'), 'syphilis_screening', 'result'));
        $this->assertSame($cvcId, (int) DB::table('cvc_screening')->value('cvc_screening_id'));
        $this->assertNull(DB::table('cvc_screening')->value('value'));
    }

    public function test_forged_screening_ids_and_maternal_care_id_are_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1209',
            'member_no' => 'MB-1209',
        ]);
        $this->register($household, $resident);
        $from = $this->labUrl($household, $resident);

        $this->from($from)->put($this->updateRoute($household, $resident, 'laboratory'), [
            'maternal_care_id' => 999,
            'syphilis_screening_id' => 888,
            'cvc_screening_id' => 777,
            'syphilis' => [
                'syphilis_screening_id' => 888,
                'id' => 888,
                'result' => 'REACTIVE',
            ],
            'cvc' => [
                'id' => 777,
                'cvc_screening_id' => 777,
                'value' => '3',
            ],
        ])->assertRedirect()->assertSessionHasErrors([
            'maternal_care_id',
            'syphilis_screening_id',
            'cvc_screening_id',
            'syphilis.id',
            'syphilis.syphilis_screening_id',
            'cvc.id',
            'cvc.cvc_screening_id',
        ]);

        $this->assertSame(0, DB::table('syphilis_screening')->count());
        $this->assertSame(0, DB::table('cvc_screening')->count());
    }

    public function test_cross_resident_isolation(): void
    {
        ['household' => $firstHh, 'resident' => $first] = $this->seedMember([
            'household_no' => 'HH-1210',
            'member_no' => 'MB-1210',
            'first_name' => 'First',
        ]);
        ['household' => $secondHh, 'resident' => $second] = $this->seedMember([
            'household_no' => 'HH-1211',
            'member_no' => 'MB-1211',
            'first_name' => 'Second',
        ]);
        $this->register($firstHh, $first);
        $this->register($secondHh, $second);

        $this->put($this->updateRoute($firstHh, $first, 'laboratory'), [
            'syphilis' => ['result' => 'REACTIVE'],
            'cvc' => ['value' => '11'],
        ])->assertRedirect();
        $firstCare = $this->maternalCareId($first);

        $this->put($this->updateRoute($secondHh, $second, 'laboratory'), [
            'syphilis' => ['result' => 'NON REACTIVE'],
            'cvc' => ['value' => '2'],
        ])->assertRedirect();

        $this->assertSame(2, DB::table('syphilis_screening')->count());
        $this->assertSame(
            'REACTIVE',
            AtRestRecord::open(DB::table('syphilis_screening')->where('maternal_care_id', $firstCare)->value('result'), 'syphilis_screening', 'result')
        );
        $this->assertSame(
            'NON REACTIVE',
            AtRestRecord::open(DB::table('syphilis_screening')->where('maternal_care_id', $this->maternalCareId($second))->value('result'), 'syphilis_screening', 'result')
        );
        $this->assertEqualsWithDelta(
            11.0,
            (float) DB::table('cvc_screening')->where('maternal_care_id', $firstCare)->value('value'),
            0.001
        );
    }

    public function test_completed_episode_cannot_modify_laboratory(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1212',
            'member_no' => 'MB-1212',
        ]);
        $this->register($household, $resident);
        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'syphilis' => ['result' => 'REACTIVE'],
            'cvc' => ['value' => '5'],
        ])->assertRedirect();

        Carbon::setTestNow(Carbon::parse('2026-10-20T08:30'));
        $this->put($this->updateRoute($household, $resident, 'delivery'), [
            'outcome' => 'FT',
            'datetime' => '2026-10-20T08:30',
        ])->assertRedirect();

        $this->from($this->labUrl($household, $resident))
            ->put($this->updateRoute($household, $resident, 'laboratory'), [
                'syphilis' => ['result' => 'NON REACTIVE'],
                'cvc' => ['value' => '9'],
                'urinalysis' => ['date' => '2026-10-21'],
            ])
            ->assertForbidden();

        $this->assertSame('REACTIVE', AtRestRecord::open(DB::table('syphilis_screening')->value('result'), 'syphilis_screening', 'result'));
        $this->assertEqualsWithDelta(5.0, (float) DB::table('cvc_screening')->value('value'), 0.001);
        $this->assertSame(0, DB::table('urinalysis_screening')->count());
    }

    public function test_ui_and_history_display_new_screens_read_only(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1213',
            'member_no' => 'MB-1213',
        ]);
        $this->register($household, $resident);

        $page = $this->get($this->labUrl($household, $resident))->assertOk()->getContent();
        foreach (['hepatitis_b', 'cbc', 'gdm', 'urinalysis', 'ultrasound', 'syphilis', 'hiv', 'cvc', 'gestational'] as $key) {
            $this->assertStringContainsString('data-mc-lab="'.$key.'"', $page);
        }
        $this->assertStringContainsString('name="urinalysis[date]"', $page);
        $this->assertStringNotContainsString('name="urinalysis[result]"', $page);
        $this->assertStringContainsString('name="ultrasound[date]"', $page);
        $this->assertStringNotContainsString('name="ultrasound[result]"', $page);
        $this->assertStringContainsString('name="syphilis[date]"', $page);
        $this->assertStringContainsString('name="syphilis[result]"', $page);
        $this->assertStringContainsString('value="REACTIVE"', $page);
        $this->assertStringContainsString('value="NON REACTIVE"', $page);
        $this->assertStringContainsString('value="Reactive"', $page);
        $this->assertStringContainsString('name="cvc[value]"', $page);
        $this->assertStringNotContainsString('name="cvc[date]"', $page);
        $this->assertStringContainsString('name="gestational[value]"', $page);
        $this->assertStringNotContainsString('name="gestational[date]"', $page);
        $this->assertStringContainsString('data-mc-edit-for="laboratory"', $page);

        $this->put($this->updateRoute($household, $resident, 'laboratory'), [
            'urinalysis' => ['date' => '2026-06-01'],
            'syphilis' => ['date' => '2026-06-02', 'result' => 'NON REACTIVE'],
            'cvc' => ['value' => '6.5'],
        ])->assertRedirect();

        $this->put($this->updateRoute($household, $resident, 'trans-out'), [
            'to_facility' => 'RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Moved',
            'date_transferred_out' => '2026-06-15',
        ])->assertRedirect();

        $history = $this->get(route('household-profiling.members.maternal-care.history.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'pregnancyId' => sprintf('MC-%03d', $this->maternalCareId($resident)),
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-mc-readonly="true"', $history);
        $this->assertStringContainsString('value="2026-06-01"', $history);
        $this->assertStringContainsString('value="2026-06-02"', $history);
        $this->assertStringContainsString('value="6.5"', $history);
        $this->assertStringNotContainsString('data-mc-edit-for="laboratory"', $history);
    }

    public function test_offline_maternal_section_update_accepts_new_laboratory_fields(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember([
            'household_no' => 'HH-1214',
            'member_no' => 'MB-1214',
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
                '_health_section' => 'laboratory',
                'urinalysis' => ['date' => ''],
                'cvc' => ['value' => ''],
            ],
            $parent,
        ));
        $empty->assertOk();
        $this->assertSame(0, DB::table('urinalysis_screening')->count());
        $this->assertSame(0, DB::table('cvc_screening')->count());

        $saved = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'maternal_section_update',
                '_health_section' => 'laboratory',
                'urinalysis' => ['date' => '2026-07-01'],
                'ultrasound' => ['date' => '2026-07-02'],
                'syphilis' => ['date' => '2026-07-03', 'result' => 'REACTIVE'],
                'hiv' => ['result' => 'NON REACTIVE'],
                'cvc' => ['value' => '0'],
                'gestational' => ['value' => '3.5'],
            ],
            $parent,
        ));
        $saved->assertOk()->assertJsonPath('code', 'SYNCED');
        $this->assertSame(1, DB::table('urinalysis_screening')->count());
        $this->assertSame('2026-07-01', DB::table('urinalysis_screening')->value('date_screened'));
        $this->assertSame('REACTIVE', AtRestRecord::open(DB::table('syphilis_screening')->value('result'), 'syphilis_screening', 'result'));
        $this->assertSame('NON REACTIVE', AtRestRecord::open(DB::table('hiv_screening')->value('result'), 'hiv_screening', 'result'));
        $this->assertEqualsWithDelta(0.0, (float) DB::table('cvc_screening')->value('value'), 0.001);
        $this->assertEqualsWithDelta(3.5, (float) DB::table('gestational_screening')->value('value'), 0.001);
        $this->assertSame(0, DB::table('cbc_hgb_hct_screening')->count());
        $this->assertSame(0, DB::table('gdm_screening')->count());
    }
}
