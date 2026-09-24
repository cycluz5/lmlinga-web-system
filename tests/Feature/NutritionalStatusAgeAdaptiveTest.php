<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\TimbangRecord;
use App\Support\RiskAssessmentClinicalValues;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Boundary coverage for the resident-based, age-adaptive Nutritional Status
 * module (LMLINGA implementation task). Age is always computed at the
 * measurement date, never "today" — see TEST H.
 */
class NutritionalStatusAgeAdaptiveTest extends TestCase
{
    use RefreshDatabase;

    private const MEASUREMENT_DATE = '2026-09-16';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(\App\Support\StaffRole::BHW);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedResident(string $householdNo, string $memberNo, Carbon $birthday, string $sex = 'Male'): array
    {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 1',
        ]);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'relation' => 'Self',
            'birthday' => $birthday->toDateString(),
            'sex' => $sex,
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    private function createUrl(Household $household, Resident $resident): string
    {
        return route('households.residents.nutritional-status.create', [
            'householdNo' => $household->household_no,
            'residentId' => $resident->id,
        ]);
    }

    private function storeUrl(Household $household, Resident $resident): string
    {
        return route('households.residents.nutritional-status.store', [
            'householdNo' => $household->household_no,
            'residentId' => $resident->id,
        ]);
    }

    private function previewUrl(Household $household, Resident $resident): string
    {
        return route('households.residents.nutritional-status.preview', [
            'householdNo' => $household->household_no,
            'residentId' => $resident->id,
        ]);
    }

    // TEST A — 0 months: MUAC disabled/rejected, WFA uses the supported reference.
    public function test_a_zero_months_muac_disabled_and_weight_for_age_computed(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9001',
            'MB-9001',
            $measurementDate->copy(),
        );

        $html = $this->get($this->createUrl($household, $resident))->assertOk()->getContent();
        $this->assertStringContainsString('Not applicable below 6 months.', $html);

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '3.3',
            'muac_cm' => '10',
        ])->assertSessionHasErrors('muac_cm');
        $this->assertSame(0, TimbangRecord::query()->count());

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '3.3',
        ])->assertRedirect();

        $row = TimbangRecord::query()->first();
        $this->assertSame('Normal', $row->weight_for_age);
        $this->assertSame('N/A', $row->muac_status);
        $this->assertNull($row->bmi_value);
    }

    // TEST B — 5 months: MUAC disabled/rejected.
    public function test_b_five_months_muac_disabled(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9002',
            'MB-9002',
            $measurementDate->copy()->subMonths(5),
        );

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '6',
            'muac_cm' => '13',
        ])->assertSessionHasErrors('muac_cm');
        $this->assertSame(0, TimbangRecord::query()->count());
    }

    // TEST C — exactly 6 months: MUAC enabled/accepted, classification works.
    public function test_c_exactly_six_months_muac_enabled_and_classified(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9003',
            'MB-9003',
            $measurementDate->copy()->subMonths(6),
        );

        $html = $this->get($this->createUrl($household, $resident))->assertOk()->getContent();
        $this->assertStringNotContainsString('Not applicable below 6 months.', $html);

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '7',
            'muac_cm' => '11.0',
        ])->assertRedirect();

        $row = TimbangRecord::query()->first();
        $this->assertSame('Severe Acute Malnutrition (SAM)', $row->muac_status);

        $row->delete();
        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '7',
            'muac_cm' => '12.0',
        ])->assertRedirect();
        $this->assertSame('Moderate Acute Malnutrition (MAM)', TimbangRecord::query()->latest('timbang_id')->first()->muac_status);

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '7',
            'muac_cm' => '13.0',
        ])->assertRedirect();
        $this->assertSame('Normal', TimbangRecord::query()->latest('timbang_id')->first()->muac_status);
    }

    // TEST D — 59 months: MUAC enabled.
    public function test_d_fifty_nine_months_muac_enabled(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9004',
            'MB-9004',
            $measurementDate->copy()->subMonths(59),
        );

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '14',
            'muac_cm' => '13.5',
        ])->assertRedirect();

        $row = TimbangRecord::query()->first();
        $this->assertSame('Normal', $row->muac_status);
    }

    // TEST E — exactly 60 months / 5 years: MUAC disabled/rejected; BMI numeric available; no child MUAC.
    public function test_e_exactly_sixty_months_muac_disabled_bmi_available(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9005',
            'MB-9005',
            $measurementDate->copy()->subMonths(60),
        );

        $html = $this->get($this->createUrl($household, $resident))->assertOk()->getContent();
        $this->assertStringContainsString('data-timbang-bmi-field', $html);
        $this->assertDoesNotMatchRegularExpression('/data-timbang-bmi-field[^>]*\shidden/', $html);

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '18',
            'height_cm' => '110',
            'muac_cm' => '13',
        ])->assertSessionHasErrors('muac_cm');
        $this->assertSame(0, TimbangRecord::query()->count());

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '18',
            'height_cm' => '110',
        ])->assertRedirect();

        $row = TimbangRecord::query()->first();
        $this->assertSame('N/A', $row->muac_status);
        $this->assertNull($row->weight_for_age);
        $this->assertNotNull($row->bmi_value);
    }

    // TEST F — 5-19 years: BMI numeric works, adult BMI is NOT applied, missing BMI-for-Age reference handled safely.
    public function test_f_five_to_nineteen_years_bmi_numeric_without_adult_classification(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9006',
            'MB-9006',
            $measurementDate->copy()->subYears(12),
        );

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '80',
            'height_cm' => '150',
        ])->assertRedirect();

        $row = TimbangRecord::query()->first();
        $this->assertNotNull($row->bmi_value);
        // Numeric BMI is available, but the project has no approved
        // BMI-for-Age 5-19 reference — must not borrow adult BMI thresholds
        // and must not guess a classification.
        $this->assertSame(TimbangRecord::REFERENCE_UNAVAILABLE, $row->bmi_status);
        $this->assertNotContains($row->bmi_status, TimbangRecord::ADULT_BMI_STATUS);
        $this->assertSame('N/A', $row->muac_status);
        $this->assertNull($row->weight_for_age);
        // The only computed indicator (bmi_status) is a pending sentinel,
        // not a concrete result — overall status must not be guessed either.
        $this->assertNull($row->overall_nutritional_status);
    }

    // TEST G — Adult: BMI calculation works, existing adult BMI classification is reused, WFA/HFA/MUAC not applied.
    public function test_g_adult_reuses_shared_adult_bmi_classification(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9007',
            'MB-9007',
            $measurementDate->copy()->subYears(40),
        );

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '50',
            'height_cm' => '160',
        ])->assertRedirect();

        $row = TimbangRecord::query()->first();
        $expectedBmi = round(50 / (1.6 * 1.6), 1);
        $this->assertSame($expectedBmi, (float) $row->bmi_value);
        $this->assertSame(RiskAssessmentClinicalValues::classifyAdultBmi($expectedBmi), $row->bmi_status);
        $this->assertSame('Normal', $row->bmi_status); // 50kg/160cm -> BMI 19.5, sanity-checks the fixture
        $this->assertSame('N/A', $row->muac_status);
        $this->assertNull($row->weight_for_age);
        $this->assertNull($row->height_for_age);
        $this->assertNotNull($row->overall_nutritional_status);
    }

    // TEST H — historical measurement: resident is adult today but the
    // measurement occurred when the resident was a child. Applicability must
    // use age at the measurement date, not today's age.
    public function test_h_historical_measurement_uses_age_at_measurement_not_today(): void
    {
        $today = Carbon::parse(self::MEASUREMENT_DATE);
        $birthday = $today->copy()->subYears(30); // adult today
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9008',
            'MB-9008',
            $birthday,
        );

        $historicalMeasurementDate = $birthday->copy()->addMonths(18)->toDateString();

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => $historicalMeasurementDate,
            'weight_kg' => '10',
            'height_cm' => '78',
            'muac_cm' => '13',
        ])->assertRedirect();

        $row = TimbangRecord::query()->first();
        // At 18 months (the measurement date), not 30 years (today): child
        // indicators applied, BMI did not.
        $this->assertNotNull($row->weight_for_age);
        $this->assertNotNull($row->muac_status);
        $this->assertNotSame('N/A', $row->muac_status);
        $this->assertNull($row->bmi_value);
    }

    // TEST I — URL: canonical resident route works; legacy member route still works.
    public function test_i_canonical_and_legacy_urls_both_work(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9009',
            'MB-9009',
            $measurementDate->copy()->subYears(25),
        );

        $this->get($this->createUrl($household, $resident))->assertOk();

        $legacyUrl = route('household-profiling.members.nutritional-status.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);
        $this->get($legacyUrl)->assertOk();

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '55',
            'height_cm' => '160',
        ])->assertRedirect(route('households.residents.nutritional-status', [
            'householdNo' => $household->household_no,
            'residentId' => $resident->id,
        ]));
    }

    // TEST J — security: client-controlled computed values are ignored/prohibited.
    public function test_j_client_supplied_computed_fields_are_prohibited(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9010',
            'MB-9010',
            $measurementDate->copy()->subYears(25),
        );
        $other = $this->seedResident('HH-9011', 'MB-9011', $measurementDate->copy()->subYears(30));

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '55',
            'height_cm' => '160',
            'resident_id' => $other['resident']->id,
            'bmi_status' => 'Normal',
            'weight_for_age' => 'Normal',
            'height_for_age' => 'Normal',
            'muac_status' => 'Normal',
            'overall_nutritional_status' => 'Normal',
        ])->assertSessionHasErrors([
            'resident_id',
            'bmi_status',
            'weight_for_age',
            'height_for_age',
            'muac_status',
            'overall_nutritional_status',
        ]);

        $this->assertSame(0, TimbangRecord::query()->count());
    }

    // Height-for-Age is now classified against the National Nutrition
    // Council (DOH) reference (resources/growth-references/nnc_growth_standards.json),
    // supplied to fill the gap previously left as "Reference data required".
    public function test_height_for_age_classifies_against_the_nnc_reference(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9014',
            'MB-9014',
            $measurementDate->copy()->subMonths(12),
            'Female',
        );

        // NNC female/12mo height-for-age row: [66.2,66.3,68.8,68.9,79.2,79.3]
        // (severeStunted, stuntedFrom, stuntedTo, normalFrom, normalTo, tall).
        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '9',
            'height_cm' => '60', // below severeStunted (66.2)
        ])->assertRedirect();
        $this->assertSame('Severely Stunted', TimbangRecord::query()->latest('timbang_id')->first()->height_for_age);

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '9',
            'height_cm' => '73', // within normal range (68.9-79.2)
        ])->assertRedirect();
        $this->assertSame('Normal', TimbangRecord::query()->latest('timbang_id')->first()->height_for_age);

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '9',
            'height_cm' => '90', // above normalTo (79.2)
        ])->assertRedirect();
        $this->assertSame('Tall', TimbangRecord::query()->latest('timbang_id')->first()->height_for_age);
    }

    public function test_weight_for_age_classifies_overweight_against_the_nnc_reference(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9015',
            'MB-9015',
            $measurementDate->copy()->subMonths(12),
            'Male',
        );

        // NNC male/12mo weight-for-age row: [6.9,7.0,7.6,7.7,12.0].
        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '15', // above normalTo (12.0)
        ])->assertRedirect();

        $row = TimbangRecord::query()->first();
        $this->assertSame('Overweight', $row->weight_for_age);
        $this->assertSame('At Risk', $row->overall_nutritional_status);
    }

    // Member-view "Nutritional Status" side card is age-adaptive: babies
    // (0-59 months, with or without MUAC applicable) show Weight/Height/
    // Status; 5y+ residents show Weight/Height/BMI as "20.8 (Normal)".
    public function test_member_card_shows_status_not_bmi_for_a_baby_under_six_months(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9016',
            'MB-9016',
            $measurementDate->copy()->subMonths(3),
        );

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '6',
        ])->assertRedirect();

        $card = $this->get(route('household-profiling.members.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('>Status<', $card);
        $this->assertStringNotContainsString('>BMI<', $card);
    }

    public function test_member_card_shows_muac_for_the_six_to_fifty_nine_month_band(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9018',
            'MB-9018',
            $measurementDate->copy()->subMonths(18),
        );

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '10',
            'muac_cm' => '13',
        ])->assertRedirect();

        $card = $this->get(route('household-profiling.members.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('>Status<', $card);
        $this->assertStringContainsString('>MUAC<', $card);
        $this->assertStringContainsString('13 cm (Normal)', $card);
    }

    public function test_member_card_hides_muac_for_infant_under_six_months(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9019',
            'MB-9019',
            $measurementDate->copy()->subMonths(3),
        );

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '6',
        ])->assertRedirect();

        $card = $this->get(route('household-profiling.members.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('>Status<', $card);
        $this->assertStringNotContainsString('>MUAC<', $card);
    }

    public function test_member_card_shows_bmi_with_status_for_a_five_year_old_and_up(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9017',
            'MB-9017',
            $measurementDate->copy()->subYears(30),
        );

        // 60kg/170cm -> BMI 20.8 (Normal), matching the requested "20.8 (Normal)" format.
        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '60',
            'height_cm' => '170',
        ])->assertRedirect();

        $card = $this->get(route('household-profiling.members.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('>BMI<', $card);
        $this->assertStringNotContainsString('>Status<', $card);
        $this->assertStringContainsString('20.8 (Normal)', $card);
    }

    // History page groups records into age-band cards (mirroring the
    // Health Records -> Child Care -> Non-Residents nutrition page's
    // sectioned layout), one card per band the resident actually has
    // records in.
    public function test_history_page_groups_records_into_age_band_sections(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        // Birthday puts the resident at exactly 7 months old "today" (the
        // fixed MEASUREMENT_DATE), so an earlier visit at 3 months old and
        // today's visit at 7 months old are both valid (not future) dates.
        $birthday = $measurementDate->copy()->subMonths(7);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9026',
            'MB-9026',
            $birthday,
        );

        // Visit 1: resident is 3 months old (0-5m band).
        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => $birthday->copy()->addMonths(3)->toDateString(),
            'weight_kg' => '6',
        ])->assertRedirect();

        // Visit 2 (today): resident is 7 months old (6-59m band).
        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '8',
            'muac_cm' => '13',
        ])->assertRedirect();

        $html = $this->get(route('households.residents.nutritional-status', [
            'householdNo' => $household->household_no,
            'residentId' => $resident->id,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('0–5 Months Record', $html);
        $this->assertStringContainsString('6–59 Months Record', $html);
        $this->assertStringNotContainsString('5–19 Years Record', $html);
        $this->assertStringNotContainsString('Adult Record', $html);
        $this->assertStringContainsString('data-lml-hh-timbang-age-group="0-5m"', $html);
        $this->assertStringContainsString('data-lml-hh-timbang-age-group="6-59m"', $html);
        // Both cards' rows still carry the exact markup progress-tracking
        // tests depend on.
        $this->assertStringContainsString('<dt>Weight Progress</dt>', $html);
        $this->assertStringContainsString('<dt>Height Progress</dt>', $html);
    }

    public function test_history_page_shows_a_single_section_for_an_adult(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9027',
            'MB-9027',
            $measurementDate->copy()->subYears(35),
        );

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '70',
            'height_cm' => '165',
        ])->assertRedirect();

        $html = $this->get(route('households.residents.nutritional-status', [
            'householdNo' => $household->household_no,
            'residentId' => $resident->id,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Adult Record', $html);
        $this->assertStringNotContainsString('0–5 Months Record', $html);
        $this->assertStringNotContainsString('6–59 Months Record', $html);
        $this->assertStringNotContainsString('5–19 Years Record', $html);
    }

    // The member profile's Nutritional Status "Edit" link now lands on the
    // history page, never directly on the create form — "Add Measurement"
    // is reachable only via the history page's own "Add Record" button.
    public function test_member_profile_nutrition_link_goes_to_history_not_create(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9028',
            'MB-9028',
            $measurementDate->copy()->subYears(25),
        );

        $html = $this->get(route('household-profiling.members.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $historyUrl = route('households.residents.nutritional-status', [
            'householdNo' => $household->household_no,
            'residentId' => $resident->id,
        ]);
        $createUrl = route('households.residents.nutritional-status.create', [
            'householdNo' => $household->household_no,
            'residentId' => $resident->id,
        ]);

        $this->assertStringContainsString($historyUrl, $html);
        $this->assertStringNotContainsString($createUrl, $html);

        // From the history page, "Add Record" is the only path to create.
        $historyHtml = $this->get($historyUrl)->assertOk()->getContent();
        $this->assertStringContainsString($createUrl, $historyHtml);
    }

    // Weight/Height Progress render with a sign-based color hook: green for
    // an increase, red for a decrease, neutral for zero/baseline.
    public function test_progress_color_hooks_reflect_increase_and_decrease(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9029',
            'MB-9029',
            $measurementDate->copy()->subYears(25),
        );

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => $measurementDate->copy()->subMonths(2)->toDateString(),
            'weight_kg' => '60',
            'height_cm' => '160',
        ])->assertRedirect();

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => $measurementDate->copy()->subMonths(1)->toDateString(),
            'weight_kg' => '62',
            'height_cm' => '158',
        ])->assertRedirect();

        $html = $this->get(route('households.residents.nutritional-status', [
            'householdNo' => $household->household_no,
            'residentId' => $resident->id,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-weight-progress="+2 kg"', $html);
        $this->assertStringContainsString('data-height-progress="-2 cm"', $html);
    }

    // Preview endpoint: read-only, non-persisting, reuses the same
    // authoritative service used on save.
    public function test_preview_endpoint_computes_without_persisting(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9012',
            'MB-9012',
            $measurementDate->copy()->subMonths(18),
        );

        $response = $this->getJson($this->previewUrl($household, $resident).'?'.http_build_query([
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '10',
            'muac_cm' => '14',
        ]))->assertOk()->json();

        $this->assertTrue($response['found']);
        $this->assertSame('6-59m', $response['age_band']);
        $this->assertSame('Normal', $response['weight_for_age']);
        $this->assertSame('Normal', $response['muac_status']);
        // Height was not submitted this preview, so Height-for-Age has no
        // measurement to classify against the NNC reference — null, not guessed.
        $this->assertNull($response['height_for_age']);
        $this->assertSame(0, TimbangRecord::query()->count());
    }

    public function test_preview_endpoint_ignores_muac_outside_its_band_instead_of_erroring(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9013',
            'MB-9013',
            $measurementDate->copy()->subYears(30),
        );

        $response = $this->getJson($this->previewUrl($household, $resident).'?'.http_build_query([
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '60',
            'height_cm' => '165',
            'muac_cm' => '20',
        ]))->assertOk()->json();

        $this->assertTrue($response['found']);
        $this->assertSame('adult', $response['age_band']);
        $this->assertSame('N/A', $response['muac_status']);
        $this->assertNotNull($response['bmi_value']);
        $this->assertSame(0, TimbangRecord::query()->count());
    }
}
