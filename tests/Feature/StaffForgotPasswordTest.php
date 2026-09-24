<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Models\ResidentAccount;
use App\Models\User;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class StaffForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'OldStaffPass!1';

    private const NEW_PASSWORD = 'NewStaffPass!9';

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createStaff(string $role, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'password' => self::OLD_PASSWORD,
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
        ], $overrides));

        $user->assignCurrentAppointment([
            'role' => $role,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        return $user->fresh(['currentAppointment']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function resetPayload(User $user, string $token, array $overrides = []): array
    {
        return array_merge([
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $overrides);
    }

    public function test_forgot_password_page_loads_for_guests(): void
    {
        $html = $this->get(route('password.request'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Forgot Password', $html);
        $this->assertStringContainsString('Send Verification', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('action="'.e(route('password.email')).'"', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringNotContainsString('Placeholder action only', $html);
        $this->assertStringNotContainsString('action="#"', $html);
        $this->assertStringContainsString('href="'.e(route('login')).'"', $html);
    }

    public function test_registered_admin_email_can_request_reset(): void
    {
        Notification::fake();
        $admin = $this->createStaff(StaffRole::ADMIN, [
            'email' => 'admin.reset@example.test',
        ]);

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $admin->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        Notification::assertSentTo($admin, ResetPassword::class);
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $admin->email,
        ]);
    }

    public function test_registered_bhw_email_can_request_reset(): void
    {
        Notification::fake();
        $bhw = $this->createStaff(StaffRole::BHW, [
            'email' => 'bhw.reset@example.test',
        ]);

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $bhw->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        Notification::assertSentTo($bhw, ResetPassword::class);
    }

    public function test_unknown_email_does_not_expose_account_existence(): void
    {
        Notification::fake();
        $known = $this->createStaff(StaffRole::ADMIN, [
            'email' => 'known.reset@example.test',
        ]);

        $unknown = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'nobody@example.test']);

        $unknown->assertRedirect(route('password.request'))
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE)
            ->assertSessionDoesntHaveErrors();
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'nobody@example.test',
        ]);

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $known->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        Notification::assertSentTo($known, ResetPassword::class);
    }

    public function test_valid_reset_token_resets_password_and_logs_in_with_new_password(): void
    {
        $user = $this->createStaff(StaffRole::ADMIN, [
            'email' => 'admin.token@example.test',
            'must_change_password' => true,
        ]);
        $token = Password::broker()->createToken($user);
        $staffCount = User::query()->count();
        $appointmentId = $user->resolveCurrentAppointment()?->getKey();

        $this->get(route('password.reset', [
            'token' => $token,
            'email' => $user->email,
        ]))->assertOk()
            ->assertSee('Reset Password')
            ->assertSee('New Password')
            ->assertSee('Confirm New Password')
            ->assertSee('Reset Password', false);

        $this->from(route('password.reset', ['token' => $token]))
            ->post(route('password.update'), $this->resetPayload($user, $token))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', ForgotPasswordController::RESET_SUCCESS_MESSAGE);

        $fresh = $user->fresh(['currentAppointment']);
        $this->assertNotNull($fresh);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $fresh->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, (string) $fresh->password));
        $this->assertFalse($fresh->must_change_password);
        $this->assertSame(StaffRole::ADMIN, $fresh->role);
        $this->assertSame(StaffAccountStatus::ACTIVE, $fresh->status);
        $this->assertSame($appointmentId, $fresh->resolveCurrentAppointment()?->getKey());
        $this->assertSame($staffCount, User::query()->count());
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $user->email,
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => self::OLD_PASSWORD,
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($fresh->fresh(['currentAppointment']));
    }

    public function test_bhw_reset_leaves_role_and_appointment_unchanged(): void
    {
        $user = $this->createStaff(StaffRole::BHW, [
            'email' => 'bhw.token@example.test',
        ]);
        $token = Password::broker()->createToken($user);
        $appointment = $user->resolveCurrentAppointment();
        $this->assertNotNull($appointment);

        $this->post(route('password.update'), $this->resetPayload($user, $token))
            ->assertRedirect(route('login'));

        $fresh = $user->fresh(['currentAppointment']);
        $this->assertSame(StaffRole::BHW, $fresh?->role);
        $this->assertSame($appointment->getKey(), $fresh?->resolveCurrentAppointment()?->getKey());
        $this->assertSame($appointment->assigned_barangay, $fresh?->resolveCurrentAppointment()?->assigned_barangay);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])->assertRedirect(route('dashboard'));
    }

    public function test_invalid_token_cannot_reset_password(): void
    {
        $user = $this->createStaff(StaffRole::ADMIN, [
            'email' => 'admin.invalid@example.test',
        ]);

        $this->from(route('password.reset', ['token' => 'not-a-real-token']))
            ->post(route('password.update'), $this->resetPayload($user, 'not-a-real-token'))
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, (string) $user->fresh()->password));
    }

    public function test_expired_token_cannot_reset_password(): void
    {
        $user = $this->createStaff(StaffRole::BHW, [
            'email' => 'bhw.expired@example.test',
        ]);
        $token = Password::broker()->createToken($user);

        $this->travel(61)->minutes();

        $this->from(route('password.reset', ['token' => $token]))
            ->post(route('password.update'), $this->resetPayload($user, $token))
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, (string) $user->fresh()->password));
    }

    public function test_token_cannot_be_reused_after_successful_reset(): void
    {
        $user = $this->createStaff(StaffRole::ADMIN, [
            'email' => 'admin.reuse@example.test',
        ]);
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), $this->resetPayload($user, $token))
            ->assertRedirect(route('login'));

        $this->from(route('password.reset', ['token' => $token]))
            ->post(route('password.update'), $this->resetPayload($user, $token, [
                'password' => 'ThirdStaffPass!3',
                'password_confirmation' => 'ThirdStaffPass!3',
            ]))
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $user->fresh()->password));
        $this->assertFalse(Hash::check('ThirdStaffPass!3', (string) $user->fresh()->password));
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $user = $this->createStaff(StaffRole::ADMIN, [
            'email' => 'admin.mismatch@example.test',
        ]);
        $token = Password::broker()->createToken($user);

        $this->from(route('password.reset', ['token' => $token]))
            ->post(route('password.update'), $this->resetPayload($user, $token, [
                'password_confirmation' => 'DifferentPass!1',
            ]))
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, (string) $user->fresh()->password));
    }

    public function test_password_policy_is_enforced_on_reset(): void
    {
        $user = $this->createStaff(StaffRole::BHW, [
            'email' => 'bhw.policy@example.test',
        ]);
        $token = Password::broker()->createToken($user);

        $this->from(route('password.reset', ['token' => $token]))
            ->post(route('password.update'), $this->resetPayload($user, $token, [
                'password' => 'weak',
                'password_confirmation' => 'weak',
            ]))
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, (string) $user->fresh()->password));
    }

    public function test_another_users_token_cannot_reset_this_users_password(): void
    {
        $owner = $this->createStaff(StaffRole::ADMIN, [
            'email' => 'owner.reset@example.test',
        ]);
        $target = $this->createStaff(StaffRole::BHW, [
            'email' => 'target.reset@example.test',
        ]);
        $ownerToken = Password::broker()->createToken($owner);

        $this->post(route('password.update'), $this->resetPayload($target, $ownerToken))
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, (string) $owner->fresh()->password));
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, (string) $target->fresh()->password));
    }

    public function test_forged_user_id_and_email_do_not_bypass_token_ownership(): void
    {
        $owner = $this->createStaff(StaffRole::ADMIN, [
            'email' => 'forged.owner@example.test',
        ]);
        $other = $this->createStaff(StaffRole::BHW, [
            'email' => 'forged.other@example.test',
        ]);
        $token = Password::broker()->createToken($owner);

        $this->post(route('password.update'), $this->resetPayload($other, $token, [
            'user_id' => $owner->getKey(),
            'id' => $owner->getKey(),
        ]))->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, (string) $owner->fresh()->password));
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, (string) $other->fresh()->password));
    }

    public function test_resident_account_password_remains_untouched(): void
    {
        $staff = $this->createStaff(StaffRole::ADMIN, [
            'email' => 'staff.isolation@example.test',
        ]);
        $resident = ResidentAccount::factory()->create([
            'email' => 'resident.isolation@example.test',
            'password' => 'ResidentPass!1',
        ]);
        $residentHash = (string) $resident->password;
        $residentCount = ResidentAccount::query()->count();
        $token = Password::broker()->createToken($staff);

        $this->post(route('password.update'), $this->resetPayload($staff, $token))
            ->assertRedirect(route('login'));

        $this->assertSame($residentHash, (string) $resident->fresh()->password);
        $this->assertTrue(Hash::check('ResidentPass!1', (string) $resident->fresh()->password));
        $this->assertSame($residentCount, ResidentAccount::query()->count());
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $staff->fresh()->password));
    }

    public function test_chatbot_forgot_password_route_is_unchanged(): void
    {
        $this->get(route('chatbot.password.request'))
            ->assertOk()
            ->assertSee('lml-chatbot-forgot-password', false);

        $this->get(route('chatbot.password.reset'))
            ->assertOk();
    }

    public function test_authenticated_staff_cannot_use_guest_reset_routes(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $this->get(route('password.request'))->assertRedirect(route('dashboard'));
        $this->post(route('password.email'), ['email' => 'anyone@example.test'])
            ->assertRedirect(route('dashboard'));
        $this->get(route('password.reset', ['token' => 'abc']))
            ->assertRedirect(route('dashboard'));
        $this->post(route('password.update'), [
            'token' => 'abc',
            'email' => 'anyone@example.test',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('dashboard'));
    }

    public function test_reset_link_in_notification_uses_staff_reset_route(): void
    {
        Notification::fake();
        $user = $this->createStaff(StaffRole::ADMIN, [
            'email' => 'link.check@example.test',
        ]);

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            $url = (string) $mail->actionUrl;

            return str_contains($url, '/reset-password/'.$notification->token)
                && str_contains($url, 'email=');
        });
    }
}
