<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PersistCatalogHousehold;
use Tests\TestCase;

/**
 * Feature coverage for Household Profiling — View Household page.
 *
 * Note: php artisan test --filter=Household also matches Environmental Health
 * Household Water Supply / Spot Mapping handoff tests. Those are NOT View Household
 * coverage. This class is the dedicated View Household suite.
 */
class HouseholdProfilingViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(\App\Support\StaffRole::BHW);
    }

    public function test_valid_household_page_loads_summary_fields(): void
    {
        PersistCatalogHousehold::persist('HH-151');

        $response = $this->get(route('household-profiling.view', ['householdNo' => 'HH-151']));

        $response->assertOk();
        $response->assertSee('HH 151', false);
        $response->assertSee('Kristine Reyes', false);
        $response->assertSee('Zone 2', false);
        $response->assertDontSee('>Street</span>', false);
        $response->assertDontSee('>Accomplished By</span>', false);
        $response->assertDontSee('Layuan St.', false);
        $response->assertDontSee('Lani Magistrado (BHW)', false);
        $response->assertSee('01/21/2026', false);
        $response->assertDontSee('January 21, 2026', false);
        $this->assertStringContainsString('data-source="db"', $response->getContent());
    }

    public function test_dynamic_member_count_matches_member_collection(): void
    {
        $persisted = PersistCatalogHousehold::persist('HH-151');
        $memberCount = $persisted->residents()->count();

        $response = $this->get(route('household-profiling.view', ['householdNo' => 'HH-151']));

        $response->assertOk();
        $response->assertSee('Household Members ('.$memberCount.')', false);
        $response->assertSee($memberCount.' members', false);
    }

    public function test_head_badge_renders_once_on_head_member_only(): void
    {
        PersistCatalogHousehold::persist('HH-151');

        $response = $this->get(route('household-profiling.view', ['householdNo' => 'HH-151']));

        $response->assertOk();

        $html = $response->getContent();
        $badgeCount = substr_count($html, 'lml-hh-view__head-badge');
        $this->assertSame(1, $badgeCount, 'Head badge must render exactly once');

        $this->assertMatchesRegularExpression(
            '/lml-hh-view__head-badge">Head<\/span>[\s\S]{0,300}?data-label="Relationship">Head<\/td>/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Angelo David Reyes[\s\S]{0,250}?lml-hh-view__head-badge/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-label="Relationship">Spouse<\/td>[\s\S]{0,80}?lml-hh-view__head-badge|lml-hh-view__head-badge[\s\S]{0,80}?data-label="Relationship">Spouse<\/td>/u',
            $html
        );
    }

    public function test_hh_151_does_not_show_catalog_amenity_classifications(): void
    {
        PersistCatalogHousehold::persist('HH-151');

        $response = $this->get(route('household-profiling.view', ['householdNo' => 'HH-151']));

        $response->assertOk();
        $response->assertSee('Access to Safe Water', false);
        $response->assertSee('Sanitation Services', false);
        $response->assertSee('Not recorded', false);
        $response->assertDontSee('Level III', false);
        $response->assertDontSee('Basic Sanitation Facility', false);
        $response->assertDontSee('Safely Managed Sanitation Services', false);
    }

    public function test_hh_154_renders_without_hh_151_leak(): void
    {
        PersistCatalogHousehold::persist('HH-154');

        $response = $this->get(route('household-profiling.view', ['householdNo' => 'HH-154']));

        $response->assertOk();
        $response->assertSee('HH 154', false);
        $response->assertDontSee('Level III', false);
        $response->assertDontSee('Safely Managed Sanitation Services', false);
        $response->assertDontSee('Basic Sanitation Facility', false);
        $response->assertDontSee('Kristine Reyes', false);
    }

    public function test_details_button_text_and_aria_label(): void
    {
        PersistCatalogHousehold::persist('HH-151');

        $response = $this->get(route('household-profiling.view', ['householdNo' => 'HH-151']));

        $response->assertOk();
        $response->assertSee('aria-label="View household amenities details"', false);
        $response->assertSee('>Details</span>', false);
        $response->assertDontSee('View Full Amenities', false);
        $response->assertDontSee('View Details', false);
    }

    public function test_invalid_household_shows_not_found_state(): void
    {
        $response = $this->get(route('household-profiling.view', ['householdNo' => 'HH-999']));

        $response->assertOk();
        $response->assertSee('Household not found', false);
        $response->assertSee('Back to Household List', false);
        $response->assertDontSee('Household Amenities', false);
        $response->assertDontSee('Household Members (', false);
        $response->assertDontSee('Kristine Reyes', false);
    }

    public function test_malformed_household_number_is_not_routable(): void
    {
        $response = $this->get('/household-profiling/HH-ABC');

        $response->assertNotFound();
    }

    public function test_member_action_links_render_with_route_backed_urls(): void
    {
        PersistCatalogHousehold::persist('HH-151');

        $response = $this->get(route('household-profiling.view', ['householdNo' => 'HH-151']));

        $response->assertOk();

        $createUrl = route('household-profiling.members.create', ['householdNo' => 'HH-151']);
        $showUrl = route('household-profiling.members.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]);
        $editUrl = route('household-profiling.members.edit', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]);

        $response->assertSee('href="'.e($createUrl).'"', false);
        $response->assertSee('Add Household Member', false);
        $response->assertSee('href="'.e($showUrl).'"', false);
        $response->assertSee('href="'.e($editUrl).'"', false);
        $response->assertSee('>View</span>', false);
        $response->assertSee('>Edit</span>', false);
    }

    public function test_household_profiling_route_names_remain_resolvable(): void
    {
        $this->assertSame(
            url('/household-profiling/HH-151'),
            route('household-profiling.view', ['householdNo' => 'HH-151'])
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/create'),
            route('household-profiling.members.create', ['householdNo' => 'HH-151'])
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001'),
            route('household-profiling.members.show', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-001',
            ])
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/edit'),
            route('household-profiling.members.edit', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-001',
            ])
        );
    }
}
