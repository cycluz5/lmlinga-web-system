<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Mail\StaffEmailVerificationOtpMail;
use App\Models\User;
use App\Services\StaffEmailVerificationService;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Tests\TestCase;

/**
 * Batch 2 — staff forgot-password: tokens only for registered, eligible accounts; one generic response for
 * every address; per-address and per-IP limits; links stop working when the account stops being eligible.
 */
class StaffForgotPasswordEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function staff(string $email, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'email' => $email,
            'username' => 'u.'.substr(md5($email), 0, 8),
            'password' => 'OldPassword!1',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
        ], $overrides));
        $user->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        return $user->fresh(['currentAppointment']);
    }

    private function requestReset(string $email)
    {
        return $this->from(route('password.request'))->post(route('password.email'), ['email' => $email]);
    }

    private function tokenCount(string $email): int
    {
        return DB::table('password_reset_tokens')->where('email', strtolower($email))->count();
    }

    private function resetWith(User $user, string $token)
    {
        return $this->from(route('password.reset', ['token' => $token]))->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'BrandNew!Pass9',
            'password_confirmation' => 'BrandNew!Pass9',
        ]);
    }

    // ------------------------------------------------------------------ issuing

    public function test_registered_active_account_gets_a_token_and_a_reset_email(): void
    {
        $user = $this->staff('active.reset@example.test');

        $this->requestReset('Active.Reset@Example.test')
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        $this->assertSame(1, $this->tokenCount($user->email));
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_unregistered_email_is_told_it_is_not_registered_and_gets_no_token_or_email(): void
    {
        $this->staff('someone.real@example.test');

        $this->requestReset('nobody.here@example.test')
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors(['email' => ForgotPasswordController::NOT_REGISTERED_MESSAGE])
            ->assertSessionMissing('status');

        $this->assertSame(0, $this->tokenCount('nobody.here@example.test'));
        Notification::assertNothingSent();
    }

    public function test_not_registered_message_is_shown_on_the_page_and_kept_in_the_input(): void
    {
        $this->requestReset('dummy.person@example.test');

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee(ForgotPasswordController::NOT_REGISTERED_MESSAGE);
    }

    public function test_inactive_account_gets_no_token_and_no_email(): void
    {
        $user = $this->staff('inactive.reset@example.test', ['status' => StaffAccountStatus::INACTIVE]);

        $this->requestReset($user->email)
            ->assertSessionHasErrors(['email' => ForgotPasswordController::NOT_ELIGIBLE_MESSAGE]);

        $this->assertSame(0, $this->tokenCount($user->email));
        Notification::assertNothingSent();
    }

    public function test_account_pending_email_verification_gets_no_token_and_no_email(): void
    {
        $user = $this->staff('pending.reset@gmail.com');
        app(StaffEmailVerificationService::class)->markPending($user);

        $this->requestReset($user->email)
            ->assertSessionHasErrors(['email' => ForgotPasswordController::NOT_ELIGIBLE_MESSAGE]);

        $this->assertSame(0, $this->tokenCount($user->email));
        Notification::assertNothingSent();
    }

    public function test_invalid_email_format_is_rejected_by_validation(): void
    {
        $this->requestReset('not-an-email')->assertSessionHasErrors(['email' => 'Enter a valid email address.']);
        Notification::assertNothingSent();
    }

    public function test_mail_failure_does_not_change_the_response(): void
    {
        $user = $this->staff('mail.fail@example.test');
        Password::shouldReceive('sendResetLink')->andThrow(new RuntimeException('SMTP down'));

        $this->requestReset($user->email)
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);
    }

    // ------------------------------------------------------------------ limits

    public function test_per_address_limit_applies_to_registered_addresses_with_one_message(): void
    {
        $user = $this->staff('limited.reset@example.test');

        for ($i = 0; $i < ForgotPasswordController::MAX_REQUESTS_PER_EMAIL; $i++) {
            $this->travel(61)->seconds(); // past the broker's own 60 s per-user throttle
            $this->requestReset($user->email)->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);
        }

        $this->travel(61)->seconds();
        $this->requestReset($user->email)
            ->assertSessionHasErrors(['email' => ForgotPasswordController::TOO_MANY_MESSAGE]);

        Notification::assertSentTimes(ResetPassword::class, ForgotPasswordController::MAX_REQUESTS_PER_EMAIL);
    }

    public function test_per_address_limit_also_applies_to_unregistered_addresses(): void
    {
        for ($i = 0; $i < ForgotPasswordController::MAX_REQUESTS_PER_EMAIL; $i++) {
            $this->requestReset('ghost.address@example.test')
                ->assertSessionHasErrors(['email' => ForgotPasswordController::NOT_REGISTERED_MESSAGE]);
        }

        $this->requestReset('ghost.address@example.test')
            ->assertSessionHasErrors(['email' => ForgotPasswordController::TOO_MANY_MESSAGE]);
    }

    public function test_per_ip_route_throttle_still_applies(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->requestReset("ip.limit.$i@example.test")->assertRedirect(route('password.request'));
        }

        $this->requestReset('ip.limit.7@example.test')->assertStatus(429);
    }

    // ------------------------------------------------------------------ using a link

    public function test_valid_link_still_resets_the_password(): void
    {
        $user = $this->staff('valid.link@example.test');
        $token = Password::broker()->createToken($user);

        $this->resetWith($user, $token)->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('BrandNew!Pass9', (string) $user->fresh()->password));
        $this->assertSame(0, $this->tokenCount($user->email));
    }

    public function test_link_stops_working_after_the_account_is_deactivated(): void
    {
        $user = $this->staff('deactivated.later@example.test');
        $token = Password::broker()->createToken($user);
        $user->forceFill(['status' => StaffAccountStatus::INACTIVE])->save();

        $this->resetWith($user, $token)->assertSessionHasErrors(['email' => ForgotPasswordController::INVALID_LINK_MESSAGE]);

        $this->assertTrue(Hash::check('OldPassword!1', (string) $user->fresh()->password));
        $this->assertSame(0, $this->tokenCount($user->email));
    }

    public function test_link_stops_working_while_email_verification_is_pending(): void
    {
        $user = $this->staff('pending.later@gmail.com');
        $token = Password::broker()->createToken($user);
        app(StaffEmailVerificationService::class)->markPending($user);

        $this->resetWith($user, $token)->assertSessionHasErrors(['email' => ForgotPasswordController::INVALID_LINK_MESSAGE]);

        $this->assertTrue(Hash::check('OldPassword!1', (string) $user->fresh()->password));
    }

    // ------------------------------------------------------------------ Batch 1 + Batch 2 together

    public function test_worker_can_reset_password_after_completing_email_verification(): void
    {
        Mail::fake();
        $user = $this->staff('verified.then.reset@gmail.com');
        app(StaffEmailVerificationService::class)->markPending($user);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'OldPassword!1'])
            ->assertRedirect(route('staff.email-verification.show'));
        $code = null;
        Mail::assertSent(StaffEmailVerificationOtpMail::class, function ($mail) use (&$code): bool {
            $code = $mail->content()->with['otpCode'];

            return true;
        });
        $this->post(route('staff.email-verification.verify'), ['otp' => $code])->assertRedirect(route('login'));

        $this->requestReset($user->email)->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        $this->assertSame(1, $this->tokenCount($user->email));
        Notification::assertSentTo($user, ResetPassword::class);
    }
}
