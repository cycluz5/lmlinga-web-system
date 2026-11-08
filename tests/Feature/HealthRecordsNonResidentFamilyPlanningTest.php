<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Health Records → Family Planning → Non-Resident / unregistered clients.
 */
class HealthRecordsNonResidentFamilyPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_resident_routes_resolve(): void
    {
        $names = [
            'health-records.family-planning.non-residents.index',
            'health-records.family-planning.non-residents.create',
            'health-records.family-planning.non-residents.store',
            'health-records.family-planning.non-residents.show',
            'health-records.family-planning.non-residents.destroy',
            'health-records.family-planning.non-residents.visits.create',
            'health-records.family-planning.non-residents.visits.store',
            'health-records.family-planning.non-residents.visits.edit',
            'health-records.family-planning.non-residents.visits.update',
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
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.non-residents.index'));

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
        $this->assertStringContainsString('Showing 0 of 0 entries', $html);
    }

    public function test_listing_header_actions_include_back_add_export(): void
    {
        $fpIndexUrl = route('health-records.family-planning.index');

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.non-residents.index'));

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
        $this->actingAsStaff(StaffRole::BNS);
        $response = $this->get(route('health-records.family-planning.non-residents.index'));

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
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.non-residents.create'));

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

    public function test_unknown_client_record_shows_not_found(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.non-residents.show', [
            'clientKey' => 'roselyn-a-mendoza',
        ]));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Client not found', $html);
        $this->assertStringContainsString('No non-resident client matches this record.', $html);
        $this->assertStringNotContainsString('ROSELYN A. MENDOZA', $html);
    }

    public function test_add_visit_unknown_client_shows_not_found(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.non-residents.visits.create', [
            'clientKey' => 'roselyn-a-mendoza',
        ]));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Client not found', $html);
        $this->assertStringNotContainsString('ADD RECORD', $html);
    }

    public function test_edit_visit_unknown_client_shows_not_found(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.non-residents.visits.edit', [
            'clientKey' => 'roselyn-a-mendoza',
            'visitId' => 'NR-FP-001',
        ]));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Client not found', $html);
        $this->assertStringNotContainsString('EDIT VISIT', $html);
        $this->assertStringNotContainsString('data-hr-fp-nr-delete-visit', $html);
        $this->assertStringNotContainsString('Delete Visit', $html);
    }

    public function test_frozen_summary_does_not_link_to_non_resident_listing(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString(
            'href="'.e(route('health-records.family-planning.non-residents.index')).'"',
            $html
        );
        $this->assertStringNotContainsString('Non - Residents Client', $html);
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

    public function test_listing_actions_column_is_ready_for_persisted_rows(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.non-residents.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-fp-nr-tbody', $html);
        $this->assertStringContainsString('data-hr-fp-nr-delete-dialog', $html);
        $this->assertStringNotContainsString('data-hr-fp-nr-delete-client', $html);
        $this->assertStringContainsString('Showing 0 of 0 entries', $html);
    }

    public function test_create_form_posts_to_store_route(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.non-residents.create'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString(
            'action="'.e(route('health-records.family-planning.non-residents.store')).'"',
            $html
        );
        $this->assertStringNotContainsString('action="#"', $html);
        $this->assertStringNotContainsString('Backend persistence is not yet implemented', $html);
    }

    public function test_unknown_show_keeps_family_planning_sidebar_active(): void
    {
        $this->actingAsStaff(StaffRole::BNS);
        $response = $this->get(route('health-records.family-planning.non-residents.show', [
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
