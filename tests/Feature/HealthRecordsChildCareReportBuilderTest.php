<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\Resident;
use App\Support\HealthRecordsChildCare;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Health Records → Child Care Report Builder — choices before export,
 * same pattern as Environmental Health's, Household Profiling's, Death's,
 * Maternal Care's, Family Planning's, and Risk Assessment's Report
 * Builder pages. Filters: zone, age (band or custom range in years), sex.
 * Columns: Name, Age, Birthday, Sex.
 */
class HealthRecordsChildCareReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_report_builder_page_loads_with_filters_and_preview(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18'));

        $this->createChild('Grace Ramos', 'Zone 8', '2020-01-15', 'female');
        $this->createChild('Zone Two Person', 'Zone 2', '2015-06-01', 'male');

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.report-builder'));

        $response->assertOk();
        $response->assertSee('Child Care Report', false);
        $response->assertSee('data-hrcc-report', false);
        $response->assertSee('data-hrcc-report-zone', false);
        $response->assertSee('data-hrcc-report-age', false);
        $response->assertSee('data-hrcc-report-sex', false);
        $response->assertSee('data-hrcc-export', false);
        $response->assertSee('Export PDF', false);
        $response->assertSee('data-hrcc-report-rows', false);
        $response->assertSee('Grace Ramos', false);
        $response->assertSee('Zone Two Person', false);
    }

    public function test_dashboard_export_button_lands_on_report_builder_first(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.index'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString(
            'data-export-url="'.e(route('health-records.child-care.report-builder')).'"',
            $html
        );
        $this->assertStringNotContainsString(
            'data-export-url="'.e(route('health-records.child-care.export')).'"',
            $html
        );
    }

    public function test_export_rows_respects_zone_sex_and_age_band_filters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18'));

        $this->createChild('Zone A Girl', 'Zone A', '2020-01-01', 'female'); // ~6 years
        $this->createChild('Zone B Boy', 'Zone B', '2012-01-01', 'male'); // ~14 years

        $this->assertSame(['Zone A Girl'], array_column(HealthRecordsChildCare::exportRows('Zone A'), 'full_name'));
        $this->assertSame(['Zone A Girl'], array_column(HealthRecordsChildCare::exportRows(null, 'all', null, null, 'female'), 'full_name'));
        $this->assertSame(['Zone A Girl'], array_column(HealthRecordsChildCare::exportRows(null, '5-9'), 'full_name'));
        $this->assertSame(['Zone B Boy'], array_column(HealthRecordsChildCare::exportRows(null, '10-14'), 'full_name'));
        $this->assertCount(2, HealthRecordsChildCare::exportRows());
    }

    public function test_export_rows_respects_custom_age_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18'));

        $this->createChild('Young Child', 'Zone C', '2024-01-01', 'male'); // ~2 years
        $this->createChild('Older Child', 'Zone C', '2015-01-01', 'female'); // ~11 years

        $this->assertSame(
            ['Young Child'],
            array_column(HealthRecordsChildCare::exportRows(null, 'custom', 0, 5), 'full_name')
        );
        $this->assertSame(
            ['Older Child'],
            array_column(HealthRecordsChildCare::exportRows(null, 'custom', 10, 12), 'full_name')
        );
        $this->assertCount(2, HealthRecordsChildCare::exportRows(null, 'custom', 0, 18));
    }

    public function test_scope_label_reflects_active_filters(): void
    {
        $this->assertSame('All Ages · All Sexes', HealthRecordsChildCare::scopeLabel('all', null));
        $this->assertSame('5–9 years · Female', HealthRecordsChildCare::scopeLabel('5-9', 'female'));
        $this->assertSame('Custom 3–7 yrs · Male', HealthRecordsChildCare::scopeLabel('custom', 'male', 3, 7));
        $this->assertSame('Custom · All Sexes', HealthRecordsChildCare::scopeLabel('custom', null));
    }

    public function test_export_control_downloads_pdf_reflecting_filters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18'));

        $this->createChild('Adrian Corporal', 'Zone 2', '2018-01-01', 'male');
        $this->createChild('Haziel Santos', 'Zone 3', '2016-01-01', 'female');

        $this->actingAsStaff(StaffRole::BHW);
        $all = $this->get(route('health-records.child-care.export'));
        $all->assertOk();
        $all->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $all->getContent());
        $this->assertStringContainsString('Adrian Corporal', $all->getContent());
        $this->assertStringContainsString('Haziel Santos', $all->getContent());
        $this->assertStringContainsString('filename=', (string) $all->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', strtolower((string) $all->headers->get('content-disposition')));

        $this->actingAsStaff(StaffRole::BHW);
        $filtered = $this->get(route('health-records.child-care.export', ['zone' => 'Zone 2']));
        $filtered->assertOk();
        $filtered->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $filtered->getContent());
        $this->assertStringContainsString('Adrian Corporal', $filtered->getContent());
        $this->assertStringNotContainsString('Haziel Santos', $filtered->getContent());
    }

    public function test_export_control_respects_sex_and_custom_age_filters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18'));

        $this->createChild('Filtered In', 'Zone 5', '2020-01-01', 'female');
        $this->createChild('Filtered Out', 'Zone 5', '2020-01-01', 'male');

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.export', [
            'sex' => 'female',
            'age' => 'custom',
            'age_min' => 0,
            'age_max' => 18,
        ]));

        $response->assertOk();
        $this->assertStringContainsString('Filtered In', $response->getContent());
        $this->assertStringNotContainsString('Filtered Out', $response->getContent());
    }

    private function createChild(string $name, string $zone, string $birthday, string $sex): Resident
    {
        $household = Household::factory()->create(['zone' => $zone]);
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        return Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => $first,
            'last_name' => $last,
            'birthday' => $birthday,
            'sex' => $sex,
        ]);
    }
}
