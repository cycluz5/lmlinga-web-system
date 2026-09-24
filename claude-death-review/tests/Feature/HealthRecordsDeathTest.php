<?php

namespace Tests\Feature;

use App\Models\DeathRequest;
use App\Support\HealthRecordsDeath;
use App\Support\ResidentVitalStatus;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthRecordsDeathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('death_certificates');
    }

    /** @return list<string> */
    private function names(array $rows): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) $row['full_name'],
            $rows
        ));
    }

    public function test_death_route_resolves(): void
    {
        $this->assertTrue(Route::has('health-records.death.index'));
        $this->assertFalse(Route::has('health-records.death'));
        $this->assertTrue(Route::has('health-records.death.show'));
        $this->assertTrue(Route::has('health-records.death.store'));

        $route = Route::getRoutes()->getByName('health-records.death.index');
        $this->assertNotNull($route);
        $this->assertSame('health-records/death', $route->uri());
    }

    public function test_death_page_requires_selected_resident_to_open_form(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.death.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-death', $html);
        $this->assertStringContainsString('data-death-data-mode="persisted"', $html);
        $this->assertStringContainsString('id="lml-hr-death-residents"', $html);
        $this->assertStringContainsString('Select a resident', $html);
        $this->assertStringContainsString('Kristine Reyes', $html);
        $this->assertStringContainsString(
            route('health-records.death.show', ['householdNo' => 'HH-151', 'memberId' => 'MB-002']),
            $html
        );
    }

    public function test_resident_picker_distinguishes_same_name_catalog_members(): void
    {
        $candidates = HealthRecordsDeath::residentCandidates();
        $byId = [];
        foreach ($candidates as $row) {
            $byId[(string) $row['member_id']] = $row;
        }

        $this->assertArrayHasKey('MB-001', $byId);
        $this->assertArrayHasKey('MB-002', $byId);
        $this->assertSame('HH-151', $byId['MB-001']['household_no']);
        $this->assertSame('HH-151', $byId['MB-002']['household_no']);
        $this->assertSame('Kristine Reyes', $byId['MB-001']['full_name']);
        $this->assertSame('Kristine Reyes', $byId['MB-002']['full_name']);
        $this->assertSame('Male', $byId['MB-001']['sex']);
        $this->assertSame('Female', $byId['MB-002']['sex']);
        $this->assertSame('Head', $byId['MB-001']['relationship']);
        $this->assertSame('Wife', $byId['MB-002']['relationship']);
        $this->assertSame('May 4, 1991', $byId['MB-001']['birthday_display']);
        $this->assertSame('August 12, 1991', $byId['MB-002']['birthday_display']);

        $html = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.death.index'))
            ->getContent();

        $this->assertStringContainsString('MB-001', $html);
        $this->assertStringContainsString('MB-002', $html);
        $this->assertStringContainsString('Born May 4, 1991', $html);
        $this->assertStringContainsString('Born August 12, 1991', $html);
        $this->assertStringContainsString(
            'aria-label="Record death for Kristine Reyes, Male, Head, MB-001"',
            $html
        );
        $this->assertStringContainsString(
            'aria-label="Record death for Kristine Reyes, Female, Wife, MB-002"',
            $html
        );
    }

    public function test_empty_collection_still_derives_zero_counts_and_empty_markup_exists(): void
    {
        $emptySummary = HealthRecordsDeath::summaryCounts([]);
        $this->assertSame(['total' => 0, 'female' => 0, 'male' => 0], $emptySummary);

        $html = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.death.index'))
            ->getContent();

        $this->assertStringContainsString('data-hr-death-empty', $html);
        $this->assertStringContainsString('No death records have been recorded yet.', $html);
        $this->assertMatchesRegularExpression(
            '/data-death-stat="total"[^>]*>\s*0\s*</u',
            $html
        );
    }

    public function test_approved_records_render_in_listing_and_summary(): void
    {
        $this->createApprovedRequest('HH-151', 'MB-002', 'Kristine Reyes', 'Female', 'Cardiac arrest');

        $rows = HealthRecordsDeath::listingRows();
        $summary = HealthRecordsDeath::summaryCounts(
            array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['status'] === DeathRequest::STATUS_APPROVED
            ))
        );

        $this->assertCount(1, $rows);
        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['female']);
        $this->assertSame(0, $summary['male']);

        $html = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.death.index'))
            ->getContent();

        $this->assertStringContainsString('Kristine Reyes', $html);
        $this->assertStringContainsString('Cardiac arrest', $html);
        $this->assertStringContainsString('Approved', $html);
        $this->assertMatchesRegularExpression(
            '/data-death-stat="total"[^>]*>\s*1\s*</u',
            $html
        );
    }

    public function test_search_zone_cause_sex_and_year_filters_match_rows(): void
    {
        $rows = [
            $this->filterRow('Kristine Reyes', 'Female', 'Zone 1', 'Kidney Failure', '2026-03-12'),
            $this->filterRow('Jacob Magistrado', 'Male', 'Zone 2', 'Accident', '2026-01-30'),
            $this->filterRow('Haziel Santos', 'Female', 'Zone 3', 'Stroke', '2025-01-04'),
        ];

        $this->assertSame(
            ['Kristine Reyes'],
            $this->names(HealthRecordsDeath::filterRows($rows, ['search' => 'Kristine']))
        );
        $this->assertSame(
            ['Kristine Reyes', 'Haziel Santos'],
            $this->names(HealthRecordsDeath::filterRows($rows, ['sex' => 'female']))
        );
        $this->assertSame(
            ['Jacob Magistrado'],
            $this->names(HealthRecordsDeath::filterRows($rows, ['zone' => 'Zone 2']))
        );
        $this->assertSame(
            ['Haziel Santos'],
            $this->names(HealthRecordsDeath::filterRows($rows, ['year' => '2025']))
        );
    }

    public function test_table_headers_render(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.death.index'));

        $response->assertOk();
        $html = $response->getContent();

        preg_match_all('/<th scope="col">([^<]+)<\/th>/u', $html, $headerMatches);
        $this->assertContains('Full Name', $headerMatches[1]);
        $this->assertContains('Cause of Death', $headerMatches[1]);
        $this->assertContains('Status', $headerMatches[1]);
        $this->assertStringContainsString('<caption class="visually-hidden">', $html);
    }

    public function test_export_control_is_present_and_disabled(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.death.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-death-export', $html);
        $this->assertMatchesRegularExpression(
            '/data-hr-death-export[^>]*\bdisabled\b/u',
            $html
        );
        $this->assertFalse(Route::has('health-records.death.export'));
    }

    public function test_death_sidebar_is_active_and_health_records_expanded(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.death.index'));

        $response->assertOk();
        $this->assertSame('death', UiRole::sidebarActiveKey());
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/id="lml-sidebar-collapse-health-records"[^>]*\bis-open\b/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink--active[^>]*aria-current="page"[^>]*>[\s\S]*>Death</u',
            $html
        );
        $this->assertStringNotContainsString('id="lml-sidebar-collapse-requests"', $html);
    }

    public function test_remains_independent_of_household_profiling_death(): void
    {
        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->post(route('household-profiling.members.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), [
                'cause_of_death' => 'Pneumonia',
                'date_of_death' => '2026-03-15',
            ])
            ->assertRedirect();

        $listing = $this->get(route('health-records.death.index'));
        $listing->assertOk();
        $html = $listing->getContent();

        $this->assertStringNotContainsString('Pneumonia', $html);
        $this->assertSame(0, DeathRequest::query()->count());
        $this->assertFalse(ResidentVitalStatus::isDeceased('HH-151', 'MB-002'));
    }

    /**
     * @return array<string, mixed>
     */
    private function filterRow(string $name, string $sex, string $zone, string $cause, string $iso): array
    {
        $year = substr($iso, 0, 4);

        return [
            'full_name' => $name,
            'sex' => $sex,
            'sex_filter' => strtolower($sex) === 'female' ? 'female' : 'male',
            'zone' => $zone,
            'cause_of_death' => $cause,
            'year' => $year,
        ];
    }

    private function createApprovedRequest(
        string $householdNo,
        string $memberId,
        string $name,
        string $sex,
        string $cause
    ): DeathRequest {
        $request = DeathRequest::query()->create([
            'household_no' => $householdNo,
            'member_id' => $memberId,
            'resident_name' => $name,
            'resident_sex' => $sex,
            'resident_age' => 35,
            'zone' => 'Zone 2',
            'household_display_no' => 'HH 151',
            'address' => 'Layuan St., Brgy. La Medalla',
            'cause_of_death' => $cause,
            'date_of_death' => '2026-07-12',
            'registry_no' => '2026-00123',
            'certificate_no' => 'DC-2026-00451',
            'certificate_disk' => 'death_certificates',
            'certificate_path' => 'HH-151/MB-002/1/file.pdf',
            'certificate_original_name' => 'certificate.pdf',
            'certificate_mime' => 'application/pdf',
            'certificate_size' => 1200,
            'certificate_extension' => 'pdf',
            'status' => DeathRequest::STATUS_APPROVED,
            'submitted_by_name' => 'Sarah',
            'submitted_by_role' => 'bhw',
            'submitted_at' => now(),
            'reviewed_by_name' => 'Admin User',
            'reviewed_by_role' => 'admin',
            'reviewed_at' => now(),
        ]);

        ResidentVitalStatus::markDeceased($request);

        return $request;
    }
}
