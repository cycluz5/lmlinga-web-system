<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\MaternalPregnancy;
use App\Models\Resident;
use App\Support\HealthRecordsMaternal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Health Records → Maternal Report Builder — choices before export, same
 * pattern as Environmental Health's, Household Profiling's, and Death's
 * Report Builder pages.
 */
class HealthRecordsMaternalReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_report_builder_page_loads_with_filters_and_preview(): void
    {
        $this->createMaternalRecord('Grace Ramos', 'Zone 8', '2026-09-10');
        $this->createMaternalRecord('Zone Two Person', 'Zone 2', '2026-03-01');

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.maternal.report-builder'));

        $response->assertOk();
        $response->assertSee('Maternal Care Report', false);
        $response->assertSee('data-hrmc-report', false);
        $response->assertSee('data-hrmc-report-zone', false);
        $response->assertSee('data-hrmc-report-year', false);
        $response->assertSee('data-hrmc-report-month', false);
        $response->assertSee('data-hrmc-export', false);
        $response->assertSee('Export PDF', false);
        $response->assertSee('data-hrmc-report-rows', false);
        $response->assertSee('Grace Ramos', false);
        $response->assertSee('Zone Two Person', false);
    }

    public function test_dashboard_export_button_lands_on_report_builder_first(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.maternal.index'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString(
            'data-export-url="'.e(route('health-records.maternal.report-builder')).'"',
            $html
        );
        $this->assertStringNotContainsString(
            'data-export-url="'.e(route('health-records.maternal.export')).'"',
            $html
        );
    }

    public function test_export_rows_respects_zone_year_and_month_filters(): void
    {
        $this->createMaternalRecord('Zone A Person', 'Zone A', '2026-03-15');
        $this->createMaternalRecord('Zone B Person', 'Zone B', '2026-09-15');

        $this->assertSame(['Zone A Person'], array_column(HealthRecordsMaternal::exportRows('Zone A'), 'full_name'));
        $this->assertSame(['Zone B Person'], array_column(HealthRecordsMaternal::exportRows(null, '2026', '09'), 'full_name'));
        $this->assertSame(['Zone A Person'], array_column(HealthRecordsMaternal::exportRows(null, '2026', '03'), 'full_name'));
        $this->assertCount(2, HealthRecordsMaternal::exportRows(null, '2026'));
    }

    public function test_period_label_reflects_active_filters(): void
    {
        $this->assertSame('All Time', HealthRecordsMaternal::periodLabel('all', 'all'));
        $this->assertSame('Year 2026', HealthRecordsMaternal::periodLabel('2026', 'all'));
        $this->assertSame('September 2026', HealthRecordsMaternal::periodLabel('2026', '09'));
    }

    public function test_export_control_downloads_pdf_reflecting_filters(): void
    {
        $this->createMaternalRecord('Adrian Corporal', 'Zone 2', '2026-09-11');
        $this->createMaternalRecord('Haziel Santos', 'Zone 3', '2026-03-05');

        $this->actingAsStaff(StaffRole::BHW);
        $all = $this->get(route('health-records.maternal.export'));
        $all->assertOk();
        $all->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $all->getContent());
        $this->assertStringContainsString('Adrian Corporal', $all->getContent());
        $this->assertStringContainsString('Haziel Santos', $all->getContent());
        $this->assertStringContainsString('filename=', (string) $all->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', strtolower((string) $all->headers->get('content-disposition')));

        $this->actingAsStaff(StaffRole::BHW);
        $filtered = $this->get(route('health-records.maternal.export', ['zone' => 'Zone 2']));
        $filtered->assertOk();
        $filtered->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $filtered->getContent());
        $this->assertStringContainsString('Adrian Corporal', $filtered->getContent());
        $this->assertStringNotContainsString('Haziel Santos', $filtered->getContent());
    }

    public function test_detailed_export_rows_are_straight_rows_with_every_field_always_present(): void
    {
        $this->createMaternalRecord('Early Client', 'Zone 5', '2026-09-01');

        $rows = HealthRecordsMaternal::detailedExportRows();
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertArrayNotHasKey('sections', $row);
        $this->assertSame('1-0', $row['gravida_parity']);
        $this->assertSame('Active', $row['status']);

        // Every field from every group — including ones this early
        // pregnancy has no data for yet — is present as its own column on
        // the row, blank (empty string, never '—') rather than omitted.
        foreach ([
            'pv_t1_v1_date', 'pv_t1_v1_height', 'pv_t1_v1_weight', 'pv_t1_v1_bmi',
            'pv_t3_v5_date', 'pv_t3_v5_bmi',
            'supp_deworming_date', 'supp_ifa_v1_date', 'supp_ifa_v1_tablets',
            'supp_mms_v6_date', 'supp_calcium_v3_tablets',
            'lab_hepatitis_b_date', 'lab_hepatitis_b_result', 'lab_cbc_date', 'lab_gdm_result',
            'delivery_outcome', 'delivery_type', 'delivery_birth_weight', 'delivery_status',
            'delivery_datetime', 'delivery_date_terminated', 'delivery_fetal_death_date',
            'delivery_abortion_date', 'delivery_birth_attendant', 'delivery_birth_attendant_other',
            'delivery_place', 'delivery_facility_name', 'delivery_bemonc_cemonc',
            'postnatal_c1', 'postnatal_c4', 'postnatal_supp_v1_date', 'postnatal_supp_v3_tablets',
        ] as $key) {
            $this->assertArrayHasKey($key, $row, "missing key {$key}");
            $this->assertSame('', $row[$key], "expected {$key} to be blank, got '{$row[$key]}'");
        }
    }

    public function test_detailed_export_rows_include_only_filled_visits_across_all_sections(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 6']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Full',
            'last_name' => 'Record',
            'sex' => 'Female',
            'birthday' => '1994-02-10',
        ]);

        $pregnancy = MaternalPregnancy::factory()->create([
            'resident_id' => $resident->id,
            'status' => MaternalPregnancy::STATUS_COMPLETED,
            'registered_at' => '2026-01-05',
            'lmp' => '2025-06-01',
            'gravida' => 2,
            'parity' => 1,
            'edd' => '2026-03-08',
            'prenatal' => [
                't1_v1' => ['date' => '2026-01-10', 'height' => '158', 'weight' => '56', 'bmi' => '22.4', 'bp' => '110/70'],
                't2_v1' => ['date' => ''],
            ],
            'supplementations' => [
                'deworming_date' => '2026-01-10',
                'ifa' => ['v1' => ['date' => '2026-01-10', 'tablets' => '30'], 'v2' => ['date' => '', 'tablets' => '']],
            ],
            'laboratory' => [
                'hepatitis_b' => ['date' => '2026-01-12', 'result' => 'Negative'],
            ],
            'delivery' => [
                'outcome' => 'FT',
                'delivery_type' => 'VD',
                'birth_weight' => '3.1',
                'status' => 'Live',
                'datetime' => '2026-09-01T10:00',
                'birth_attendant' => 'MD',
                'place' => 'public',
                'facility_name' => 'La Medalla Health Center',
                'bemonc_cemonc' => 'Yes',
            ],
            'postnatal' => [
                'contacts' => ['c1' => '2026-09-02', 'c2' => ''],
                'supplementation' => ['v1' => ['date' => '2026-09-02', 'tablets' => '10']],
            ],
        ]);
        // Real (non-factory) records get pregnancy_no = MC-{id}; the factory
        // assigns a random one ('pregnancy_no' is also not mass-assignable,
        // so this must go through direct attribute assignment, not update()).
        $pregnancy->pregnancy_no = sprintf('MC-%03d', $pregnancy->id);
        $pregnancy->save();

        // FT/PT completed pregnancies only surface in history/exports 42
        // inclusive days after the delivery date — move "today" past that
        // gate so this test (about flattened field export, not the gate
        // itself) isn't a ticking time bomb tied to the real clock.
        Carbon::setTestNow(Carbon::parse('2026-10-20'));

        $rows = HealthRecordsMaternal::detailedExportRows();
        $this->assertCount(1, $rows);
        $row = $rows[0];

        // The filled visit's sub-fields land in their own columns; the
        // empty t2_v1 slot's columns are still present but blank.
        $this->assertStringContainsString('158', $row['pv_t1_v1_height']);
        $this->assertStringContainsString('56', $row['pv_t1_v1_weight']);
        $this->assertSame('', $row['pv_t2_v1_date']);
        $this->assertSame('', $row['pv_t2_v1_height']);

        // Deworming + IFA visit 1 filled; IFA visit 2 stays blank.
        $this->assertNotSame('', $row['supp_deworming_date']);
        $this->assertNotSame('', $row['supp_ifa_v1_date']);
        $this->assertSame('30', $row['supp_ifa_v1_tablets']);
        $this->assertSame('', $row['supp_ifa_v2_date']);
        $this->assertSame('', $row['supp_ifa_v2_tablets']);

        $this->assertSame('Negative', $row['lab_hepatitis_b_result']);
        $this->assertNotSame('', $row['lab_hepatitis_b_date']);
        $this->assertSame('', $row['lab_cbc_date']);
        $this->assertSame('', $row['lab_cbc_result']);

        $this->assertSame('Full Term', $row['delivery_outcome']);
        $this->assertSame('VD - Vaginal Delivery', $row['delivery_type']);
        $this->assertSame('3.1', $row['delivery_birth_weight']);
        $this->assertSame('MD - Doctor', $row['delivery_birth_attendant']);
        $this->assertSame('Public Health Facility', $row['delivery_place']);
        $this->assertSame('La Medalla Health Center', $row['delivery_facility_name']);
        $this->assertSame('Yes', $row['delivery_bemonc_cemonc']);
        $this->assertSame('', $row['delivery_fetal_death_date']);
        $this->assertSame('', $row['delivery_abortion_date']);

        $this->assertNotSame('', $row['postnatal_c1']);
        $this->assertSame('', $row['postnatal_c2']);
        $this->assertNotSame('', $row['postnatal_supp_v1_date']);
        $this->assertSame('10', $row['postnatal_supp_v1_tablets']);
    }

    public function test_export_pdf_contains_all_group_titles_for_every_record(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 7']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Detailed',
            'last_name' => 'Client',
            'sex' => 'Female',
            'birthday' => '1994-02-10',
        ]);

        $pregnancy = MaternalPregnancy::factory()->create([
            'resident_id' => $resident->id,
            'status' => MaternalPregnancy::STATUS_COMPLETED,
            'registered_at' => '2026-01-05',
            'delivery' => ['outcome' => 'FT', 'datetime' => '2026-09-01T10:00'],
        ]);
        $pregnancy->pregnancy_no = sprintf('MC-%03d', $pregnancy->id);
        $pregnancy->save();

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.maternal.export'));
        $response->assertOk();
        $pdf = $response->getContent();

        // Every group header always prints (straight-row export), whether
        // or not that client has data in it.
        $this->assertStringContainsString('REGISTRATION', $pdf);
        $this->assertStringContainsString('PRENATAL VISITS', $pdf);
        $this->assertStringContainsString('SUPPLEMENTATION', $pdf);
        $this->assertStringContainsString('LABORATORY SCREENING', $pdf);
        $this->assertStringContainsString('PREGNANCY DELIVERY', $pdf);
        $this->assertStringContainsString('POSTNATAL CARE', $pdf);
    }

    public function test_column_pages_are_six_fixed_topics_with_name_only_anchor_after_page_one(): void
    {
        $pages = HealthRecordsMaternal::columnPages();

        $groupTitles = [];
        foreach ($pages as $page) {
            $groupTitles[$page['groupTitle']] = true;
        }
        $this->assertSame(
            ['CLIENT & REGISTRATION', 'PRENATAL VISITS', 'SUPPLEMENTATION', 'LABORATORY SCREENING', 'PREGNANCY DELIVERY & OUTCOME', 'POSTNATAL CARE'],
            array_keys($groupTitles)
        );

        // Page 1 carries the full Client group (Name, Age, Birthday) plus Registration.
        $page1Keys = array_column(array_merge(...array_column($pages[0]['sections'], 'fields')), 'key');
        $this->assertContains('full_name', $page1Keys);
        $this->assertContains('age', $page1Keys);
        $this->assertContains('birthday', $page1Keys);
        $this->assertContains('registered_date', $page1Keys);

        // Every page after page 1 repeats only Name — not Age/Birthday — as the anchor.
        foreach (array_slice($pages, 1) as $page) {
            $keys = array_column(array_merge(...array_column($page['sections'], 'fields')), 'key');
            $this->assertContains('full_name', $keys, $page['groupTitle'].' is missing the Name anchor');
            $this->assertNotContains('age', $keys, $page['groupTitle'].' should not repeat Age');
            $this->assertNotContains('birthday', $keys, $page['groupTitle'].' should not repeat Birthday');
        }

        // Every column page stays within the printable page width.
        foreach ($pages as $page) {
            $totalWidth = array_sum(array_column(array_merge(...array_column($page['sections'], 'fields')), 'width'));
            $this->assertLessThanOrEqual(928.0, $totalWidth, $page['groupTitle'].' part '.$page['part'].' is too wide');
        }
    }

    private function createMaternalRecord(string $name, string $zone, string $registeredAt): MaternalPregnancy
    {
        $household = Household::factory()->create(['zone' => $zone]);
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => $first,
            'last_name' => $last,
            'sex' => 'Female',
            'birthday' => '1994-02-10',
        ]);

        return MaternalPregnancy::factory()->create([
            'resident_id' => $resident->id,
            'status' => MaternalPregnancy::STATUS_ACTIVE,
            'registered_at' => $registeredAt,
            'lmp' => '2025-11-01',
            'gravida' => 1,
            'parity' => 0,
            'edd' => '2026-08-08',
        ]);
    }
}
