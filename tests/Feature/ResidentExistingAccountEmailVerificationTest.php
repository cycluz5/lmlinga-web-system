<?php

namespace Tests\Feature;

use App\Mail\ResidentEmailVerificationOtpMail;
use App\Models\RecordRequest;
use App\Models\ResidentAccount;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Support\HouseholdLinkingFixtures;
use Tests\Support\ResidentEmailOtpFixtures;
use Tests\TestCase;

/**
 * SR-03 backward-compatible transition: accounts created before email verification keep login, chatbot,
 * household links, health pages and in-progress requests; they verify their stored email once before
 * submitting a NEW household record request. Staff-verified household linking is unchanged.
 */
class ResidentExistingAccountEmailVerificationTest extends TestCase
{
    use HouseholdLinkingFixtures;
    use RefreshDatabase;
    use ResidentEmailOtpFixtures;

    private const EMAIL = 'existing.resident@example.test';

    private const VERIFIED_MOBILE = '09170000001';

    protected function setUp(): void
    {
        parent::setUp();

        config(['lmlinga.household_linking.mode' => 'staff_verified']);
        $this->ensureLinkingSchema();
        $this->fakeSms();
        Http::preventStrayRequests();
        Mail::fake();
    }

    private function legacyAccount(string $email = self::EMAIL): int
    {
        return $this->createResidentAccount($email, emailVerified: false);
    }

    /** Full staff-verified link for a verified account, then mark it unverified (as if linked before SR-03). */
    private function linkedLegacyAccount(): array
    {
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);

        $this->actingAsResident($accountId, self::EMAIL);
        $this->post(route('chatbot.household.verification.store'), $this->requestPayload(self::EMAIL, ['mobileNumber' => self::VERIFIED_MOBILE]));
        $requestId = (int) $this->latestRequest($accountId)->request_id;

        $this->loginStaff($this->seedErdAdmin());
        $this->post(route('household-requests.verify-identity', ['id' => 'req-'.$requestId]), ['identity_verified' => '1', 'mobile_verified' => '1']);

