<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\StaffEmailVerificationController;
use App\Mail\StaffEmailVerificationOtpMail;
use App\Models\StaffEmailVerification;
use App\Models\User;
use App\Services\HealthWorkerAccountService;
use App\Services\StaffEmailVerificationService;
use App\Support\LoginAttemptLockout;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Support\AuthenticatesStaff;
use Tests\TestCase;

/**
 * Batch 1 — Admin-created health-worker accounts stay unusable until the worker proves ownership of the
 * account email with a 5-minute, single-use, 6-digit code.
 */
class HealthWorkerEmailVerificationTest extends TestCase
{
    use AuthenticatesStaff;
    use RefreshDatabase;

    private const PASSWORD = 'WorkerVerify!9';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function createWorkerViaAdmin(string $email = 'new.worker@gmail.com', string $role = 'BHW'): User
    {
        $this->actingAsStaff(StaffRole::ADMIN, [
            'email' => 'verify.admin@example.test',
            'username' => 'verify.admin',
        ]);

        $this->post(route('user-management.health-workers.store'), [
            'first_name' => 'Pending',
            'middle_name' => 'Email',
            'last_name' => 'Worker',
            'email' => $email,
            'username' => 'pending.worker.'.substr(md5($email), 0, 6),
            'mobile' => '09171234567',
            'role' => $role,
            'status' => StaffAccountStatus::ACTIVE,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect();

        $this->post(route('logout'));
        Auth::forgetGuards();

        $worker = User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->firstOrFail();

        // These tests cover the plain email-code step. The temporary-password variant (code + new password
        // on one screen) is covered by HealthWorkerFirstLoginPasswordOtpTest, so clear that marker here and
        // drop the welcome email from the fake.
        $worker->forceFill(['temp_password_issued_at' => null])->save();
        Mail::fake();

        return $worker->fresh();
    }

    private function loginAs(string $email, string $password = self::PASSWORD)
    {
        return $this->post(route('login.store'), ['email' => $email, 'password' => $password]);
    }

    private function lastSentCode(): string
    {
        $code = null;
        Mail::assertSent(StaffEmailVerificationOtpMail::class, function (StaffEmailVerificationOtpMail $mail) use (&$code): bool {
            $code = $mail->content()->with['otpCode'];

            return true;
        });

        return (string) $code;
    }

    private function wrongCode(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    // ------------------------------------------------------------------ creation & login restriction

    public function test_admin_created_worker_is_pending_and_no_code_is_sent_at_creation(): void
    {
        $worker = $this->createWorkerViaAdmin();

        $this->assertTrue(app(StaffEmailVerificationService::class)->isPending($worker));
        $this->assertSame(StaffAccountStatus::ACTIVE, $worker->status);
        Mail::assertNothingSent();
    }

    public function test_unverified_worker_with_correct_password_is_not_logged_in_and_gets_a_code(): void
    {
        $worker = $this->createWorkerViaAdmin();

        $this->loginAs($worker->email)->assertRedirect(route('staff.email-verification.show'));

        $this->assertGuest();
        Mail::assertSent(StaffEmailVerificationOtpMail::class, fn ($mail) => $mail->hasTo($worker->email));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->lastSentCode());

        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_unverified_worker_with_wrong_password_gets_generic_failure_and_no_code(): void
    {
        $worker = $this->createWorkerViaAdmin();

        $this->loginAs($worker->email, 'WrongPassword!1')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => LoginAttemptLockout::GENERIC_FAILURE]);

        $this->assertGuest();
        Mail::assertNothingSent();
    }

    public function test_valid_gmail_format_alone_does_not_activate_the_account(): void
    {
        $worker = $this->createWorkerViaAdmin('looks.real.person@gmail.com');

        $this->loginAs($worker->email)->assertRedirect(route('staff.email-verification.show'));
        $this->loginAs($worker->email)->assertRedirect(route('staff.email-verification.show'));

        $this->assertGuest();
        $this->assertTrue(app(StaffEmailVerificationService::class)->isPending($worker->fresh()));
    }

    // ------------------------------------------------------------------ successful verification

    public function test_correct_code_activates_the_account_and_the_worker_can_then_log_in(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $this->loginAs($worker->email);
        $code = $this->lastSentCode();

        $this->post(route('staff.email-verification.verify'), ['otp' => $code])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', StaffEmailVerificationController::VERIFIED_MESSAGE);

        $this->assertGuest();
        $this->assertFalse(app(StaffEmailVerificationService::class)->isPending($worker->fresh()));

        $row = StaffEmailVerification::query()->where('user_id', $worker->id)->firstOrFail();
        $this->assertNotNull($row->verified_at);
        $this->assertNull($row->code_hash);

        $this->loginAs($worker->email)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($worker->fresh());
    }

    public function test_code_is_accepted_just_before_five_minutes(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $this->loginAs($worker->email);
        $code = $this->lastSentCode();

        $this->travel(4 * 60 + 59)->seconds();

        $this->post(route('staff.email-verification.verify'), ['otp' => $code])->assertRedirect(route('login'));
        $this->assertFalse(app(StaffEmailVerificationService::class)->isPending($worker->fresh()));
    }

    // ------------------------------------------------------------------ rejection paths

    public function test_invalid_code_is_rejected_and_account_stays_pending(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $this->loginAs($worker->email);
        $code = $this->lastSentCode();

        $this->post(route('staff.email-verification.verify'), ['otp' => $this->wrongCode($code)])
            ->assertRedirect(route('staff.email-verification.show'))
            ->assertSessionHasErrors('otp');

        $this->post(route('staff.email-verification.verify'), ['otp' => 'abc'])
            ->assertSessionHasErrors('otp');

        $this->assertTrue(app(StaffEmailVerificationService::class)->isPending($worker->fresh()));
        $this->assertGuest();
    }

    public function test_expired_code_is_rejected_after_five_minutes(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $this->loginAs($worker->email);
        $code = $this->lastSentCode();

        $this->travel(5 * 60 + 1)->seconds();

        $this->post(route('staff.email-verification.verify'), ['otp' => $code])
            ->assertRedirect(route('staff.email-verification.show'))
            ->assertSessionHasErrors(['otp' => 'The code is invalid or has expired.']);

        $this->assertTrue(app(StaffEmailVerificationService::class)->isPending($worker->fresh()));
    }

    public function test_code_cannot_be_reused_after_successful_verification(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $this->loginAs($worker->email);
        $code = $this->lastSentCode();
        $service = app(StaffEmailVerificationService::class);

        $this->assertSame(StaffEmailVerificationService::VERIFIED, $service->verify($worker->fresh(), $code));
        $this->assertSame(StaffEmailVerificationService::NOT_PENDING, $service->verify($worker->fresh(), $code));

        // Re-requiring verification (e.g. email change) must not revive the old code.
        $service->markPending($worker->fresh());
        $this->assertSame(StaffEmailVerificationService::INVALID, $service->verify($worker->fresh(), $code));
    }

    public function test_five_wrong_attempts_void_the_code_even_for_the_correct_value(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $this->loginAs($worker->email);
        $code = $this->lastSentCode();

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('staff.email-verification.verify'), ['otp' => $this->wrongCode($code)])
                ->assertSessionHasErrors(['otp' => 'The code is invalid or has expired.']);
        }
        $this->post(route('staff.email-verification.verify'), ['otp' => $this->wrongCode($code)])
            ->assertSessionHasErrors(['otp' => 'Too many incorrect attempts. Please request a new code.']);

