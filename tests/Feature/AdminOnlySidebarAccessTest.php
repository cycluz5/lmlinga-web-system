<?php

namespace Tests\Feature;

use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminOnlySidebarAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_user_management_and_requests(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->get(route('user-management.index'))->assertOk();
        $this->get(route('household-requests.index'))->assertOk();
        $this->get(route('death-requests.index'))->assertOk();
    }

    /**
     * @return list<list<string>>
     */
    public static function workerRoles(): array
    {
        return [
            [StaffRole::BHW],
            [StaffRole::BNS],
            [StaffRole::BSPO],
        ];
    }

    #[DataProvider('workerRoles')]
    public function test_workers_cannot_open_admin_modules_directly(string $role): void
    {
        $this->actingAsStaff($role);

        $this->get(route('user-management.index'))->assertForbidden();
        $this->get(route('household-requests.index'))->assertForbidden();
        $this->get(route('death-requests.index'))->assertForbidden();
        $this->get(route('user-management.health-workers.create'))->assertForbidden();
    }

    #[DataProvider('workerRoles')]
    public function test_workers_still_see_disabled_admin_sidebar_items(string $role): void
    {
        $this->actingAsStaff($role);

        $html = $this->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>User Management</span>', $html);
        $this->assertStringContainsString('>Requests</span>', $html);
        $this->assertStringContainsString('>Household Requests</span>', $html);
        $this->assertStringContainsString('>Mortality</span>', $html);
        $this->assertStringContainsString('data-lml-admin-only', $html);
        $this->assertStringContainsString('lml-sidebar__link--disabled', $html);
        $this->assertStringNotContainsString('href="'.e(route('user-management.index')).'"', $html);
        $this->assertStringNotContainsString('href="'.e(route('household-requests.index')).'"', $html);
        $this->assertStringNotContainsString('href="'.e(route('death-requests.index')).'"', $html);

        $umPos = strpos($html, '>User Management</span>');
        $reqPos = strpos($html, '>Requests</span>');
        $annPos = strpos($html, '>Announcement</span>');
        $this->assertNotFalse($umPos);
        $this->assertNotFalse($reqPos);
        $this->assertNotFalse($annPos);
        $this->assertGreaterThan($umPos, $reqPos);
        $this->assertGreaterThan($reqPos, $annPos);
    }

    public function test_admin_sidebar_keeps_enabled_user_management_and_requests_links(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $html = $this->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="'.e(route('user-management.index')).'"', $html);
        $this->assertStringContainsString('href="'.e(route('household-requests.index')).'"', $html);
        $this->assertStringContainsString('href="'.e(route('death-requests.index')).'"', $html);
        $this->assertStringNotContainsString('lml-sidebar__link--disabled', $html);
        $this->assertStringNotContainsString('data-lml-admin-only', $html);
    }
}
