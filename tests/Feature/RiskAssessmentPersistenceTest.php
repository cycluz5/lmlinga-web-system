<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Support\DatabaseSchemaGuard;
use App\Support\DemoRiskAssessment;
use App\Support\UiRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

/**
 * DB-12 Phase 2 — Risk Assessment database persistence for Household Profiling.
 */
class RiskAssessmentPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
    private function seedPersistedResident(array $overrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => $overrides['household_no'] ?? 'HH-930',
            'zone' => 'Zone 1',
            'street' => 'Risk St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-930',
            'first_name' => $overrides['first_name'] ?? 'Ana',
            'last_name' => $overrides['last_name'] ?? 'Risk',
            'middle_name' => null,
            'relation' => 'Daughter',
            'sex' => 'Female',
            'birthday' => $overrides['birthday'] ?? '1990-05-01',
            'relationship_status' => 'Single',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    /**
     * @return array<string, mixed>
     */
    private function validStorePayload(array $overrides = []): array
    {
        return array_merge([
            'red_flags' => ['none'],
            'past_medical' => ['none'],
            'family_history' => ['hypertension'],
            'tobacco' => 'never',
            'alcohol' => 'never',
            'dietary' => 'yes',
            'physical_activity' => 'yes',
            'height_cm' => '165',
            'weight_kg' => '58',
            'bmi' => '21.3',
            'waist_cm' => '72',
            'systolic' => '120',
            'diastolic' => '80',
            'bp_status' => 'Normal',
            'visual_no_screening' => '0',
            'visual_blurred' => '0',
            'visual_blurred_note' => '',
        ], $overrides);
    }

    private function storeRoute(Household $household, Resident $resident): string
    {
        return route('household-profiling.members.risk-assessment.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);
    }

    public function test_risk_assessments_schema_contract(): void
    {
        $this->assertTrue(Schema::hasTable('risk_assessments'));
        foreach ([
            'resident_id', 'assessment_no', 'conducted_at',
            'red_flags', 'past_medical', 'family_history', 'dietary',
            'tobacco', 'alcohol', 'physical_activity',
            'height_cm', 'weight_kg', 'bmi', 'waist_cm',
            'systolic', 'diastolic', 'bp_status', 'bp_reading', 'bmi_label',
            'visual_no_screening', 'visual_blurred', 'visual_blurred_note',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('risk_assessments', $column), $column);
        }
    }

    public function test_persisted_resident_can_open_empty_risk_assessment_history(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('household-profiling.members.risk-assessment', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No risk assessments recorded for this resident.', $html);
        $this->assertStringContainsString('Ana Risk', $html);
        $this->assertStringNotContainsString('data-assessment-id="RA-001"', $html);
    }

    public function test_add_post_creates_exactly_one_row_for_resident(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->actingAsStaff(StaffRole::BHW);
        $this->post($this->storeRoute($household, $resident), $this->validStorePayload())
            ->assertRedirect(route('household-profiling.members.risk-assessment', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]));

        $this->assertSame(1, RiskAssessment::query()->count());
        $row = RiskAssessment::query()->first();
        $this->assertNotNull($row);
        $this->assertSame($resident->id, $row->resident_id);
        $this->assertSame('RA-001', $row->assessment_no);
        $this->assertSame('2026-08-15', $row->conducted_at->toDateString());
        $this->assertSame('120/80', $row->bp_reading);
        $this->assertSame(['none'], $row->red_flags);
    }

    public function test_assessment_no_is_server_generated_and_unique(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->post($this->storeRoute($household, $resident), $this->validStorePayload())->assertRedirect();
        $this->post($this->storeRoute($household, $resident), $this->validStorePayload())->assertRedirect();

        $numbers = RiskAssessment::query()->orderBy('id')->pluck('assessment_no')->all();
        $this->assertSame(['RA-001', 'RA-002'], $numbers);
        $this->assertCount(2, array_unique($numbers));
    }

    public function test_illegal_enum_returns_422_and_creates_no_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->from(route('household-profiling.members.risk-assessment.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'tobacco' => 'not-a-valid-option',
        ]))->assertSessionHasErrors('tobacco');

        $this->assertSame(0, RiskAssessment::query()->count());
    }

    public function test_all_optional_fields_may_be_omitted(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->post($this->storeRoute($household, $resident), [])->assertRedirect();

        $this->assertSame(1, RiskAssessment::query()->count());
        $row = RiskAssessment::query()->first();
        $this->assertNull($row->tobacco);
        $this->assertNull($row->red_flags);
        $this->assertFalse($row->visual_no_screening);
    }

    public function test_history_displays_new_db_row(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->post($this->storeRoute($household, $resident), $this->validStorePayload())->assertRedirect();

        $html = $this->get(route('household-profiling.members.risk-assessment', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('08/15/2026', $html);
        $this->assertStringContainsString('120/80', $html);
        $this->assertStringContainsString('21.3', $html);
        $this->assertStringContainsString('data-conducted-at="2026-08-15"', $html);
    }

    public function test_another_resident_does_not_see_the_row(): void
    {
        ['household' => $h1, 'resident' => $r1] = $this->seedPersistedResident([
            'household_no' => 'HH-931',
            'member_no' => 'MB-931',
        ]);
        ['household' => $h2, 'resident' => $r2] = $this->seedPersistedResident([
            'household_no' => 'HH-932',
            'member_no' => 'MB-932',
            'first_name' => 'Other',
            'last_name' => 'Person',
        ]);

        $this->post($this->storeRoute($h1, $r1), $this->validStorePayload())->assertRedirect();

        $html = $this->get(route('household-profiling.members.risk-assessment', [
            'householdNo' => $h2->household_no,
            'memberId' => $r2->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('No risk assessments recorded for this resident.', $html);
        $this->assertSame(0, RiskAssessment::query()->where('resident_id', $r2->id)->count());
        $this->assertSame(1, RiskAssessment::query()->where('resident_id', $r1->id)->count());
    }

    public function test_db_resident_without_demo_catalog_fixture_works(): void
    {
        // HH-999 / MB-999 are not in DemoCatalog risk-assessments fixture.
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-999',
            'member_no' => 'MB-999',
            'first_name' => 'Solo',
            'last_name' => 'DbOnly',
        ]);

        $this->get(route('household-profiling.members.risk-assessment', [
            'householdNo' => 'HH-999',
            'memberId' => 'MB-999',
        ]))->assertOk()->assertSee('Solo DbOnly', false);

        $this->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'red_flags' => ['chest_pain'],
        ]))->assertRedirect();

        $this->assertDatabaseHas('risk_assessments', [
            'resident_id' => $resident->id,
            'assessment_no' => 'RA-001',
        ]);

        $this->assertSame([], DemoRiskAssessment::forMember('HH-999', 'MB-999'));
    }

    public function test_persisted_resident_history_does_not_inject_demo_fixture_rows(): void
    {
        // Same business keys as demo fixture HH-151/MB-001, but DB-backed.
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-151',
            'member_no' => 'MB-001',
            'first_name' => 'Kristine',
            'last_name' => 'Reyes',
        ]);

        $html = $this->get(route('household-profiling.members.risk-assessment', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('No risk assessments recorded for this resident.', $html);
        $this->assertStringNotContainsString('06/08/2026', $html);
        $this->assertStringNotContainsString('RA-001', $html);
        $this->assertNotEmpty(DemoRiskAssessment::forMember('HH-151', 'MB-001'));
    }

    public function test_show_loads_db_values(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'red_flags' => ['chest_pain'],
        ]))->assertRedirect();

        $html = $this->get(route('household-profiling.members.risk-assessment.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'assessmentId' => 'RA-001',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-assessment-id="RA-001"', $html);
        $this->assertStringContainsString('data-risk-assess-section-card="red-flags"', $html);
    }

    public function test_unknown_assessment_returns_404_for_persisted_resident(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->get(route('household-profiling.members.risk-assessment.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'assessmentId' => 'RA-999',
        ]))->assertNotFound();
    }

    public function test_assessment_belonging_to_another_member_returns_404(): void
    {
        ['household' => $h1, 'resident' => $r1] = $this->seedPersistedResident([
            'household_no' => 'HH-941',
            'member_no' => 'MB-941',
        ]);
        ['household' => $h2, 'resident' => $r2] = $this->seedPersistedResident([
            'household_no' => 'HH-942',
            'member_no' => 'MB-942',
            'first_name' => 'Other',
            'last_name' => 'Member',
        ]);

        $this->post($this->storeRoute($h1, $r1), $this->validStorePayload())->assertRedirect();

        $this->get(route('household-profiling.members.risk-assessment.show', [
            'householdNo' => $h2->household_no,
            'memberId' => $r2->member_no,
            'assessmentId' => 'RA-001',
        ]))->assertNotFound();
    }

    public function test_section_edit_updates_same_row_without_increasing_count(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'red_flags' => ['none'],
        ]))->assertRedirect();

        $params = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'assessmentId' => 'RA-001',
            'section' => 'red-flags',
        ];

        $this->put(
            route('household-profiling.members.risk-assessment.section.update', $params),
            ['red_flags' => ['chest_pain', 'seizure']]
        )->assertRedirect(route('household-profiling.members.risk-assessment.section', $params));

        $this->assertSame(1, RiskAssessment::query()->count());
        $row = RiskAssessment::query()->first();
        $this->assertSame(['chest_pain', 'seizure'], $row->red_flags);
        $this->assertSame('RA-001', $row->assessment_no);
    }

    public function test_tampered_member_identity_fails_closed_on_store(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->post(route('household-profiling.members.risk-assessment.store', [
            'householdNo' => $household->household_no,
            'memberId' => 'MB-000',
        ]), $this->validStorePayload())->assertNotFound();

        $this->assertSame(0, RiskAssessment::query()->where('resident_id', $resident->id)->count());
    }

    public function test_stolen_assessment_no_across_members_fails_closed_on_update(): void
    {
        ['household' => $h1, 'resident' => $r1] = $this->seedPersistedResident([
            'household_no' => 'HH-951',
            'member_no' => 'MB-951',
        ]);
        ['household' => $h2] = $this->seedPersistedResident([
            'household_no' => 'HH-952',
            'member_no' => 'MB-952',
            'first_name' => 'Other',
            'last_name' => 'Person',
        ]);

        $this->post($this->storeRoute($h1, $r1), $this->validStorePayload([
            'red_flags' => ['none'],
        ]))->assertRedirect();

        $this->put(route('household-profiling.members.risk-assessment.section.update', [
            'householdNo' => $h2->household_no,
            'memberId' => 'MB-952',
            'assessmentId' => 'RA-001',
            'section' => 'red-flags',
        ]), ['red_flags' => ['chest_pain']])->assertForbidden();

        $this->assertSame(['none'], RiskAssessment::query()->where('resident_id', $r1->id)->value('red_flags'));
    }

    public function test_demo_only_member_store_fails_closed(): void
    {
        // No DB seed — DemoCatalog HH-151/MB-001 only.
        $this->post(route('household-profiling.members.risk-assessment.store', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]), $this->validStorePayload())->assertNotFound();

        $this->assertSame(0, RiskAssessment::query()->count());
    }

    public function test_date_filter_this_month_last_3_months_this_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20'));
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        RiskAssessment::factory()->create([
            'resident_id' => $resident->id,
            'assessment_no' => 'RA-101',
            'conducted_at' => '2026-08-10',
            'bp_reading' => '110/70',
            'bmi_label' => 'Aug',
        ]);
        RiskAssessment::factory()->create([
            'resident_id' => $resident->id,
            'assessment_no' => 'RA-102',
            'conducted_at' => '2026-06-01',
            'bp_reading' => '120/80',
            'bmi_label' => 'Jun',
        ]);
        RiskAssessment::factory()->create([
            'resident_id' => $resident->id,
            'assessment_no' => 'RA-103',
            'conducted_at' => '2025-12-01',
            'bp_reading' => '130/85',
            'bmi_label' => 'PrevYear',
        ]);

        $history = fn (array $query) => $this->get(route('household-profiling.members.risk-assessment', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ] + $query))->assertOk()->getContent();

        $thisMonth = $history(['date' => 'this_month']);
        $this->assertStringContainsString('Aug', $thisMonth);
        $this->assertStringNotContainsString('Jun', $thisMonth);
        $this->assertStringNotContainsString('PrevYear', $thisMonth);

        $last3 = $history(['date' => 'last_3_months']);
        $this->assertStringContainsString('Aug', $last3);
        $this->assertStringContainsString('Jun', $last3);
        $this->assertStringNotContainsString('PrevYear', $last3);

        $thisYear = $history(['date' => 'this_year']);
        $this->assertStringContainsString('Aug', $thisYear);
        $this->assertStringContainsString('Jun', $thisYear);
        $this->assertStringNotContainsString('PrevYear', $thisYear);
    }

    public function test_custom_range_inclusive_and_invalid_preserves_approved_behavior(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20'));
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        RiskAssessment::factory()->create([
            'resident_id' => $resident->id,
            'assessment_no' => 'RA-201',
            'conducted_at' => '2026-05-01',
            'bmi_label' => 'MayEdge',
        ]);
        RiskAssessment::factory()->create([
            'resident_id' => $resident->id,
            'assessment_no' => 'RA-202',
            'conducted_at' => '2026-06-15',
            'bmi_label' => 'Mid',
        ]);

        $base = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];

        $inclusive = $this->get(route('household-profiling.members.risk-assessment', $base + [
            'date' => 'custom',
            'from' => '2026-05-01',
            'to' => '2026-05-01',
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('MayEdge', $inclusive);
        $this->assertStringNotContainsString('>Mid<', $inclusive);

        $incomplete = $this->get(route('household-profiling.members.risk-assessment', $base + [
            'date' => 'custom',
            'from' => '2026-05-01',
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('MayEdge', $incomplete);
        $this->assertStringContainsString('Mid', $incomplete);

        $inverted = $this->get(route('household-profiling.members.risk-assessment', $base + [
            'date' => 'custom',
            'from' => '2026-06-15',
            'to' => '2026-05-01',
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('MayEdge', $inverted);
        $this->assertStringContainsString('Mid', $inverted);
    }

    public function test_none_exclusive_rules_on_store(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'red_flags' => ['chest_pain', 'none'],
        ]))->assertRedirect();

        $row = RiskAssessment::query()->first();
        $this->assertSame(['none'], $row->red_flags);
    }

    public function test_create_form_posts_to_store_for_persisted_resident(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $html = $this->get(route('household-profiling.members.risk-assessment.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $storeUrl = route('household-profiling.members.risk-assessment.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);
        $this->assertStringContainsString('action="'.e($storeUrl).'"', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('_token', $html);
    }

    public function test_refresh_returns_db_saved_section_values(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'family_history' => ['diabetes_mellitus'],
        ]))->assertRedirect();

        $params = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'assessmentId' => 'RA-001',
            'section' => 'family-history',
        ];

        $this->put(
            route('household-profiling.members.risk-assessment.section.update', $params),
            ['family_history' => ['stroke', 'cancer']]
        )->assertRedirect();

        // New request (no session overlay dependency).
        $this->flushSession();

        $html = $this->get(route('household-profiling.members.risk-assessment.section', $params))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/name="family_history\[\]"[^>]*value="stroke"[^>]*checked/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="family_history\[\]"[^>]*value="cancer"[^>]*checked/u',
            $html
        );
    }

    public function test_client_supplied_resident_id_and_assessment_no_are_prohibited(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->from(route('household-profiling.members.risk-assessment.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'resident_id' => 999,
            'assessment_no' => 'RA-999',
        ]))->assertSessionHasErrors(['resident_id', 'assessment_no']);

        $this->assertSame(0, RiskAssessment::query()->count());
    }

    public function test_create_stores_server_calculated_bmi_and_bp_status(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'height_cm' => '178',
            'weight_kg' => '55',
            'bmi' => '99.9',
            'systolic' => '118',
            'diastolic' => '76',
            'bp_status' => 'NORMAL',
        ]))->assertRedirect();

        $row = RiskAssessment::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('17.4', number_format((float) $row->bmi, 1, '.', ''));
        $this->assertSame('NORMAL', $row->bp_status);
        $this->assertSame('118/76', $row->bp_reading);
    }

    public function test_tampered_bmi_and_bp_status_cannot_override_server_calculation(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'height_cm' => '178',
            'weight_kg' => '55',
            'bmi' => '99.9',
            'systolic' => '181',
            'diastolic' => '100',
            'bp_status' => 'NORMAL',
        ]))->assertRedirect();

        $row = RiskAssessment::query()->first();
        $this->assertSame('17.4', number_format((float) $row->bmi, 1, '.', ''));
        $this->assertSame('SEVERE HYPERTENSION', $row->bp_status);
        $this->assertNotSame('99.9', (string) $row->bmi);
        $this->assertNotSame('NORMAL', $row->bp_status);
    }

    public function test_section_edit_recalculates_bmi_and_bp_status_on_same_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'height_cm' => '178',
            'weight_kg' => '55',
            'systolic' => '118',
            'diastolic' => '76',
        ]))->assertRedirect();

        $params = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'assessmentId' => 'RA-001',
            'section' => 'physical',
        ];

        $this->put(
            route('household-profiling.members.risk-assessment.section.update', $params),
            [
                'height_cm' => '170',
                'weight_kg' => '68',
                'bmi' => '1.0',
                'waist_cm' => '80',
                'systolic' => '145',
                'diastolic' => '82',
                'bp_status' => 'NORMAL',
                'visual_no_screening' => '0',
                'visual_blurred' => '0',
                'visual_blurred_note' => '',
            ]
        )->assertRedirect(route('household-profiling.members.risk-assessment.section', $params));

        $this->assertSame(1, RiskAssessment::query()->count());
        $row = RiskAssessment::query()->first();
        // 68 / (1.7^2) = 23.529... → 23.5
        $this->assertSame('23.5', number_format((float) $row->bmi, 1, '.', ''));
        $this->assertSame('STAGE 2 HYPERTENSION', $row->bp_status);
        $this->assertSame('145/82', $row->bp_reading);

        $this->flushSession();
        $html = $this->get(route('household-profiling.members.risk-assessment.section', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('value="23.5"', $html);
        $this->assertStringContainsString('value="STAGE 2 HYPERTENSION"', $html);
    }

    public function test_create_form_marks_bmi_and_bp_status_readonly(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $html = $this->get(route('household-profiling.members.risk-assessment.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        // BMI is server/JS-derived only — never directly editable, so it's a
        // hidden field now (its value is shown read-only via a combined
        // "20 (Normal)" display, not a readonly text input).
        $this->assertMatchesRegularExpression('/type="hidden"[^>]*name="bmi"/u', $html);
        $this->assertMatchesRegularExpression('/name="bp_status"[^>]*\breadonly\b/u', $html);
        $this->assertStringContainsString('data-risk-assess-bmi', $html);
        $this->assertStringContainsString('data-risk-assess-bp-status', $html);
    }

    public function test_non_physical_section_edits_preserve_clinical_values(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'red_flags' => ['none'],
            'past_medical' => ['none'],
            'family_history' => ['none'],
            'tobacco' => 'never',
            'alcohol' => 'never',
            'dietary' => 'yes',
            'physical_activity' => 'yes',
            'height_cm' => '178',
            'weight_kg' => '55',
            'bmi' => '99.9',
            'waist_cm' => '72',
            'systolic' => '111',
            'diastolic' => '80',
            'bp_status' => 'NORMAL',
            'visual_no_screening' => '0',
            'visual_blurred' => '0',
            'visual_blurred_note' => '',
        ]))->assertRedirect();

        $before = RiskAssessment::query()->first();
        $this->assertNotNull($before);
        $this->assertSame('RA-001', $before->assessment_no);
        $this->assertSame($resident->id, $before->resident_id);
        $this->assertSame('17.4', number_format((float) $before->bmi, 1, '.', ''));
        $this->assertSame('STAGE 1 HYPERTENSION', $before->bp_status);
        $this->assertSame('111/80', $before->bp_reading);
        $this->assertSame(178.0, (float) $before->height_cm);
        $this->assertSame(55.0, (float) $before->weight_kg);
        $this->assertSame(111, $before->systolic);
        $this->assertSame(80, $before->diastolic);

        $base = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'assessmentId' => 'RA-001',
        ];

        $this->put(
            route('household-profiling.members.risk-assessment.section.update', $base + [
                'section' => 'red-flags',
            ]),
            ['red_flags' => ['chest_pain']]
        )->assertRedirect();

        $this->put(
            route('household-profiling.members.risk-assessment.section.update', $base + [
                'section' => 'past-medical',
            ]),
            ['past_medical' => ['asthma']]
        )->assertRedirect();

        $this->put(
            route('household-profiling.members.risk-assessment.section.update', $base + [
                'section' => 'family-history',
            ]),
            ['family_history' => ['stroke']]
        )->assertRedirect();

        $this->put(
            route('household-profiling.members.risk-assessment.section.update', $base + [
                'section' => 'lifestyle',
            ]),
            [
                'tobacco' => 'current',
                'alcohol' => 'light',
                'dietary' => 'no',
                'physical_activity' => 'no',
            ]
        )->assertRedirect();

        $this->assertSame(1, RiskAssessment::query()->count());
        $after = RiskAssessment::query()->first();
        $this->assertNotNull($after);
        $this->assertSame($before->id, $after->id);
        $this->assertSame('RA-001', $after->assessment_no);
        $this->assertSame($resident->id, $after->resident_id);

        $this->assertSame(['chest_pain'], $after->red_flags);
        $this->assertSame(['asthma'], $after->past_medical);
        $this->assertSame(['stroke'], $after->family_history);
        $this->assertSame('current', $after->tobacco);
        $this->assertSame('light', $after->alcohol);
        $this->assertSame('no', $after->dietary);
        $this->assertSame('no', $after->physical_activity);

        $this->assertNotNull($after->bmi);
        $this->assertNotNull($after->bp_status);
        $this->assertNotNull($after->bp_reading);
        $this->assertSame('17.4', number_format((float) $after->bmi, 1, '.', ''));
        $this->assertSame('STAGE 1 HYPERTENSION', $after->bp_status);
        $this->assertSame('111/80', $after->bp_reading);
        $this->assertSame(178.0, (float) $after->height_cm);
        $this->assertSame(55.0, (float) $after->weight_kg);
        $this->assertSame(72.0, (float) $after->waist_cm);
        $this->assertSame(111, $after->systolic);
        $this->assertSame(80, $after->diastolic);
        $this->assertFalse($after->visual_no_screening);
        $this->assertFalse($after->visual_blurred);
    }

    public function test_physical_section_edit_recalculates_approved_bp_examples(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'height_cm' => '178',
            'weight_kg' => '55',
            'systolic' => '118',
            'diastolic' => '76',
        ]))->assertRedirect();

        $params = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'assessmentId' => 'RA-001',
            'section' => 'physical',
        ];

        $cases = [
            ['systolic' => '124', 'diastolic' => '76', 'status' => 'ELEVATED'],
            ['systolic' => '111', 'diastolic' => '80', 'status' => 'STAGE 1 HYPERTENSION'],
            ['systolic' => '181', 'diastolic' => '100', 'status' => 'SEVERE HYPERTENSION'],
        ];

        foreach ($cases as $case) {
            $this->put(
                route('household-profiling.members.risk-assessment.section.update', $params),
                [
                    'height_cm' => '178',
                    'weight_kg' => '55',
                    'bmi' => '99.9',
                    'waist_cm' => '72',
                    'systolic' => $case['systolic'],
                    'diastolic' => $case['diastolic'],
                    'bp_status' => 'NORMAL',
                    'visual_no_screening' => '0',
                    'visual_blurred' => '0',
                    'visual_blurred_note' => '',
                ]
            )->assertRedirect();

            $row = RiskAssessment::query()->first();
            $this->assertSame(1, RiskAssessment::query()->count());
            $this->assertSame('RA-001', $row->assessment_no);
            $this->assertSame($resident->id, $row->resident_id);
            $this->assertSame('17.4', number_format((float) $row->bmi, 1, '.', ''));
            $this->assertSame($case['status'], $row->bp_status);
            $this->assertSame($case['systolic'].'/'.$case['diastolic'], $row->bp_reading);
            $this->assertNotSame('99.9', (string) $row->bmi);
            $this->assertNotSame('NORMAL', $row->bp_status);
        }
    }

    /**
     * DB12-F01 — DB connection/query failure must not degrade into demo/session save.
     */
    public function test_db12_f01_section_update_db_failure_does_not_enter_demo_session_path(): void
    {
        // Overlap a catalog household/member so silent demo fallback would be reachable.
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-151',
            'member_no' => 'MB-001',
            'first_name' => 'Real',
            'last_name' => 'Resident',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $this->post($this->storeRoute($household, $resident), $this->validStorePayload([
                'red_flags' => ['none'],
                'height_cm' => '178',
                'weight_kg' => '55',
                'systolic' => '111',
                'diastolic' => '80',
                'bmi' => '99.9',
                'bp_status' => 'NORMAL',
            ]))
            ->assertRedirect();

        $before = RiskAssessment::query()->first();
        $this->assertNotNull($before);
        $this->assertSame('STAGE 1 HYPERTENSION', $before->bp_status);
        $this->assertSame('17.4', number_format((float) $before->bmi, 1, '.', ''));
        $this->assertSame(['none'], $before->red_flags);
        $beforeId = $before->id;
        $beforeUpdatedAt = (string) $before->updated_at;

        $this->mock(DatabaseSchemaGuard::class, function ($mock): void {
            $mock->shouldReceive('tableExists')
                ->with('households')
                ->andThrow(new ServiceUnavailableHttpException(
                    null,
                    'Database is temporarily unavailable.'
                ));
        });

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->put(
                route('household-profiling.members.risk-assessment.section.update', [
                    'householdNo' => 'HH-151',
                    'memberId' => 'MB-001',
                    'assessmentId' => 'RA-001',
                    'section' => 'red-flags',
                ]),
                ['red_flags' => ['chest_pain']]
            );

        $response->assertStatus(503);
        $this->assertNull(session('status'));
        $this->assertSame([], session(DemoRiskAssessment::SESSION_KEY, []));

        $this->assertSame(1, RiskAssessment::query()->count());
        $after = RiskAssessment::query()->first();
        $this->assertSame($beforeId, $after->id);
        $this->assertSame(['none'], $after->red_flags);
        $this->assertSame('STAGE 1 HYPERTENSION', $after->bp_status);
        $this->assertSame('17.4', number_format((float) $after->bmi, 1, '.', ''));
        $this->assertSame($beforeUpdatedAt, (string) $after->updated_at);
    }

    /**
     * DB12-F01 — DB failure on read must not render demo catalog as the resident record.
     */
    public function test_db12_f01_show_db_failure_does_not_render_demo_as_resident_record(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-151',
            'member_no' => 'MB-001',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $this->post($this->storeRoute($household, $resident), $this->validStorePayload([
                'height_cm' => '178',
                'weight_kg' => '55',
                'systolic' => '111',
                'diastolic' => '80',
                'bp_status' => 'NORMAL',
            ]))
            ->assertRedirect();

        $this->assertSame(1, RiskAssessment::query()->count());

        $this->mock(DatabaseSchemaGuard::class, function ($mock): void {
            $mock->shouldReceive('tableExists')
                ->with('households')
                ->andThrow(new ServiceUnavailableHttpException(
                    null,
                    'Database is temporarily unavailable.'
                ));
        });

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('household-profiling.members.risk-assessment.show', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-001',
                'assessmentId' => 'RA-001',
            ]));

        $response->assertStatus(503);
        $this->assertNull(session('status'));
        $this->assertSame([], session(DemoRiskAssessment::SESSION_KEY, []));
        // Demo fixture RA-002 must not appear as a successful history/show render.
        $response->assertDontSee('RA-002', false);
        $response->assertDontSee('Risk assessment section saved.', false);
    }

    /**
     * DB12-F01 — table absence must not fall back to DemoCatalog identities.
     */
    public function test_db12_f01_table_absent_does_not_use_demo_catalog(): void
    {
        $this->mock(DatabaseSchemaGuard::class, function ($mock): void {
            $mock->shouldReceive('tableExists')
                ->with('households')
                ->andReturn(false);
        });

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('household-profiling.members.risk-assessment', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-001',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No record found.', $html);
        $this->assertStringNotContainsString('RA-001', $html);
        $this->assertStringNotContainsString('Kristine Reyes', $html);
        $this->assertSame(0, RiskAssessment::query()->count());
        $this->assertSame([], session(DemoRiskAssessment::SESSION_KEY, []));
    }
}
