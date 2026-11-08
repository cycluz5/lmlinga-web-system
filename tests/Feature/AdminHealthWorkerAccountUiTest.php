<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminHealthWorkerAccountUiTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_ADMIN_EMAIL = 'admin@example.test';

    private const TEST_ADMIN_PASSWORD = 'test-admin-password';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'demo.staff_accounts' => [
                [
                    'email' => self::TEST_ADMIN_EMAIL,
                    'password' => self::TEST_ADMIN_PASSWORD,
                    'shell_role' => 'admin',
                    'display_name' => 'Test Admin',
                    'identities' => [self::TEST_ADMIN_EMAIL],
                ],
            ],
        ]);
    }

    public function test_admin_manage_health_workers_links_to_create_account(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('user-management.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="'.e(route('user-management.health-workers.create')).'"', $html);
        $this->assertStringContainsString('Add Health Worker', $html);
        $this->assertStringNotContainsString('ADD is a UI placeholder', $html);
    }

    public function test_admin_create_account_screen_is_inside_user_management(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('user-management.health-workers.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Create Account', $html);
        $this->assertStringContainsString('Temporary Password', $html);
        $this->assertStringContainsString('Confirm Password', $html);
        $this->assertStringContainsString('Back to Manage Health Workers', $html);
        $this->assertStringContainsString('data-lml-hw-create', $html);
        $this->assertStringContainsString('lml-sidebar__link--active', $html);
        $this->assertStringContainsString('>User Management</span>', $html);
    }

    public function test_bhw_cannot_open_create_health_worker(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $this->get(route('user-management.health-workers.create'))
            ->assertForbidden();
    }

    public function test_admin_dashboard_exposes_user_management_after_database_login(): void
    {
        $admin = User::factory()->create([
            'email' => self::TEST_ADMIN_EMAIL,
            'username' => 'admin.example',
            'password' => self::TEST_ADMIN_PASSWORD,
            'must_change_password' => false,
            'status' => StaffAccountStatus::ACTIVE,
        ]);
        $admin->assignCurrentAppointment([
            'role' => StaffRole::ADMIN,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        $this->post(route('login.store'), [
            'email' => self::TEST_ADMIN_EMAIL,
            'password' => self::TEST_ADMIN_PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('>User Management</span>', $html);
        $this->assertStringContainsString('href="'.e(route('user-management.index')).'"', $html);

        $this->get(route('user-management.health-workers.create'))->assertOk();
    }

    public function test_create_form_does_not_hardcode_demo_worker_redirect(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('user-management.health-workers.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-profile-url', $html);
        $this->assertStringNotContainsString('/health-workers/hw-001/view', $html);
    }

    public function test_worker_profile_and_edit_account_details_are_wired(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $worker = User::factory()->create([
            'must_change_password' => false,
            'status' => StaffAccountStatus::ACTIVE,
            'first_name' => 'Wired',
            'middle_name' => null,
            'last_name' => 'Account',
        ]);
        $worker->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);
        $id = (string) $worker->id;

        $view = $this->get(route('user-management.health-workers.view', ['id' => $id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Edit Account Details', $view);
        $this->assertStringContainsString('Back to Manage Health Workers', $view);
        $this->assertStringContainsString(route('user-management.health-workers.edit', ['id' => $id]), $view);
        $this->assertStringContainsString('Wired', $view);
        $this->assertStringNotContainsString('Admin Sarah', $view);

        $edit = $this->get(route('user-management.health-workers.edit', ['id' => $id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Edit Account Details', $edit);
        $this->assertStringContainsString('data-hw-wizard-save', $edit);
        $this->assertStringContainsString('data-hw-wizard-cancel', $edit);
        $this->assertStringContainsString('Back to Manage Health Workers', $edit);
        $this->assertStringNotContainsString('Admin Sarah', $edit);
    }

    public function test_demo_hw_profile_is_not_served(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $view = $this->get(route('user-management.health-workers.view', ['id' => 'hw-001']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Health worker not found', $view);
        $this->assertStringNotContainsString('Admin Sarah', $view);
        $this->assertStringNotContainsString('sarah.santos', $view);
        $this->assertStringNotContainsString('Edit Account Details', $view);

        $edit = $this->get(route('user-management.health-workers.edit', ['id' => 'hw-001']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Health worker not found', $edit);
        $this->assertStringNotContainsString('Admin Sarah', $edit);
        $this->assertStringNotContainsString('data-hw-wizard-save', $edit);
    }
}
