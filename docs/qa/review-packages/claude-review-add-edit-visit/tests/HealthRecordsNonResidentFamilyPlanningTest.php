<?php

namespace Tests\Feature;

use App\Support\HealthRecordsNonResidentFamilyPlanning;
use App\Support\UiRole;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Health Records → Family Planning → Non-Resident / unregistered clients.
 */
class HealthRecordsNonResidentFamilyPlanningTest extends TestCase
{
    public function test_non_resident_routes_resolve(): void
    {
        $names = [
            'health-records.family-planning.non-residents.index',
            'health-records.family-planning.non-residents.create',
            'health-records.family-planning.non-residents.show',
            'health-records.family-planning.non-residents.visits.create',
            'health-records.family-planning.non-residents.visits.edit',
        ];

        foreach ($names as $name) {
            $this->assertTrue(Route::has($name), "Missing route: {$name}");
        }

        $index = Route::getRoutes()->getByName('health-records.family-planning.non-residents.index');
        $this->assertNotNull($index);
        $this->assertSame('health-records/family-planning/non-residents', $index->uri());
    }

    public function test_listing_renders_with_filters_and_table(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.non-residents.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-fp-nr', $html);
        $this->assertStringContainsString('data-hr-fp-nr-listing-header', $html);
        $this->assertStringContainsString('data-hr-fp-nr-action-group', $html);
        $this->assertMatchesRegularExpression(
            '/<h1 class="lml-topbar__title">\s*Family Planning \| Non Residents\s*<\/h1>/u',
            $html
        );
        $this->assertStringNotContainsString(
            'HEALTH RECORDS - FAMILY PLANNING - NON-RESIDENTS CLIENTS',
            $html
        );
        $this->assertStringNotContainsString('Family Planning | Non-Residents', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-hr-fp-nr__title"[^>]*>/u',
            $html
        );
        $this->assertStringContainsString(
            'List of all non-resident clients who received family planning services in this barangay.',
            $html
        );
        $this->assertStringContainsString('data-hr-fp-nr-search', $html);
        $this->assertStringContainsString('data-hr-fp-nr-barangay', $html);
        $this->assertStringContainsString('data-hr-fp-nr-year', $html);
        $this->assertStringContainsString('for="lml-hr-fp-nr-search"', $html);

        $this->assertMatchesRegularExpression(
            '/<thead>[\s\S]*<th scope="col">Full Name<\/th>\s*<th scope="col">Age<\/th>\s*<th scope="col">Method<\/th>\s*<th scope="col">Start Date<\/th>\s*<th scope="col">Last Visit<\/th>\s*<th scope="col">Actions<\/th>\s*<\/tr>/u',
            $html
        );
        $this->assertStringContainsString('data-hr-fp-nr-delete-dialog', $html);
        $this->assertStringContainsString('Delete Non-Resident Record?', $html);
        $this->assertStringNotContainsString('bi-three-dots-vertical', $html);
        $this->assertStringContainsString('Showing 1 to 7 of 7 entries', $html);

        foreach (HealthRecordsNonResidentFamilyPlanning::clients() as $client) {
            $this->assertStringContainsString($client['full_name'], $html);
        }
    }

    public function test_listing_header_actions_include_back_add_export(): void
    {
        $fpIndexUrl = route('health-records.family-planning.index');

        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.non-residents.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-fp-nr-back', $html);
        $this->assertStringContainsString('href="'.e($fpIndexUrl).'"', $html);
        $this->assertStringNotContainsString('javascript:history.back()', $html);
        $this->assertMatchesRegularExpression(
            '/data-hr-fp-nr-back[^>]*>[\s\S]*?>\s*Back\s*</u',
            $html
        );
        $this->assertStringContainsString('data-hr-fp-nr-add', $html);
        $this->assertMatchesRegularExpression('/>\s*Add Visit\s*<\/span>/u', $html);
        $this->assertStringContainsString('data-hr-fp-nr-export', $html);
        $this->assertMatchesRegularExpression('/>\s*Export Data\s*<\/span>/u', $html);

        $this->assertMatchesRegularExpression(
            '/data-hr-fp-nr-listing-header[\s\S]*data-hr-fp-nr-back[\s\S]*data-hr-fp-nr-action-group/u',
            $html
        );
        $actionGroupPos = strpos($html, 'data-hr-fp-nr-action-group');
        $this->assertNotFalse($actionGroupPos);
        $actionChunk = substr($html, $actionGroupPos, 900);
        $this->assertStringNotContainsString('data-hr-fp-nr-back', $actionChunk);
        $this->assertStringContainsString('data-hr-fp-nr-add', $actionChunk);
        $this->assertStringContainsString('data-hr-fp-nr-export', $actionChunk);
        $addPos = strpos($actionChunk, 'data-hr-fp-nr-add');
        $exportPos = strpos($actionChunk, 'data-hr-fp-nr-export');
        $this->assertNotFalse($addPos);
        $this->assertNotFalse($exportPos);
        $this->assertTrue($addPos < $exportPos);
        $this->assertStringContainsString(
            'href="'.e(route('health-records.family-planning.non-residents.create')).'"',
            $html
        );
        $this->assertStringNotContainsString('bi-three-dots-vertical', $html);
        $this->assertStringContainsString('Showing 1 to 7 of 7 entries', $html);
        $this->assertStringContainsString('entries', $html);
        $this->assertMatchesRegularExpression(
            '/aria-current="page"[^>]*>\s*1\s*</u',
            $html
        );
        $this->assertStringContainsString('data-hr-fp-nr-page-prev', $html);
        $this->assertStringContainsString('data-hr-fp-nr-page-next', $html);
        $this->assertStringContainsString('aria-label="Previous page"', $html);
        $this->assertStringContainsString('aria-label="Next page"', $html);
        $this->assertStringNotContainsString('data-hr-fp-nr-page-size', $html);
        $this->assertStringNotContainsString('per page', $html);
        $this->assertStringNotContainsString('Rows per page', $html);
        $this->assertStringNotContainsString('for="lml-hr-fp-nr-page-size"', $html);
        $this->assertStringContainsString('for="lml-hr-fp-nr-barangay"', $html);
        $this->assertStringContainsString('for="lml-hr-fp-nr-year"', $html);
    }

