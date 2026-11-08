<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\TimbangRecord;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Weight-for-Length/Height (Phase 1 completion) — WHO SD-position reference
 * (resources/growth-references/who_weight_for_length_height_zscore.csv),
 * 0-59 completed months, shares the Weight-for-Age/Height-for-Age band.
 */
class NutritionalStatusWeightForHeightTest extends TestCase
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

    private function storeUrl(Household $household, Resident $resident): string
    {
        return route('households.residents.nutritional-status.store', [
            'householdNo' => $household->household_no,
            'residentId' => $resident->id,
        ]);
    }

    /**
     * Reference row used throughout: who_weight_for_length_height_zscore.csv
     * "Weight-for-length,Boy,75.0,7.5,8.1,8.8,9.5,10.3,11.3,12.3" — used for
     * residents under 24 months (WHO recumbent-length table).
     */
    public function test_classifies_all_five_categories_under_twenty_four_months(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        $cases = [
            ['weight' => '7.0', 'expected' => 'Severely Wasted'],  // < 7.5 (SD-3)
            ['weight' => '7.8', 'expected' => 'Wasted'],           // 7.5-<8.1 (SD-2)
            ['weight' => '9.5', 'expected' => 'Normal'],           // <= 10.3 (SD+1)
            ['weight' => '10.8', 'expected' => 'Possible Risk of Overweight'], // <= 11.3 (SD+2)
            ['weight' => '12.0', 'expected' => 'Overweight'],      // > 11.3
        ];

        foreach ($cases as $i => $case) {
            ['household' => $household, 'resident' => $resident] = $this->seedResident(
                "HH-95{$i}0",
                "MB-95{$i}0",
                $measurementDate->copy()->subMonths(12),
            );

            $this->post($this->storeUrl($household, $resident), [
                'measurement_date' => self::MEASUREMENT_DATE,
                'weight_kg' => $case['weight'],
                'height_cm' => '75.0',
            ])->assertRedirect();

            $row = TimbangRecord::query()->where('resident_id', $resident->id)->first();
            $this->assertSame($case['expected'], $row->weight_for_height, "weight={$case['weight']}");
        }
    }

    /**
     * At the same 90.0cm measurement, a weight of 13.85kg falls into
     * different bands depending on which WHO table applies:
     *   Weight-for-length,Boy,90.0 -> SD+1=13.8  -> 13.85 is "Possible Risk of Overweight"
     *   Weight-for-height,Boy,90.0 -> SD+1=14.0  -> 13.85 is "Normal"
     * This proves the length/height table switch happens at 24 months.
     */
    public function test_switches_from_length_table_to_height_table_at_twenty_four_months(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);

        ['household' => $under, 'resident' => $residentUnder] = $this->seedResident(
            'HH-9520',
            'MB-9520',
            $measurementDate->copy()->subMonths(23),
        );
        $this->post($this->storeUrl($under, $residentUnder), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '13.85',
            'height_cm' => '90.0',
        ])->assertRedirect();
        $this->assertSame(
            'Possible Risk of Overweight',
            TimbangRecord::query()->where('resident_id', $residentUnder->id)->first()->weight_for_height
        );

        ['household' => $over, 'resident' => $residentOver] = $this->seedResident(
            'HH-9521',
            'MB-9521',
            $measurementDate->copy()->subMonths(24),
        );
        $this->post($this->storeUrl($over, $residentOver), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '13.85',
            'height_cm' => '90.0',
        ])->assertRedirect();
        $this->assertSame(
            'Normal',
            TimbangRecord::query()->where('resident_id', $residentOver->id)->first()->weight_for_height
        );
    }

    public function test_not_applicable_and_null_outside_the_zero_to_fifty_nine_month_band(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9522',
            'MB-9522',
            $measurementDate->copy()->subYears(30),
        );

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '60',
            'height_cm' => '160',
        ])->assertRedirect();

        $this->assertNull(TimbangRecord::query()->first()->weight_for_height);
    }

    public function test_severely_wasted_worsens_overall_status_to_severely_malnourished(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9523',
            'MB-9523',
            $measurementDate->copy()->subMonths(12),
        );

        // 7.0kg at 75.0cm -> Severely Wasted (see reference row above).
        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '7.0',
            'height_cm' => '75.0',
        ])->assertRedirect();

        $row = TimbangRecord::query()->first();
        $this->assertSame('Severely Wasted', $row->weight_for_height);
        $this->assertSame('Severely Malnourished', $row->overall_nutritional_status);
    }

    public function test_missing_height_leaves_weight_for_height_null_without_erroring(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9524',
            'MB-9524',
            $measurementDate->copy()->subMonths(12),
        );

        $this->post($this->storeUrl($household, $resident), [
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '9.5',
        ])->assertRedirect();

        $row = TimbangRecord::query()->first();
        $this->assertNull($row->weight_for_height);
        // Weight-for-Age still computes independently — preserved, not broken.
        $this->assertNotNull($row->weight_for_age);
    }

    public function test_preview_endpoint_includes_weight_for_height(): void
    {
        $measurementDate = Carbon::parse(self::MEASUREMENT_DATE);
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            'HH-9525',
            'MB-9525',
            $measurementDate->copy()->subMonths(12),
        );

        $previewUrl = route('households.residents.nutritional-status.preview', [
            'householdNo' => $household->household_no,
            'residentId' => $resident->id,
        ]);

        $response = $this->getJson($previewUrl.'?'.http_build_query([
            'measurement_date' => self::MEASUREMENT_DATE,
            'weight_kg' => '9.5',
            'height_cm' => '75.0',
        ]))->assertOk()->json();

        $this->assertSame('Normal', $response['weight_for_height']);
        $this->assertTrue($response['weight_for_height_applicable']);
        $this->assertSame(0, TimbangRecord::query()->count());
    }
}
