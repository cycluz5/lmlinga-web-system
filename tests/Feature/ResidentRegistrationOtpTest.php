<?php

namespace Tests\Feature;

use App\Mail\ResidentRegistrationOtpMail;
use App\Models\ResidentAccount;
use App\Models\ResidentRegistrationVerification;
use App\Services\ResidentRegistrationOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class ResidentRegistrationOtpTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'otp.resident@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        Mail::fake();
        ClientTestingErdSchema::ensure();
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function register(array $overrides = [])
    {
        return $this->post(route('chatbot.register.store'), array_merge([
            'first_name' => 'Ana',
            'middle_name' => 'B',
            'last_name' => 'Cruz',
            'zone' => '2',
            'email' => self::EMAIL,
            'password' => 'SafePassw0rd!x',
        ], $overrides));
    }

    private function setKnownCode(string $email = self::EMAIL, string $code = '123456'): void
    {
        ResidentRegistrationVerification::query()->where('email', $email)->update([
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
        ]);
    }

    public function test_submitting_form_sends_code_and_creates_no_account(): void
    {
        $this->register()->assertRedirect(route('chatbot.register.verify'));

        Mail::assertSent(ResidentRegistrationOtpMail::class, fn ($m) => $m->hasTo(self::EMAIL));
        $this->assertSame(0, ResidentAccount::query()->count());
        $this->assertSame(1, ResidentRegistrationVerification::query()->count());
        $this->get(route('chatbot.register.verify'))->assertOk()->assertSee('o***@example.test');
    }

    public function test_pending_payload_is_encrypted_and_password_hashed(): void
    {
        $this->register();

        $raw = (string) DB::table('resident_registration_verifications')->value('payload');
        $this->assertStringNotContainsString('SafePassw0rd', $raw);
        $this->assertStringNotContainsString('Cruz', $raw);
        $this->assertTrue(Hash::check('SafePassw0rd!x', ResidentRegistrationVerification::query()->first()->payload['password']));
    }

    public function test_correct_code_creates_one_account_and_resident_can_log_in(): void
    {
        $this->register();
        $this->setKnownCode();

        $this->post(route('chatbot.register.verify.submit'), ['otp' => '123456'])
            ->assertRedirect(route('chatbot.login'))
            ->assertSessionHas('success', 'Email verified, account created. You can now log in.');

        $this->assertSame(1, ResidentAccount::query()->where('email', self::EMAIL)->count());
        $this->assertSame(0, ResidentRegistrationVerification::query()->count());
        $this->assertTrue(Hash::check('SafePassw0rd!x', ResidentAccount::query()->first()->password));

        $this->post(route('chatbot.login.store'), [
            'email' => self::EMAIL,
            'password' => 'SafePassw0rd!x',
        ])->assertSessionHasNoErrors();
    }

    public function test_wrong_code_increments_attempts_and_five_exhaust_it(): void
    {
        $this->register();
        $this->setKnownCode();

        $this->post(route('chatbot.register.verify.submit'), ['otp' => '000000'])->assertSessionHasErrors('otp');
        $this->assertSame(1, ResidentRegistrationVerification::query()->first()->attempts);

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('chatbot.register.verify.submit'), ['otp' => '000000']);
        }

        $this->assertNull(ResidentRegistrationVerification::query()->first()->code_hash);

        $this->post(route('chatbot.register.verify.submit'), ['otp' => '123456'])->assertSessionHasErrors('otp');
        $this->assertSame(0, ResidentAccount::query()->count());
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->register();
        $this->setKnownCode();
        ResidentRegistrationVerification::query()->update(['expires_at' => now()->subSecond()]);

        $this->post(route('chatbot.register.verify.submit'), ['otp' => '123456'])->assertSessionHasErrors('otp');
        $this->assertSame(0, ResidentAccount::query()->count());
    }

    public function test_resend_cooldown_and_hourly_cap(): void
    {
        $this->register();

        $this->post(route('chatbot.register.verify.resend'))->assertSessionHasErrors('otp');
        Mail::assertSent(ResidentRegistrationOtpMail::class, 1);

        ResidentRegistrationVerification::query()->update(['last_sent_at' => now()->subMinutes(2)]);
        $this->post(route('chatbot.register.verify.resend'))->assertSessionHas('status');
        Mail::assertSent(ResidentRegistrationOtpMail::class, 2);

        ResidentRegistrationVerification::query()->update([
            'last_sent_at' => now()->subMinutes(2),
            'sends_in_window' => 5,
            'send_window_started_at' => now()->subMinutes(10),
        ]);
        $this->post(route('chatbot.register.verify.resend'))->assertSessionHasErrors('otp');
        Mail::assertSent(ResidentRegistrationOtpMail::class, 2);
    }

    public function test_email_registered_by_someone_else_while_pending_fails_cleanly(): void
    {
        $this->register();
        $this->setKnownCode();

        DB::table('resident_accounts')->insert([
            'first_name' => 'Other',
            'middle_name' => 'A',
            'last_name' => 'Person',
            'zone_purok' => '1',
            'email' => self::EMAIL,
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post(route('chatbot.register.verify.submit'), ['otp' => '123456'])
            ->assertRedirect(route('chatbot.register'))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, ResidentAccount::query()->where('email', self::EMAIL)->count());
        $this->assertSame(0, ResidentRegistrationVerification::query()->count());
    }

    public function test_reregistering_same_email_overwrites_pending_row(): void
    {
        $this->register();
        $this->register(['first_name' => 'Maria']);

        $row = ResidentRegistrationVerification::query()->first();
        $this->assertSame(1, ResidentRegistrationVerification::query()->count());
        $this->assertSame('Maria', $row->payload['first_name']);
        // Send-window counters survive the overwrite.
        $this->assertSame(1, $row->sends_in_window);
    }

    public function test_cancel_clears_session_and_pending_row(): void
    {
        $this->register();

        $this->post(route('chatbot.register.verify.cancel'))->assertRedirect(route('chatbot.register'));

        $this->assertNull(session(ResidentRegistrationOtpService::SESSION_KEY));
        $this->assertSame(0, ResidentRegistrationVerification::query()->count());
        $this->get(route('chatbot.register.verify'))->assertRedirect(route('chatbot.register'));
    }

    public function test_mixed_case_email_is_stored_lowercased(): void
    {
        $this->register(['email' => 'Otp.Resident@Example.TEST']);
        $this->setKnownCode();
        $this->post(route('chatbot.register.verify.submit'), ['otp' => '123456']);

        $this->assertSame(self::EMAIL, ResidentAccount::query()->first()->email);
    }

    public function test_verify_without_pending_registration_redirects_to_form(): void
    {
        $this->get(route('chatbot.register.verify'))->assertRedirect(route('chatbot.register'));
        $this->post(route('chatbot.register.verify.submit'), ['otp' => '123456'])->assertRedirect(route('chatbot.register'));
    }

    public function test_flag_off_restores_direct_account_creation(): void
    {
        config(['lmlinga.resident_registration_otp.enabled' => false]);

        $this->register()->assertRedirect(route('chatbot.login'));

        Mail::assertNothingSent();
        $this->assertSame(1, ResidentAccount::query()->where('email', self::EMAIL)->count());
    }

    public function test_delivery_failure_returns_to_form_and_leaves_no_pending_row(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->from(route('chatbot.register'))->register()
            ->assertRedirect(route('chatbot.register'))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, ResidentRegistrationVerification::query()->count());
        $this->assertSame(0, ResidentAccount::query()->count());
    }
}
