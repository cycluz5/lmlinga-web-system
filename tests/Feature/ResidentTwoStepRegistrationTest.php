<?php

namespace Tests\Feature;

use App\Mail\ResidentRegistrationAccountExistsMail;
use App\Models\ResidentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ClientTestingErdSchema;
use Tests\Support\ResidentEmailOtpFixtures;
use Tests\TestCase;

/**
 * Batch 6 (refinement note 6) — two-step resident registration:
 * step 1 = email + password, verified with the 5-minute emailed code; step 2 = name + zone.
 * No resident account exists until BOTH the email is verified AND step 2 is submitted.
 */
class ResidentTwoStepRegistrationTest extends TestCase
{
    use RefreshDatabase;
    use ResidentEmailOtpFixtures;

    private const EMAIL = 'two.step.resident@gmail.com';

    private const PASSWORD = 'SafePassw0rd!x';

    protected function setUp(): void
    {
        parent::setUp();
        // Same production-shaped (ERD) schema as ResidentRegistrationEmailOtpTest (resident_accounts.zone_purok).
        ClientTestingErdSchema::ensure();
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    private function stepOne(string $email = self::EMAIL)
    {
        return $this->post(route('chatbot.register.store'), ['email' => $email, 'password' => self::PASSWORD]);
    }

    private function verify(?string $code)
    {
        return $this->post(route('chatbot.register.verify.submit'), ['otp' => (string) $code]);
    }

    private function stepTwo(array $overrides = [])
    {
        return $this->post(route('chatbot.register.details.store'), array_merge([
            'first_name' => 'Ana', 'middle_name' => 'B', 'last_name' => 'Cruz', 'zone' => '2',
        ], $overrides));
    }

    private function accounts(string $email = self::EMAIL): int
    {
        return ResidentAccount::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->count();
    }

    // ------------------------------------------------------------------ the pages

    public function test_step_one_page_asks_only_for_email_and_password(): void
    {
        $html = $this->get(route('chatbot.register'))->assertOk()->getContent();

        $this->assertStringContainsString('data-lml-register-step="1"', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="password"', $html);
        foreach (['first_name', 'middle_name', 'last_name', 'zone'] as $field) {
            $this->assertStringNotContainsString('name="'.$field.'"', $html);
        }
        $this->assertStringContainsString('action="'.route('chatbot.register.store').'"', $html);
    }

    // ------------------------------------------------------------------ happy path

    public function test_full_two_step_flow_creates_exactly_one_verified_account_only_at_the_end(): void
    {
        $this->stepOne()->assertRedirect(route('chatbot.register.verify'));
        $this->assertSame(0, $this->accounts(), 'no account after step 1');

        $this->verify($this->lastEmailedCode(self::EMAIL))->assertRedirect(route('chatbot.register.details'));
        $this->assertSame(0, $this->accounts(), 'no account after the email code alone');

        $html = $this->get(route('chatbot.register.details'))->assertOk()->getContent();
        $this->assertStringContainsString('data-lml-register-step="2"', $html);
        $this->assertStringContainsString('t***@gmail.com', $html);
        foreach (['first_name', 'middle_name', 'last_name', 'zone'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html);
        }
        $this->assertStringNotContainsString('name="password"', $html);
        $this->assertStringNotContainsString('name="email"', $html);

        $this->stepTwo()->assertRedirect(route('chatbot.login'))->assertSessionHas('success');

        $this->assertSame(1, $this->accounts());
        $account = ResidentAccount::query()->where('email', self::EMAIL)->firstOrFail();
        $this->assertSame(['Ana', 'B', 'Cruz', '2'], [$account->first_name, $account->middle_name, $account->last_name, (string) $account->zone_purok]);
        $this->assertTrue(Hash::check(self::PASSWORD, (string) $account->password));
        if (ResidentAccount::supportsEmailVerification()) {
            $this->assertNotNull($account->email_verified_at);
        }

        // The finished registration cannot be replayed.
        $this->stepTwo(['first_name' => 'Other'])->assertRedirect(route('chatbot.register'));
        $this->assertSame(1, $this->accounts());
    }

    // ------------------------------------------------------------------ step 2 cannot be reached or used without verification

    public function test_step_two_is_refused_before_the_email_code_is_verified(): void
    {
        $this->stepOne();

        $this->get(route('chatbot.register.details'))->assertRedirect(route('chatbot.register'));
        $this->stepTwo()->assertRedirect(route('chatbot.register'));
        $this->assertSame(0, $this->accounts());

        // Without any registration in this session at all.
        $this->flushSession();
        $this->stepTwo()->assertRedirect(route('chatbot.register'));
        $this->assertSame(0, $this->accounts());
    }

    public function test_wrong_or_expired_code_never_opens_step_two(): void
    {
        $this->stepOne();
        $code = $this->lastEmailedCode(self::EMAIL);

        $this->verify($code === '000000' ? '111111' : '000000')->assertRedirect(route('chatbot.register.verify'));
        $this->get(route('chatbot.register.details'))->assertRedirect(route('chatbot.register'));

        $this->travel(5 * 60 + 1)->seconds();
        $this->verify($code)->assertRedirect(route('chatbot.register.verify'));
        $this->get(route('chatbot.register.details'))->assertRedirect(route('chatbot.register'));
        $this->assertSame(0, $this->accounts());
    }

    public function test_registered_email_never_reaches_step_two_and_gets_the_same_step_one_response(): void
    {
        // Seeded the same way as ResidentRegistrationEmailOtpTest (production-shaped schema).
        DB::table('resident_accounts')->insert([
            'first_name' => 'Existing', 'middle_name' => 'A', 'last_name' => 'User', 'zone_purok' => '1',
            'email' => self::EMAIL, 'password' => bcrypt('Old-Passw0rd!'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->stepOne()->assertRedirect(route('chatbot.register.verify'));
        Mail::assertSent(ResidentRegistrationAccountExistsMail::class, fn ($m) => $m->hasTo(self::EMAIL));
        $this->assertNull($this->lastEmailedCode(self::EMAIL));

        foreach (['000000', '123456'] as $guess) {
            $this->verify($guess)->assertRedirect(route('chatbot.register.verify'));
        }
        $this->get(route('chatbot.register.details'))->assertRedirect(route('chatbot.register'));
        $this->assertSame(1, $this->accounts());
    }

    // ------------------------------------------------------------------ step 2 validation and time window

    public function test_step_two_validates_name_and_zone_and_can_be_retried(): void
    {
        $this->stepOne();
        $this->verify($this->lastEmailedCode(self::EMAIL));

        $this->from(route('chatbot.register.details'))->stepTwo(['zone' => '9', 'first_name' => ''])
            ->assertSessionHasErrors(['zone', 'first_name']);
        $this->assertSame(0, $this->accounts());

        $this->stepTwo()->assertRedirect(route('chatbot.login'));
        $this->assertSame(1, $this->accounts());
    }

    public function test_step_two_expires_after_the_details_window(): void
    {
        $this->stepOne();
        $this->verify($this->lastEmailedCode(self::EMAIL));

        $this->travel((int) config('lmlinga.resident_email_verification.details_minutes') + 1)->minutes();

        $this->get(route('chatbot.register.details'))->assertRedirect(route('chatbot.register'));
        $this->stepTwo()->assertRedirect(route('chatbot.register'));
        $this->assertSame(0, $this->accounts());
    }

    public function test_step_one_still_enforces_gmail_and_password_rules(): void
    {
        $this->post(route('chatbot.register.store'), ['email' => 'someone@yahoo.com', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');
        $this->post(route('chatbot.register.store'), ['email' => self::EMAIL, 'password' => 'weak'])
            ->assertSessionHasErrors('password');

        Mail::assertNothingSent();
        $this->assertSame(0, $this->accounts());
    }

    public function test_resend_is_refused_once_the_email_is_verified(): void
    {
        $this->stepOne();
        $this->verify($this->lastEmailedCode(self::EMAIL));
        $sent = $this->emailedCodeCount(self::EMAIL);

        $this->travel(61)->seconds();
        $this->post(route('chatbot.register.resend'))->assertRedirect(route('chatbot.register'));

        $this->assertSame($sent, $this->emailedCodeCount(self::EMAIL));
    }
}
