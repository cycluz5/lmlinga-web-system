<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\StaffEmailVerificationController;
use App\Mail\StaffAccountCredentialsMail;
use App\Mail\StaffEmailVerificationOtpMail;
use App\Models\User;
use App\Services\StaffEmailVerificationService;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use App\Support\UserManagementErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\AuthenticatesStaff;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

/**
 * Admin-created (or admin-reset) health-worker accounts: the credentials are emailed, and on first login the
 * worker confirms the email with a 6-digit code AND replaces the temporary password on the same screen.
 */
class HealthWorkerFirstLoginPasswordOtpTest extends TestCase
{
    use AuthenticatesStaff;
    use RefreshDatabase;

    private const TEMP = 'TempWorker!9';

    private const NEW = 'MyOwnPass!42';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function createWorkerViaAdmin(string $email = 'first.login@gmail.com'): User
    {
        $this->actingAsStaff(StaffRole::ADMIN, [
            'email' => 'fl.admin@example.test',
            'username' => 'fl.admin',
        ]);

        $this->post(route('user-management.health-workers.store'), [
            'first_name' => 'Pat',
            'middle_name' => 'Temp',
            'last_name' => 'Worker',
            'email' => $email,
            'username' => 'pat.'.substr(md5($email), 0, 6),
            'mobile' => '09171234567',
            'role' => 'BHW',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => self::TEMP,
            'password_confirmation' => self::TEMP,
        ])->assertRedirect();

        $this->post(route('logout'));
        Auth::forgetGuards();

        return User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->firstOrFail();
    }

    private function loginAs(string $email, string $password)
    {
        return $this->post(route('login.store'), ['email' => $email, 'password' => $password]);
    }

    private function lastCode(): string
    {
        $code = null;
        Mail::assertSent(StaffEmailVerificationOtpMail::class, function (StaffEmailVerificationOtpMail $mail) use (&$code): bool {
            $code = $mail->content()->with['otpCode'];

            return true;
        });

        return (string) $code;
    }

    private function firstLoginUntilCodeScreen(User $worker): string
    {
        $this->loginAs($worker->email, self::TEMP)->assertRedirect(route('staff.email-verification.show'));

        return $this->lastCode();
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $code, string $password = self::NEW, ?string $confirmation = null): array
    {
        return [
            'otp' => $code,
            'new_password' => $password,
            'new_password_confirmation' => $confirmation ?? $password,
        ];
    }

    private function wrongCode(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    public function test_create_sends_credentials_email_to_the_given_address_and_marks_the_account(): void
    {
        $worker = $this->createWorkerViaAdmin('Created.Worker@Gmail.com');

        Mail::assertSent(StaffAccountCredentialsMail::class, function (StaffAccountCredentialsMail $mail): bool {
            $with = $mail->content()->with;

            return $mail->hasTo('created.worker@gmail.com')
                && $with['temporaryPassword'] === self::TEMP
                && $with['isReset'] === false
                && str_contains($with['loginUrl'], '/login');
        });
        Mail::assertNotSent(StaffEmailVerificationOtpMail::class);

        $this->assertNotNull($worker->temp_password_issued_at);
        $this->assertTrue($worker->must_change_password);
        $this->assertTrue(app(StaffEmailVerificationService::class)->isPending($worker));
    }

    public function test_create_flash_names_the_address_the_email_went_to(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'fl.admin2@example.test', 'username' => 'fl.admin2']);

