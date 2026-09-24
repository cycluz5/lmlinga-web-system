<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Support\HealthRecordsRiskAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Health Records → Risk Assessment Report Builder — choices before
 * export, same pattern as Environmental Health's, Household Profiling's,
 * Death's, Maternal Care's, and Family Planning's Report Builder pages.
 * Sections mirror Maternal's grouped, multi-page architecture: Client
 * (Household No/Name/Age/Birthday/Date Assessed) plus Red Flag Assessment/
 * Past Medical History/Family History/Lifestyle & Risk Factor/Physical
 * Measurement and Clinical Screening.
 */
class HealthRecordsRiskAssessmentReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_builder_page_loads_with_filters_and_preview(): void
    {
        $this->createAssessment('Grace Ramos', 'Zone 8', '2026-09-10');
        $this->createAssessment('Zone Two Person', 'Zone 2', '2026-03-01');

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.risk-assessment.report-builder'));

        $response->assertOk();
        $response->assertSee('Risk Assessment Report', false);
        $response->assertSee('data-hrra-report', false);
        $response->assertSee('data-hrra-report-zone', false);
        $response->assertSee('data-hrra-report-year', false);
        $response->assertSee('data-hrra-report-month', false);
        $response->assertSee('data-hrra-export', false);
        $response->assertSee('Export PDF', false);
        $response->assertSee('data-hrra-report-rows', false);
        $response->assertSee('Grace Ramos', false);
        $response->assertSee('Zone Two Person', false);
    }

    public function test_dashboard_export_button_lands_on_report_builder_first(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.risk-assessment.index'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString(
            'data-export-url="'.e(route('health-records.risk-assessment.report-builder')).'"',
            $html
        );
        $this->assertStringNotContainsString(
            'data-export-url="'.e(route('health-records.risk-assessment.export')).'"',
            $html
        );
    }

    public function test_export_rows_respects_zone_year_and_month_filters(): void
    {
        $this->createAssessment('Zone A Person', 'Zone A', '2026-03-15');
        $this->createAssessment('Zone B Person', 'Zone B', '2026-09-15');

        $this->assertSame(['Zone A Person'], array_column(HealthRecordsRiskAssessment::exportRows('Zone A'), 'full_name'));
        $this->assertSame(['Zone B Person'], array_column(HealthRecordsRiskAssessment::exportRows(null, '2026', '09'), 'full_name'));
        $this->assertSame(['Zone A Person'], array_column(HealthRecordsRiskAssessment::exportRows(null, '2026', '03'), 'full_name'));
        $this->assertCount(2, HealthRecordsRiskAssessment::exportRows(null, '2026'));
    }

    public function test_period_label_reflects_active_filters(): void
    {
        $this->assertSame('All Time', HealthRecordsRiskAssessment::periodLabel('all', 'all'));
        $this->assertSame('Year 2026', HealthRecordsRiskAssessment::periodLabel('2026', 'all'));
        $this->assertSame('September 2026', HealthRecordsRiskAssessment::periodLabel('2026', '09'));
    }

    public function test_column_pages_are_six_fixed_topics_with_name_only_anchor_after_page_one(): void
    {
        $pages = HealthRecordsRiskAssessment::columnPages();

        $groupTitles = [];
        foreach ($pages as $page) {
            if (! in_array($page['groupTitle'], $groupTitles, true)) {
                $groupTitles[] = $page['groupTitle'];
            }
        }

        $this->assertSame([
            'CLIENT & RED FLAG ASSESSMENT',
            'RED FLAG ASSESSMENT',
            'PAST MEDICAL HISTORY',
            'FAMILY HISTORY',
            'LIFESTYLE & RISK FACTOR',
            'PHYSICAL MEASUREMENT AND CLINICAL SCREENING',
        ], $groupTitles);

        // Page 1 combines the full Client group with as many Red Flag
        // Assessment fields as fit, rather than leaving that width blank.
        $firstPageBaseFields = array_column($pages[0]['sections'][0]['fields'], 'key');
        $this->assertSame(['household_no', 'full_name', 'age', 'birthday', 'date_assessed'], $firstPageBaseFields);
        $firstPageRedFlagFields = array_column($pages[0]['sections'][1]['fields'], 'key');
        $this->assertNotEmpty($firstPageRedFlagFields);
        $this->assertStringStartsWith('rf_', $firstPageRedFlagFields[0]);

        foreach (array_slice($pages, 1) as $page) {
            $anchorFields = array_column($page['sections'][0]['fields'], 'key');
            $this->assertSame(['full_name'], $anchorFields);
        }

        foreach ($pages as $page) {
            $width = 0.0;
            foreach ($page['sections'] as $section) {
                foreach ($section['fields'] as $field) {
                    $width += $field['width'];
                }
            }
            $this->assertLessThanOrEqual(928.0, $width, $page['groupTitle'].' part '.$page['part'].' overflowed the page width');
        }
    }

    public function test_detailed_export_rows_resolve_checklist_lifestyle_and_physical_fields(): void
    {
        $this->createAssessment('Ana Bautista', 'Zone 1', '2026-09-11', [
            'red_flags' => ['chest_pain', 'seizure'],
            'past_medical' => ['hypertension'],
            'family_history' => ['none'],
            'tobacco' => 'current',
            'alcohol' => 'light',
            'dietary' => 'yes',
            'physical_activity' => 'yes',
            'height_cm' => 160,
            'weight_kg' => 55,
            'bmi' => null,
            'waist_cm' => 80,
            'systolic' => 130,
            'diastolic' => 85,
            'bp_status' => 'Elevated',
        ]);

        $rows = HealthRecordsRiskAssessment::detailedExportRows();
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame('Yes', $row['rf_chest_pain']);
        $this->assertSame('Yes', $row['rf_seizure']);
        $this->assertSame('', $row['rf_none']);
        $this->assertSame('Yes', $row['pmh_hypertension']);
        $this->assertSame('', $row['pmh_none']);
        // The form's actual "None of the above" choice for Family History
        // must be reflected, not dropped — it is a real recorded answer.
        $this->assertSame('Yes', $row['fh_none']);
        $this->assertSame('', $row['fh_hypertension']);

        $this->assertSame('Current User', $row['lifestyle_tobacco']);
        $this->assertSame('Light (Occasional)', $row['lifestyle_alcohol']);
        $this->assertSame('Yes', $row['lifestyle_dietary']);
        $this->assertSame('Yes', $row['lifestyle_physical_activity']);

        $this->assertSame('160.00', $row['phys_height']);
        $this->assertSame('55.00', $row['phys_weight']);
        $this->assertNotSame('', $row['phys_bmi']);
        $this->assertSame('80.00', $row['phys_waist']);
        $this->assertSame('130/85', $row['phys_blood_pressure']);
        $this->assertSame('Elevated', $row['phys_bp_status']);
    }

    public function test_blank_fields_render_blank_not_dash(): void
    {
        $this->createAssessment('Blank Fields Person', 'Zone 4', '2026-05-01', [
            'red_flags' => [],
            'past_medical' => [],
            'family_history' => [],
            'tobacco' => '',
            'alcohol' => '',
            'dietary' => [],
            'physical_activity' => '',
            'height_cm' => null,
            'weight_kg' => null,
            'bmi' => null,
            'waist_cm' => null,
            'systolic' => null,
            'diastolic' => null,
            'bp_status' => '',
            'visual_no_screening' => false,
            'visual_blurred' => false,
            'visual_blurred_note' => null,
        ]);

        $row = HealthRecordsRiskAssessment::detailedExportRows()[0];

        foreach (['rf_chest_pain', 'rf_none', 'pmh_hypertension', 'pmh_none', 'fh_hypertension', 'fh_none', 'lifestyle_tobacco', 'phys_height', 'phys_bmi', 'phys_blood_pressure', 'visual_has_blurred_vision'] as $key) {
            $this->assertSame('', $row[$key], "Expected blank for {$key}");
            $this->assertStringNotContainsString('—', (string) $row[$key]);
        }
    }

    public function test_export_control_downloads_pdf_reflecting_filters(): void
    {
        $this->createAssessment('Adrian Corporal', 'Zone 2', '2026-09-11');
        $this->createAssessment('Haziel Santos', 'Zone 3', '2026-03-05');

        $this->actingAsStaff(StaffRole::BHW);
        $all = $this->get(route('health-records.risk-assessment.export'));
        $all->assertOk();
        $all->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $all->getContent());
        $this->assertStringContainsString('Adrian Corporal', $all->getContent());
        $this->assertStringContainsString('Haziel Santos', $all->getContent());
        $this->assertStringContainsString('filename=', (string) $all->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', strtolower((string) $all->headers->get('content-disposition')));

        $this->actingAsStaff(StaffRole::BHW);
        $filtered = $this->get(route('health-records.risk-assessment.export', ['zone' => 'Zone 2']));
        $filtered->assertOk();
        $filtered->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $filtered->getContent());
        $this->assertStringContainsString('Adrian Corporal', $filtered->getContent());
        $this->assertStringNotContainsString('Haziel Santos', $filtered->getContent());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAssessment(string $name, string $zone, string $conductedAt, array $overrides = []): RiskAssessment
    {
        $household = Household::factory()->create(['zone' => $zone]);
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => $first,
            'last_name' => $last,
        ]);

        return RiskAssessment::factory()->create(array_merge([
            'resident_id' => $resident->id,
            'conducted_at' => $conductedAt,
        ], $overrides));
    }
}