        $this->post(route('staff.email-verification.verify'), ['otp' => $code])->assertSessionHasErrors('otp');
        $this->assertTrue(app(StaffEmailVerificationService::class)->isPending($worker->fresh()));
    }

    public function test_resend_has_a_cooldown_replaces_the_old_code_and_is_capped_per_hour(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $this->loginAs($worker->email);
        $first = $this->lastSentCode();

        $this->post(route('staff.email-verification.resend'))
            ->assertSessionHasErrors(['otp' => 'Please wait before requesting another code.']);
        Mail::assertSentCount(1);

        $this->travel(61)->seconds();
        $this->post(route('staff.email-verification.resend'))->assertSessionHas('status');
        Mail::assertSentCount(2);

        $service = app(StaffEmailVerificationService::class);
        // Codes sent so far: 2. Allow up to 5 per hour.
        for ($i = 0; $i < 3; $i++) {
            $this->travel(61)->seconds();
            $this->assertSame(StaffEmailVerificationService::SENT, $service->sendCode($worker->fresh()));
        }
        $this->travel(61)->seconds();
        $this->assertSame(StaffEmailVerificationService::SEND_LIMIT, $service->sendCode($worker->fresh()));
        Mail::assertSentCount(5);

        // The very first code was replaced by later codes (skip only on a one-in-a-million collision).
        $codes = [];
        Mail::assertSent(StaffEmailVerificationOtpMail::class, function ($mail) use (&$codes): bool {
            $codes[] = $mail->content()->with['otpCode'];

            return true;
        });
        if ($first !== end($codes)) {
            $this->assertSame(StaffEmailVerificationService::INVALID, $service->verify($worker->fresh(), $first));
        }
    }

    public function test_verify_endpoint_is_rate_limited(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $this->loginAs($worker->email);

        $limit = (int) config('lmlinga.staff_email_verification.verify_per_10_minutes');
        for ($i = 0; $i < $limit; $i++) {
            $this->post(route('staff.email-verification.verify'), ['otp' => '12345']);
        }

        $this->post(route('staff.email-verification.verify'), ['otp' => '12345'])->assertStatus(429);
    }

    // ------------------------------------------------------------------ fabricated / inaccessible email

    public function test_undeliverable_email_never_activates_the_account(): void
    {
        $worker = $this->createWorkerViaAdmin('does.not.exist.anywhere@gmail.com');

        Mail::shouldReceive('to')->andThrow(new RuntimeException('550 mailbox unavailable'));

        $this->loginAs($worker->email)->assertRedirect(route('staff.email-verification.show'));
        $this->assertGuest();

        $row = StaffEmailVerification::query()->where('user_id', $worker->id)->firstOrFail();
        $this->assertNull($row->code_hash);
        $this->assertNull($row->verified_at);
        $this->assertTrue(app(StaffEmailVerificationService::class)->isPending($worker->fresh()));
    }

    public function test_code_is_bound_to_the_email_it_was_sent_to(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $this->loginAs($worker->email);
        $code = $this->lastSentCode();

        // Admin changes the email after the code was sent: the old code no longer proves ownership.
        $worker->forceFill(['email' => 'corrected.address@gmail.com'])->save();

        $this->assertSame(
            StaffEmailVerificationService::INVALID,
            app(StaffEmailVerificationService::class)->verify($worker->fresh(), $code)
        );
    }

    // ------------------------------------------------------------------ preservation of existing behaviour

    public function test_existing_accounts_without_a_verification_record_log_in_unchanged(): void
    {
        $existing = User::factory()->create([
            'email' => 'existing.worker@example.test',
            'password' => 'Existing!123',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
        ]);
        $existing->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        $this->loginAs('existing.worker@example.test', 'Existing!123')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($existing->fresh());
        Mail::assertNothingSent();
    }

    public function test_inactive_pending_account_gets_generic_failure_and_no_code(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $worker->forceFill(['status' => StaffAccountStatus::INACTIVE])->save();

        $this->loginAs($worker->email)->assertSessionHasErrors(['email' => LoginAttemptLockout::GENERIC_FAILURE]);
        Mail::assertNothingSent();
    }

    public function test_session_of_an_account_that_becomes_pending_is_ended(): void
    {
        $worker = $this->actingAsStaff(StaffRole::BHW);
        $this->get(route('dashboard'))->assertOk();

        app(StaffEmailVerificationService::class)->markPending($worker);

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_duplicate_email_is_rejected_even_while_the_first_account_is_pending(): void
    {
        $this->createWorkerViaAdmin('duplicate.check@gmail.com');

        $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'second.admin@example.test', 'username' => 'second.admin']);
        $this->post(route('user-management.health-workers.store'), [
            'first_name' => 'Second',
            'last_name' => 'Copy',
            'email' => 'Duplicate.Check@gmail.com',
            'username' => 'second.copy',
            'mobile' => '09171234567',
            'role' => 'BHW',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, User::query()->whereRaw('LOWER(email) = ?', ['duplicate.check@gmail.com'])->count());
    }

    public function test_pending_admin_does_not_count_as_another_active_admin(): void
    {
        $verifiedAdmin = $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'only.real.admin@example.test', 'username' => 'only.real.admin']);
        $this->deactivateSeededSuperAdmin();
        $service = app(HealthWorkerAccountService::class);
        $before = $service->countOtherActiveAdmins($verifiedAdmin->id);

        $this->post(route('user-management.health-workers.store'), [
            'first_name' => 'Pending',
            'last_name' => 'Admin',
            'email' => 'pending.admin@gmail.com',
            'username' => 'pending.admin',
            'mobile' => '09171234567',
            'role' => 'Admin',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect();

        $this->assertSame($before, $service->countOtherActiveAdmins($verifiedAdmin->id));
    }

    public function test_pending_session_binding_expires(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $this->loginAs($worker->email);

        $this->get(route('staff.email-verification.show'))->assertOk()->assertSee('Verify Your Email');

        $this->travel(31)->minutes();

        $this->get(route('staff.email-verification.show'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => StaffEmailVerificationController::RESTART_MESSAGE]);
    }

    public function test_verification_page_requires_a_pending_login(): void
    {
        $this->get(route('staff.email-verification.show'))->assertRedirect(route('login'));
        $this->post(route('staff.email-verification.verify'), ['otp' => '123456'])->assertRedirect(route('login'));
        $this->post(route('staff.email-verification.resend'))->assertRedirect(route('login'));
    }

    public function test_code_is_never_stored_in_plaintext(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $this->loginAs($worker->email);
        $code = $this->lastSentCode();

        $row = StaffEmailVerification::query()->where('user_id', $worker->id)->firstOrFail();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertStringNotContainsString($code, (string) $row->code_hash);
        $this->assertArrayNotHasKey('code_hash', $row->toArray());
    }

    private function existingVerifiedWorker(string $email): User
    {
        $worker = User::factory()->create([
            'email' => $email,
            'username' => 'existing.'.substr(md5($email), 0, 6),
            'password' => 'Existing!123',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
        ]);
        $worker->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-15',
        ]);

        return $worker->fresh(['currentAppointment']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(User $worker, array $overrides = []): array
    {
        return array_merge([
            'sex' => 'Female',
            'hw_first_name' => 'Maria',
            'hw_last_name' => 'Reyes',
            'hw_middle_name' => 'Cruz',
            'hw_suffix' => 'N/A',
            'hw_dob' => '1990-02-02',
            'hw_civil_status' => 'Married',
            'hw_nationality' => 'Filipino',
            'hw_mobile' => '09179876543',
            'hw_email' => $worker->email,
            'hw_house_no' => '99',
            'hw_street' => 'Updated St.',
            'hw_purok_zone' => 'Zone 2',
            'hw_barangay' => 'La Medalla',
            'hw_municipality' => 'Iriga City',
            'hw_province' => 'Camarines Sur',
            'hw_zip' => '4431',
            'hw_role' => 'BHW',
            'hw_assigned_barangay' => 'La Medalla',
            'hw_assigned_zone' => ['Zone 2'],
            'hw_date_appointed' => '2020-01-15',
            'hw_end_appointment' => '2031-12-31',
            'hw_username' => $worker->username,
            'hw_status' => StaffAccountStatus::ACTIVE,
        ], $overrides);
    }

    public function test_admin_changing_a_workers_email_requires_reverification_of_the_new_address(): void
    {
        $worker = $this->existingVerifiedWorker('old.address@example.test');
        $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'editor.admin@example.test', 'username' => 'editor.admin']);

        $this->put(
            route('user-management.health-workers.update', ['id' => (string) $worker->id]),
            $this->updatePayload($worker, ['hw_email' => 'new.address@gmail.com'])
        )->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue(app(StaffEmailVerificationService::class)->isPending($worker->fresh()));

        $this->post(route('logout'));
        Auth::forgetGuards();
        $this->loginAs('new.address@gmail.com', 'Existing!123')->assertRedirect(route('staff.email-verification.show'));
        $this->assertGuest();
        Mail::assertSent(StaffEmailVerificationOtpMail::class, fn ($mail) => $mail->hasTo('new.address@gmail.com'));
    }

    public function test_admin_edit_without_email_change_keeps_the_account_usable(): void
    {
        $worker = $this->existingVerifiedWorker('unchanged.address@example.test');
        $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'editor2.admin@example.test', 'username' => 'editor2.admin']);

        $this->put(
            route('user-management.health-workers.update', ['id' => (string) $worker->id]),
            $this->updatePayload($worker, ['hw_mobile' => '09170000000'])
        )->assertSessionHasNoErrors()->assertRedirect();

        $this->assertFalse(app(StaffEmailVerificationService::class)->isPending($worker->fresh()));
    }

    public function test_edit_rejects_a_case_variant_of_another_accounts_email_and_stores_lowercase(): void
    {
        $this->existingVerifiedWorker('taken.address@example.test');
        $worker = $this->existingVerifiedWorker('editable.address@example.test');
        $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'case.admin@example.test', 'username' => 'case.admin']);

        // Case variant of another account's email: a validation error, never an HTTP 500.
        $this->put(
            route('user-management.health-workers.update', ['id' => (string) $worker->id]),
            $this->updatePayload($worker, ['hw_email' => '  Taken.Address@Example.TEST '])
        )->assertSessionHasErrors('hw_email');
        $this->assertSame('editable.address@example.test', $worker->fresh()->email);

        // Changing only the letter case of its own email: saved lowercase, no re-verification required.
        $this->put(
            route('user-management.health-workers.update', ['id' => (string) $worker->id]),
            $this->updatePayload($worker, ['hw_email' => 'Editable.Address@Example.test'])
        )->assertSessionHasNoErrors();
        $this->assertSame('editable.address@example.test', $worker->fresh()->email);
        $this->assertFalse(app(StaffEmailVerificationService::class)->isPending($worker->fresh()));
    }

    public function test_admin_list_marks_pending_accounts(): void
    {
        $this->createWorkerViaAdmin('listed.pending@gmail.com');
        $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'list.admin@example.test', 'username' => 'list.admin']);

        $this->get(route('user-management.index'))->assertOk()->assertSee('data-hw-email-pending', false);
    }

    public function test_disabling_the_feature_restores_immediate_login(): void
    {
        config(['lmlinga.staff_email_verification.enabled' => false]);

        $worker = $this->createWorkerViaAdmin();

        $this->loginAs($worker->email)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($worker->fresh());
    }
}
