<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\DeathRequest;
use App\Models\Household;
use App\Models\Resident;
use App\Support\HealthRecordsDeath;
use App\Support\ResidentVitalStatus;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
        $this->assertTrue(Route::has('health-records.death.residents'));
        $this->assertTrue(Route::has('health-records.death.export'));

        $route = Route::getRoutes()->getByName('health-records.death.index');
        $this->assertNotNull($route);
        $this->assertSame('health-records/death', $route->uri());
        $this->assertSame('health-records/death/residents', Route::getRoutes()->getByName('health-records.death.residents')?->uri());
        $this->assertSame('health-records/death/export', Route::getRoutes()->getByName('health-records.death.export')?->uri());
    }

    public function test_death_listing_does_not_render_resident_selection(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.death.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-death', $html);
        $this->assertStringContainsString('data-death-data-mode="persisted"', $html);
        $this->assertStringNotContainsString('id="lml-hr-death-residents"', $html);
        $this->assertStringNotContainsString('data-hr-death-resident-search', $html);
        $this->assertStringNotContainsString('Select a resident', $html);
        $this->assertStringNotContainsString('data-hr-death-record', $html);
        $this->assertStringNotContainsString('Record death', $html);
        // Export Data now lands on the Report Builder's choices page first
        // (same pattern as Environmental Health and Household Profiling),
        // not a direct download link.
        $this->assertStringContainsString(route('health-records.death.report-builder'), $html);
        $this->assertStringNotContainsString('href="'.route('health-records.death.export'), $html);
        $this->assertStringContainsString('data-death-stat="pending"', $html);
    }

    public function test_record_death_opens_dedicated_resident_selection_page(): void
    {
        $this->seedDeathPickerHousehold();

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.death.residents'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame('death', UiRole::sidebarActiveKey());
        $this->assertStringContainsString('id="lml-hr-death-residents"', $html);
        $this->assertStringContainsString('Select a resident', $html);
        $this->assertStringContainsString(
            'Choose a resident to open or submit a death record for Admin verification.',
            $html
        );
        $this->assertStringNotContainsString('lml-hr-death-residents-heading', $html);
        $this->assertStringNotContainsString('class="lml-hr-death__title"', $html);
        $this->assertStringContainsString('Kristine Reyes', $html);
        $this->assertStringContainsString(
            route('health-records.death.show', ['householdNo' => 'HH-151', 'memberId' => 'MB-002']),
            $html
        );
        $this->assertStringContainsString('Back to Death records page', $html);
        $this->assertStringContainsString(route('health-records.death.index'), $html);
        $this->assertStringNotContainsString('lml-hr-death__residents-hint', $html);
        $this->assertStringContainsString('lml-hr-death__open-btn', $html);
        $this->assertStringContainsString('data-hr-death-resident-filters', $html);
        $this->assertStringContainsString('placeholder="Search resident name"', $html);
        $this->assertStringContainsString('All Zones', $html);
        $this->assertStringContainsString('All Statuses', $html);
        $this->assertStringContainsString('Pending verification', $html);
        $this->assertStringNotContainsString('Reset Filters', $html);
        $this->assertStringNotContainsString('Clear Filters', $html);
    }

    public function test_resident_picker_distinguishes_same_name_catalog_members(): void
    {
        $this->seedDeathPickerHousehold();

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
        $this->assertSame('05/04/1991', $byId['MB-001']['birthday_display']);
        $this->assertSame('08/12/1991', $byId['MB-002']['birthday_display']);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.death.residents'))
            ->getContent();
        $tbody = $this->residentTbodyHtml($html);

        $this->assertStringContainsString('MB-001', $html);
        $this->assertStringContainsString('MB-002', $html);
        $this->assertStringNotContainsString('Born 05/04/1991', $tbody);
        $this->assertStringNotContainsString('Born 08/12/1991', $tbody);
        $this->assertStringNotContainsString('lml-hr-death__resident-meta', $tbody);
        $this->assertStringContainsString('lml-hr-death__record-row', $tbody);
        $this->assertStringContainsString(
            'aria-label="Record death for Kristine Reyes, MB-001"',
            $tbody
        );
        $this->assertStringContainsString(
            'aria-label="Record death for Kristine Reyes, MB-002"',
            $tbody
        );
    }

    public function test_resident_selection_filters_include_search_zone_and_status_without_reset(): void
    {
        $this->seedDeathPickerHousehold();
        $this->seedZoneHouseholds();

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.death.residents'))
            ->getContent();

        $searchPos = strpos($html, 'data-hr-death-resident-search');
        $zonePos = strpos($html, 'data-hr-death-resident-zone');
        $statusPos = strpos($html, 'data-hr-death-resident-status');

        $this->assertNotFalse($searchPos);
        $this->assertNotFalse($zonePos);
        $this->assertNotFalse($statusPos);
        $this->assertLessThan($zonePos, $searchPos);
        $this->assertLessThan($statusPos, $zonePos);

        foreach (['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5'] as $zone) {
            $this->assertStringContainsString('>'.$zone.'</option>', $html);
        }

        $this->assertStringContainsString('data-status-label=', $html);
        $this->assertStringContainsString('data-zone=', $html);
        $this->assertStringNotContainsString('Reset Filters', $html);
        $this->assertStringNotContainsString('Clear Filters', $html);
        $this->assertStringNotContainsString('data-hr-death-resident-reset', $html);
        $this->assertMatchesRegularExpression(
            '/<th scope="col">Resident<\/th>\s*<th scope="col">Household<\/th>\s*<th scope="col">Zone<\/th>\s*<th scope="col">Status<\/th>\s*<th scope="col">Action<\/th>/u',
            $html
        );
    }

    public function test_resident_action_column_uses_record_death_or_open_from_existing_state(): void
    {
        $this->seedDeathPickerHousehold();
        $this->seedAdrianResident();

        $this->createListingRequest(
            'HH-153',
            'MB-005',
            'Adrian Corporal',
            'Male',
            'SILOS',
            'Zone 1',
            now()->subDay()
        );

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.death.residents'))
            ->getContent();
        $tbody = $this->residentTbodyHtml($html);

        $this->assertStringContainsString('<th scope="col">Action</th>', $html);
        $this->assertStringContainsString('data-hr-death-resident-action="record"', $tbody);
        $this->assertStringContainsString('data-hr-death-resident-action="open"', $tbody);

        $kristine = $this->residentRowHtml($tbody, 'MB-002');
        $this->assertStringContainsString('Record Death', $kristine);
        $this->assertStringNotContainsString('data-hr-death-resident-action="open"', $kristine);
        $this->assertStringContainsString(
            route('health-records.death.show', ['householdNo' => 'HH-151', 'memberId' => 'MB-002']),
            $kristine
        );

        $adrian = $this->residentRowHtml($tbody, 'MB-005');
        $this->assertStringContainsString('data-hr-death-resident-action="open"', $adrian);
        $this->assertStringContainsString('Open', $adrian);
        $this->assertStringNotContainsString('Record Death', $adrian);
        $this->assertStringContainsString(
            route('health-records.death.show', ['householdNo' => 'HH-153', 'memberId' => 'MB-005']),
            $adrian
        );
    }

    public function test_empty_collection_still_derives_zero_counts_and_empty_markup_exists(): void
    {
        $emptySummary = HealthRecordsDeath::summaryCounts([]);
        $this->assertSame(['total' => 0, 'female' => 0, 'male' => 0, 'pending' => 0], $emptySummary);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.death.index'))
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

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.death.index'))
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
            $this->filterRow('Haziel Santos', 'Female', 'Zone 3', 'Stroke', '2025-07-04'),
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
        $this->assertSame(
            ['Jacob Magistrado'],
            $this->names(HealthRecordsDeath::filterRows($rows, ['month' => '01']))
        );
        $this->assertSame(
            ['Kristine Reyes'],
            $this->names(HealthRecordsDeath::filterRows($rows, ['year' => '2026', 'month' => '03']))
        );
    }

    public function test_filtered_summary_counts_update_with_filters(): void
    {
        $rows = [
            array_merge($this->filterRow('Kristine Reyes', 'Female', 'Zone 1', 'Kidney Failure', '2026-03-12'), [
                'status' => DeathRequest::STATUS_APPROVED,
            ]),
            array_merge($this->filterRow('Jacob Magistrado', 'Male', 'Zone 2', 'Accident', '2026-01-30'), [
                'status' => DeathRequest::STATUS_APPROVED,
            ]),
            array_merge($this->filterRow('Haziel Santos', 'Female', 'Zone 3', 'Stroke', '2025-01-04'), [
                'status' => DeathRequest::STATUS_PENDING,
            ]),
        ];

        $allSummary = HealthRecordsDeath::summaryCounts($rows);
        $this->assertSame(
            ['total' => 2, 'female' => 1, 'male' => 1, 'pending' => 1],
            $allSummary
        );

        $femaleSummary = HealthRecordsDeath::summaryCounts(
            HealthRecordsDeath::filterRows($rows, ['sex' => 'female'])
        );
        $this->assertSame(
            ['total' => 1, 'female' => 1, 'male' => 0, 'pending' => 1],
            $femaleSummary
        );
    }

    public function test_table_headers_render(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.death.index'));

        $response->assertOk();
        $html = $response->getContent();

        preg_match_all('/<th scope="col">([^<]+)<\/th>/u', $html, $headerMatches);
        $this->assertContains('Name', $headerMatches[1]);
        $this->assertContains('Age', $headerMatches[1]);
        $this->assertContains('Birthday', $headerMatches[1]);
        $this->assertContains('Sex', $headerMatches[1]);
        $this->assertContains('Cause of Death', $headerMatches[1]);
        $this->assertContains('Status', $headerMatches[1]);
        $this->assertStringContainsString('data-hr-death-month', $html);
        $this->assertStringContainsString('<caption class="visually-hidden">', $html);
    }

    public function test_listing_rows_show_name_only_and_open_from_entire_row(): void
    {
        $this->createListingRequest(
            'HH-153',
            'MB-005',
            'Adrian Corporal',
            'Male',
            'SILOS',
            'Zone 2',
            now()->subDay()
        );

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.death.index'))
            ->getContent();
        $tbody = $this->listingTbodyHtml($html);

        $this->assertStringContainsString('Adrian Corporal', $tbody);
        $this->assertStringNotContainsString('Male · MB-005', $tbody);
        $this->assertStringNotContainsString('lml-hr-death__resident-meta', $tbody);
        $this->assertStringContainsString('lml-hr-death__record-row', $tbody);
        $this->assertStringContainsString(
            'aria-label="Open death record for Adrian Corporal"',
            $tbody
        );
        $this->assertStringContainsString(
            route('health-records.death.show', ['householdNo' => 'HH-153', 'memberId' => 'MB-005']),
            $tbody
        );
    }

    public function test_death_record_back_link_returns_to_death_listing(): void
    {
        $this->seedDeathPickerHousehold();

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.death.show', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]))
            ->getContent();

        $this->assertStringContainsString('Back to Death records page', $html);
        $this->assertStringContainsString(
            'href="'.e(route('health-records.death.index')).'"',
            $html
        );
        $this->assertStringNotContainsString(
            route('health-records.death.residents'),
            $html
        );
    }

    public function test_death_record_view_uses_profile_grid_and_spaced_submitted_details(): void
    {
        $this->seedAdrianResident();

        $this->createListingRequest(
            'HH-153',
            'MB-005',
            'Adrian Corporal',
            'Male',
            'SILOS',
            'Zone 2',
            now()->subDay()
        );

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.death.show', [
                'householdNo' => 'HH-153',
                'memberId' => 'MB-005',
            ]))
            ->getContent();

        $this->assertStringContainsString('lml-hr-death-form__meta--profile', $html);
        $this->assertStringContainsString('lml-hr-death-form__meta-col', $html);
        $this->assertStringContainsString('lml-hr-death-form__meta-list', $html);
        $this->assertStringContainsString('lml-hr-death-form__profile-head', $html);
        $this->assertStringContainsString('Submitted details', $html);
        $this->assertStringContainsString('lml-hr-death--record', $html);
        $this->assertStringContainsString('Registry Number', $html);
        $this->assertStringNotContainsString('Certificate No.', $html);
        $this->assertStringNotContainsString('Death Certificate No.', $html);
    }

    public function test_death_record_profile_fields_use_three_three_two_column_distribution(): void
    {
        $this->seedAdrianResident();

        $this->createListingRequest(
            'HH-153',
            'MB-005',
            'Adrian Corporal',
            'Male',
            'SILOS',
            'Zone 2',
            now()->subDay()
        );

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.death.show', [
                'householdNo' => 'HH-153',
                'memberId' => 'MB-005',
            ]))
            ->getContent();

        $profile = $this->deathProfileMetaHtml($html);
        $col1 = $this->deathProfileColumnHtml($profile, '1');
        $col2 = $this->deathProfileColumnHtml($profile, '2');
        $col3 = $this->deathProfileColumnHtml($profile, '3');

        $this->assertSame(3, substr_count($col1, '<dt>'));
        $this->assertSame(3, substr_count($col2, '<dt>'));
        $this->assertSame(2, substr_count($col3, '<dt>'));
        $this->assertSame(8, substr_count($profile, '<dt>'));

        $this->assertStringContainsString('<dt>Member ID</dt>', $col1);
        $this->assertStringContainsString('<dt>Sex</dt>', $col1);
        $this->assertStringContainsString('<dt>Date of Birth</dt>', $col1);
        $this->assertStringNotContainsString('<dt>Zone</dt>', $col1);

        $this->assertStringContainsString('<dt>Relationship</dt>', $col2);
        $this->assertStringContainsString('<dt>Age</dt>', $col2);
        $this->assertStringContainsString('<dt>Household</dt>', $col2);

        $this->assertStringContainsString('<dt>Address</dt>', $col3);
        $this->assertStringContainsString('<dt>Zone</dt>', $col3);
        $this->assertLessThan(
            strpos($col3, '<dt>Zone</dt>'),
            strpos($col3, '<dt>Address</dt>')
        );
    }

    public function test_death_listing_does_not_render_instructional_description(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.death.index'))
            ->getContent();

        $this->assertStringNotContainsString('lml-hr-death__description', $html);
        $this->assertStringNotContainsString('Admin verification is required', $html);
    }

    public function test_export_control_downloads_verified_only_pdf(): void
    {
        $adrian = $this->createListingRequest('HH-153', 'MB-005', 'Adrian Corporal', 'Male', 'Accident', 'Zone 2', now()->subDay());
        $adrian->update(['status' => DeathRequest::STATUS_APPROVED]);
        $haziel = $this->createListingRequest('HH-151', 'MB-002', 'Haziel Santos', 'Female', 'Stroke', 'Zone 3', now()->subDays(3));
        $haziel->update(['status' => DeathRequest::STATUS_APPROVED]);
        // Still pending — "Only the verified residents" excludes this one.
        $this->createListingRequest('HH-151', 'MB-001', 'Kristine Reyes', 'Male', 'Cardiac arrest', 'Zone 1', now()->subDays(2));

        $this->actingAsStaff(StaffRole::BHW);
        $listing = $this->get(route('health-records.death.index'));
        $listing->assertOk();
        $html = $listing->getContent();
        $this->assertStringContainsString('data-hr-death-export', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-hr-death-export[^>]*\bdisabled\b/u',
            $html
        );

        $allRows = HealthRecordsDeath::verifiedExportRows();
        $this->assertSame(['Adrian Corporal', 'Haziel Santos'], $this->names($allRows));

        $this->actingAsStaff(StaffRole::BHW);
        $all = $this->get(route('health-records.death.export'));
        $all->assertOk();
        $all->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $all->getContent());
        $this->assertStringContainsString('Adrian Corporal', $all->getContent());
        $this->assertStringContainsString('Haziel Santos', $all->getContent());
        $this->assertStringNotContainsString('Kristine Reyes', $all->getContent());
        $this->assertStringContainsString('filename=', (string) $all->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', strtolower((string) $all->headers->get('content-disposition')));

        $zoneRows = HealthRecordsDeath::verifiedExportRows('Zone 2');
        $this->assertSame(['Adrian Corporal'], $this->names($zoneRows));

        $this->actingAsStaff(StaffRole::BHW);
        $filtered = $this->get(route('health-records.death.export', ['zone' => 'Zone 2']));
        $filtered->assertOk();
        $filtered->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $filtered->getContent());
        $this->assertStringContainsString('Adrian Corporal', $filtered->getContent());
        $this->assertStringNotContainsString('Haziel Santos', $filtered->getContent());
    }

    public function test_death_sidebar_is_active_and_health_records_expanded(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.death.index'));

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
        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-sidebar-collapse-requests"[^>]*\bis-open\b/u',
            $html
        );
    }

    public function test_remains_independent_of_household_profiling_death(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('household-profiling.members.death.store', [
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

    public function test_death_listing_paginates_seven_records_per_page(): void
    {
        $names = [
            'Adrian Corporal',
            'Kristine Reyes',
            'Haziel Santos',
            'Jacob Magistrado',
            'Juan dela Cruz',
            'Maria Santos',
            'Rosa Lim',
            'Carlo Evangelista',
        ];

        foreach ($names as $index => $name) {
            $this->createListingRequest(
                'HH-15'.($index + 1),
                'MB-00'.($index + 1),
                $name,
                $index % 2 === 0 ? 'Male' : 'Female',
                'Cause '.$index,
                'Zone '.(($index % 5) + 1),
                now()->subMinutes($index + 1)
            );
        }

        $this->actingAsStaff(StaffRole::BHW);
        $page1 = $this->get(route('health-records.death.index'));
        $page1->assertOk();
        $html1 = $page1->getContent();
        $tbody1 = $this->listingTbodyHtml($html1);

        $this->assertSame(7, $this->countListingRows($html1));
        $this->assertStringContainsString('data-hr-death-pagination', $html1);
        $this->assertStringContainsString('Adrian Corporal', $tbody1);
        $this->assertStringContainsString('Rosa Lim', $tbody1);
        $this->assertStringNotContainsString('Carlo Evangelista', $tbody1);
        $this->assertTrue(strpos($tbody1, 'Adrian Corporal') < strpos($tbody1, 'Rosa Lim'));

        $this->actingAsStaff(StaffRole::BHW);
        $page2 = $this->get(route('health-records.death.index', ['page' => 2]));
        $page2->assertOk();
        $html2 = $page2->getContent();
        $tbody2 = $this->listingTbodyHtml($html2);

        $this->assertSame(1, $this->countListingRows($html2));
        $this->assertStringContainsString('Carlo Evangelista', $tbody2);
        $this->assertStringNotContainsString('Adrian Corporal', $tbody2);
    }

    public function test_death_listing_filters_persist_across_pagination(): void
    {
        for ($index = 0; $index < 8; $index++) {
            $this->createListingRequest(
                'HH-16'.($index + 1),
                'MB-01'.($index + 1),
                'Female Resident '.$index,
                'Female',
                'Stroke',
                'Zone 3',
                now()->subMinutes($index + 1)
            );
        }
        $this->createListingRequest('HH-153', 'MB-005', 'Adrian Corporal', 'Male', 'SILOS', 'Zone 2', now()->subMinutes(20));

        $this->actingAsStaff(StaffRole::BHW);
        $page1 = $this->get(route('health-records.death.index', ['sex' => 'female']));
        $page1->assertOk();
        $html1 = $page1->getContent();

        $this->assertSame(7, $this->countListingRows($html1));
        $this->assertStringNotContainsString('Adrian Corporal', $this->listingTbodyHtml($html1));
        $this->assertStringContainsString('name="sex"', $html1);
        $this->assertStringContainsString('value="female" selected', $html1);
        $this->assertStringContainsString('sex=female', $html1);

        $this->actingAsStaff(StaffRole::BHW);
        $page2 = $this->get(route('health-records.death.index', ['sex' => 'female', 'page' => 2]));
        $page2->assertOk();
        $html2 = $page2->getContent();
        $this->assertSame(1, $this->countListingRows($html2));
        $this->assertStringContainsString('Female Resident 7', $this->listingTbodyHtml($html2));
        $this->assertStringNotContainsString('Adrian Corporal', $this->listingTbodyHtml($html2));
        $this->assertStringContainsString('sex=female', $html2);
    }

    public function test_pdf_export_is_not_limited_to_current_pagination_page(): void
    {
        for ($index = 0; $index < 8; $index++) {
            $this->createListingRequest(
                'HH-17'.($index + 1),
                'MB-02'.($index + 1),
                'Export Resident '.$index,
                'Male',
                'Accident',
                'Zone 1',
                now()->subMinutes($index + 1)
            )->update(['status' => DeathRequest::STATUS_APPROVED]);
        }

        $this->actingAsStaff(StaffRole::BHW);
        $listing = $this->get(route('health-records.death.index'));
        $this->assertSame(7, $this->countListingRows($listing->getContent()));

        $this->actingAsStaff(StaffRole::BHW);
        $pdf = $this->get(route('health-records.death.export'));
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('Export Resident 0', $pdf->getContent());
        $this->assertStringContainsString('Export Resident 7', $pdf->getContent());

        $exported = HealthRecordsDeath::verifiedExportRows();
        $this->assertCount(8, $exported);
        $this->assertSame('Export Resident 0', $exported[0]['full_name']);
        $this->assertSame('Export Resident 7', $exported[7]['full_name']);
    }

    public function test_summary_counts_remain_global_while_listing_is_paginated(): void
    {
        for ($index = 0; $index < 8; $index++) {
            $this->createApprovedRequest(
                'HH-18'.($index + 1),
                'MB-03'.($index + 1),
                $index < 3 ? 'Female Resident '.$index : 'Male Resident '.$index,
                $index < 3 ? 'Female' : 'Male',
                'Cause '.$index
            );
        }

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.death.index'))
            ->getContent();

        $this->assertSame(7, $this->countListingRows($html));
        $this->assertMatchesRegularExpression(
            '/data-death-stat="total"[^>]*>\s*8\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-death-stat="female"[^>]*>\s*3\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-death-stat="male"[^>]*>\s*5\s*</u',
            $html
        );
    }

    private function countListingRows(string $html): int
    {
        return substr_count($this->listingTbodyHtml($html), 'data-hr-death-row');
    }

    private function listingTbodyHtml(string $html): string
    {
        if (preg_match('/<tbody data-hr-death-tbody>(.*?)<\/tbody>/su', $html, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function residentTbodyHtml(string $html): string
    {
        if (preg_match('/<tbody data-hr-death-resident-tbody>(.*?)<\/tbody>/su', $html, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function residentRowHtml(string $tbody, string $memberId): string
    {
        if (! preg_match_all('/<tr\b[^>]*data-hr-death-resident-row[^>]*>.*?<\/tr>/su', $tbody, $rows)) {
            return '';
        }

        foreach ($rows[0] as $row) {
            if (str_contains($row, '/'.$memberId.'"') || str_contains($row, ', '.$memberId.'"')) {
                return $row;
            }
        }

        return '';
    }

    private function deathProfileMetaHtml(string $html): string
    {
        if (preg_match(
            '/(<div class="lml-hr-death-form__meta-col" data-death-profile-col="1".*?<div class="lml-hr-death-form__meta-col" data-death-profile-col="3".*?<\/dl>\s*<\/div>)/su',
            $html,
            $matches
        )) {
            return $matches[1];
        }

        return '';
    }

    private function deathProfileColumnHtml(string $profileHtml, string $column): string
    {
        $nextColumn = (string) ((int) $column + 1);

        if ((int) $column < 3) {
            if (preg_match(
                '/data-death-profile-col="'.$column.'"[^>]*>(.*?)(?=data-death-profile-col="'.$nextColumn.'")/su',
                $profileHtml,
                $matches
            )) {
                return $matches[1];
            }

            return '';
        }

        if (preg_match(
            '/data-death-profile-col="'.$column.'"[^>]*>(.*)$/su',
            $profileHtml,
            $matches
        )) {
            return $matches[1];
        }

        return '';
    }

    private function createListingRequest(
        string $householdNo,
        string $memberId,
        string $name,
        string $sex,
        string $cause,
        string $zone,
        ?\DateTimeInterface $submittedAt = null
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
            'date_of_death' => '2026-07-12',
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
            'submitted_at' => $submittedAt ?? now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filterRow(string $name, string $sex, string $zone, string $cause, string $iso): array
    {
        $year = substr($iso, 0, 4);
        $month = substr($iso, 5, 2);

        return [
            'full_name' => $name,
            'member_id' => '',
            'sex' => $sex,
            'sex_filter' => strtolower($sex) === 'female' ? 'female' : 'male',
            'zone' => $zone,
            'cause_of_death' => $cause,
            'year' => $year,
            'month' => $month,
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

    private function seedDeathPickerHousehold(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-151',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'address' => 'Layuan St., Brgy. La Medalla',
        ]);

        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-001',
            'last_name' => 'Reyes',
            'first_name' => 'Kristine',
            'middle_name' => null,
            'relation' => 'Head',
            'sex' => 'Male',
            'birthday' => '1991-05-04',
        ]);

        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-002',
            'last_name' => 'Reyes',
            'first_name' => 'Kristine',
            'middle_name' => null,
            'relation' => 'Wife',
            'sex' => 'Female',
            'birthday' => '1991-08-12',
        ]);
    }

    private function seedZoneHouseholds(): void
    {
        foreach (['Zone 1', 'Zone 3', 'Zone 4', 'Zone 5'] as $index => $zone) {
            Household::factory()->create([
                'household_no' => 'HH-9'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'zone' => $zone,
            ]);
        }
    }

    private function seedAdrianResident(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-153',
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
            'address' => 'Layuan St., Brgy. La Medalla',
        ]);

        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-005',
            'last_name' => 'Corporal',
            'first_name' => 'Adrian',
            'middle_name' => null,
            'relation' => 'Head',
            'sex' => 'Male',
            'birthday' => '1990-01-01',
        ]);
    }
}
