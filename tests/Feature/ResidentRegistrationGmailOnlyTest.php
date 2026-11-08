<?php

namespace Tests\Feature;

use App\Http\Controllers\Chatbot\ResidentRegistrationController;
use App\Mail\ResidentEmailVerificationOtpMail;
use App\Models\ResidentAccount;
use App\Services\ResidentEmailOtpService;
use App\Support\ResidentAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClientTestingErdSchema;
use Tests\Support\ResidentEmailOtpFixtures;
use Tests\TestCase;

/**
 * Gmail-only resident registration. Every new-registration path (email OTP and the rollback path) accepts
 * only strict Gmail addresses, checked server-side after trimming and lowercasing. Rejected addresses never
 * start an OTP. Existing accounts (any domain) keep login and email verification.
 */
class ResidentRegistrationGmailOnlyTest extends TestCase
{
    use RefreshDatabase;
    use ResidentEmailOtpFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        ClientTestingErdSchema::ensure();
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    private function register(string $email)
    {
        return $this->from(route('chatbot.register'))
            ->post(route('chatbot.register.store'), $this->registrationPayload($email));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedAddresses(): array
    {
        return [
            'other domain' => ['juan.resident@example.test'],
            'yahoo' => ['juan.resident@yahoo.com'],
            'googlemail' => ['juan.resident@googlemail.com'],
            'gmail.co' => ['juan.resident@gmail.co'],
            'gmail.com.ph' => ['juan.resident@gmail.com.ph'],
            'gmail subdomain' => ['juan.resident@mail.gmail.com'],
            'gmail.com as local part' => ['gmail.com@yahoo.com'],
            'gmail.com then other domain' => ['juan.resident@gmail.com.evil.test'],
            'missing dot in domain' => ['juan.resident@gmailcom'],
            'no at sign' => ['juan.resident.gmail.com'],
            'two at signs' => ['juan@resident@gmail.com'],
            'empty local part' => ['@gmail.com'],
            'inner space' => ['juan resident@gmail.com'],
            'leading dot' => ['.juanresident@gmail.com'],
            'trailing dot' => ['juanresident.@gmail.com'],
            'consecutive dots' => ['juan..resident@gmail.com'],
            'too short (5)' => ['juanr@gmail.com'],
            'too long (31)' => [str_repeat('a', 31).'@gmail.com'],
            'plus alias' => ['juan.resident+lmlinga@gmail.com'],
            'underscore' => ['juan_resident@gmail.com'],
            'hyphen' => ['juan-resident@gmail.com'],
        ];
    }

    #[DataProvider('rejectedAddresses')]
    public function test_non_gmail_and_malformed_addresses_are_rejected_and_never_start_an_otp(string $email): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message;
        });

        $this->register($email)
            ->assertRedirect(route('chatbot.register'))
            ->assertSessionHasErrors(['email' => ResidentRegistrationController::GMAIL_MESSAGE]);

        Mail::assertNothingSent();
        $this->assertNull(session(ResidentEmailOtpService::SESSION_PENDING_TOKEN));
        $this->assertNotContains('resident.email_otp.sent', $logged);
        $this->assertSame(0, ResidentAccount::query()->count());
        $this->get(route('chatbot.register.verify'))->assertRedirect(route('chatbot.register'));
    }

    public function test_empty_email_keeps_the_standard_required_message(): void
    {
        $this->register('   ')->assertSessionHasErrors(['email' => 'The email field is required.']);
        Mail::assertNothingSent();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedAddresses(): array
    {
        return [
            'letters' => ['juanresident@gmail.com'],
            'dots and digits' => ['juan.dela.cruz2026@gmail.com'],
            'six characters' => ['abcdef@gmail.com'],
            'thirty characters' => [str_repeat('a', 30).'@gmail.com'],
        ];
    }

    #[DataProvider('acceptedAddresses')]
    public function test_valid_gmail_addresses_start_the_email_otp(string $email): void
    {
        $this->register($email)->assertRedirect(route('chatbot.register.verify'))->assertSessionHasNoErrors();

        Mail::assertSent(ResidentEmailVerificationOtpMail::class, fn ($mail): bool => $mail->hasTo($email));
        $this->assertSame(0, ResidentAccount::query()->count(), 'No account before the code is verified.');
    }

    public function test_email_is_trimmed_and_lowercased_before_validation_and_storage(): void
    {
        $this->register('  Juan.DelaCruz@Gmail.COM ')->assertRedirect(route('chatbot.register.verify'));

        Mail::assertSent(ResidentEmailVerificationOtpMail::class, fn ($mail): bool => $mail->hasTo('juan.delacruz@gmail.com'));

        $this->post(route('chatbot.register.verify.submit'), ['otp' => $this->lastEmailedCode('juan.delacruz@gmail.com')])
            ->assertRedirect(route('chatbot.login'));
        $this->assertSame(['juan.delacruz@gmail.com'], ResidentAccount::query()->pluck('email')->all());
        $this->assertTrue(ResidentAccount::query()->firstOrFail()->hasVerifiedEmail());
    }

    public function test_rejection_happens_server_side_without_the_browser_check(): void
    {
        // A crafted request (no page, no JavaScript) with a non-Gmail address is still refused.
        $this->post(route('chatbot.register.store'), $this->registrationPayload('crafted.request@example.test'))
            ->assertSessionHasErrors(['email' => ResidentRegistrationController::GMAIL_MESSAGE]);

        $this->post(route('chatbot.register.store'), array_merge($this->registrationPayload('x'), ['email' => ['crafted.request@gmail.com']]))
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
        $this->assertSame(0, ResidentAccount::query()->count());
    }

    public function test_rollback_mode_also_accepts_only_gmail(): void
    {
        config(['lmlinga.resident_email_verification.enabled' => false]);

        $this->register('rollback.resident@example.test')
            ->assertSessionHasErrors(['email' => ResidentRegistrationController::GMAIL_MESSAGE]);
        $this->assertSame(0, ResidentAccount::query()->count());

        $this->register(' Rollback.Resident@Gmail.com')->assertRedirect(route('chatbot.login'));
        $this->assertSame(['rollback.resident@gmail.com'], ResidentAccount::query()->pluck('email')->all());
        Mail::assertNothingSent();
    }

    public function test_existing_non_gmail_accounts_keep_login_and_email_verification(): void
    {
        $accountId = (int) DB::table('resident_accounts')->insertGetId([
            'first_name' => 'Existing', 'middle_name' => 'Non', 'last_name' => 'Gmail', 'zone_purok' => '1',
            'email' => 'existing.resident@example.test', 'email_verified_at' => null,
            'password' => bcrypt('SafePassw0rd!x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = (array) DB::table('resident_accounts')->where('account_id', $accountId)->first();

        $this->post(route('chatbot.login.store'), ['email' => 'existing.resident@example.test', 'password' => 'SafePassw0rd!x'])
            ->assertRedirect(route('chatbot.main'));

        $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $accountId,
            ResidentAuthenticator::SESSION_EMAIL => 'existing.resident@example.test',
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ]);
        $this->post(route('chatbot.email.verify.send'))->assertSessionHas('email_otp_status');
        $this->post(route('chatbot.email.verify.submit'), ['otp' => $this->lastEmailedCode('existing.resident@example.test')])
            ->assertRedirect(route('chatbot.main'));

        $after = (array) DB::table('resident_accounts')->where('account_id', $accountId)->first();
        $this->assertNotNull($after['email_verified_at']);
        unset($before['email_verified_at'], $before['updated_at'], $after['email_verified_at'], $after['updated_at']);
        $this->assertSame($before, $after, 'Only the verification timestamp changes; the non-Gmail email is kept.');
    }

    public function test_register_page_shows_the_gmail_help_text_and_client_error_slot(): void
    {
        $this->get(route('chatbot.register'))
            ->assertOk()
            ->assertSee('Use your Gmail address (…@gmail.com). We will send a 6-digit verification code to it.')
            ->assertSee('data-lml-register-email', false)
            ->assertSee('id="email-gmail-error"', false)
            ->assertSee('data-lml-register-form', false);
    }
}
