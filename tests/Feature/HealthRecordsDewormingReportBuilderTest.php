<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\DewormingRecord;
use App\Models\Household;
use App\Models\Resident;
use App\Support\HealthRecordsDeworming;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Health Records → Child Care → Deworming Report Builder — choices
 * before export, same pattern as Environmental Health's, Household
 * Profiling's, Death's, Maternal Care's, Family Planning's, Risk
 * Assessment's, and Child Care's own Report Builder pages. Data source:
 * one row per resident with a current-year deworming record.
 */
class HealthRecordsDewormingReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-22')->startOfDay());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_report_builder_page_loads_with_filters_and_preview(): void
    {
        $this->createDewormedChild('Grace Ramos', 'Zone 8', 'Female', 1);

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.deworming.report-builder'));

        $response->assertOk();
        $response->assertSee('Deworming Report', false);
        $response->assertSee('data-hrdw-report', false);
        $response->assertSee('data-hrdw-report-zone', false);
        $response->assertSee('data-hrdw-report-sex', false);
        $response->assertSee('data-hrdw-report-status', false);
        $response->assertSee('data-hrdw-export', false);
        $response->assertSee('Export PDF', false);
        $response->assertSee('data-hrdw-report-rows', false);
        $response->assertSee('Grace Ramos', false);
    }

    public function test_dashboard_export_button_lands_on_report_builder_first(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString(
            'data-export-url="'.e(route('health-records.child-care.deworming.report-builder')).'"',
            $html
        );
        $this->assertStringNotContainsString(
            'data-export-url="'.e(route('health-records.child-care.deworming.export')).'"',
            $html
        );
    }

    public function test_export_rows_respects_zone_sex_and_status_filters(): void
    {
        $this->createDewormedChild('Zone A Person', 'Zone A', 'Female', 1);
        $this->createDewormedChild('Zone B Person', 'Zone B', 'Male', 2);

        $this->assertSame(['Zone A Person'], array_column(HealthRecordsDeworming::exportRows('Zone A'), 'full_name'));
        $this->assertSame(['Zone A Person'], array_column(HealthRecordsDeworming::exportRows(null, 'female'), 'full_name'));
        $this->assertSame(['Zone A Person'], array_column(HealthRecordsDeworming::exportRows(null, null, '1-dose'), 'full_name'));
        $this->assertSame(['Zone B Person'], array_column(HealthRecordsDeworming::exportRows(null, null, '2-doses'), 'full_name'));
        $this->assertCount(2, HealthRecordsDeworming::exportRows());
    }

    public function test_export_control_downloads_pdf_reflecting_filters(): void
    {
        $this->createDewormedChild('Adrian Corporal', 'Zone 2', 'Male', 1);
        $this->createDewormedChild('Haziel Santos', 'Zone 3', 'Female', 2);

        $this->actingAsStaff(StaffRole::BHW);
        $all = $this->get(route('health-records.child-care.deworming.export'));
        $all->assertOk();
        $all->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $all->getContent());
        $this->assertStringContainsString('Adrian Corporal', $all->getContent());
        $this->assertStringContainsString('Haziel Santos', $all->getContent());
        $this->assertStringContainsString('filename=', (string) $all->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', strtolower((string) $all->headers->get('content-disposition')));

        $this->actingAsStaff(StaffRole::BHW);
        $filtered = $this->get(route('health-records.child-care.deworming.export', ['zone' => 'Zone 2']));
        $filtered->assertOk();
        $filtered->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $filtered->getContent());
        $this->assertStringContainsString('Adrian Corporal', $filtered->getContent());
        $this->assertStringNotContainsString('Haziel Santos', $filtered->getContent());
    }

    /**
     * @param  int  $rounds  1 = July round only, 2 = both rounds given
     */
    private function createDewormedChild(string $name, string $zone, string $sex, int $rounds): Resident
    {
        $household = Household::factory()->create(['zone' => $zone]);
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => $first,
            'last_name' => $last,
            'sex' => $sex,
        ]);

        DewormingRecord::factory()->create([
            'resident_id' => $resident->id,
            'year' => 2026,
            'round' => 1,
            'date_given' => '2026-07-15',
        ]);

        if ($rounds === 2) {
            DewormingRecord::factory()->create([
                'resident_id' => $resident->id,
                'year' => 2026,
                'round' => 2,
                'date_given' => '2027-01-15',
            ]);
        }

        return $resident;
    }
}
