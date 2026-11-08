<?php

namespace Tests\Feature;

use App\Mail\ResidentEmailVerificationOtpMail;
use App\Mail\ResidentRegistrationAccountExistsMail;
use App\Models\ResidentAccount;
use App\Services\HouseholdRecordRequestOtpIssuer;
use App\Services\ResidentEmailOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Support\ClientTestingErdSchema;
use Tests\Support\ResidentEmailOtpFixtures;
use Tests\TestCase;

/**
 * Panel refinement SR-03 (RSC S-03/S-04, TR T-02/T-03/T-17): a resident account is created only after
 * the 6-digit code emailed to the address is entered within 5 minutes. sqlite :memory:, mail faked,
 * stray HTTP blocked (only the breached-password API is faked).
 */
class ResidentRegistrationEmailOtpTest extends TestCase
{
    use RefreshDatabase;
    use ResidentEmailOtpFixtures;

    private const EMAIL = 'new.resident.test@gmail.com';

    protected function setUp(): void
    {
        parent::setUp();

        ClientTestingErdSchema::ensure();
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    private function register(string $email = self::EMAIL, array $overrides = [])
    {
        return $this->post(route('chatbot.register.store'), $this->registrationPayload($email, $overrides));
    }

    private function verify(?string $code)
    {
        return $this->post(route('chatbot.register.verify.submit'), ['otp' => (string) $code]);
    }

    private function accountCount(string $email = self::EMAIL): int
    {
        return ResidentAccount::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->count();
    }

    // ---------------------------------------------------------------- happy path

    public function test_submitting_the_form_sends_a_code_but_creates_no_account(): void
    {
        $this->freezeSecond();

        $this->register()->assertRedirect(route('chatbot.register.verify'))->assertSessionHasNoErrors();

        $this->assertSame(0, $this->accountCount());
        $this->assertSame(1, $this->emailedCodeCount(self::EMAIL));
        Mail::assertSent(ResidentEmailVerificationOtpMail::class, fn ($mail): bool => $mail->hasTo(self::EMAIL) && count($mail->to) === 1);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $this->lastEmailedCode(self::EMAIL));

        $this->get(route('chatbot.register.verify'))
            ->assertOk()
            ->assertSee('n***@gmail.com')
            ->assertDontSee(self::EMAIL)
            ->assertSee('<strong data-lml-otp-timer-value>05:00</strong>', false);
    }

    public function test_correct_code_creates_exactly_one_verified_account_that_can_log_in(): void
    {
        $this->register();
        $this->verify($this->lastEmailedCode(self::EMAIL))
            ->assertRedirect(route('chatbot.login'))
            ->assertSessionHas('success', 'Email verified. Account created successfully. You can now log in.');

        $this->assertSame(1, $this->accountCount());
        $account = ResidentAccount::query()->where('email', self::EMAIL)->firstOrFail();
        $this->assertNotNull($account->email_verified_at);
        $this->assertTrue($account->hasVerifiedEmail());
        $this->assertSame('Ana', $account->first_name);
        $this->assertSame('2', (string) $account->zone_purok);
        $this->assertTrue(Hash::check('SafePassw0rd!x', (string) $account->password));

        // The pending registration is gone; the same code cannot create a second account.
        $this->verify($this->lastEmailedCode(self::EMAIL))->assertRedirect(route('chatbot.register'));
        $this->assertSame(1, $this->accountCount());

        $this->post(route('chatbot.login.store'), ['email' => self::EMAIL, 'password' => 'SafePassw0rd!x'])
            ->assertRedirect(route('chatbot.main'));
        $this->get(route('chatbot.main'))->assertOk()->assertDontSee('data-lml-email-verify-notice', false);
    }

    // ---------------------------------------------------------------- expiry (5 minutes)

    public function test_code_expiry_is_five_minutes_like_the_household_otp(): void
    {
        $this->assertSame(5, ResidentEmailOtpService::EXPIRY_MINUTES);
        $this->assertSame(HouseholdRecordRequestOtpIssuer::EXPIRY_MINUTES, ResidentEmailOtpService::EXPIRY_MINUTES);
    }

    public function test_code_is_accepted_just_before_five_minutes(): void
    {
        $this->freezeSecond();
        $this->register();
        $code = $this->lastEmailedCode(self::EMAIL);

        $this->travel(299)->seconds();
        $this->verify($code)->assertRedirect(route('chatbot.login'));
        $this->assertSame(1, $this->accountCount());
    }

