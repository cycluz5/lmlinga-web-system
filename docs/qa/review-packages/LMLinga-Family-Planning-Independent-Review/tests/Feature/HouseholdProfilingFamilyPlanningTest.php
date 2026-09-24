<?php

namespace Tests\Feature;

use App\Support\DemoFamilyPlanning;
use App\Support\UiRole;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Feature coverage for Household Profiling → member → Family Planning.
 */
class HouseholdProfilingFamilyPlanningTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{householdNo: string, memberId: string}
     */
    private function memberWithVisits(): array
    {
        return [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ];
    }

    /**
     * @return array{householdNo: string, memberId: string}
     */
    private function memberWithoutVisits(): array
    {
        return [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-002',
        ];
    }

    public function test_named_routes_resolve_under_household_profiling_member(): void
    {
        $params = $this->memberWithVisits();

        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/family-planning'),
            route('household-profiling.members.family-planning.index', $params)
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/family-planning/create'),
            route('household-profiling.members.family-planning.create', $params)
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/family-planning/FP-001'),
            route('household-profiling.members.family-planning.show', $params + ['visitId' => 'FP-001'])
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/family-planning/FP-001/edit'),
            route('household-profiling.members.family-planning.edit', $params + ['visitId' => 'FP-001'])
        );
    }

    public function test_routes_are_protected_by_ui_role_middleware(): void
    {
        foreach ([
            'household-profiling.members.family-planning.index',
            'household-profiling.members.family-planning.create',
            'household-profiling.members.family-planning.show',
            'household-profiling.members.family-planning.edit',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('ui.role', $route->gatherMiddleware());
        }
    }

    public function test_household_family_planning_remains_separate_from_health_records_summary(): void
    {
        $this->assertTrue(Route::has('household-profiling.members.family-planning.index'));
        $this->assertTrue(Route::has('health-records.family-planning.index'));
        $this->assertFalse(Route::has('health-records.family-planning'));

        $hh = Route::getRoutes()->getByName('household-profiling.members.family-planning.index');
        $hr = Route::getRoutes()->getByName('health-records.family-planning.index');

        $this->assertNotNull($hh);
        $this->assertNotNull($hr);
        $this->assertNotSame($hh->uri(), $hr->uri());
    }

    public function test_member_view_family_planning_uses_named_route_link(): void
    {
        $params = $this->memberWithVisits();
        $response = $this->get(route('household-profiling.members.show', $params));

        $response->assertOk();
        $response->assertSee('data-hh-member-family-planning', false);
        $response->assertDontSee('data-hh-member-view-record="Family Planning"', false);
        $response->assertSee(
            'href="'.e(route('household-profiling.members.family-planning.index', $params)).'"',
            false
        );
        $response->assertSee('data-hh-member-maternal-care', false);
        $response->assertDontSee('data-hh-member-view-record="Maternal"', false);
        $response->assertSee('data-hh-member-death', false);
        $response->assertDontSee('data-hh-member-view-record="Death"', false);
    }

    public function test_history_screen_renders_for_resident_with_fixture_rows(): void
    {
        $params = $this->memberWithVisits();
        $response = $this->get(route(
            'household-profiling.members.family-planning.index',
            $params
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-fp-mode="history"', $html);
        $this->assertStringContainsString('data-household-no="HH-151"', $html);
        $this->assertStringContainsString('data-member-id="MB-001"', $html);
        $this->assertStringContainsString('Kristine Reyes', $html);
        $this->assertStringContainsString('FAMILY PLANNING VISIT RECORDS', $html);
        $this->assertStringContainsString(
            'Monitor family planning visits and commodities provided to clients.',
            $html
        );
        $this->assertStringContainsString('Visit Date', $html);
        $this->assertStringContainsString('Commodities Given', $html);
        $this->assertStringContainsString('Total Quantity', $html);
        $this->assertStringContainsString('June 8, 2026', $html);
        $this->assertStringContainsString('Pills, Condoms', $html);
        $this->assertStringContainsString('>13</td>', $html);
        $this->assertStringContainsString('May 1, 2026', $html);
        $this->assertStringContainsString('DMPA', $html);
        $this->assertStringContainsString('October 8, 2025', $html);
        $this->assertStringContainsString('data-fp-add', $html);
        $this->assertStringContainsString('Filter family planning visits by visit date', $html);
        $this->assertStringContainsString('>Date</option>', $html);
        $this->assertStringContainsString('>All Dates</option>', $html);
        $this->assertStringContainsString('>This Month</option>', $html);
        $this->assertStringContainsString('>Last 3 Months</option>', $html);
        $this->assertStringContainsString('>This Year</option>', $html);
        $this->assertStringContainsString('>Custom range</option>', $html);
        $this->assertSame(3, substr_count($html, 'data-fp-row'));

        $this->assertMatchesRegularExpression(
            '/data-fp-total-visits[^>]*>\s*3\s*</u',
            $html
        );
        $this->assertStringContainsString('data-fp-last-visit', $html);
        $this->assertStringContainsString('datetime="2026-06-08"', $html);
    }

    public function test_total_visits_and_last_visit_match_demo_summary(): void
    {
        $visits = DemoFamilyPlanning::forMember('HH-151', 'MB-001');
        $stats = DemoFamilyPlanning::summaryStats($visits);

        $this->assertSame(3, $stats['total_visits']);
        $this->assertSame('2026-06-08', $stats['last_visit']);
        $this->assertSame('June 8, 2026', $stats['last_visit_label']);
    }

    public function test_empty_history_state_for_resident_without_visits(): void
    {
        $response = $this->get(route(
            'household-profiling.members.family-planning.index',
            $this->memberWithoutVisits()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('No family planning visits recorded for this resident.', $html);
        $this->assertSame(0, substr_count($html, 'data-fp-row'));
        $this->assertMatchesRegularExpression(
            '/data-fp-total-visits[^>]*>\s*0\s*</u',
            $html
        );
    }

    public function test_unknown_member_shows_not_found(): void
    {
        $response = $this->get(route(
            'household-profiling.members.family-planning.index',
            [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-999',
            ]
        ));

        $response->assertOk();
        $response->assertSee('Member not found', false);
        $response->assertSee('MB-999', false);
    }

    public function test_household_profiling_remains_sidebar_active(): void
    {
        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route(
                'household-profiling.members.family-planning.index',
                $this->memberWithVisits()
            ))
            ->assertOk();

        $this->assertSame(
            'household-profiling',
            UiRole::sidebarActiveKey('household-profiling')
        );
    }

    public function test_date_filter_this_year_limits_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        $response = $this->get(route(
            'household-profiling.members.family-planning.index',
            $this->memberWithVisits() + ['date' => 'this_year']
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(2, substr_count($html, 'data-fp-row'));
        $this->assertStringContainsString('June 8, 2026', $html);
        $this->assertStringContainsString('May 1, 2026', $html);
        $this->assertStringNotContainsString('October 8, 2025', $html);
    }

    public function test_date_filter_custom_range(): void
    {
        $response = $this->get(route(
            'household-profiling.members.family-planning.index',
            $this->memberWithVisits() + [
                'date' => 'custom',
                'from' => '2026-05-01',
                'to' => '2026-05-01',
            ]
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'data-fp-row'));
        $this->assertStringContainsString('May 1, 2026', $html);
        $this->assertStringContainsString('DMPA', $html);
    }

    public function test_add_destination_renders_create_form(): void
    {
        $params = $this->memberWithVisits();
        $response = $this->get(route(
            'household-profiling.members.family-planning.create',
            $params
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-fp-mode="create"', $html);
        $this->assertStringContainsString('ADD RECORD', $html);
        $this->assertStringContainsString('Visit Information', $html);
        $this->assertStringContainsString('Commodities Given', $html);
        $this->assertStringContainsString('Add Another Commodity', $html);
        $this->assertStringContainsString('data-fp-save', $html);
        $this->assertStringContainsString(
            'href="'.e(route('household-profiling.members.family-planning.index', $params)).'"',
            $html
        );
    }

    public function test_view_destination_preserves_member_and_visit(): void
    {
        $params = $this->memberWithVisits() + ['visitId' => 'FP-001'];
        $response = $this->get(route(
            'household-profiling.members.family-planning.show',
            $params
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-fp-mode="view"', $html);
        $this->assertStringContainsString('data-household-no="HH-151"', $html);
        $this->assertStringContainsString('data-member-id="MB-001"', $html);
        $this->assertStringContainsString('data-visit-id="FP-001"', $html);
        $this->assertStringContainsString('VIEW FAMILY PLANNING RECORD', $html);
        $this->assertStringContainsString('Pills', $html);
        $this->assertStringContainsString('Condoms', $html);
        $this->assertStringContainsString(
            'href="'.e(route('household-profiling.members.family-planning.edit', $params)).'"',
            $html
        );
    }

    public function test_edit_destination_preloads_visit(): void
    {
        $params = $this->memberWithVisits() + ['visitId' => 'FP-003'];
        $response = $this->get(route(
            'household-profiling.members.family-planning.edit',
            $params
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-fp-mode="edit"', $html);
        $this->assertStringContainsString('EDIT FAMILY PLANNING RECORD', $html);
        $this->assertStringContainsString('value="2025-10-08"', $html);
        $this->assertStringContainsString('Pills given to the resident.', $html);
    }

    public function test_unknown_visit_shows_not_found(): void
    {
        $response = $this->get(route(
            'household-profiling.members.family-planning.show',
            $this->memberWithVisits() + ['visitId' => 'FP-999']
        ));

        $response->assertOk();
        $response->assertSee('Visit not found', false);
        $response->assertSee('FP-999', false);
    }

    public function test_history_add_and_view_links_are_valid(): void
    {
        $params = $this->memberWithVisits();
        $response = $this->get(route(
            'household-profiling.members.family-planning.index',
            $params
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString(
            'href="'.e(route('household-profiling.members.family-planning.create', $params)).'"',
            $html
        );
        $this->assertStringContainsString(
            'href="'.e(route(
                'household-profiling.members.family-planning.show',
                $params + ['visitId' => 'FP-001']
            )).'"',
            $html
        );
    }
}
