<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\Resident;
use App\Support\UiRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Health Records → Child Care → Deworming monitoring summary.
 */
class HealthRecordsDewormingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-22')->startOfDay());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedEligibleChild(): array
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-501',
            'zone' => 'Zone 2',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-501',
            'first_name' => 'Monitor',
            'last_name' => 'Child',
            'birthday' => now()->subMonths(6)->format('Y-m-d'),
            'sex' => 'Female',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    public function test_deworming_route_resolves(): void
    {
        $this->assertTrue(Route::has('health-records.child-care.deworming'));

        $route = Route::getRoutes()->getByName('health-records.child-care.deworming');
        $this->assertNotNull($route);
        $this->assertSame('health-records/child-care/deworming', $route->uri());
    }

    public function test_deworming_page_renders_successfully(): void
    {
        $this->actingAsStaff(StaffRole::BNS);
        $response = $this->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-deworming', $html);
        $this->assertStringContainsString('lml-hr-child-care--deworming', $html);
        $this->assertStringContainsString(
            'Record and management of deworming details for monitoring and tracking treatment status.',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-hr-deworming-heading"[^>]*>\s*Child Care\s*</u',
            $html
        );
    }

    public function test_deworming_pill_is_active_current(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Deworming\s*</u',
            $html
        );
    }

    public function test_vitamin_a_and_operation_timbang_pills_remain_present(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.vitamin-a')).'"',
            $html
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.operation-timbang')).'"',
            $html
        );
        $this->assertSame(1, preg_match_all('/>\s*Vitamin A\s*<\/a>/u', $html));
        $this->assertSame(1, preg_match_all('/>\s*Operation Timbang\s*<\/a>/u', $html));

        $this->assertMatchesRegularExpression(
            '/>\s*Vitamin A\s*<\/a>[\s\S]*>\s*Deworming\s*<\/a>[\s\S]*>\s*Operation Timbang\s*<\/a>/u',
            $html
        );
    }

    public function test_export_data_control_exists(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-dw-export', $html);
        $this->assertStringContainsString('aria-label="Export Deworming data"', $html);
        $this->assertMatchesRegularExpression('/>\s*Export Data\s*<\/span>/u', $html);
        $this->assertStringNotContainsString('data-hr-dw-add', $html);
        $this->assertStringNotContainsString('data-hr-cc-non-residents', $html);
    }

    public function test_resident_view_links_render_without_summary_add_button(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedEligibleChild();
        \App\Models\DewormingRecord::factory()->create([
            'resident_id' => $resident->id,
            'year' => 2026,
            'round' => 1,
            'date_given' => '2026-07-01',
        ]);

        $viewUrl = route('household-profiling.members.deworming', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.deworming'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-hr-dw-add', $html);
        $this->assertStringContainsString('data-hr-dw-view', $html);
        $this->assertMatchesRegularExpression('/<th scope="col">\s*Action\s*<\/th>/u', $html);
        $this->assertStringContainsString('href="'.e($viewUrl).'"', $html);
        $this->assertStringContainsString('Monitor Child', $html);
        $this->assertStringNotContainsString('Andrei B. Malaya', $html);
        $this->assertStringNotContainsString('data-hr-cc-non-residents', $html);
    }

    public function test_legacy_health_records_show_redirects_to_household_member_deworming(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedEligibleChild();

        $showUrl = route('health-records.child-care.deworming.show', [
            'childKey' => 'monitor-child',
        ]);
        $target = route('household-profiling.members.deworming', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);

        $this->assertTrue(Route::has('health-records.child-care.deworming.show'));
        $this->assertTrue(Route::has('health-records.child-care.deworming.create'));
        $this->assertFalse(Route::has('health-records.child-care.deworming.store'));

        $this->actingAsStaff(StaffRole::BHW);
        $this->get($showUrl)
            ->assertRedirect($target);
    }

    public function test_legacy_health_records_create_redirects_to_monitoring_summary(): void
    {
        $this->assertTrue(Route::has('health-records.child-care.deworming.create'));

        $createUrl = route('health-records.child-care.deworming.create', [
            'childKey' => 'monitor-child',
        ]);
        $monitoringUrl = route('health-records.child-care.deworming');

        $this->actingAsStaff(StaffRole::BHW);
        $this->get($createUrl)
            ->assertRedirect($monitoringUrl);

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->followingRedirects()
            ->get($createUrl)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-lml-hr-deworming', $html);
        $this->assertStringContainsString('data-hr-dw-export', $html);
        $this->assertStringNotContainsString('data-hr-dw-add', $html);
        $this->assertStringNotContainsString('data-lml-hr-dw-mode="create"', $html);
        $this->assertStringNotContainsString('data-hr-dw-add-record', $html);
        $this->assertStringNotContainsString('id="lml-hr-dw-add-title"', $html);
        $this->assertStringNotContainsString('Add a Deworming record for the selected child.', $html);
    }

    public function test_supplemental_fixture_child_key_shows_not_found(): void
    {
        foreach (['andrei-b-malaya', 'crisley-f-fernando', 'gabriel-allan-s-chua'] as $childKey) {
            $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.deworming.show', [
                    'childKey' => $childKey,
                ]))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('Record not found', $html);
            $this->assertStringNotContainsString('data-hr-dw-add-record', $html);
            $this->assertStringNotContainsString('data-hr-cc-non-residents', $html);
        }
    }

    public function test_unknown_child_key_renders_not_found_without_nr_navigation(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.deworming.show', [
                'childKey' => 'unknown-child-key',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Record not found', $html);
        $this->assertStringNotContainsString('data-hr-dw-add-record', $html);
        $this->assertStringNotContainsString('data-hr-cc-non-residents', $html);
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.deworming')).'"',
            $html
        );
    }

    public function test_summary_cards_render(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('First Round (July)', $html);
        $this->assertStringContainsString('Second Round (January)', $html);
        $this->assertStringContainsString('Received 1 dose/year', $html);
        $this->assertStringContainsString('Received 2 dose/year', $html);
        $this->assertMatchesRegularExpression(
            '/lml-hr-dw-card__label[^>]*>\s*Status\s*</u',
            $html
        );
    }

    public function test_filter_controls_render(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-dw-search', $html);
        $this->assertStringContainsString('placeholder="Name of Member"', $html);
        $this->assertStringContainsString('for="lml-hr-dw-search"', $html);
        $this->assertStringContainsString('data-hr-dw-zone', $html);
        $this->assertStringContainsString('>All Zones</option>', $html);
        $this->assertStringContainsString('for="lml-hr-dw-zone"', $html);
        $this->assertStringContainsString('data-hr-dw-sex', $html);
        $this->assertStringContainsString('for="lml-hr-dw-sex"', $html);
        $this->assertStringContainsString('data-hr-dw-status', $html);
        $this->assertStringContainsString('for="lml-hr-dw-status"', $html);
        $this->assertMatchesRegularExpression('/>\s*Sex\s*<\/option>/u', $html);
        $this->assertMatchesRegularExpression('/>\s*Status\s*<\/option>/u', $html);
    }

    public function test_table_headings_render_exactly(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/<th scope="col">\s*Full Name\s*<\/th>/u', $html);
        $this->assertMatchesRegularExpression('/<th scope="col">\s*Age\s*<\/th>/u', $html);
        $this->assertMatchesRegularExpression('/<th scope="col">\s*July Round \(Date\)\s*<\/th>/u', $html);
        $this->assertMatchesRegularExpression('/<th scope="col">\s*January Round \(Date\)\s*<\/th>/u', $html);
        $this->assertMatchesRegularExpression('/<th scope="col">\s*Action\s*<\/th>/u', $html);
    }

    public function test_db_monitoring_mode_and_derived_summary_render(): void
    {
        $this->seedEligibleChild();
        \App\Models\DewormingRecord::factory()->create([
            'resident_id' => \App\Models\Resident::query()->first()->id,
            'year' => 2026,
            'round' => 1,
            'date_given' => '2026-07-01',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.deworming'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-dw-data-mode="db"', $html);
        $this->assertStringContainsString('Monitor Child', $html);
        $this->assertStringContainsString('6 Months', $html);
        $this->assertStringNotContainsString('3 yrs old', $html);
        $this->assertStringNotContainsString('Andrei B. Malaya', $html);
        $this->assertMatchesRegularExpression(
            '/data-dw-stat="first-round"[^>]*>\s*1\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-dw-stat="received-1-dose"[^>]*>\s*100%\s*</u',
            $html
        );
    }

    public function test_health_records_expanded_and_child_care_sidebar_active(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.deworming'));

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
            '/lml-sidebar__sublink[^>]*>\s*(?:<[^>]+>\s*)*Deworming\s*</u',
            $html
        );
    }

    public function test_frozen_vitamin_a_route_remains_reachable(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.vitamin-a'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-vitamin-a', $html);
        $this->assertStringContainsString('lml-hr-child-care--vitamin-a', $html);
        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Vitamin A\s*</u',
            $html
        );
    }

    public function test_non_residents_scope_pill_is_absent(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.deworming'))
            ->assertOk()
            ->getContent();

        $this->assertSame(0, preg_match_all('/>\s*Non-Residents\s*<\/span>/u', $html));
        $this->assertSame(0, substr_count($html, 'data-hr-cc-non-residents'));
        $this->assertStringNotContainsString(
            'href="'.e(route('health-records.child-care.non-residents.index')).'"',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-hr-child-care__pill--active[^>]*aria-current="page"[^>]*>\s*Deworming\s*</u',
            $html
        );
        $this->assertSame('child-care', UiRole::sidebarActiveKey());
        $this->assertStringContainsString('data-lml-hr-deworming', $html);
    }
}
