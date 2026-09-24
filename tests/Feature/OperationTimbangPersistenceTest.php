<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\TimbangRecord;
use App\Models\Resident;
use App\Support\HealthRecordsChildCare;
use App\Support\HealthRecordsOperationTimbang;
use App\Support\OperationTimbangMonitoringService;
use App\Support\UiRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DB-16 — Operation Timbang database-backed monitoring persistence.
 */
class OperationTimbangPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedChild(array $residentOverrides = [], array $householdOverrides = []): array
    {
        $household = Household::factory()->create(array_merge([
            'household_no' => 'HH-'.fake()->unique()->numberBetween(800, 899),
            'zone' => 'Zone 2',
            'street' => 'Timbang St.',
        ], $householdOverrides));

        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => 'MB-'.fake()->unique()->numberBetween(800, 899),
            'first_name' => 'Ana',
            'last_name' => 'Timbang',
            'relation' => 'Daughter',
            'birthday' => now()->subMonths(10)->format('Y-m-d'),
            'sex' => 'Female',
        ], $residentOverrides));

        return ['household' => $household, 'resident' => $resident];
    }

    public function test_timbang_records_are_the_operation_timbang_authority(): void
    {
        $this->assertTrue(Schema::hasTable('timbang_records'));
        foreach ([
            'timbang_id',
            'resident_id',
            'measurement_date',
            'weight_kg',
            'height_cm',
            'muac_cm',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('timbang_records', $column), $column);
        }

        Schema::dropIfExists('operation_timbang_measurements');
        $this->assertFalse(Schema::hasTable('operation_timbang_measurements'));
        $this->assertTrue((new OperationTimbangMonitoringService)->measurementTableReady());
        $this->assertTrue((new OperationTimbangMonitoringService)->tablesReady());
    }

    public function test_page_does_not_render_figma_demo_child_names(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.operation-timbang'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-ot-data-mode="database"', $html);
        $this->assertStringNotContainsString('data-ot-data-mode="figma-preview"', $html);

        foreach ([
            'Kristine B. Reyes',
            'Jacob A. Magistrado',
            'Haziel H. Santos',
            'Andrei B. Malaya',
            'Crisley F. Fernando',
            'Gabriel Allan S. Chua',
        ] as $demoName) {
            $this->assertStringNotContainsString($demoName, $html);
        }
    }

    public function test_empty_database_renders_zero_summaries_and_no_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2026,
                'month' => 8,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/data-ot-stat="measured-0-23"[^>]*>\s*0\s*</u', $html);
        $this->assertMatchesRegularExpression('/data-ot-stat="total-male"[^>]*>\s*0\s*</u', $html);
        $this->assertMatchesRegularExpression('/data-ot-stat="total-female"[^>]*>\s*0\s*</u', $html);
        $this->assertStringNotContainsString('data-hr-ot-row', $html);
    }

    public function test_eligible_resident_appears_in_monitoring_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        ['resident' => $resident] = $this->seedChild([
            'first_name' => 'Lila',
            'last_name' => 'Eligible',
            'birthday' => '2025-10-01',
            'sex' => 'Female',
        ], ['zone' => 'Zone 3']);

        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-08-10',
            'weight_kg' => 8.5,
            'height_cm' => 70.0,
            'muac_cm' => 14.0,
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2026,
                'month' => 8,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Lila Eligible', $html);
        $this->assertStringContainsString('8.5 kg', $html);
        $this->assertStringContainsString('70 cm', $html);
        $this->assertStringContainsString('14', $html);
        $this->assertStringContainsString('data-zone="Zone 3"', $html);
        $this->assertStringContainsString('data-sex="female"', $html);
    }

    public function test_ineligible_adult_resident_does_not_appear(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        ['resident' => $adult] = $this->seedChild([
            'first_name' => 'Adult',
            'last_name' => 'Resident',
            'birthday' => '1990-01-01',
            'sex' => 'Male',
        ]);

        TimbangRecord::factory()->create([
            'resident_id' => $adult->id,
            'measurement_date' => '2026-08-10',
        ]);

        ['resident' => $child] = $this->seedChild([
            'first_name' => 'Child',
            'last_name' => 'Only',
            'birthday' => now()->subMonths(8)->format('Y-m-d'),
            'sex' => 'Male',
        ], ['household_no' => 'HH-811', 'zone' => 'Zone 1']);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2026,
                'month' => 8,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Child Only', $html);
        $this->assertStringNotContainsString('Adult Resident', $html);
    }

    public function test_multiple_measurements_for_one_child_can_coexist(): void
    {
        ['resident' => $resident] = $this->seedChild();

        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-01-15',
            'weight_kg' => 7.0,
        ]);
        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-08-15',
            'weight_kg' => 9.0,
        ]);

        $this->assertSame(
            2,
            TimbangRecord::query()->where('resident_id', $resident->id)->count()
        );
    }

    public function test_year_month_filtering_selects_session_measurement(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        ['resident' => $resident] = $this->seedChild([
            'first_name' => 'Session',
            'last_name' => 'Child',
        ]);

        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-01-20',
            'weight_kg' => 6.0,
            'height_cm' => 60.0,
            'muac_cm' => 12.0,
        ]);
        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-08-12',
            'weight_kg' => 9.5,
            'height_cm' => 72.0,
            'muac_cm' => 14.5,
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $january = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2026,
                'month' => 1,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('6 kg', $january);
        $this->assertStringNotContainsString('9.5 kg', $january);

        $this->actingAsStaff(StaffRole::BHW);
        $august = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2026,
                'month' => 8,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('9.5 kg', $august);
        $this->assertStringNotContainsString('6 kg', $august);
    }

    public function test_zone_derives_from_household(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $this->seedChild([
            'first_name' => 'Zone',
            'last_name' => 'Kid',
            'birthday' => now()->subMonths(5)->format('Y-m-d'),
        ], ['zone' => 'Zone 5']);

        $zones = HealthRecordsOperationTimbang::zones();
        $this->assertContains('Zone 5', $zones);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2026,
                'month' => 8,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Zone 5</option>', $html);
        $this->assertStringContainsString('data-zone="Zone 5"', $html);
    }

    public function test_summaries_derive_from_database_records(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        ['resident' => $girl] = $this->seedChild([
            'first_name' => 'Girl',
            'last_name' => 'One',
            'birthday' => now()->subMonths(6)->format('Y-m-d'),
            'sex' => 'Female',
        ], ['zone' => 'Zone 1', 'household_no' => 'HH-821']);

        ['resident' => $boy] = $this->seedChild([
            'first_name' => 'Boy',
            'last_name' => 'Two',
            'birthday' => now()->subMonths(12)->format('Y-m-d'),
            'sex' => 'Male',
        ], ['zone' => 'Zone 1', 'household_no' => 'HH-822']);

        TimbangRecord::factory()->create([
            'resident_id' => $girl->id,
            'measurement_date' => '2026-08-05',
        ]);

        $service = app(OperationTimbangMonitoringService::class);
        $rows = $service->monitoringRowsForYearMonth(2026, 8);
        $summary = $service->summaryCardsForYearMonth(2026, 8, $rows);

        $this->assertSame('1', $summary['measured_0_23']);
        $this->assertSame('1', $summary['total_male']);
        $this->assertSame('1', $summary['total_female']);
        // Unresolved specialized DOH metrics stay at zero rather than guessed.
        $this->assertSame('0', $summary['ps_0_23']);
        $this->assertSame('0', $summary['over_age']);
        $this->assertSame('0', $summary['transferred']);
        $this->assertSame('0', $summary['dead']);
        $this->assertSame('0', $summary['not_available']);
        $this->assertSame('0', $summary['new_cases']);
    }

    public function test_support_layer_no_longer_depends_on_demo_catalog_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        $rows = HealthRecordsOperationTimbang::monitoringRows(2026, 8);
        $names = array_column($rows, 'full_name');

        $this->assertNotContains('Kristine B. Reyes', $names);
        $this->assertNotContains('Jacob A. Magistrado', $names);
    }

    public function test_status_is_not_invented_from_measurements(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        ['resident' => $resident] = $this->seedChild([
            'first_name' => 'Raw',
            'last_name' => 'Measure',
        ]);

        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-08-10',
            'weight_kg' => 3.4,
            'height_cm' => 43.5,
            'muac_cm' => 11.0,
        ]);

        $rows = HealthRecordsOperationTimbang::monitoringRows(2026, 8);
        $this->assertNotEmpty($rows);
        $this->assertSame(OperationTimbangMonitoringService::STATUS_UNCLASSIFIED, $rows[0]['status']);
        $this->assertSame('—', $rows[0]['status_label']);
    }

    public function test_years_are_not_hardcoded_2025_2026_2027_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        ['resident' => $resident] = $this->seedChild();
        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2024-03-01',
        ]);

        $years = HealthRecordsOperationTimbang::years();
        $this->assertContains(2026, $years);
        $this->assertContains(2024, $years);
        $this->assertNotSame([2025, 2026, 2027], $years);
        $this->assertNotContains(2027, $years);
        $this->assertSame($years, array_values($years));
        $this->assertSame($years, collect($years)->sortDesc()->values()->all());
    }

    public function test_current_year_is_always_available_even_without_measurements(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-11-03'));

        $this->assertSame(0, TimbangRecord::query()->count());

        $years = HealthRecordsOperationTimbang::years();
        $this->assertSame([2024], $years);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.operation-timbang'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="lml-hr-ot-year"[\s\S]*?<option[^>]*value="2024"[^>]*>\s*2024\s*<\/option>/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-hr-ot-year"[\s\S]*?<option[^>]*value="2026"/u',
            $html
        );
    }

    public function test_historical_measurement_years_appear_in_year_options(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        ['resident' => $resident] = $this->seedChild([
            'first_name' => 'Histo',
            'last_name' => 'Year',
            'birthday' => '2024-01-15',
        ]);

        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2023-06-10',
            'weight_kg' => 5.5,
        ]);
        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2025-02-14',
            'weight_kg' => 7.0,
        ]);
        // Future-dated row must not invent a future year option.
        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2027-01-01',
            'weight_kg' => 9.0,
        ]);

        $years = HealthRecordsOperationTimbang::years();
        $this->assertSame([2026, 2025, 2023], $years);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2025,
                'month' => 2,
            ]))
            ->assertOk()
            ->getContent();

        foreach ([2026, 2025, 2023] as $year) {
            $this->assertMatchesRegularExpression(
                '/id="lml-hr-ot-year"[\s\S]*?<option[^>]*value="'.$year.'"[^>]*>\s*'.$year.'\s*<\/option>/u',
                $html
            );
        }
        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-hr-ot-year"[\s\S]*?<option[^>]*value="2027"/u',
            $html
        );
        $this->assertStringContainsString('7 kg', $html);
        $this->assertStringNotContainsString('5.5 kg', $html);
    }

    public function test_selecting_historical_year_month_returns_that_periods_records(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        ['resident' => $resident] = $this->seedChild([
            'first_name' => 'Archive',
            'last_name' => 'Child',
            'birthday' => '2024-05-01',
        ]);

        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2025-11-20',
            'weight_kg' => 6.8,
            'height_cm' => 64.0,
            'muac_cm' => 12.0,
        ]);
        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-08-08',
            'weight_kg' => 8.4,
            'height_cm' => 69.0,
            'muac_cm' => 13.2,
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2025,
                'month' => 11,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Archive Child', $html);
        $this->assertStringContainsString('6.8 kg', $html);
        $this->assertStringNotContainsString('8.4 kg', $html);
        $this->assertMatchesRegularExpression(
            '/data-hr-ot-summary-label[^>]*>\s*November 2025\s*</u',
            $html
        );
        // Historical year: all months selectable (none marked future).
        $this->assertDoesNotMatchRegularExpression(
            '/data-hr-ot-month[^>]*data-ot-future="1"/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-hr-ot-year"[\s\S]*?<option[^>]*value="2025"[^>]*selected/u',
            $html
        );
    }

    public function test_past_period_returns_persisted_records(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        ['resident' => $resident] = $this->seedChild([
            'first_name' => 'Past',
            'last_name' => 'Record',
            'birthday' => '2025-06-01',
        ]);

        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-01-18',
            'weight_kg' => 6.4,
            'height_cm' => 62.0,
            'muac_cm' => 12.2,
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2026,
                'month' => 1,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Past Record', $html);
        $this->assertStringContainsString('6.4 kg', $html);
        $this->assertMatchesRegularExpression('/data-ot-stat="measured-0-23"[^>]*>\s*1\s*</u', $html);
    }

    public function test_current_month_can_return_persisted_records(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        ['resident' => $resident] = $this->seedChild([
            'first_name' => 'Current',
            'last_name' => 'Month',
            'birthday' => '2025-11-01',
        ]);

        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-08-12',
            'weight_kg' => 8.8,
            'height_cm' => 70.5,
            'muac_cm' => 13.8,
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2026,
                'month' => 8,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Current Month', $html);
        $this->assertStringContainsString('8.8 kg', $html);
        $this->assertMatchesRegularExpression('/data-ot-stat="measured-0-23"[^>]*>\s*1\s*</u', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-month="8"[^>]*data-ot-future="1"/u',
            $html
        );
    }

    public function test_future_month_returns_zero_summary_and_no_monitoring_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        ['resident' => $resident] = $this->seedChild([
            'first_name' => 'Future',
            'last_name' => 'Seed',
            'birthday' => '2025-09-01',
        ]);

        // Even a wrongly dated future measurement must not appear as recordable.
        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-09-05',
            'weight_kg' => 10.0,
            'height_cm' => 75.0,
            'muac_cm' => 15.0,
        ]);

        $service = app(OperationTimbangMonitoringService::class);
        $this->assertTrue($service->isFuturePeriod(2026, 9));
        $this->assertSame([], $service->monitoringRowsForYearMonth(2026, 9));
        $this->assertSame($service->emptySummaryCards(), $service->summaryCardsForYearMonth(2026, 9));

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2026,
                'month' => 9,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Future Seed', $html);
        $this->assertStringNotContainsString('10 kg', $html);
        $this->assertStringNotContainsString('data-hr-ot-row', $html);
        $this->assertMatchesRegularExpression('/data-ot-stat="measured-0-23"[^>]*>\s*0\s*</u', $html);
        $this->assertMatchesRegularExpression('/data-ot-stat="total-male"[^>]*>\s*0\s*</u', $html);
        $this->assertMatchesRegularExpression('/data-ot-stat="total-female"[^>]*>\s*0\s*</u', $html);
    }

    public function test_future_year_returns_zero_summary_and_no_monitoring_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        ['resident' => $resident] = $this->seedChild([
            'first_name' => 'Next',
            'last_name' => 'Year',
            'birthday' => '2025-01-01',
        ]);

        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2027-02-10',
            'weight_kg' => 11.0,
            'height_cm' => 80.0,
            'muac_cm' => 16.0,
        ]);

        $service = app(OperationTimbangMonitoringService::class);
        $this->assertTrue($service->isFuturePeriod(2027, 2));
        // Future years are omitted from the Year selector even if a future-dated row exists.
        $this->assertNotContains(2027, HealthRecordsOperationTimbang::years());
        $this->assertSame([], $service->monitoringRowsForYearMonth(2027, 2));
        $this->assertSame($service->emptySummaryCards(), $service->summaryCardsForYearMonth(2027, 2));

        // Requesting a future year falls back to an available (non-future) year.
        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.operation-timbang', [
                'year' => 2027,
                'month' => 2,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-hr-ot-year"[\s\S]*?<option[^>]*value="2027"/u',
            $html
        );
        $this->assertStringNotContainsString('11 kg', $html);
    }

    public function test_future_period_logic_is_not_hardcoded_to_august_2026(): void
    {
        // Deterministic "now" far from the common Aug 2026 fixture.
        Carbon::setTestNow(Carbon::parse('2025-03-20'));

        $service = app(OperationTimbangMonitoringService::class);

        $this->assertFalse($service->isFuturePeriod(2025, 3)); // current month allowed
        $this->assertFalse($service->isFuturePeriod(2025, 2)); // past month
        $this->assertFalse($service->isFuturePeriod(2024, 12)); // past year
        $this->assertTrue($service->isFuturePeriod(2025, 4)); // next month
        $this->assertTrue($service->isFuturePeriod(2026, 1)); // next year

        ['resident' => $resident] = $this->seedChild([
            'first_name' => 'March',
            'last_name' => 'Kid',
            'birthday' => '2024-06-01',
        ]);
        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2025-03-15',
            'weight_kg' => 7.7,
        ]);
        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2025-04-02',
            'weight_kg' => 8.1,
        ]);

        $currentRows = $service->monitoringRowsForYearMonth(2025, 3);
        $this->assertNotEmpty($currentRows);
        $this->assertSame('7.7 kg', $currentRows[0]['weight']);

        $this->assertSame([], $service->monitoringRowsForYearMonth(2025, 4));
        $this->assertSame(
            $service->emptySummaryCards(),
            $service->summaryCardsForYearMonth(2025, 4)
        );

        $sessions = HealthRecordsOperationTimbang::monthSessions(2025);
        $this->assertSame('March', $sessions[2]['label']);
        $this->assertFalse($sessions[2]['is_future']);
        $this->assertSame('April', $sessions[3]['label']);
        $this->assertTrue($sessions[3]['is_future']);
        $this->assertStringNotContainsString('2025', $sessions[2]['label']);

        // Year options follow the faked "now", not a hard-coded 2026 list.
        $this->assertSame([2025], HealthRecordsOperationTimbang::years());
    }

    public function test_zero_measurement_eligible_child_still_appears_with_no_record(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        ['resident' => $resident] = $this->seedChild([
            'first_name' => 'Empty',
            'last_name' => 'Measure',
        ]);

        $rows = HealthRecordsOperationTimbang::monitoringRows(2026, 8);
        $this->assertCount(1, $rows);
        $this->assertSame((int) $resident->getKey(), $rows[0]['resident_id']);
        $this->assertSame('Empty Measure', $rows[0]['full_name']);
        $this->assertFalse($rows[0]['has_measurement']);
        $this->assertSame(HealthRecordsChildCare::EMPTY_RECORD, $rows[0]['weight']);
        $this->assertSame(HealthRecordsChildCare::EMPTY_RECORD, $rows[0]['height']);
        $this->assertSame(HealthRecordsChildCare::EMPTY_RECORD, $rows[0]['muac']);
        $this->assertSame(0, TimbangRecord::query()->count());
    }

    public function test_latest_same_month_row_wins_by_date_then_timbang_id(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        ['resident' => $resident] = $this->seedChild(['first_name' => 'Latest', 'last_name' => 'Wins']);

        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-08-01',
            'weight_kg' => 7.0,
            'height_cm' => 65.0,
            'muac_cm' => 12.0,
        ]);
        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-08-12',
            'weight_kg' => 8.0,
            'height_cm' => 66.0,
            'muac_cm' => 13.0,
        ]);
        $sameDateEarlier = TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-08-12',
            'weight_kg' => 8.1,
            'height_cm' => 66.1,
            'muac_cm' => 13.1,
        ]);
        $sameDateLater = TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-08-12',
            'weight_kg' => 8.2,
            'height_cm' => 66.2,
            'muac_cm' => 13.2,
        ]);

        $this->assertGreaterThan($sameDateEarlier->getKey(), $sameDateLater->getKey());
        $this->assertSame(4, TimbangRecord::query()->where('resident_id', $resident->id)->count());

        $rows = HealthRecordsOperationTimbang::monitoringRows(2026, 8);
        $this->assertSame('8.2 kg', $rows[0]['weight']);
        $this->assertSame('66.2 cm', $rows[0]['height']);
        $this->assertSame('13.2', $rows[0]['muac']);
    }

    public function test_resident_a_measurement_does_not_appear_on_resident_b(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        ['resident' => $a] = $this->seedChild([
            'first_name' => 'Alpha',
            'last_name' => 'Child',
        ], ['household_no' => 'HH-831']);
        ['resident' => $b] = $this->seedChild([
            'first_name' => 'Bravo',
            'last_name' => 'Child',
        ], ['household_no' => 'HH-832']);

        TimbangRecord::factory()->create([
            'resident_id' => $a->id,
            'measurement_date' => '2026-08-10',
            'weight_kg' => 11.1,
            'height_cm' => 70.0,
            'muac_cm' => 14.0,
        ]);

        $rows = collect(HealthRecordsOperationTimbang::monitoringRows(2026, 8))->keyBy('full_name');
        $this->assertSame('11.1 kg', $rows['Alpha Child']['weight']);
        $this->assertSame((int) $a->getKey(), $rows['Alpha Child']['resident_id']);
        $this->assertSame(HealthRecordsChildCare::EMPTY_RECORD, $rows['Bravo Child']['weight']);
        $this->assertSame((int) $b->getKey(), $rows['Bravo Child']['resident_id']);
        $this->assertNotSame($rows['Alpha Child']['resident_id'], $rows['Bravo Child']['resident_id']);
    }

    public function test_age_59_months_included_and_60_months_excluded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15')->startOfDay());
        ['resident' => $included] = $this->seedChild([
            'first_name' => 'Fifty',
            'last_name' => 'Nine',
            'birthday' => Carbon::parse('2026-08-15')->subMonthsNoOverflow(59)->format('Y-m-d'),
        ], ['household_no' => 'HH-833']);
        ['resident' => $excluded] = $this->seedChild([
            'first_name' => 'Sixty',
            'last_name' => 'Plus',
            'birthday' => Carbon::parse('2026-08-15')->subMonthsNoOverflow(60)->format('Y-m-d'),
        ], ['household_no' => 'HH-834']);

        TimbangRecord::factory()->create([
            'resident_id' => $included->id,
            'measurement_date' => '2026-08-10',
            'weight_kg' => 15.0,
        ]);
        TimbangRecord::factory()->create([
            'resident_id' => $excluded->id,
            'measurement_date' => '2026-08-10',
            'weight_kg' => 20.0,
        ]);

        $names = array_column(HealthRecordsOperationTimbang::monitoringRows(2026, 8), 'full_name');
        $this->assertContains('Fifty Nine', $names);
        $this->assertNotContains('Sixty Plus', $names);
    }

    public function test_manual_nutritional_status_store_feeds_operation_timbang(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        ['household' => $household, 'resident' => $resident] = $this->seedChild([
            'first_name' => 'Manual',
            'last_name' => 'Ns',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('household-profiling.members.nutritional-status.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'measurement_date' => '2026-08-12',
            'weight_kg' => '8.40',
            'height_cm' => '69.50',
            'muac_cm' => '13.5',
        ])->assertRedirect();

        $this->assertSame(1, TimbangRecord::query()->where('resident_id', $resident->id)->count());
        $rows = HealthRecordsOperationTimbang::monitoringRows(2026, 8);
        $this->assertSame('8.4 kg', $rows[0]['weight']);
        $this->assertSame('69.5 cm', $rows[0]['height']);
        $this->assertSame('13.5', $rows[0]['muac']);
        $this->assertSame(OperationTimbangMonitoringService::STATUS_UNCLASSIFIED, $rows[0]['status']);
    }

    public function test_risk_assessment_shaped_timbang_row_is_readable_without_a_second_event(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        ['resident' => $resident] = $this->seedChild([
            'first_name' => 'Ra',
            'last_name' => 'Shaped',
        ]);

        $created = (new \App\Support\TimbangRecordService)->createFromRiskAssessmentPhysical(
            $resident,
            '2026-08-08',
            9.25,
            71.0
        );
        $this->assertNotNull($created);
        $this->assertSame(1, TimbangRecord::query()->where('resident_id', $resident->id)->count());

        $rows = HealthRecordsOperationTimbang::monitoringRows(2026, 8);
        $this->assertSame('9.25 kg', $rows[0]['weight']);
        $this->assertSame('71 cm', $rows[0]['height']);
        $this->assertSame(HealthRecordsChildCare::EMPTY_RECORD, $rows[0]['muac']);
        $this->assertSame(1, TimbangRecord::query()->where('resident_id', $resident->id)->count());
    }

    public function test_adult_maternal_timbang_row_is_excluded_from_operation_timbang(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        ['resident' => $adult] = $this->seedChild([
            'first_name' => 'Maternal',
            'last_name' => 'Adult',
            'birthday' => '1995-03-01',
            'sex' => 'Female',
        ], ['household_no' => 'HH-840']);
        ['resident' => $child] = $this->seedChild([
            'first_name' => 'Listed',
            'last_name' => 'Child',
        ], ['household_no' => 'HH-841']);

        (new \App\Support\TimbangRecordService)->createFromMaternalPhysical(
            $adult,
            '2026-08-08',
            55.0,
            160.0
        );

        $names = array_column(HealthRecordsOperationTimbang::monitoringRows(2026, 8), 'full_name');
        $this->assertContains('Listed Child', $names);
        $this->assertNotContains('Maternal Adult', $names);
        $this->assertSame(1, TimbangRecord::query()->where('resident_id', $adult->id)->count());
    }

    public function test_works_when_operation_timbang_measurements_table_is_absent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        Schema::dropIfExists('operation_timbang_measurements');

        ['resident' => $resident] = $this->seedChild([
            'first_name' => 'Live',
            'last_name' => 'Shape',
        ]);
        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-08-10',
            'weight_kg' => 8.0,
            'height_cm' => 68.0,
            'muac_cm' => 12.5,
        ]);

        $this->assertFalse(Schema::hasTable('operation_timbang_measurements'));
        $rows = HealthRecordsOperationTimbang::monitoringRows(2026, 8);
        $this->assertSame('Live Shape', $rows[0]['full_name']);
        $this->assertSame('8 kg', $rows[0]['weight']);
    }
}
