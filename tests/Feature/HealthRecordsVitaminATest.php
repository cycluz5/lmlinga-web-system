<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\ChildNutrition;
use App\Models\Household;
use App\Models\Resident;
use App\Support\HealthRecordsVitaminA;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Health Records → Child Care → Vitamin A monitoring summary.
 */
class HealthRecordsVitaminATest extends TestCase
{
    use RefreshDatabase;
    public function test_vitamin_a_route_resolves(): void
    {
        $this->assertTrue(Route::has('health-records.child-care.vitamin-a'));

        $route = Route::getRoutes()->getByName('health-records.child-care.vitamin-a');
        $this->assertNotNull($route);
        $this->assertSame('health-records/child-care/vitamin-a', $route->uri());
    }

    public function test_child_care_summary_links_to_vitamin_a_named_route(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.index'));

        $response->assertOk();
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.vitamin-a')).'"',
            $response->getContent()
        );
    }

    public function test_vitamin_a_page_renders_successfully(): void
    {
        $this->actingAsStaff(StaffRole::BNS);
        $response = $this->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-vitamin-a', $html);
        $this->assertStringContainsString('lml-hr-child-care--vitamin-a', $html);
        $this->assertStringContainsString(
            'Record and management of Vitamin A supplementation details for monitoring and tracking nutritional status.',
            $html
        );
    }

    public function test_vitamin_a_pill_is_active_current(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Vitamin A\s*</u',
            $html
        );
    }

    public function test_deworming_and_operation_timbang_pills_remain_present(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.deworming')).'"',
            $html
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.operation-timbang')).'"',
            $html
        );
        $this->assertSame(1, preg_match_all('/>\s*Deworming\s*<\/a>/u', $html));
        $this->assertSame(1, preg_match_all('/>\s*Operation Timbang\s*<\/a>/u', $html));

        $this->assertMatchesRegularExpression(
            '/>\s*Vitamin A\s*<\/a>[\s\S]*>\s*Deworming\s*<\/a>[\s\S]*>\s*Operation Timbang\s*<\/a>/u',
            $html
        );
    }

    public function test_health_records_expanded_and_child_care_sidebar_active(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame('child-care', UiRole::sidebarActiveKey());
        $this->assertMatchesRegularExpression(
            '/id="lml-sidebar-collapse-health-records"[^>]*\bis-open\b/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink[^>]*aria-current="page"[^>]*>[\s\S]*>Child Care</u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink[^>]*>\s*(?:<[^>]+>\s*)*Vitamin A\s*</u',
            $html
        );
    }

    public function test_age_group_labels_and_dose_headers_render(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('6 – 11 mos. old', $html);
        $this->assertStringContainsString('12 – 59 mos. old', $html);
        $this->assertStringContainsString('60 – 71 mos. old', $html);
        $this->assertStringContainsString('Vitamin A 100,000 IU', $html);
        $this->assertStringContainsString('Vitamin A 200,000 IU', $html);
        $this->assertStringContainsString('Percentage', $html);
        $this->assertStringContainsString('Accomplishment', $html);
        $this->assertStringContainsString('Total Number of children given vitamin A', $html);
        $this->assertMatchesRegularExpression('/>\s*Age Group\s*</u', $html);
    }

    public function test_zone_filter_and_export_render_without_add_control(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-va-zone', $html);
        $this->assertStringContainsString('>All Zones</option>', $html);
        $this->assertStringContainsString('for="lml-hr-va-zone"', $html);
        $this->assertStringContainsString('data-hr-va-export', $html);
        $this->assertMatchesRegularExpression('/>\s*Export Data\s*<\/span>/u', $html);

        $this->assertStringNotContainsString('data-hr-cc-add', $html);
        $this->assertStringNotContainsString('data-hr-va-add', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/aria-label="Add child care record"/u',
            $html
        );
    }

    public function test_table_has_search_and_sortable_column_headers(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-va-search', $html);
        $this->assertStringContainsString('placeholder="Search age group"', $html);
        $this->assertStringContainsString('data-hr-va-results', $html);

        foreach ([
            'target', 'va_100k_male', 'va_100k_female', 'va_100k_total',
            'va_200k_male', 'va_200k_female', 'va_200k_total', 'percentage',
        ] as $key) {
            $this->assertStringContainsString('data-hr-va-sort="'.$key.'"', $html);
        }

        $this->assertSame(8, substr_count($html, 'aria-sort="none"'));

        $rowCount = count(HealthRecordsVitaminA::monitoringRows());
        $this->assertSame($rowCount * 8, substr_count($html, 'data-value="'));
    }

    public function test_database_backed_rows_render_without_figma_preview_values(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-va-data-mode="persisted"', $html);
        $this->assertStringNotContainsString('>91%<', $html);
        $this->assertStringNotContainsString('>248<', $html);

        foreach (HealthRecordsVitaminA::monitoringRows() as $row) {
            if (! empty($row['is_total'])) {
                continue;
            }
            $this->assertSame('0', (string) $row['target']);
        }
    }

    public function test_vitamin_a_counts_dose_from_child_nutrition_records(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Vitamin',
            'last_name' => 'Child',
            'sex' => 'Female',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);

        ChildNutrition::factory()->create([
            'resident_id' => $resident->id,
            'vitamin_a_va_6_11_date' => now()->toDateString(),
        ]);

        $row611 = collect(HealthRecordsVitaminA::monitoringRows())
            ->firstWhere('key', '6-11');

        $this->assertIsArray($row611);
        $this->assertSame('1', $row611['target']);
        $this->assertSame('0', $row611['va_100k_male']);
        $this->assertSame('1', $row611['va_100k_female']);
        $this->assertSame('1', $row611['va_100k_total']);
        $this->assertSame('100%', $row611['percentage']);
    }

    public function test_total_row_sums_zeros_and_percentage_stays_blank_when_empty(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, preg_match('/data-age-group="total"[\s\S]*?<\/tr>/u', $html, $totalMatch));
        $totalRow = $totalMatch[0];
        $this->assertDoesNotMatchRegularExpression('/>\s*\d+%\s*</u', $totalRow);
        $this->assertStringContainsString('>0<', preg_replace('/\s+/', '', $totalRow) ?? $totalRow);

        $this->assertSame(1, preg_match('/data-age-group="6-11"[\s\S]*?<\/tr>/u', $html, $row611Match));
        $row611 = $row611Match[0];
        $this->assertStringContainsString('>0<', preg_replace('/\s+/', '', $row611) ?? $row611);
        $this->assertStringNotContainsString('Figma preview/demo', $html);
    }

    public function test_no_vitamin_a_rows_keep_coverage_structure_with_zero_doses(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'sex' => 'Male',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);

        $row611 = collect(HealthRecordsVitaminA::monitoringRows())->firstWhere('key', '6-11');
        $total = collect(HealthRecordsVitaminA::monitoringRows())->firstWhere('key', 'total');

        $this->assertSame('1', $row611['target']);
        $this->assertSame('0', $row611['va_100k_male']);
        $this->assertSame('0', $row611['va_100k_female']);
        $this->assertSame('0', $row611['va_100k_total']);
        $this->assertSame('', $row611['percentage']);
        $this->assertSame('1', $total['target']);
        $this->assertSame('0', $total['va_100k_total']);
        $this->assertSame('', $total['percentage']);
    }

    public function test_12_59_dated_vitamin_a_credits_200k_slot(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'sex' => 'Male',
            'birthday' => now()->subMonths(24)->toDateString(),
        ]);

        ChildNutrition::factory()->create([
            'resident_id' => $resident->id,
            'vitamin_a_va_12_59_1_date' => now()->toDateString(),
        ]);

        $row = collect(HealthRecordsVitaminA::monitoringRows())->firstWhere('key', '12-59');
        $this->assertSame('1', $row['target']);
        $this->assertSame('1', $row['va_200k_male']);
        $this->assertSame('1', $row['va_200k_total']);
        $this->assertSame('', $row['va_100k_total']);
        $this->assertSame('100%', $row['percentage']);
    }

    public function test_two_12_59_doses_count_child_once(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'sex' => 'Female',
            'birthday' => now()->subMonths(30)->toDateString(),
        ]);

        ChildNutrition::factory()->create([
            'resident_id' => $resident->id,
            'vitamin_a_va_12_59_1_date' => now()->toDateString(),
            'vitamin_a_va_12_59_2_date' => now()->toDateString(),
        ]);

        $row = collect(HealthRecordsVitaminA::monitoringRows())->firstWhere('key', '12-59');
        $this->assertSame('1', $row['va_200k_female']);
        $this->assertSame('1', $row['va_200k_total']);
    }

    public function test_60_71_current_age_uses_compatible_200k_storage(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'sex' => 'Male',
            'birthday' => now()->subMonths(65)->toDateString(),
        ]);

        ChildNutrition::factory()->create([
            'resident_id' => $resident->id,
            'vitamin_a_va_12_59_1_date' => now()->toDateString(),
        ]);

        $row = collect(HealthRecordsVitaminA::monitoringRows())->firstWhere('key', '60-71');
        $this->assertSame('1', $row['target']);
        $this->assertSame('1', $row['va_200k_male']);
        $this->assertSame('1', $row['va_200k_total']);
    }

    public function test_resident_a_dose_does_not_credit_resident_b(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $dosed = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Alpha',
            'sex' => 'Female',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Bravo',
            'sex' => 'Male',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);

        ChildNutrition::factory()->create([
            'resident_id' => $dosed->id,
            'vitamin_a_va_6_11_date' => now()->toDateString(),
        ]);

        $row = collect(HealthRecordsVitaminA::monitoringRows())->firstWhere('key', '6-11');
        $this->assertSame('2', $row['target']);
        $this->assertSame('1', $row['va_100k_female']);
        $this->assertSame('0', $row['va_100k_male']);
        $this->assertSame('1', $row['va_100k_total']);
        $this->assertSame('50%', $row['percentage']);
    }

    public function test_zone_filter_excludes_other_zone_targets_and_doses(): void
    {
        $zone1 = Household::factory()->create(['zone' => 'Zone 1']);
        $zone2 = Household::factory()->create(['zone' => 'Zone 2']);
        $a = Resident::factory()->create([
            'household_id' => $zone1->id,
            'sex' => 'Female',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);
        $b = Resident::factory()->create([
            'household_id' => $zone2->id,
            'sex' => 'Male',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);

        ChildNutrition::factory()->create([
            'resident_id' => $a->id,
            'vitamin_a_va_6_11_date' => now()->toDateString(),
        ]);
        ChildNutrition::factory()->create([
            'resident_id' => $b->id,
            'vitamin_a_va_6_11_date' => now()->toDateString(),
        ]);

        $all = collect(HealthRecordsVitaminA::monitoringRows())->firstWhere('key', '6-11');
        $z1 = collect(HealthRecordsVitaminA::monitoringRows('Zone 1'))->firstWhere('key', '6-11');
        $z2 = collect(HealthRecordsVitaminA::monitoringRows('Zone 2'))->firstWhere('key', '6-11');
        $totalZ1 = collect(HealthRecordsVitaminA::monitoringRows('Zone 1'))->firstWhere('key', 'total');

        $this->assertSame('2', $all['target']);
        $this->assertSame('2', $all['va_100k_total']);
        $this->assertSame('1', $z1['target']);
        $this->assertSame('1', $z1['va_100k_female']);
        $this->assertSame('0', $z1['va_100k_male']);
        $this->assertSame('1', $z2['target']);
        $this->assertSame('1', $z2['va_100k_male']);
        $this->assertSame('1', $totalZ1['va_100k_total']);
        $this->assertSame('1', $totalZ1['target']);
    }

    public function test_vitamin_a_page_applies_zone_query_on_the_server(): void
    {
        $zone1 = Household::factory()->create(['zone' => 'Zone 1']);
        $zone2 = Household::factory()->create(['zone' => 'Zone 2']);
        $a = Resident::factory()->create([
            'household_id' => $zone1->id,
            'sex' => 'Female',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);
        Resident::factory()->create([
            'household_id' => $zone2->id,
            'sex' => 'Male',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);
        ChildNutrition::factory()->create([
            'resident_id' => $a->id,
            'vitamin_a_va_6_11_date' => now()->toDateString(),
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.vitamin-a', ['zone' => 'Zone 1']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<option value="Zone 1"[^>]*selected/u', $html);
        $this->assertSame(1, preg_match('/data-age-group="6-11"[\s\S]*?<\/tr>/u', $html, $match));
        $compact = preg_replace('/\s+/', '', $match[0]) ?? $match[0];
        $this->assertStringContainsString('>1<', $compact);
    }

    public function test_total_row_aggregates_filtered_age_bands(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $infant = Resident::factory()->create([
            'household_id' => $household->id,
            'sex' => 'Female',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);
        $toddler = Resident::factory()->create([
            'household_id' => $household->id,
            'sex' => 'Male',
            'birthday' => now()->subMonths(24)->toDateString(),
        ]);

        ChildNutrition::factory()->create([
            'resident_id' => $infant->id,
            'vitamin_a_va_6_11_date' => now()->toDateString(),
        ]);
        ChildNutrition::factory()->create([
            'resident_id' => $toddler->id,
            'vitamin_a_va_12_59_1_date' => now()->toDateString(),
        ]);

        $total = collect(HealthRecordsVitaminA::monitoringRows())->firstWhere('key', 'total');
        $this->assertSame('2', $total['target']);
        $this->assertSame('1', $total['va_100k_female']);
        $this->assertSame('1', $total['va_100k_total']);
        $this->assertSame('1', $total['va_200k_male']);
        $this->assertSame('1', $total['va_200k_total']);
        $this->assertSame('100%', $total['percentage']);
    }

    public function test_non_residents_scope_pill_is_absent(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.vitamin-a'))
            ->assertOk()
            ->getContent();

        $this->assertSame(0, preg_match_all('/>\s*Non-Residents\s*<\/span>/u', $html));
        $this->assertSame(0, substr_count($html, 'data-hr-cc-non-residents'));
        $this->assertStringNotContainsString(
            'href="'.e(route('health-records.child-care.non-residents.index')).'"',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Vitamin A\s*</u',
            $html
        );
        $this->assertSame('child-care', UiRole::sidebarActiveKey());
        $this->assertStringContainsString('data-lml-hr-vitamin-a', $html);
    }

    public function test_export_pdf_matches_the_live_table_format_and_data(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Vitamin',
            'last_name' => 'Exportchild',
            'sex' => 'Female',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);

        ChildNutrition::factory()->create([
            'resident_id' => $resident->id,
            'vitamin_a_va_6_11_date' => now()->toDateString(),
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.vitamin-a.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $pdf = $response->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('filename=', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', strtolower((string) $response->headers->get('content-disposition')));

        // Same 3-tier grouped header as the live table.
        $this->assertStringContainsString('Age Group', $pdf);
        $this->assertStringContainsString('Total Number of Children Given Vitamin A', $pdf);
        $this->assertStringContainsString('Vitamin A 100,000 IU', $pdf);
        $this->assertStringContainsString('Vitamin A 200,000 IU', $pdf);
        $this->assertStringContainsString('Percentage', $pdf);

        // Same rows/values HealthRecordsVitaminA::monitoringRows() computes.
        $this->assertStringContainsString('6', $pdf);
        $this->assertStringContainsString('mos. old', $pdf);
        $this->assertStringContainsString('100%', $pdf);
        $this->assertStringContainsString('Total', $pdf);
    }

    public function test_export_pdf_reflects_zone_filter(): void
    {
        $zoneA = Household::factory()->create(['zone' => 'Zone A']);
        $residentA = Resident::factory()->create([
            'household_id' => $zoneA->id,
            'sex' => 'Male',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);
        ChildNutrition::factory()->create([
            'resident_id' => $residentA->id,
            'vitamin_a_va_6_11_date' => now()->toDateString(),
        ]);

        $zoneB = Household::factory()->create(['zone' => 'Zone B']);
        $residentB = Resident::factory()->create([
            'household_id' => $zoneB->id,
            'sex' => 'Male',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);
        ChildNutrition::factory()->create([
            'resident_id' => $residentB->id,
            'vitamin_a_va_6_11_date' => now()->toDateString(),
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $all = $this->get(route('health-records.child-care.vitamin-a.export'));
        $all->assertOk();
        $this->assertStringContainsString('All Zones', $all->getContent());

        $filtered = $this->get(route('health-records.child-care.vitamin-a.export', ['zone' => 'Zone A']));
        $filtered->assertOk();
        $this->assertStringContainsString('Zone: Zone A', $filtered->getContent());
    }

    public function test_year_and_month_filter_controls_render(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-va-year', $html);
        $this->assertStringContainsString('>All Years</option>', $html);
        $this->assertStringContainsString('data-hr-va-month', $html);
        $this->assertStringContainsString('>All Months</option>', $html);
        $this->assertStringContainsString('>January</option>', $html);
        $this->assertStringContainsString('>September</option>', $html);
    }

    public function test_monitoring_rows_respect_year_and_month_filter(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'sex' => 'Female',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);

        ChildNutrition::factory()->create([
            'resident_id' => $resident->id,
            'vitamin_a_va_6_11_date' => '2026-09-15',
        ]);

        $rowSeptember = collect(HealthRecordsVitaminA::monitoringRows(null, '2026', '09'))
            ->firstWhere('key', '6-11');
        $this->assertSame('1', $rowSeptember['va_100k_female']);

        $rowMarch = collect(HealthRecordsVitaminA::monitoringRows(null, '2026', '03'))
            ->firstWhere('key', '6-11');
        $this->assertSame('0', $rowMarch['va_100k_female']);

        $rowOtherYear = collect(HealthRecordsVitaminA::monitoringRows(null, '2025', null))
            ->firstWhere('key', '6-11');
        $this->assertSame('0', $rowOtherYear['va_100k_female']);

        // Target (eligible population) is not date-restricted — only the
        // "given" counts are, since Target reflects current age eligibility.
        $this->assertSame('1', $rowMarch['target']);
    }

    public function test_export_pdf_reflects_year_and_month_filter(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'sex' => 'Female',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);
        ChildNutrition::factory()->create([
            'resident_id' => $resident->id,
            'vitamin_a_va_6_11_date' => '2026-09-15',
        ]);

        $this->actingAsStaff(StaffRole::BHW);

        $septemberExport = $this->get(route('health-records.child-care.vitamin-a.export', [
            'year' => '2026',
            'month' => '09',
        ]));
        $septemberExport->assertOk();
        $this->assertStringContainsString('September 2026', $septemberExport->getContent());

        $marchExport = $this->get(route('health-records.child-care.vitamin-a.export', [
            'year' => '2026',
            'month' => '03',
        ]));
        $marchExport->assertOk();
        $this->assertStringContainsString('March 2026', $marchExport->getContent());
    }

    public function test_dashboard_export_button_lands_on_report_builder_first(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.vitamin-a'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'data-export-url="'.e(route('health-records.child-care.vitamin-a.report-builder')).'"',
            $html
        );
        $this->assertStringNotContainsString(
            'data-export-url="'.e(route('health-records.child-care.vitamin-a.export')).'"',
            $html
        );
    }
}