        $this->actingAsResident($accountId, self::EMAIL);
        $this->post(route('chatbot.household.verification.sms.send'));
        $sent = $this->sentSms();
        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => end($sent)['code']]);
        $this->assertSame(RecordRequest::STATUS_APPROVED, $this->latestRequest($accountId)->status);

        DB::table('resident_accounts')->where('account_id', $accountId)->update(['email_verified_at' => null]);

        return ['account_id' => $accountId, 'resident_id' => $household['resident_id'], 'request_id' => $requestId];
    }

    private function snapshot(int $accountId): array
    {
        return [
            'account' => (array) DB::table('resident_accounts')->where('account_id', $accountId)->first(['account_id', 'resident_id', 'first_name', 'middle_name', 'last_name', 'zone_purok', 'email', 'password', 'created_at']),
            'requests' => DB::table('record_requests')->where('account_id', $accountId)->orderBy('request_id')->get()->map(fn ($r) => (array) $r)->all(),
            'otps' => DB::table('record_request_otps')->orderBy('otp_id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function sendAndVerifyEmail(int $accountId, string $email = self::EMAIL)
    {
        $this->actingAsResident($accountId, $email);
        $this->post(route('chatbot.email.verify.send'))->assertSessionHas('email_otp_status');

        return $this->post(route('chatbot.email.verify.submit'), ['otp' => $this->lastEmailedCode($email)]);
    }

    // ---------------------------------------------------------------- existing access preserved

    public function test_unverified_existing_account_can_log_in_and_use_the_chatbot(): void
    {
        $accountId = $this->legacyAccount();

        $this->post(route('chatbot.login.store'), ['email' => self::EMAIL, 'password' => 'SafePassw0rd!x'])
            ->assertRedirect(route('chatbot.main'));
        $this->get(route('chatbot.main'))
            ->assertOk()
            ->assertSee('data-lml-email-verify-notice', false)
            ->assertSee('Verify your email address');

        $this->assertNull(DB::table('resident_accounts')->where('account_id', $accountId)->value('email_verified_at'));
        Mail::assertNothingSent();
    }

    public function test_linked_legacy_account_keeps_household_access_and_records_before_and_after_verifying(): void
    {
        $link = $this->linkedLegacyAccount();
        $before = $this->snapshot($link['account_id']);

        $this->actingAsResident($link['account_id'], self::EMAIL);
        $this->get(route('chatbot.household.information'))->assertOk();
        $this->get(route('chatbot.household.members.show', ['member' => $link['resident_id']]))->assertOk();
        $this->get(route('chatbot.household.members.child-care', ['member' => $link['resident_id']]))->assertOk();
        $this->get(route('chatbot.main'))->assertOk()->assertSee('Access Household Record')->assertSee('data-lml-email-verify-notice', false);

        $this->sendAndVerifyEmail($link['account_id'])->assertRedirect(route('chatbot.main'));

        $this->assertSame($before, $this->snapshot($link['account_id']));
        $this->assertSame($link['resident_id'], (int) $this->linkedResidentId($link['account_id']));
        $this->assertNotNull(DB::table('resident_accounts')->where('account_id', $link['account_id'])->value('email_verified_at'));
        $this->get(route('chatbot.household.information'))->assertOk();
        $this->get(route('chatbot.main'))->assertOk()->assertDontSee('data-lml-email-verify-notice', false);
    }

    public function test_request_already_awaiting_otp_completes_without_email_verification(): void
    {
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $this->actingAsResident($accountId, self::EMAIL);
        $this->post(route('chatbot.household.verification.store'), $this->requestPayload(self::EMAIL, ['mobileNumber' => self::VERIFIED_MOBILE]));
        $requestId = (int) $this->latestRequest($accountId)->request_id;
        $this->loginStaff($this->seedErdAdmin());
        $this->post(route('household-requests.verify-identity', ['id' => 'req-'.$requestId]), ['identity_verified' => '1', 'mobile_verified' => '1']);

        // Request was in progress before the account had a verified email.
        DB::table('resident_accounts')->where('account_id', $accountId)->update(['email_verified_at' => null]);

        $this->actingAsResident($accountId, self::EMAIL);
        $this->get(route('chatbot.household.verification.sms'))->assertOk();
        $this->post(route('chatbot.household.verification.sms.send'))->assertRedirect(route('chatbot.household.verification.sms'));
        $sent = $this->sentSms();
        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => end($sent)['code']]);

        $this->assertSame(RecordRequest::STATUS_APPROVED, $this->latestRequest($accountId)->status);
        $this->assertSame($household['resident_id'], (int) $this->linkedResidentId($accountId));
    }

    // ---------------------------------------------------------------- the gate

    public function test_unverified_account_must_verify_before_a_new_household_request(): void
    {
        $this->seedLinkableHousehold();
        $accountId = $this->legacyAccount();
        $this->actingAsResident($accountId, self::EMAIL);

        $this->get(route('chatbot.household.verification'))
            ->assertRedirect(route('chatbot.email.verify'))
            ->assertSessionHas('email_verification_required');
        $this->post(route('chatbot.household.verification.store'), $this->requestPayload(self::EMAIL))
            ->assertRedirect(route('chatbot.email.verify'));
        $this->assertNull($this->latestRequest($accountId));

        $this->get(route('chatbot.email.verify'))
            ->assertOk()
            ->assertSee('Please verify your email address before submitting a household record request.')
            ->assertSee('e***@example.test')
            ->assertSee('Send verification code');

        // After verifying, the resident returns to the request form and the normal staff-verified flow runs.
        $this->sendAndVerifyEmail($accountId)->assertRedirect(route('chatbot.household.verification'));
        $this->get(route('chatbot.household.verification'))->assertOk();
        $this->post(route('chatbot.household.verification.store'), $this->requestPayload(self::EMAIL, ['mobileNumber' => self::VERIFIED_MOBILE]))
            ->assertRedirect(route('chatbot.main'));
        $this->assertSame(RecordRequest::STATUS_PENDING, $this->latestRequest($accountId)->status);
        $this->assertSame([], $this->sentSms(), 'Email verification must not trigger any household OTP.');
        $this->assertNull($this->linkedResidentId($accountId), 'Email verification never grants household access.');
    }

    public function test_verified_account_is_not_affected_by_the_gate(): void
    {
        $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $this->actingAsResident($accountId, self::EMAIL);

        $this->get(route('chatbot.household.verification'))->assertOk();
        $this->get(route('chatbot.email.verify'))->assertRedirect(route('chatbot.main'));
        $this->get(route('chatbot.main'))->assertOk()->assertDontSee('data-lml-email-verify-notice', false);
    }

    // ---------------------------------------------------------------- the code

    public function test_code_goes_only_to_the_stored_account_email(): void
    {
        $accountId = $this->legacyAccount();
        $this->actingAsResident($accountId, self::EMAIL);

        $this->post(route('chatbot.email.verify.send'), ['email' => 'attacker@example.test']);

        Mail::assertSent(ResidentEmailVerificationOtpMail::class, 1);
        Mail::assertSent(ResidentEmailVerificationOtpMail::class, fn ($mail): bool => $mail->hasTo(self::EMAIL) && count($mail->to) === 1);
        Mail::assertNotSent(ResidentEmailVerificationOtpMail::class, fn ($mail): bool => $mail->hasTo('attacker@example.test'));
    }

    public function test_verification_sets_only_email_verified_at(): void
    {
        $accountId = $this->legacyAccount();
        $before = $this->snapshot($accountId);

        $this->sendAndVerifyEmail($accountId)->assertRedirect(route('chatbot.main'))->assertSessionHas('status', 'Your email address has been verified.');

        $this->assertSame($before, $this->snapshot($accountId));
        $this->assertTrue(ResidentAccount::query()->findOrFail($accountId)->hasVerifiedEmail());
    }

    public function test_existing_account_code_expires_after_five_minutes(): void
    {
        $this->freezeSecond();
        $accountId = $this->legacyAccount();
        $this->actingAsResident($accountId, self::EMAIL);
        $this->post(route('chatbot.email.verify.send'));
        $this->get(route('chatbot.email.verify'))->assertSee('<strong data-lml-otp-timer-value>05:00</strong>', false);
        $code = $this->lastEmailedCode(self::EMAIL);

        $this->travel(301)->seconds();
        $this->post(route('chatbot.email.verify.submit'), ['otp' => $code])
            ->assertSessionHas('email_otp_error', 'The code is invalid or has expired.');
        $this->assertNull(DB::table('resident_accounts')->where('account_id', $accountId)->value('email_verified_at'));
    }

    public function test_existing_account_five_wrong_attempts_void_the_code_and_resend_has_a_cooldown(): void
    {
        $this->freezeSecond();
        $accountId = $this->legacyAccount();
        $this->actingAsResident($accountId, self::EMAIL);
        $this->post(route('chatbot.email.verify.send'));
        $code = (string) $this->lastEmailedCode(self::EMAIL);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('chatbot.email.verify.submit'), ['otp' => $wrong])->assertSessionHas('email_otp_error', 'The code is invalid or has expired.');
        }
        $this->post(route('chatbot.email.verify.submit'), ['otp' => $wrong])->assertSessionHas('email_otp_error', 'Too many incorrect attempts. Please request a new code.');
        $this->post(route('chatbot.email.verify.submit'), ['otp' => $code])->assertSessionHas('email_otp_error');
        $this->assertNull(DB::table('resident_accounts')->where('account_id', $accountId)->value('email_verified_at'));

        $this->post(route('chatbot.email.verify.send'))->assertSessionHas('email_otp_error', 'Please wait 60 seconds before requesting a new code.');
        $this->travel(60)->seconds();
        $this->post(route('chatbot.email.verify.send'))->assertSessionHas('email_otp_status');
        $this->post(route('chatbot.email.verify.submit'), ['otp' => $this->lastEmailedCode(self::EMAIL)])->assertRedirect(route('chatbot.main'));
    }

    public function test_verify_without_requesting_a_code_is_refused(): void
    {
        $accountId = $this->legacyAccount();
        $this->actingAsResident($accountId, self::EMAIL);

        $this->post(route('chatbot.email.verify.submit'), ['otp' => '123456'])
            ->assertSessionHas('email_otp_error', 'Please request a verification code first.');
        $this->assertNull(DB::table('resident_accounts')->where('account_id', $accountId)->value('email_verified_at'));
    }

    // ---------------------------------------------------------------- isolation and unauthorized access

    public function test_one_accounts_code_cannot_verify_another_account(): void
    {
        $first = $this->legacyAccount('first@example.test');
        $second = $this->legacyAccount('second@example.test');

        $this->actingAsResident($first, 'first@example.test');
        $this->post(route('chatbot.email.verify.send'));
        $firstCode = $this->lastEmailedCode('first@example.test');

        $this->actingAsResident($second, 'second@example.test');
        $this->post(route('chatbot.email.verify.send'));
        $secondCode = $this->lastEmailedCode('second@example.test');
        if ($firstCode !== $secondCode) {
            $this->post(route('chatbot.email.verify.submit'), ['otp' => $firstCode])->assertSessionHas('email_otp_error');
            $this->assertNull(DB::table('resident_accounts')->where('account_id', $second)->value('email_verified_at'));
        }
        $this->assertNull(DB::table('resident_accounts')->where('account_id', $first)->value('email_verified_at'));
    }

    public function test_code_is_void_if_the_account_email_changes_after_sending(): void
    {
        $accountId = $this->legacyAccount();
        $this->actingAsResident($accountId, self::EMAIL);
        $this->post(route('chatbot.email.verify.send'));
        $code = $this->lastEmailedCode(self::EMAIL);

        DB::table('resident_accounts')->where('account_id', $accountId)->update(['email' => 'changed@example.test']);

        $this->post(route('chatbot.email.verify.submit'), ['otp' => $code])->assertSessionHas('email_otp_error');
        $this->assertNull(DB::table('resident_accounts')->where('account_id', $accountId)->value('email_verified_at'));
    }

    public function test_guests_cannot_use_the_account_verification_routes(): void
    {
        $this->get(route('chatbot.email.verify'))->assertRedirect(route('chatbot.login'));
        $this->post(route('chatbot.email.verify.send'))->assertRedirect(route('chatbot.login'));
        $this->post(route('chatbot.email.verify.submit'), ['otp' => '123456'])->assertRedirect(route('chatbot.login'));
        Mail::assertNothingSent();
    }

    public function test_staff_session_cannot_use_the_resident_verification_routes(): void
    {
        $this->loginStaff($this->seedErdAdmin());

        $this->get(route('chatbot.email.verify'))->assertRedirect(route('chatbot.login'));
        $this->post(route('chatbot.email.verify.send'))->assertRedirect(route('chatbot.login'));
        Mail::assertNothingSent();
    }

    public function test_send_endpoint_is_throttled_per_account(): void
    {
        config(['lmlinga.resident_email_verification.send_per_10_minutes' => 2]);
        $first = $this->legacyAccount('first@example.test');
        $second = $this->legacyAccount('second@example.test');

        $this->actingAsResident($first, 'first@example.test');
        $this->post(route('chatbot.email.verify.send'))->assertRedirect();
        $this->post(route('chatbot.email.verify.send'))->assertRedirect();
        $this->post(route('chatbot.email.verify.send'))->assertStatus(429);

        // Another account on the same IP is unaffected (keyed per resident account).
        $this->actingAsResident($second, 'second@example.test');
        $this->post(route('chatbot.email.verify.send'))->assertRedirect()->assertSessionHas('email_otp_status');
    }

    // ---------------------------------------------------------------- compatibility switches

    public function test_before_the_migration_runs_existing_accounts_are_not_gated(): void
    {
        $this->seedLinkableHousehold();
        $accountId = $this->legacyAccount();
        Schema::table('resident_accounts', function (Blueprint $table): void {
            $table->dropColumn('email_verified_at');
        });

        $this->actingAsResident($accountId, self::EMAIL);
        $this->get(route('chatbot.household.verification'))->assertOk();
        $this->get(route('chatbot.main'))->assertOk()->assertDontSee('data-lml-email-verify-notice', false);
        $this->get(route('chatbot.email.verify'))->assertRedirect(route('chatbot.main'));
    }

    public function test_before_the_migration_runs_registration_still_verifies_the_email(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        Schema::table('resident_accounts', function (Blueprint $table): void {
            $table->dropColumn('email_verified_at');
        });

        $this->post(route('chatbot.register.store'), $this->registrationPayload('pre.migration.test@gmail.com'))
            ->assertRedirect(route('chatbot.register.verify'));
        $this->assertSame(0, DB::table('resident_accounts')->where('email', 'pre.migration.test@gmail.com')->count());
        $this->post(route('chatbot.register.verify.submit'), ['otp' => $this->lastEmailedCode('pre.migration.test@gmail.com')])
            ->assertRedirect(route('chatbot.login'));
        $this->assertSame(1, DB::table('resident_accounts')->where('email', 'pre.migration.test@gmail.com')->count());
    }

    public function test_disabling_the_feature_removes_the_gate(): void
    {
        config(['lmlinga.resident_email_verification.enabled' => false]);
        $this->seedLinkableHousehold();
        $accountId = $this->legacyAccount();
        $this->actingAsResident($accountId, self::EMAIL);

        $this->get(route('chatbot.household.verification'))->assertOk();
        $this->get(route('chatbot.main'))->assertOk()->assertDontSee('data-lml-email-verify-notice', false);
    }
}
