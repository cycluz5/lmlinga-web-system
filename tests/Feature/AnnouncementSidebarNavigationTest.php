<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Announcement;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

class AnnouncementSidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function seedSidebarDemoAnnouncements(): void
    {
        Announcement::factory()->create([
            'title' => 'Free Deworming Program — August 30',
            'audience_label' => 'Infants 0–6 months',
            'target_group' => 'age',
            'event_date' => now()->addDays(2)->toDateString(),
            'posted_at' => now(),
        ]);
    }
    private function pretendNamedRoute(string $routeName): void
    {
        $request = Request::create('/', 'GET');
        $route = (new Route(['GET'], '/', fn () => null))->name($routeName);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);
        app()->instance('request', $request);
    }

    public function test_announcement_appears_between_requests_and_spot_mapping_for_admin(): void
    {
        $html = view('components.lml.dashboard.sidebar', [
            'role' => 'admin',
            'active' => 'dashboard',
        ])->render();

        $this->assertStringContainsString('>Announcement</span>', $html);
        $this->assertStringContainsString('bi-megaphone', $html);
        $this->assertStringContainsString('href="'.e(route('announcements.index')).'"', $html);

        $requestsPos = strpos($html, '>Requests</span>');
        $announcementPos = strpos($html, '>Announcement</span>');
        $spotMappingPos = strpos($html, '>Spot Mapping</span>');
        $this->assertNotFalse($requestsPos);
        $this->assertNotFalse($announcementPos);
        $this->assertNotFalse($spotMappingPos);
        $this->assertGreaterThan($requestsPos, $announcementPos);
        $this->assertLessThan($spotMappingPos, $announcementPos);
    }

    public function test_bhw_sees_announcement_with_disabled_admin_only_items(): void
    {
        $html = view('components.lml.dashboard.sidebar', [
            'role' => 'bhw',
            'active' => 'dashboard',
        ])->render();

        $this->assertStringContainsString('>Announcement</span>', $html);
        $this->assertStringContainsString('href="'.e(route('announcements.index')).'"', $html);
        $this->assertStringContainsString('>User Management</span>', $html);
        $this->assertStringContainsString('>Requests</span>', $html);
        $this->assertStringContainsString('data-lml-admin-only', $html);
        $this->assertStringNotContainsString('href="'.e(route('user-management.index')).'"', $html);
    }

    public function test_bns_and_bspo_see_announcement_with_disabled_admin_only_items(): void
    {
        foreach (['bns', 'bspo'] as $role) {
            $html = view('components.lml.dashboard.sidebar', [
                'role' => $role,
                'active' => 'dashboard',
            ])->render();

            $this->assertStringContainsString('>Announcement</span>', $html);
            $this->assertStringContainsString('href="'.e(route('announcements.index')).'"', $html);
            $this->assertStringContainsString('>User Management</span>', $html);
            $this->assertStringContainsString('>Requests</span>', $html);
            $this->assertStringContainsString('data-lml-admin-only', $html);
            $this->assertStringNotContainsString('href="'.e(route('user-management.index')).'"', $html);
        }
    }

    public function test_announcement_routes_do_not_highlight_dashboard_requests_or_spot_mapping(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('announcements.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/lml-sidebar__link--active[^>]*>[\s\S]*?>\s*Announcement\s*<\/span>|aria-current="page"[^>]*>[\s\S]*?>\s*Announcement\s*<\/span>/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/href="[^"]*\/dashboard"[^>]*class="[^"]*lml-sidebar__link--active/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__link--parent-active[^>]*>[\s\S]*?>\s*Requests\s*<\/span>/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/href="[^"]*spot-mapping"[^>]*class="[^"]*lml-sidebar__link--active/u',
            $html
        );
    }

    public function test_announcement_dashboard_is_list_only_without_create_form(): void
    {
        $this->seedSidebarDemoAnnouncements();

        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('Announcements', false)
            ->assertSee('Add Announcement', false)
            ->assertSee('Upcoming Announcements', false)
            ->assertSee('Recent Announcements', false)
            ->assertSee('Total Announcements', false)
            ->assertSee('All Announcements', false)
            ->assertSee('Search announcements...', false)
            ->assertSee('Free Deworming Program — August 30', false)
            ->assertSee('Infants 0–6 months', false)
            ->assertDontSee('Drafts', false)
            ->assertDontSee('Create Announcement', false)
            ->assertDontSee('Resident Preview', false)
            ->assertDontSee('Who needs to see this?', false)
            ->assertDontSee('Summary counts are temporary demo values for UI preview.', false)
            ->assertDontSee('Save as Draft', false)
            ->assertDontSee('A central view that summarizes key information for quick monitoring and decision-making.', false)
            ->assertDontSee('Health Condition', false)
            ->assertDontSee('>Pregnant</span>', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, '>Announcements</h1>'));
        $this->assertStringContainsString('lml-announce__dateblock', $html);
        $this->assertStringContainsString('data-lml-announce-manage', $html);
        $this->assertStringContainsString('data-announce-manage-search', $html);
        $this->assertStringContainsString('Showing', $html);
        $this->assertStringContainsString('href="'.e(route('announcements.create')).'"', $html);
        $this->assertStringContainsString('href="'.e(route('announcements.upcoming')).'"', $html);
        $this->assertStringContainsString('href="'.e(route('announcements.recent')).'"', $html);
        $this->assertStringNotContainsString('data-lml-announcement', $html);
        $this->assertStringNotContainsString('Post Announcement', $html);
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__link--active[^>]*>[\s\S]*?>\s*Announcement\s*<\/span>|aria-current="page"[^>]*>[\s\S]*?>\s*Announcement\s*<\/span>/u',
            $html
        );
    }

    public function test_upcoming_view_all_page_lists_future_events_with_sidebar_active(): void
    {
        $this->seedSidebarDemoAnnouncements();

        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('announcements.upcoming'))
            ->assertOk()
            ->assertSee('Upcoming Announcements', false)
            ->assertSee('View scheduled health activities and notices for residents.', false)
            ->assertSee('Back to Announcement', false)
            ->assertSee('Search announcements...', false)
            ->assertSee('This Week', false)
            ->assertSee('Free Deworming Program — August 30', false)
            ->assertDontSee('Who needs to see this?', false)
            ->assertDontSee('Save as Draft', false)
            ->assertDontSee('A central view that summarizes key information for quick monitoring and decision-making.', false)
            ->getContent();

        $this->assertStringContainsString('href="'.e(route('announcements.index')).'"', $html);
        $this->assertStringContainsString('lml-announce__dateblock', $html);
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__link--active[^>]*>[\s\S]*?>\s*Announcement\s*<\/span>|aria-current="page"[^>]*>[\s\S]*?>\s*Announcement\s*<\/span>/u',
            $html
        );
    }

    public function test_recent_view_all_page_lists_posted_notices_with_sidebar_active(): void
    {
        $this->seedSidebarDemoAnnouncements();

        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('announcements.recent'))
            ->assertOk()
            ->assertSee('Recent Announcements', false)
            ->assertSee('View recently posted health notices and announcements.', false)
            ->assertSee('Back to Announcement', false)
            ->assertSee('Search announcements...', false)
            ->assertSee('Posted', false)
            ->assertSee('Scheduled', false)
            ->assertDontSee('Who needs to see this?', false)
            ->assertDontSee('A central view that summarizes key information for quick monitoring and decision-making.', false)
            ->getContent();

        $this->assertStringContainsString('href="'.e(route('announcements.index')).'"', $html);
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__link--active[^>]*>[\s\S]*?>\s*Announcement\s*<\/span>|aria-current="page"[^>]*>[\s\S]*?>\s*Announcement\s*<\/span>/u',
            $html
        );
    }

    public function test_create_page_has_form_and_preview_with_sidebar_active(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('announcements.create'))
            ->assertOk()
            ->assertSee('Add Announcement', false)
            ->assertSee('Back to Announcement', false)
            ->assertSee('Create Announcement', false)
            ->assertSee('Announcement Preview', false)
            ->assertSee('Infants 0–6 months', false)
            ->assertSee('Infants 7–11 months', false)
            ->assertSee('Young Children 1–5 years', false)
            ->assertSee('Custom Age Range', false)
            ->assertSee('Months', false)
            ->assertSee('Years', false)
            ->assertSee('All Residents', false)
            ->assertSee('Specific Age Group', false)
            ->assertSee('Active Maternal', false)
            ->assertSee('Active FP User', false)
            ->assertSee('Target active maternal clients', false)
            ->assertSee('Target active family planning users', false)
            ->assertSee('Zone Coverage', false)
            ->assertSee('All Zones', false)
            ->assertSee('Specific Zones', false)
            ->assertDontSee('Health Condition', false)
            ->assertDontSee('>Pregnant</span>', false)
            ->assertDontSee('Lactating', false)
            ->assertDontSee('With Comorbidities', false)
            ->assertSee('Add Custom Zone', false)
            ->assertSee('Custom Zone / Purok', false)
            ->assertSee('Post Announcement', false)
            ->assertSee('Cancel', false)
            ->assertDontSee('Save as Draft', false)
            ->assertDontSee('Like', false)
            ->assertDontSee('>Specific Zone</span>', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/lml-sidebar__link--active[^>]*>[\s\S]*?>\s*Announcement\s*<\/span>|aria-current="page"[^>]*>[\s\S]*?>\s*Announcement\s*<\/span>/u',
            $html
        );
        $this->assertStringContainsString('data-lml-announcement', $html);
        $this->assertStringContainsString('Who needs to see this?', $html);
        $this->assertStringContainsString('data-announce-age-from-unit', $html);
        $this->assertStringContainsString('data-announce-age-to-unit', $html);
        $this->assertStringContainsString('data-announce-zone-coverage', $html);
        $this->assertStringContainsString('data-announce-custom-zone', $html);
        $this->assertStringContainsString('Zone 1', $html);
        $this->assertStringContainsString('Zone 5', $html);
        $this->assertStringContainsString('Coverage: All Zones', $html);
        $this->assertStringContainsString('href="'.e(route('announcements.index')).'"', $html);
        $this->assertStringNotContainsString('Infants 0–1', $html);
        $this->assertStringNotContainsString('Upcoming Announcements', $html);
    }

    public function test_sidebar_active_key_maps_announcement_routes(): void
    {
        $this->pretendNamedRoute('announcements.index');
        $this->assertSame('announcement', UiRole::sidebarActiveKey());

        $this->pretendNamedRoute('announcements.create');
        $this->assertSame('announcement', UiRole::sidebarActiveKey());

        $this->pretendNamedRoute('announcements.upcoming');
        $this->assertSame('announcement', UiRole::sidebarActiveKey());

        $this->pretendNamedRoute('announcements.recent');
        $this->assertSame('announcement', UiRole::sidebarActiveKey());
    }

    public function test_legacy_announcement_path_redirects_to_dashboard(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $this->get('/announcement')
            ->assertRedirect('/announcements');
    }
}