    public function test_sidebar_family_planning_active_on_listing(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bns'])
            ->get(route('health-records.family-planning.non-residents.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame('family-planning', UiRole::sidebarActiveKey());
        $this->assertMatchesRegularExpression(
            '/id="lml-sidebar-collapse-health-records"[^>]*\bis-open\b/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink--active[^>]*aria-current="page"[^>]*>[\s\S]*>Family Planning</u',
            $html
        );
    }

    public function test_add_new_client_screen_renders(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.non-residents.create'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Add New Non Resident', $html);
        $this->assertStringNotContainsString('Add New Client', $html);
        $this->assertStringContainsString('PERSONAL INFORMATION', $html);
        $this->assertStringContainsString('Family Planning Service Record', $html);
        $this->assertStringContainsString('for="lml-hr-fp-nr-first-name"', $html);
        $this->assertStringContainsString('placeholder="First Name"', $html);
        $this->assertStringContainsString('placeholder="Middle Name"', $html);
        $this->assertStringContainsString('placeholder="Last Name"', $html);
        $this->assertStringContainsString('for="lml-hr-fp-nr-birthday"', $html);
        $this->assertStringContainsString('for="lml-hr-fp-nr-sex"', $html);
        $this->assertStringContainsString('for="lml-hr-fp-nr-civil-status"', $html);
        $this->assertStringNotContainsString('for="lml-hr-fp-nr-age"', $html);
        $this->assertStringContainsString('for="lml-hr-fp-nr-address">Address</label>', $html);
        $this->assertStringNotContainsString('Address / Zone', $html);
        $this->assertStringContainsString('placeholder="Complete Address"', $html);
        $this->assertStringNotContainsString('placeholder="Zone"', $html);
        $this->assertStringNotContainsString('for="lml-hr-fp-nr-barangay"', $html);
        $this->assertStringNotContainsString('for="lml-hr-fp-nr-municipality"', $html);
        $this->assertStringNotContainsString('id="lml-hr-fp-nr-barangay"', $html);
        $this->assertStringNotContainsString('id="lml-hr-fp-nr-municipality"', $html);
        $this->assertStringContainsString('for="lml-hr-fp-nr-visit-date"', $html);
        $this->assertStringContainsString('for="lml-hr-fp-nr-method"', $html);
        $this->assertStringContainsString('placeholder="Enter remarks"', $html);
        $this->assertStringContainsString('lml-hr-fp-nr__form-actions--centered', $html);
        $this->assertStringContainsString('data-hr-fp-nr-commodity-add', $html);
        $this->assertStringContainsString('Add Another Commodity', $html);
        $this->assertStringContainsString('data-hr-fp-nr-create-form', $html);
    }

    public function test_client_record_renders(): void
    {
        $client = HealthRecordsNonResidentFamilyPlanning::findClient('roselyn-a-mendoza');
        $this->assertNotNull($client);

        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.non-residents.show', [
                'clientKey' => 'roselyn-a-mendoza',
            ]));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Non Residents Client', $html);
        $this->assertStringContainsString('ROSELYN A. MENDOZA', $html);
        $this->assertStringContainsString('Client Information', $html);
        $this->assertStringContainsString('Birthday', $html);
        $this->assertStringContainsString('January 8, 1991', $html);
        $this->assertStringContainsString('Civil Status', $html);
        $this->assertStringContainsString('Married', $html);
        $this->assertStringContainsString('Address', $html);
        $this->assertStringContainsString('Zone 2, Brgy. San Jose, Sagnay, Cam Sur', $html);
        $this->assertStringContainsString('Visit History', $html);
        $this->assertStringContainsString('Visit Date', $html);
        $this->assertStringContainsString('Remarks', $html);
        $this->assertStringContainsString('02/09/2024', $html);
        $this->assertStringContainsString('No Complaints', $html);
        $this->assertStringContainsString('Commodities Given', $html);
        $this->assertStringContainsString('Commodity', $html);
        $this->assertStringContainsString('Quantity', $html);
        $this->assertStringContainsString('Date Given', $html);
        $this->assertStringContainsString('Pills', $html);
        $this->assertStringContainsString('>30<', $html);
        $this->assertStringContainsString('02/10/2024', $html);
        $this->assertStringContainsString('Add Visit', $html);
        $this->assertStringContainsString(
            'href="'.e(route('health-records.family-planning.non-residents.visits.create', [
                'clientKey' => 'roselyn-a-mendoza',
            ])).'"',
            $html
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.family-planning.non-residents.visits.edit', [
                'clientKey' => 'roselyn-a-mendoza',
                'visitId' => 'NR-FP-001',
            ])).'"',
            $html
        );
        $this->assertStringContainsString('lml-hr-fp-nr__info-list--compact', $html);
        $this->assertStringContainsString('bi-person-vcard', $html);
        $this->assertStringNotContainsString('lml-hr-fp-nr__detail-table-wrap', $html);
    }

    public function test_add_visit_screen_renders(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.non-residents.visits.create', [
                'clientKey' => 'roselyn-a-mendoza',
            ]));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('ADD RECORD', $html);
        $this->assertStringNotContainsString('lml-hr-fp-nr__form-banner', $html);
        $this->assertStringContainsString('Visit Information', $html);
        $this->assertStringContainsString('Commodities Given', $html);
        $this->assertStringNotContainsString('for="lml-hr-fp-nr-add-method"', $html);
        $this->assertStringContainsString('for="lml-hr-fp-nr-add-remarks"', $html);
        $this->assertStringContainsString('>Date</label>', $html);
        $this->assertStringContainsString('placeholder="Enter remarks"', $html);
        $this->assertStringContainsString(
            'href="'.e(route('health-records.family-planning.non-residents.show', [
                'clientKey' => 'roselyn-a-mendoza',
            ])).'"',
            $html
        );
        $this->assertStringContainsString('lml-hr-fp-nr__form-actions--centered', $html);
        $this->assertStringContainsString('data-hr-fp-nr-visit-form', $html);
        $this->assertStringContainsString('data-hr-fp-nr-commodity-remove', $html);
    }

    public function test_edit_visit_screen_renders(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.non-residents.visits.edit', [
                'clientKey' => 'roselyn-a-mendoza',
                'visitId' => 'NR-FP-001',
            ]));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('ROSELYN A. MENDOZA', $html);
        $this->assertStringContainsString('EDIT VISIT', $html);
        $this->assertStringNotContainsString('lml-hr-fp-nr__form-banner', $html);
        $this->assertStringContainsString('for="lml-hr-fp-nr-edit-visit-date"', $html);
        $this->assertStringContainsString('>Date</label>', $html);
        $this->assertStringContainsString('for="lml-hr-fp-nr-edit-remarks"', $html);
        $this->assertStringContainsString('data-hr-fp-nr-commodity-name', $html);
        $this->assertStringContainsString('data-hr-fp-nr-commodity-qty', $html);
        $this->assertStringContainsString('data-hr-fp-nr-commodity-add', $html);
        $this->assertStringContainsString('Add Another Commodity', $html);
        $this->assertStringNotContainsString('for="lml-hr-fp-nr-edit-method"', $html);
        $this->assertStringNotContainsString('Select method', $html);
        $this->assertStringNotContainsString('data-hr-fp-nr-delete-visit', $html);
        $this->assertStringNotContainsString('Delete Visit', $html);
        $this->assertMatchesRegularExpression('/>\s*Cancel\s*<\/a>/u', $html);
        $this->assertMatchesRegularExpression('/>\s*Save\s*<\/button>/u', $html);
        $this->assertStringContainsString('lml-hr-fp-nr__form-actions--centered', $html);
        $this->assertStringContainsString('lml-hr-fp-nr__form-actions--visit-span', $html);
        $this->assertStringNotContainsString('lml-hr-fp-nr__form-actions--visit"', $html);
        $this->assertStringContainsString('value="2024-02-09"', $html);
        $this->assertStringContainsString('No Complaints', $html);
        $this->assertStringContainsString('data-visit-id="NR-FP-001"', $html);
    }

    public function test_frozen_summary_links_to_non_resident_listing(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString(
            'href="'.e(route('health-records.family-planning.non-residents.index')).'"',
            $html
        );
        $this->assertStringContainsString('Total FP Patients', $html);
        $this->assertStringContainsString('data-hr-fp-add', $html);
    }

    public function test_household_profiling_family_planning_remains_separate(): void
    {
        $this->assertTrue(Route::has('household-profiling.members.family-planning.index'));
        $this->assertTrue(Route::has('health-records.family-planning.non-residents.index'));

        $hh = Route::getRoutes()->getByName('household-profiling.members.family-planning.index');
        $nr = Route::getRoutes()->getByName('health-records.family-planning.non-residents.index');

        $this->assertNotNull($hh);
        $this->assertNotNull($nr);
        $this->assertNotSame($hh->uri(), $nr->uri());
    }

    public function test_listing_actions_column_links_per_client(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.non-residents.index'));

        $response->assertOk();
        $html = $response->getContent();

        foreach (HealthRecordsNonResidentFamilyPlanning::clients() as $client) {
            $showUrl = route('health-records.family-planning.non-residents.show', [
                'clientKey' => $client['key'],
            ]);
            $latestVisit = HealthRecordsNonResidentFamilyPlanning::latestVisit($client);
            $editUrl = $latestVisit !== null
                ? route('health-records.family-planning.non-residents.visits.edit', [
                    'clientKey' => $client['key'],
                    'visitId' => $latestVisit['id'],
                ])
                : route('health-records.family-planning.non-residents.visits.create', [
                    'clientKey' => $client['key'],
                ]);

            $this->assertStringContainsString('aria-label="View '.$client['full_name'].'"', $html);
            $this->assertStringContainsString('href="'.e($showUrl).'"', $html);
            $this->assertStringContainsString('aria-label="Delete '.$client['full_name'].'"', $html);
            $this->assertStringContainsString('data-client-key="'.$client['key'].'"', $html);
            $this->assertStringContainsString('href="'.e($editUrl).'"', $html);
        }

        $this->assertGreaterThanOrEqual(7, substr_count($html, 'data-hr-fp-nr-delete-client'));
        $this->assertGreaterThanOrEqual(7, substr_count($html, '>View</span>'));
        $this->assertGreaterThanOrEqual(7, substr_count($html, '>Edit</span>'));
        $this->assertGreaterThanOrEqual(7, substr_count($html, '>Delete</span>'));
    }

    public function test_listing_edit_targets_latest_visit_for_client_with_visits(): void
    {
        $client = HealthRecordsNonResidentFamilyPlanning::findClient('jacob-a-magistrado');
        $this->assertNotNull($client);
        $latestVisit = HealthRecordsNonResidentFamilyPlanning::latestVisit($client);
        $this->assertNotNull($latestVisit);

        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.non-residents.index'));

        $response->assertOk();
        $html = $response->getContent();

        $editUrl = route('health-records.family-planning.non-residents.visits.edit', [
            'clientKey' => 'jacob-a-magistrado',
            'visitId' => $latestVisit['id'],
        ]);

        $this->assertStringContainsString('href="'.e($editUrl).'"', $html);
        $this->assertStringContainsString('aria-label="Edit latest visit for Jacob A. Magistrado"', $html);
    }

    public function test_client_details_add_visit_link_uses_selected_client(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.non-residents.show', [
                'clientKey' => 'jacob-a-magistrado',
            ]));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('JACOB A. MAGISTRADO', $html);
        $this->assertStringContainsString(
            'href="'.e(route('health-records.family-planning.non-residents.visits.create', [
                'clientKey' => 'jacob-a-magistrado',
            ])).'"',
            $html
        );
    }

    public function test_edit_visit_renders_selected_client_context(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.family-planning.non-residents.visits.edit', [
                'clientKey' => 'jacob-a-magistrado',
                'visitId' => 'NR-FP-003',
            ]));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('JACOB A. MAGISTRADO', $html);
        $this->assertStringContainsString('EDIT VISIT', $html);
        $this->assertStringContainsString('for="lml-hr-fp-nr-edit-remarks"', $html);
        $this->assertStringNotContainsString('for="lml-hr-fp-nr-edit-method"', $html);
        $this->assertStringContainsString('data-visit-id="NR-FP-003"', $html);
    }

    public function test_client_details_sidebar_family_planning_active(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bns'])
            ->get(route('health-records.family-planning.non-residents.show', [
                'clientKey' => 'roselyn-a-mendoza',
            ]));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame('family-planning', UiRole::sidebarActiveKey());
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink--active[^>]*aria-current="page"[^>]*>[\s\S]*>Family Planning</u',
            $html
        );
    }
}
