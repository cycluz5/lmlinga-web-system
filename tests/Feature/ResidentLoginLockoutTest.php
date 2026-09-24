<?php

namespace Tests\Feature;

use App\Models\ResidentAccount;
use App\Models\User;
use App\Support\LoginAttemptLockout;
use App\Support\ResidentAuthenticator;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\LegacyResidentAccountsSchema;
use Tests\TestCase;

class ResidentLoginLockoutTest extends TestCase
{
    use RefreshDatabase;

    private const RESIDENT_PASSWORD = 'ResidentLock!1';

    private const WORKER_PASSWORD = 'WorkerLock!1';

    protected function setUp(): void
    {
        parent::setUp();
        LegacyResidentAccountsSchema::ensure();
        RateLimiter::clear(LoginAttemptLockout::attemptsKey(LoginAttemptLockout::NAMESPACE_RESIDENT, 'lock.resident@example.test'));
        RateLimiter::clear(LoginAttemptLockout::lockKey(LoginAttemptLockout::NAMESPACE_RESIDENT, 'lock.resident@example.test'));
        RateLimiter::clear(LoginAttemptLockout::attemptsKey(LoginAttemptLockout::NAMESPACE_WORKER, 'lock.resident@example.test'));
        RateLimiter::clear(LoginAttemptLockout::lockKey(LoginAttemptLockout::NAMESPACE_WORKER, 'lock.resident@example.test'));
    }

    public function test_attempts_one_through_four_fail_normally_and_fifth_locks(): void
    {
        $this->seedResident();

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->from(route('chatbot.login'))
                ->post(route('chatbot.login.store'), [
                    'email' => 'lock.resident@example.test',
                    'password' => 'WrongPass!'.$attempt,
                ])
                ->assertRedirect(route('chatbot.login'))
                ->assertSessionHasErrors(['email' => LoginAttemptLockout::GENERIC_FAILURE]);
            $this->assertFalse((bool) session(ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED));
        }

        $this->from(route('chatbot.login'))
            ->post(route('chatbot.login.store'), [
                'email' => 'lock.resident@example.test',
                'password' => 'WrongPass!5',
            ])
            ->assertRedirect(route('chatbot.login'))
            ->assertSessionHasErrors(['email' => LoginAttemptLockout::MESSAGE]);
    }

    public function test_sixth_attempt_and_correct_password_remain_locked(): void
    {
        $this->seedResident();
        $this->failUntilLocked('lock.resident@example.test');

        $this->post(route('chatbot.login.store'), [
            'email' => 'lock.resident@example.test',
            'password' => 'WrongPass!6',
        ])->assertSessionHasErrors(['email' => LoginAttemptLockout::MESSAGE]);

        $this->post(route('chatbot.login.store'), [
            'email' => 'lock.resident@example.test',
            'password' => self::RESIDENT_PASSWORD,
        ])->assertSessionHasErrors(['email' => LoginAttemptLockout::MESSAGE]);

        $this->assertFalse((bool) session(ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED));
    }

    public function test_successful_login_before_threshold_clears_failures(): void
    {
        $account = $this->seedResident();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post(route('chatbot.login.store'), [
                'email' => 'lock.resident@example.test',
                'password' => 'WrongPass!'.$attempt,
            ])->assertSessionHasErrors(['email' => LoginAttemptLockout::GENERIC_FAILURE]);
        }

        $this->post(route('chatbot.login.store'), [
            'email' => 'lock.resident@example.test',
            'password' => self::RESIDENT_PASSWORD,
        ])->assertRedirect(route('chatbot.main'));

        $this->assertTrue((bool) session(ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED));
        $this->assertEquals($account->getKey(), session(ResidentAuthenticator::SESSION_ACCOUNT_ID));

        ResidentAuthenticator::clearSession();

        $this->post(route('chatbot.login.store'), [
            'email' => 'lock.resident@example.test',
            'password' => 'WrongPass!after',
        ])->assertSessionHasErrors(['email' => LoginAttemptLockout::GENERIC_FAILURE]);
    }

    public function test_lock_expires_and_correct_login_succeeds(): void
    {
        $this->seedResident();
        $this->failUntilLocked('lock.resident@example.test');

        $this->travel(LoginAttemptLockout::LOCK_SECONDS + 1)->seconds();

        $this->post(route('chatbot.login.store'), [
            'email' => 'lock.resident@example.test',
            'password' => self::RESIDENT_PASSWORD,
        ])->assertRedirect(route('chatbot.main'));

        $this->assertTrue((bool) session(ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED));
    }

    public function test_worker_and_resident_counters_do_not_collide(): void
    {
        $this->seedResident();
        $worker = User::factory()->create([
            'email' => 'lock.resident@example.test',
            'username' => 'lock.resident.staff',
            'password' => self::WORKER_PASSWORD,
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
        ]);
        $worker->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        $this->failUntilLocked('lock.resident@example.test');

        $this->post(route('login.store'), [
            'email' => 'lock.resident@example.test',
            'password' => self::WORKER_PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($worker);
    }

    private function seedResident(): ResidentAccount
    {
        return ResidentAccount::factory()->create([
            'email' => 'lock.resident@example.test',
            'password' => self::RESIDENT_PASSWORD,
            'first_name' => 'Ana',
            'last_name' => 'Resident',
        ]);
    }

    private function failUntilLocked(string $identity): void
    {
        for ($attempt = 1; $attempt <= LoginAttemptLockout::MAX_ATTEMPTS; $attempt++) {
            $this->post(route('chatbot.login.store'), [
                'email' => $identity,
                'password' => 'WrongPass!'.$attempt,
            ]);
        }
    }
}
