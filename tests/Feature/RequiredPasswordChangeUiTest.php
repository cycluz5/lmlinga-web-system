<?php

namespace Tests\Feature;

use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequiredPasswordChangeUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_change_password_screen_is_voluntary_and_links_back_to_profile(): void
    {
        $this->actingAsStaff(StaffRole::BHW, ['must_change_password' => true]);

        $html = $this->get(route('password.change.required'))->assertOk()->getContent();

        $this->assertStringContainsString('Change Password', $html);
        $this->assertStringContainsString('Choose a new password for your staff account', $html);
        $this->assertStringContainsString('New Password', $html);
        $this->assertStringContainsString('Confirm New Password', $html);
        $this->assertStringContainsString('Update Password', $html);
        $this->assertStringContainsString('href="'.e(route('profile.show')).'"', $html);
        $this->assertStringContainsString('Back to User Profile', $html);

        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="#main-content"[^>]*>\s*Skip to main content\s*<\/a>/i',
            $html
        );

        $this->assertStringNotContainsString('>Skip</', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*>\s*Skip\s*<\/button>/i', $html);
        $this->assertStringNotContainsString('Maybe later', $html);
        $this->assertStringNotContainsString('temporary password must be replaced', $html);
        $this->assertStringNotContainsString(route('dashboard'), $html);
        $this->assertStringNotContainsString('href="'.e(route('login')).'"', $html);
    }

    public function test_workers_with_cleared_flag_can_open_change_password(): void
    {
        $this->actingAsStaff(StaffRole::BHW, ['must_change_password' => false]);

        $this->get(route('password.change.required'))->assertOk();
    }
}
