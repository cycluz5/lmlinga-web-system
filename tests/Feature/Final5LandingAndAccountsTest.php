<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Support\HealthWorkerUiCatalog;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FINAL-5 — landing personalization, name-only topbar, User Management DB-only.
 */
class Final5LandingAndAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_loads_with_local_imagery_login_cta_and_accessible_structure(): void
    {
        $illustration = public_path('assets/images/illustrations/landing.png');
        $logo = public_path('assets/images/logo/logo.png');

        $this->assertFileExists($illustration);
        $this->assertFileExists($logo);

        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringContainsString('id="main-content"', $html);
        $this->assertStringContainsString('LMLinga', $html);
        $this->assertStringContainsString('Welcome to', $html);
        $this->assertStringContainsString('Our Barangay Health Team', $html);
        $this->assertStringContainsString('href="'.e(route('login')).'"', $html);
        $this->assertGreaterThanOrEqual(1, substr_count($html, 'href="'.e(route('login')).'"'));

        $this->assertStringContainsString('assets/images/illustrations/landing.png', $html);
        $this->assertStringContainsString('assets/images/logo/logo.png', $html);
        $this->assertStringNotContainsString('https://picsum', $html);
        $this->assertStringNotContainsString('unsplash', $html);
        $this->assertDoesNotMatchRegularExpression('/src=["\']data:image\//', $html);

        $this->assertStringContainsString('lml-hero-title', $html);
        $this->assertStringContainsString('lml-focus-ring', $html);
        $this->assertStringNotContainsString('lml-landing-hero__login', $html);
        $this->assertStringNotContainsString('lml-landing-footer', $html);

        $this->assertStringNotContainsString('Kristine Reyes', $html);
        $this->assertStringNotContainsString('Juan Dela Cruz', $html);
        $this->assertStringNotContainsString('Andrei', $html);
        $this->assertStringNotContainsString('HH-151', $html);
        $this->assertStringNotContainsString('Admin Sarah', $html);
        $this->assertStringNotContainsString('households registered', $html);
        $this->assertStringNotContainsString('residents served', $html);
    }

    public function test_root_redirects_to_personalized_landing(): void
    {
        $this->get('/')->assertRedirect(route('landing'));
    }

    public function test_admin_topbar_shows_name_only_without_role(): void
    {
        $user = $this->actingAsStaff(StaffRole::ADMIN, [
            'first_name' => 'Maria',
            'middle_name' => 'Lopez',
            'last_name' => 'Santos',
            'suffix' => null,
        ]);

        $this->assertSame('Maria Lopez Santos', UiRole::displayName());
        $this->assertSame('Maria Lopez Santos', $user->composeDisplayName());

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/class="lml-topbar__user-name mb-0">\s*Maria Lopez Santos\s*<\/p>/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-topbar__user-name mb-0">[^<]*(Admin|BHW|Staff)/',
            $html
        );
        $this->assertStringNotContainsString('Maria Lopez Santos Admin', $html);
        $this->assertStringNotContainsString('Admin User', $html);
        $this->assertStringContainsString('>User Management</span>', $html);
    }

    public function test_bhw_topbar_shows_name_only_and_cannot_open_user_management(): void
    {
        $this->actingAsStaff(StaffRole::BHW, [
            'first_name' => 'Ana',
            'middle_name' => null,
            'last_name' => 'Reyes',
            'suffix' => null,
        ]);

        $this->assertSame('Ana Reyes', UiRole::displayName());

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/class="lml-topbar__user-name mb-0">\s*Ana Reyes\s*<\/p>/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-topbar__user-name mb-0">[^<]*(Admin|BHW|Staff)/',
            $html
        );
        $this->assertStringNotContainsString('Ana Reyes BHW', $html);

        $this->get(route('user-management.index'))->assertForbidden();
        $this->get(route('household-profiling.index'))->assertOk();
    }

    public function test_display_name_never_falls_back_to_admin_user_or_sarah(): void
    {
        $this->assertSame('Guest', UiRole::displayName());
        $this->assertSame('Guest', UiRole::displayName('admin'));
        $this->assertSame('Guest', UiRole::displayName('bhw'));
    }

    public function test_user_management_renders_database_accounts_not_static_catalog(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN, [
            'first_name' => 'Session',
            'middle_name' => null,
            'last_name' => 'Admin',
        ]);

        $worker = User::factory()->create([
            'first_name' => 'Jordan',
            'middle_name' => null,
            'last_name' => 'Nguyen',
            'email' => 'jordan.nguyen.final5@example.test',
            'username' => 'jordan.nguyen.final5',
            'status' => StaffAccountStatus::INACTIVE,
            'sex' => null,
            'date_of_birth' => null,
            'civil_status' => null,
            'nationality' => null,
            'must_change_password' => false,
        ]);
        $worker->assignCurrentAppointment([
            'role' => StaffRole::BNS,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 4',
            'date_appointed' => '2021-06-15',
        ]);

        $index = $this->get(route('user-management.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Jordan Nguyen', $index);
        $this->assertStringContainsString((string) $worker->id, $index);
        $this->assertStringContainsString('BNS', $index);
        $this->assertStringContainsString('Inactive', $index);
        $this->assertStringContainsString('Zone 4', $index);
        $this->assertStringNotContainsString('data-demo="true"', $index);
        $this->assertStringNotContainsString('Admin Sarah', $index);
        $this->assertStringNotContainsString('hw-001', $index);
        $this->assertStringNotContainsString('sarah.santos@example.com', $index);

        $view = $this->get(route('user-management.health-workers.view', ['id' => (string) $worker->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Jordan', $view);
        $this->assertStringContainsString('Nguyen', $view);
        $this->assertStringContainsString('jordan.nguyen.final5@example.test', $view);
        $this->assertStringContainsString('BNS', $view);
        $this->assertStringContainsString('Inactive', $view);
        $this->assertStringContainsString('06/15/2021', $view);
        $this->assertMatchesRegularExpression('/<dt>\s*Sex\s*<\/dt>\s*<dd>\s*—\s*<\/dd>/u', $view);
        $this->assertMatchesRegularExpression('/<dt>\s*Date of Birth\s*<\/dt>\s*<dd>\s*—\s*<\/dd>/u', $view);
        $this->assertStringNotContainsString('Admin Sarah', $view);
        $this->assertStringNotContainsString('Filipino', $view);
        $this->assertStringNotContainsString('04/01/1990', $view);
    }

    public function test_user_management_empty_state_and_unknown_demo_id(): void
    {
        $this->assertSame([], HealthWorkerUiCatalog::all());
        $this->assertNull(HealthWorkerUiCatalog::find('hw-001'));

        $this->actingAsStaff(StaffRole::ADMIN);

        $html = view('pages.user-management.index', [
            'active' => 'user-management',
            'pageTitle' => 'User Management',
            'pageSubtitle' => 'Manage accounts of the Barangay Health Workers',
            'healthWorkers' => [],
            'residentAccounts' => [],
        ])->render();

        $this->assertStringContainsString('No registered accounts found.', $html);
        $this->assertStringNotContainsString('Admin Sarah', $html);
        $this->assertStringNotContainsString('data-demo="true"', $html);

        $missing = $this->get(route('user-management.health-workers.view', ['id' => 'hw-001']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Health worker not found', $missing);
        $this->assertStringNotContainsString('Admin Sarah', $missing);
        $this->assertStringNotContainsString('Sarah Cruz Santos', $missing);
    }

    public function test_household_profiling_keeps_final2_display_rules(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $household = Household::factory()->create([
            'household_no' => 'HH-001',
            'zone' => 'Zone 3',
            'street' => 'Must Not Appear St.',
            'date_registered' => '2026-03-15',
            'accomplished_by' => 'Must Not Appear Staff',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-010',
            'first_name' => 'Persisted',
            'middle_name' => null,
            'last_name' => 'Head',
            'relation' => 'Head',
            'birthday' => '1985-07-09',
            'occupation' => 'Farmer',
        ]);

        $index = $this->get(route('household-profiling.index'))->assertOk()->getContent();
        $this->assertStringContainsString('>HH No.</th>', $index);
        $this->assertStringContainsString('>HH Head</th>', $index);
        $this->assertStringContainsString('>Zone</th>', $index);
        $this->assertStringContainsString('>No. of Members</th>', $index);
        $this->assertStringContainsString('>Actions</th>', $index);
        $this->assertStringNotContainsString('>Street</th>', $index);
        $this->assertStringNotContainsString('Must Not Appear St.', $index);
        $this->assertStringContainsString('Persisted Head', $index);
        $this->assertStringContainsString('Zone 3', $index);

        $profile = $this->get(route('household-profiling.view', ['householdNo' => 'HH-001']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Zone', $profile);
        $this->assertStringContainsString('Zone 3', $profile);
        $this->assertStringContainsString('Accomplished Date', $profile);
        $this->assertStringContainsString('03/15/2026', $profile);
        $this->assertStringNotContainsString('Must Not Appear St.', $profile);
        $this->assertStringNotContainsString('Must Not Appear Staff', $profile);
        $this->assertStringNotContainsString('>Street</span>', $profile);
        $this->assertStringNotContainsString('>Accomplished By</span>', $profile);
        $this->assertStringNotContainsString('Kristine Reyes', $profile);
        $this->assertStringContainsString('data-source="db"', $profile);

        $this->get(route('household-profiling.view', ['householdNo' => 'HH-151']))
            ->assertOk()
            ->assertSee('Household not found', false)
            ->assertDontSee('Kristine Reyes', false);
    }
}