    public function test_code_is_rejected_after_five_minutes_and_no_account_is_created(): void
    {
        $this->freezeSecond();
        $this->register();
        $code = $this->lastEmailedCode(self::EMAIL);

        $this->travel(301)->seconds();
        $this->verify($code)
            ->assertRedirect(route('chatbot.register.verify'))
            ->assertSessionHas('email_otp_error', 'The code is invalid or has expired.');
        $this->assertSame(0, $this->accountCount());
    }

    // ---------------------------------------------------------------- attempts

    public function test_wrong_codes_are_generic_and_five_failures_void_the_code(): void
    {
        $this->register();
        $code = (string) $this->lastEmailedCode(self::EMAIL);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 1; $i <= 4; $i++) {
            $this->verify($wrong)->assertSessionHas('email_otp_error', 'The code is invalid or has expired.');
        }
        $this->verify($wrong)->assertSessionHas('email_otp_error', 'Too many incorrect attempts. Please request a new code.');

        // Even the correct code no longer works after five failures.
        $this->verify($code)->assertSessionHas('email_otp_error', 'Too many incorrect attempts. Please request a new code.');
        $this->assertSame(0, $this->accountCount());
    }

    public function test_malformed_codes_count_as_wrong_attempts(): void
    {
        $this->register();

        $this->verify('12ab')->assertSessionHas('email_otp_error', 'The code is invalid or has expired.');
        $this->verify('')->assertSessionHas('email_otp_error', 'The code is invalid or has expired.');
        $this->assertSame(0, $this->accountCount());
    }

    // ---------------------------------------------------------------- resend

    public function test_resend_has_a_sixty_second_cooldown_and_replaces_the_old_code(): void
    {
        $this->freezeSecond();
        $this->register();
        $first = $this->lastEmailedCode(self::EMAIL);

        $this->post(route('chatbot.register.resend'))
            ->assertRedirect(route('chatbot.register.verify'))
            ->assertSessionHas('email_otp_error', 'Please wait 60 seconds before requesting a new code.');
        $this->assertSame(1, $this->emailedCodeCount(self::EMAIL));

        $this->travel(59)->seconds();
        $this->post(route('chatbot.register.resend'))->assertSessionHas('email_otp_error');
        $this->assertSame(1, $this->emailedCodeCount(self::EMAIL));

        $this->travel(1)->seconds();
        $this->post(route('chatbot.register.resend'))->assertSessionHas('email_otp_status', 'A new code has been sent.');
        $this->assertSame(2, $this->emailedCodeCount(self::EMAIL));
        $second = $this->lastEmailedCode(self::EMAIL);

        if ($first !== $second) {
            $this->verify($first)->assertSessionHas('email_otp_error', 'The code is invalid or has expired.');
        }
        $this->verify($second)->assertRedirect(route('chatbot.login'));
        $this->assertSame(1, $this->accountCount());
    }

    public function test_resend_also_restores_attempts_and_a_full_five_minutes(): void
    {
        $this->freezeSecond();
        $this->register();
        for ($i = 0; $i < 5; $i++) {
            $this->verify('000000');
        }

        $this->travel(4)->minutes();
        $this->post(route('chatbot.register.resend'))->assertSessionHas('email_otp_status');
        $this->get(route('chatbot.register.verify'))->assertSee('<strong data-lml-otp-timer-value>05:00</strong>', false);
        $this->verify($this->lastEmailedCode(self::EMAIL))->assertRedirect(route('chatbot.login'));
    }

    public function test_resends_are_capped(): void
    {
        $this->register();

        for ($i = 1; $i <= 3; $i++) {
            $this->travel(61)->seconds();
            $this->post(route('chatbot.register.resend'))->assertSessionHas('email_otp_status');
        }

        $this->travel(61)->seconds();
        $this->post(route('chatbot.register.resend'))
            ->assertSessionHas('email_otp_error', 'You have reached the maximum number of code requests. Please try again later.');
        $this->assertSame(4, $this->emailedCodeCount(self::EMAIL));
    }

    public function test_cancel_discards_the_pending_registration(): void
    {
        $this->register();
        $code = $this->lastEmailedCode(self::EMAIL);

        $this->post(route('chatbot.register.cancel'))->assertRedirect(route('chatbot.register'));
        $this->get(route('chatbot.register.verify'))->assertRedirect(route('chatbot.register'));
        $this->verify($code)->assertRedirect(route('chatbot.register'));
        $this->assertSame(0, $this->accountCount());
    }

    public function test_verify_page_without_a_pending_registration_returns_to_the_form(): void
    {
        $this->get(route('chatbot.register.verify'))->assertRedirect(route('chatbot.register'));
        $this->verify('123456')->assertRedirect(route('chatbot.register'));
        $this->post(route('chatbot.register.resend'))->assertRedirect(route('chatbot.register'));
    }

    // ---------------------------------------------------------------- duplicates and enumeration

    public function test_registered_email_gets_a_notice_not_a_code_and_the_response_is_identical(): void
    {
        DB::table('resident_accounts')->insert([
            'first_name' => 'Existing', 'middle_name' => 'A', 'last_name' => 'User', 'zone_purok' => '1',
            'email' => 'taken.resident@gmail.com', 'password' => bcrypt('Old-Passw0rd!'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = DB::table('resident_accounts')->where('email', 'taken.resident@gmail.com')->first();

        $this->freezeSecond();
        // Mixed case + spaces: the server trims/lowercases, so the existing (lowercase) account is still matched.
        $taken = $this->register('  TAKEN.Resident@Gmail.com ');
        $taken->assertSessionHasNoErrors();
        $takenPage = $this->get(route('chatbot.register.verify'))->getContent();
        $takenWrong = $this->verify('123456');
        $takenWrong->assertSessionHas('email_otp_error', 'The code is invalid or has expired.');
        $this->post(route('chatbot.register.cancel'));

        $fresh = $this->register('fresh.resident@gmail.com');
        $fresh->assertSessionHasNoErrors();
        $freshPage = $this->get(route('chatbot.register.verify'))->getContent();
        $freshWrong = $this->verify('000000' === $this->lastEmailedCode('fresh.resident@gmail.com') ? '111111' : '000000');
        $freshWrong->assertSessionHas('email_otp_error', 'The code is invalid or has expired.');

        // Same redirects, no validation error, same messages, same page apart from the masked address.
        $this->assertSame($fresh->headers->get('Location'), $taken->headers->get('Location'));
        $this->assertSame($freshWrong->headers->get('Location'), $takenWrong->headers->get('Location'));
        $this->assertSame(
            str_replace('f***@gmail.com', 'X', $freshPage),
            str_replace('t***@gmail.com', 'X', $takenPage),
        );

        Mail::assertSent(ResidentRegistrationAccountExistsMail::class, fn ($mail): bool => $mail->hasTo('taken.resident@gmail.com'));
        Mail::assertNotSent(ResidentEmailVerificationOtpMail::class, fn ($mail): bool => $mail->hasTo('taken.resident@gmail.com'));

        // The existing account is untouched and no duplicate exists.
        $this->assertSame(1, $this->accountCount('taken.resident@gmail.com'));
        $this->assertEquals($before, DB::table('resident_accounts')->where('email', 'taken.resident@gmail.com')->first());
    }

    public function test_two_pending_registrations_for_one_address_create_only_one_account(): void
    {
        $this->register();
        $firstCode = $this->lastEmailedCode(self::EMAIL);
        $firstSession = session()->all();

        $this->flushSession();
        $this->register(self::EMAIL, ['first_name' => 'Second']);
        $secondCode = $this->lastEmailedCode(self::EMAIL);
        $this->verify($secondCode)->assertRedirect(route('chatbot.login'));

        $this->flushSession();
        $this->withSession($firstSession);
        $this->verify($firstCode)->assertSessionHas('email_otp_error', 'The code is invalid or has expired.');

        $this->assertSame(1, $this->accountCount());
        $this->assertSame('Second', ResidentAccount::query()->where('email', self::EMAIL)->value('first_name'));
    }

    // ---------------------------------------------------------------- storage, logging, delivery

    public function test_code_and_password_are_never_stored_or_logged_in_plaintext(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $this->register();
        $code = (string) $this->lastEmailedCode(self::EMAIL);
        $token = (string) session(ResidentEmailOtpService::SESSION_PENDING_TOKEN);
        $pending = app(ResidentEmailOtpService::class)->pending($token);

        $this->assertIsArray($pending);
        $this->assertTrue(Hash::check($code, (string) $pending['code_hash']));
        $serialized = serialize($pending);
        $this->assertStringNotContainsString($code, str_replace((string) $pending['code_hash'], '', $serialized));
        $this->assertStringNotContainsString('SafePassw0rd!x', $serialized);
        $this->assertTrue(Hash::check('SafePassw0rd!x', (string) $pending['password_hash']));

        $this->verify('000000' === $code ? '111111' : '000000');
        $this->verify($code);

        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($code, $line);
            $this->assertStringNotContainsString(self::EMAIL, $line);
        }
    }

    public function test_mail_failure_creates_nothing_and_shows_a_retry_message(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp unavailable'));

        $this->from(route('chatbot.register'))
            ->register()
            ->assertRedirect(route('chatbot.register'))
            ->assertSessionHasErrors(['email' => 'We could not send the verification email. Please try again.']);

        $this->assertNull(session(ResidentEmailOtpService::SESSION_PENDING_TOKEN));
        $this->assertSame(0, $this->accountCount());
    }

    // ---------------------------------------------------------------- rate limiting

    public function test_per_address_email_limit_applies_equally_to_new_and_registered_addresses(): void
    {
        config([
            'lmlinga.resident_email_verification.emails_per_hour_per_address' => 2,
            // Isolate the per-address limit from the existing per-IP registration limiter (5/hour).
            'lmlinga.household_linking.register_per_hour_per_ip' => 50,
        ]);
        DB::table('resident_accounts')->insert([
            'first_name' => 'Existing', 'middle_name' => 'A', 'last_name' => 'User', 'zone_purok' => '1',
            'email' => 'taken.resident@gmail.com', 'password' => bcrypt('Old-Passw0rd!'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([self::EMAIL, 'taken.resident@gmail.com'] as $email) {
            $this->register($email)->assertRedirect(route('chatbot.register.verify'));
            $this->register($email)->assertRedirect(route('chatbot.register.verify'));
            $this->from(route('chatbot.register'))->register($email)
                ->assertRedirect(route('chatbot.register'))
                ->assertSessionHasErrors(['email' => 'Too many verification emails have been requested. Please try again later.']);
        }

        $this->assertSame(2, $this->emailedCodeCount(self::EMAIL));
        $this->travel(61)->minutes();
        $this->register()->assertRedirect(route('chatbot.register.verify'));
    }

    public function test_verify_endpoint_is_throttled_without_affecting_registration(): void
    {
        config(['lmlinga.resident_email_verification.verify_per_10_minutes' => 3]);
        $this->register();

        for ($i = 0; $i < 3; $i++) {
            $this->verify('000000')->assertRedirect();
        }
        $this->verify('000000')->assertStatus(429);

        // Separate named limiter: registration itself is still available.
        $this->register('other.resident@gmail.com')->assertRedirect(route('chatbot.register.verify'));
    }

    public function test_resend_endpoint_is_throttled(): void
    {
        config(['lmlinga.resident_email_verification.send_per_10_minutes' => 2]);
        $this->register();

        $this->post(route('chatbot.register.resend'))->assertRedirect();
        $this->post(route('chatbot.register.resend'))->assertRedirect();
        $this->post(route('chatbot.register.resend'))->assertStatus(429);
    }

    public function test_registration_form_still_validates_input(): void
    {
        $this->from(route('chatbot.register'))
            ->post(route('chatbot.register.store'), $this->registrationPayload('not-an-email', ['password' => 'weak']))
            ->assertRedirect(route('chatbot.register'))
            ->assertSessionHasErrors(['email', 'password']);

        Mail::assertNothingSent();
    }

    public function test_register_page_mentions_the_verification_code(): void
    {
        $this->get(route('chatbot.register'))->assertOk()->assertSee('Use your Gmail address (…@gmail.com). We will send a 6-digit verification code to it.');
    }

    // ---------------------------------------------------------------- rollback flag

    public function test_disabling_the_feature_restores_immediate_registration(): void
    {
        config(['lmlinga.resident_email_verification.enabled' => false]);

        $this->register()
            ->assertRedirect(route('chatbot.login'))
            ->assertSessionHas('success', 'Account created successfully. You can now log in.');

        $this->assertSame(1, $this->accountCount());
        Mail::assertNothingSent();
        $this->assertNull(Cache::get('lmlinga:resident-registration:any'));
    }
}
