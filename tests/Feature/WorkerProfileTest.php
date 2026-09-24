<?php

namespace Tests\Feature;

use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('profile.show'))->assertRedirect(route('login'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function staffRoles(): array
    {
        return [
            [StaffRole::ADMIN],
            [StaffRole::BHW],
            [StaffRole::BNS],
            [StaffRole::BSPO],
        ];
    }

    #[DataProvider('staffRoles')]
    public function test_authenticated_staff_can_view_own_profile(string $role): void
    {
        $user = $this->actingAsStaff($role, [
            'first_name' => 'Rowena',
            'middle_name' => 'Cruz',
            'last_name' => 'Villanueva',
            'email' => $role.'.profile@example.test',
            'username' => $role.'.profile',
            'mobile_number' => '09170001111',
        ]);

        $html = $this->get(route('profile.show'))
            ->assertOk()
            ->assertSee('data-lml-user-profile-page', false)
            ->assertSee('data-profile-change-password', false)
            ->assertSee(route('password.change.required'), false)
            ->getContent();

        $this->assertStringContainsString('Rowena Cruz Villanueva', $html);
        $this->assertStringContainsString($user->email, $html);
        $this->assertStringContainsString($user->username, $html);
        $this->assertStringContainsString('09170001111', $html);
        $this->assertStringContainsString('La Medalla', $html);
        $this->assertStringContainsString('Zone 1', $html);
        $this->assertStringContainsString(StaffAccountStatus::ACTIVE, $html);
        $this->assertStringNotContainsString((string) $user->password, $html);
        $this->assertStringNotContainsString('remember_token', $html);

        $this->assertStringContainsString('lml-hw-view__toolbar', $html);
        $this->assertStringContainsString('lml-hw-view__back', $html);
        $this->assertStringContainsString('data-profile-back', $html);
        $this->assertStringContainsString('href="'.e(route('dashboard')).'"', $html);
        $this->assertStringContainsString('>Back</span>', $html);
        $this->assertStringContainsString('Back to Dashboard', $html);
        $this->assertStringNotContainsString('javascript:history.back()', $html);
    }

    public function test_dashboard_shell_links_to_profile(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('lml-sidebar__profile', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-sidebar__footer"[\s\S]*?>User Profile<\/span>/',
            $html
        );

        $this->assertStringContainsString('lml-topbar__profile-link', $html);
        $this->assertStringContainsString('data-lml-user-profile', $html);
        $this->assertStringContainsString('data-lml-user-profile-avatar', $html);
        $this->assertStringContainsString('href="'.e(route('profile.show')).'"', $html);
        $this->assertStringContainsString('lml-sidebar__logout', $html);
        $this->assertStringContainsString('>Logout</span>', $html);
    }

    public function test_profile_change_password_updates_hash_without_forced_flag(): void
    {
        $user = $this->actingAsStaff(StaffRole::BHW, [
            'must_change_password' => false,
            'password' => 'OldPass!123',
        ]);

        $this->get(route('profile.show'))->assertOk();

        $this->post(route('password.change.store'), [
            'new_password' => 'NewPass!456',
            'new_password_confirmation' => 'NewPass!456',
        ])->assertRedirect(route('profile.show'));

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewPass!456', (string) $user->fresh()->password));
        $this->assertFalse((bool) $user->fresh()->must_change_password);
    }
}