        $this->post(route('user-management.health-workers.store'), [
            'first_name' => 'Pat',
            'last_name' => 'Worker',
            'email' => 'named.address@gmail.com',
            'username' => 'named.address',
            'mobile' => '09171234567',
            'role' => 'BHW',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => self::TEMP,
            'password_confirmation' => self::TEMP,
        ])->assertSessionHas('status', 'Health Worker account created successfully. Login details were emailed to named.address@gmail.com.')
            ->assertSessionHas('mail_warning', false);
    }

    public function test_first_login_sends_the_code_and_blocks_the_dashboard(): void
    {
        $worker = $this->createWorkerViaAdmin();

        $code = $this->firstLoginUntilCodeScreen($worker);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->get(route('staff.email-verification.show'))
            ->assertOk()
            ->assertSee('data-lml-staff-new-password', false)
            ->assertSee('Confirm Password')
            ->assertSee('Verify and set password');
    }

    public function test_correct_code_with_a_strong_new_password_logs_in_and_the_temporary_password_stops_working(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $code = $this->firstLoginUntilCodeScreen($worker);

        $this->post(route('staff.email-verification.verify'), $this->payload($code))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', StaffEmailVerificationController::PASSWORD_UPDATED_MESSAGE);

        $fresh = $worker->fresh();
        $this->assertAuthenticatedAs($fresh);
        $this->assertSame(StaffRole::BHW, session(UiRole::SESSION_KEY));
        $this->assertTrue(Hash::check(self::NEW, (string) $fresh->password));
        $this->assertFalse((bool) $fresh->must_change_password);
        $this->assertNull($fresh->temp_password_issued_at);
        $this->assertFalse(app(StaffEmailVerificationService::class)->isPending($fresh));
        $this->assertNotNull($fresh->email_verified_at);
        $this->get(route('dashboard'))->assertOk();

        $this->post(route('logout'));
        Auth::forgetGuards();

        $this->loginAs($worker->email, self::TEMP)->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->loginAs($worker->email, self::NEW)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($fresh);
    }

    public function test_wrong_code_does_not_change_the_password_and_keeps_the_attempt_counter(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $code = $this->firstLoginUntilCodeScreen($worker);

        $this->post(route('staff.email-verification.verify'), $this->payload($this->wrongCode($code)))
            ->assertRedirect(route('staff.email-verification.show'))
            ->assertSessionHasErrors(['otp' => 'The code is invalid or has expired.']);

        $fresh = $worker->fresh();
        $this->assertGuest();
        $this->assertTrue(Hash::check(self::TEMP, (string) $fresh->password));
        $this->assertNotNull($fresh->temp_password_issued_at);
        $this->assertTrue(app(StaffEmailVerificationService::class)->isPending($fresh));

        // Five wrong attempts void the code; the correct value then no longer works either.
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('staff.email-verification.verify'), $this->payload($this->wrongCode($code)));
        }
        $this->post(route('staff.email-verification.verify'), $this->payload($this->wrongCode($code)))
            ->assertSessionHasErrors(['otp' => 'Too many incorrect attempts. Please request a new code.']);
        $this->post(route('staff.email-verification.verify'), $this->payload($code))->assertSessionHasErrors('otp');
        $this->assertTrue(Hash::check(self::TEMP, (string) $worker->fresh()->password));
    }

    public function test_expired_code_does_not_change_the_password(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $code = $this->firstLoginUntilCodeScreen($worker);

        $this->travel(5 * 60 + 1)->seconds();

        $this->post(route('staff.email-verification.verify'), $this->payload($code))
            ->assertSessionHasErrors(['otp' => 'The code is invalid or has expired.']);

        $this->assertGuest();
        $this->assertTrue(Hash::check(self::TEMP, (string) $worker->fresh()->password));
        $this->assertNotNull($worker->fresh()->temp_password_issued_at);
    }

    public function test_new_password_equal_to_the_temporary_one_is_rejected_without_spending_the_code(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $code = $this->firstLoginUntilCodeScreen($worker);

        $this->post(route('staff.email-verification.verify'), $this->payload($code, self::TEMP))
            ->assertSessionHasErrors('new_password');

        $this->assertGuest();
        $this->assertTrue(app(StaffEmailVerificationService::class)->isPending($worker->fresh()));

        // The code was not spent: the same code still works with a proper new password.
        $this->post(route('staff.email-verification.verify'), $this->payload($code))
            ->assertRedirect(route('dashboard'));
    }

    public function test_weak_or_unconfirmed_new_password_is_rejected(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $code = $this->firstLoginUntilCodeScreen($worker);

        $this->post(route('staff.email-verification.verify'), $this->payload($code, 'weakpass'))
            ->assertSessionHasErrors('new_password');
        $this->post(route('staff.email-verification.verify'), $this->payload($code, self::NEW, 'Different!42'))
            ->assertSessionHasErrors('new_password');
        $this->post(route('staff.email-verification.verify'), ['otp' => $code])
            ->assertSessionHasErrors('new_password');

        $this->assertGuest();
        $this->assertTrue(Hash::check(self::TEMP, (string) $worker->fresh()->password));
    }

    public function test_admin_reset_sends_the_reset_email_and_forces_the_flow_again(): void
    {
        $worker = $this->createWorkerViaAdmin();
        $code = $this->firstLoginUntilCodeScreen($worker);
        $this->post(route('staff.email-verification.verify'), $this->payload($code))->assertRedirect(route('dashboard'));
        $this->post(route('logout'));
        Auth::forgetGuards();
        Mail::fake();

        $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'fl.admin3@example.test', 'username' => 'fl.admin3']);
        $worker = $worker->fresh(['currentAppointment']);
        $this->put(
            route('user-management.health-workers.update', ['id' => (string) $worker->id]),
            [
                'sex' => 'Female',
                'hw_first_name' => 'Pat',
                'hw_last_name' => 'Worker',
                'hw_middle_name' => 'Temp',
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
                'hw_username' => $worker->username ?: 'pat.worker',
                'hw_status' => StaffAccountStatus::ACTIVE,
                'hw_password' => 'ResetByAdmin!5',
                'hw_password_confirmation' => 'ResetByAdmin!5',
            ]
        )->assertSessionHasNoErrors()->assertRedirect();

        Mail::assertSent(StaffAccountCredentialsMail::class, function (StaffAccountCredentialsMail $mail) use ($worker): bool {
            $with = $mail->content()->with;

            return $mail->hasTo($worker->email)
                && $with['isReset'] === true
                && $with['temporaryPassword'] === 'ResetByAdmin!5';
        });

        $fresh = $worker->fresh();
        $this->assertNotNull($fresh->temp_password_issued_at);
        $this->assertTrue(app(StaffEmailVerificationService::class)->isPending($fresh));

        $this->post(route('logout'));
        Auth::forgetGuards();
        $this->loginAs($worker->email, 'ResetByAdmin!5')->assertRedirect(route('staff.email-verification.show'));
        $this->assertGuest();
    }

    public function test_legacy_user_with_flag_but_no_temp_marker_logs_in_normally(): void
    {
        $legacy = User::factory()->create([
            'email' => 'legacy.worker@example.test',
            'username' => 'legacy.worker',
            'password' => 'LegacyPass!1',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => true,
        ]);
        $legacy->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        $this->assertNull($legacy->fresh()->temp_password_issued_at);
        $this->loginAs('legacy.worker@example.test', 'LegacyPass!1')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($legacy->fresh());
        $this->get(route('dashboard'))->assertOk();
        Mail::assertNothingSent();
    }

    public function test_mail_failure_still_creates_the_account_and_warns_the_admin(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'fl.admin4@example.test', 'username' => 'fl.admin4']);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('550 mailbox unavailable'));

        $this->post(route('user-management.health-workers.store'), [
            'first_name' => 'Mail',
            'last_name' => 'Fails',
            'email' => 'mail.fails@gmail.com',
            'username' => 'mail.fails',
            'mobile' => '09171234567',
            'role' => 'BHW',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => self::TEMP,
            'password_confirmation' => self::TEMP,
        ])->assertRedirect()
            ->assertSessionHas('mail_warning', true)
            ->assertSessionHas('status', fn ($status): bool => str_contains((string) $status, 'could not be sent')
                && str_contains((string) $status, 'mail.fails@gmail.com'));

        $worker = User::query()->where('email', 'mail.fails@gmail.com')->firstOrFail();
        $this->assertNotNull($worker->temp_password_issued_at);
        $this->assertTrue(app(StaffEmailVerificationService::class)->isPending($worker));
    }

    public function test_safety_net_ends_the_session_of_an_authenticated_user_still_on_a_temporary_password(): void
    {
        $worker = $this->actingAsStaff(StaffRole::BHW);
        $this->get(route('dashboard'))->assertOk();

        $worker->forceFill(['temp_password_issued_at' => now(), 'must_change_password' => true])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue(app(StaffEmailVerificationService::class)->isPending($worker->fresh()));

        // The next login goes through the code + new-password screen rather than a loop.
        $this->loginAs($worker->email, 'password')->assertRedirect(route('staff.email-verification.show'));
    }

    public function test_safety_net_returns_403_json_for_offline_requests(): void
    {
        $worker = $this->actingAsStaff(StaffRole::BHW);
        $worker->forceFill(['temp_password_issued_at' => now()])->save();

        $this->getJson(route('dashboard'))->assertStatus(403);
        $this->assertGuest();
    }

    public function test_with_verification_disabled_the_worker_is_sent_to_change_password(): void
    {
        config(['lmlinga.staff_email_verification.enabled' => false]);

        $worker = $this->actingAsStaff(StaffRole::BHW);
        $worker->forceFill(['temp_password_issued_at' => now(), 'must_change_password' => true])->save();

        $this->get(route('dashboard'))->assertRedirect(route('password.change.required'));
        $this->assertAuthenticatedAs($worker->fresh());

        $this->post(route('password.change.store'), [
            'new_password' => self::NEW,
            'new_password_confirmation' => self::NEW,
        ])->assertRedirect(route('profile.show'));

        $this->assertNull($worker->fresh()->temp_password_issued_at);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_erd_user_management_mode_runs_the_same_flow(): void
    {
        ClientTestingErdSchema::ensure();
        UserManagementErdMode::resetCachedState();
        $this->assertTrue(UserManagementErdMode::isActive());
        $this->assertTrue(Schema::hasColumn('user_management', 'temp_password_issued_at'));

        $adminId = (int) DB::table('user_management')->insertGetId([
            'first_name' => 'Erd', 'middle_name' => 'T', 'last_name' => 'Admin', 'suffix' => null,
            'sex' => 'Female', 'date_of_birth' => '1990-02-02', 'civil_status' => 'Single', 'nationality' => 'Filipino',
            'mobile_number' => '09171234567', 'email' => 'erd.fl.admin@example.test', 'username' => 'erd.fl.admin',
            'house_no' => '1', 'street' => 'S', 'purok_zone' => 'Zone 1', 'barangay' => 'La Medalla',
            'municipality_city' => 'Iriga City', 'province' => 'Camarines Sur', 'zip_code' => '4431',
            'password' => Hash::make('AdminPass!1'), 'status' => 'Active', 'must_change_password' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('worker_appointments')->insert([
            'user_id' => $adminId,
            'role' => UserManagementErdMode::appointmentRoleForStorage(StaffRole::ADMIN),
            'assigned_barangay' => 'La Medalla', 'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-15', 'end_of_appointment' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs(User::query()->findOrFail($adminId))->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
        ]);

        $this->post(route('user-management.health-workers.store'), [
            'first_name' => 'Erd',
            'last_name' => 'Fresh',
            'email' => 'erd.fresh@gmail.com',
            'username' => 'erd.fresh',
            'mobile' => '09171112222',
            'role' => 'BHW',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => self::TEMP,
            'password_confirmation' => self::TEMP,
        ])->assertRedirect();

        Mail::assertSent(StaffAccountCredentialsMail::class, fn ($mail) => $mail->hasTo('erd.fresh@gmail.com'));
        $row = DB::table('user_management')->where('email', 'erd.fresh@gmail.com')->first();
        $this->assertNotNull($row->temp_password_issued_at);

        $this->post(route('logout'));
        Auth::forgetGuards();

        $this->loginAs('erd.fresh@gmail.com', self::TEMP)->assertRedirect(route('staff.email-verification.show'));
        $code = $this->lastCode();

        $this->post(route('staff.email-verification.verify'), $this->payload($code))->assertRedirect(route('dashboard'));

        $row = DB::table('user_management')->where('email', 'erd.fresh@gmail.com')->first();
        $this->assertNull($row->temp_password_issued_at);
        $this->assertTrue(Hash::check(self::NEW, (string) $row->password));
        $this->assertSame((int) $row->user_id, (int) Auth::id());
    }
}
