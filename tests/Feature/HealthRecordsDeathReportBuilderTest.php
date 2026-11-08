<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\DeathRequest;
use App\Support\HealthRecordsDeath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Health Records → Death Report Builder — choices before export, same
 * pattern as Environmental Health's and Household Profiling's Report
 * Builder pages. Only admin-verified (approved) death records are ever
 * exported or previewed.
 */
class HealthRecordsDeathReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_builder_page_loads_with_filters_and_verified_only_preview(): void
    {
        $approved = $this->createDeathRequest('HH-970', 'MB-001', 'Grace Ramos', 'Female', 'Stroke', 'Zone 8', '2026-09-10');
        $approved->update(['status' => DeathRequest::STATUS_APPROVED]);
        $this->createDeathRequest('HH-971', 'MB-002', 'Pending Person', 'Male', 'Accident', 'Zone 8', '2026-09-11');

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.death.report-builder'));

        $response->assertOk();
        $response->assertSee('Death Records Report', false);
        $response->assertSee('data-hrd-report', false);
        $response->assertSee('data-hrd-report-zone', false);
        $response->assertSee('data-hrd-report-year', false);
        $response->assertSee('data-hrd-report-month', false);
        $response->assertSee('data-hrd-export', false);
        $response->assertSee('Export PDF', false);
        $response->assertSee('data-hrd-report-rows', false);
        $response->assertSee('Grace Ramos', false);
        $response->assertDontSee('Pending Person', false);
    }

    public function test_dashboard_export_button_lands_on_report_builder_first(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.death.index'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString(route('health-records.death.report-builder'), $html);
        $this->assertStringNotContainsString('href="'.route('health-records.death.export'), $html);
    }

    public function test_verified_export_rows_excludes_pending_and_rejected(): void
    {
        $approved = $this->createDeathRequest('HH-972', 'MB-003', 'Verified One', 'Male', 'Cardiac Arrest', 'Zone 9', '2026-09-05');
        $approved->update(['status' => DeathRequest::STATUS_APPROVED]);
        $this->createDeathRequest('HH-973', 'MB-004', 'Still Pending', 'Female', 'Stroke', 'Zone 9', '2026-09-06');
        $rejected = $this->createDeathRequest('HH-974', 'MB-005', 'Was Rejected', 'Male', 'Accident', 'Zone 9', '2026-09-07');
        $rejected->update(['status' => DeathRequest::STATUS_REJECTED]);

        $rows = HealthRecordsDeath::verifiedExportRows();

        $this->assertSame(['Verified One'], array_column($rows, 'full_name'));
    }

    public function test_verified_export_rows_respects_zone_year_and_month_filters(): void
    {
        $zoneA = $this->createDeathRequest('HH-980', 'MB-006', 'Zone A Person', 'Female', 'Stroke', 'Zone A', '2026-03-15');
        $zoneA->update(['status' => DeathRequest::STATUS_APPROVED]);
        $zoneB = $this->createDeathRequest('HH-981', 'MB-007', 'Zone B Person', 'Male', 'Accident', 'Zone B', '2026-09-15');
        $zoneB->update(['status' => DeathRequest::STATUS_APPROVED]);

        $this->assertSame(['Zone A Person'], array_column(HealthRecordsDeath::verifiedExportRows('Zone A'), 'full_name'));
        $this->assertSame(['Zone B Person'], array_column(HealthRecordsDeath::verifiedExportRows(null, '2026', '09'), 'full_name'));
        $this->assertSame(['Zone A Person'], array_column(HealthRecordsDeath::verifiedExportRows(null, '2026', '03'), 'full_name'));
        $this->assertCount(2, HealthRecordsDeath::verifiedExportRows(null, '2026'));
    }

    public function test_period_label_reflects_active_filters(): void
    {
        $this->assertSame('All Time', HealthRecordsDeath::periodLabel('all', 'all'));
        $this->assertSame('Year 2026', HealthRecordsDeath::periodLabel('2026', 'all'));
        $this->assertSame('September 2026', HealthRecordsDeath::periodLabel('2026', '09'));
    }

    private function createDeathRequest(
        string $householdNo,
        string $memberId,
        string $name,
        string $sex,
        string $cause,
        string $zone,
        string $dateOfDeath
    ): DeathRequest {
        return DeathRequest::query()->create([
            'household_no' => $householdNo,
            'member_id' => $memberId,
            'resident_name' => $name,
            'resident_sex' => $sex,
            'resident_age' => 35,
            'zone' => $zone,
            'household_display_no' => str_replace('-', ' ', $householdNo),
            'address' => 'Layuan St., Brgy. La Medalla',
            'cause_of_death' => $cause,
            'date_of_death' => $dateOfDeath,
            'registry_no' => '2026-00123',
            'certificate_no' => 'DC-2026-00451',
            'certificate_disk' => 'death_certificates',
            'certificate_path' => $householdNo.'/'.$memberId.'/1/file.pdf',
            'certificate_original_name' => 'certificate.pdf',
            'certificate_mime' => 'application/pdf',
            'certificate_size' => 1200,
            'certificate_extension' => 'pdf',
            'status' => DeathRequest::STATUS_PENDING,
            'submitted_by_name' => 'Sarah',
            'submitted_by_role' => 'bhw',
            'submitted_at' => now(),
        ]);
    }
}
