<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTopbarUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_topbar_does_not_render_notification_bell(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('bi-bell', $html);
        $this->assertStringNotContainsString('aria-label="Notifications"', $html);
        $this->assertStringContainsString('Log Out', $html);
        $this->assertStringContainsString('Connection status: checking', $html);
    }

    public function test_topbar_user_name_does_not_append_admin_role(): void
    {
        $user = $this->actingAsStaff(StaffRole::ADMIN, [
            'first_name' => 'Maria',
            'middle_name' => 'Lopez',
            'last_name' => 'Santos',
            'suffix' => null,
        ]);

        $html = $this->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertSame('Maria Lopez Santos', $user->composeDisplayName());
        $this->assertMatchesRegularExpression(
            '/class="lml-topbar__user-name mb-0">\s*Maria Lopez Santos\s*<\/p>/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-topbar__user-name mb-0">[^<]*Admin/',
            $html
        );
    }

    public function test_topbar_user_name_does_not_append_bhw_role(): void
    {
        $this->actingAsStaff(StaffRole::BHW, [
            'first_name' => 'Ana',
            'middle_name' => null,
            'last_name' => 'Reyes',
            'suffix' => null,
        ]);

        $html = $this->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/class="lml-topbar__user-name mb-0">\s*Ana Reyes\s*<\/p>/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-topbar__user-name mb-0">[^<]*(BHW|BNS|BSPO)/',
            $html
        );
    }

    public function test_topbar_user_name_does_not_append_bns_or_bspo_role(): void
    {
        foreach ([StaffRole::BNS, StaffRole::BSPO] as $role) {
            $this->actingAsStaff($role, [
                'first_name' => 'Liza',
                'middle_name' => null,
                'last_name' => 'Cruz',
                'suffix' => null,
            ]);

            $html = $this->get(route('dashboard'))
                ->assertOk()
                ->getContent();

            $this->assertMatchesRegularExpression(
                '/class="lml-topbar__user-name mb-0">\s*Liza Cruz\s*<\/p>/',
                $html
            );
            $this->assertDoesNotMatchRegularExpression(
                '/class="lml-topbar__user-name mb-0">[^<]*(Admin|BHW|BNS|BSPO)/',
                $html
            );
        }
    }
}
