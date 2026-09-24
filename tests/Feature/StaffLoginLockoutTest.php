<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LoginAttemptLockout;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class StaffLoginLockoutTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'WorkerLock!1';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear(LoginAttemptLockout::attemptsKey(LoginAttemptLockout::NAMESPACE_WORKER, 'lock.worker@example.test'));
        RateLimiter::clear(LoginAttemptLockout::lockKey(LoginAttemptLockout::NAMESPACE_WORKER, 'lock.worker@example.test'));
    }

    public function test_attempts_one_through_four_fail_normally_and_fifth_locks(): void
    {
        $this->seedWorker();

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->from(route('login'))
                ->post(route('login.store'), [
                    'email' => 'lock.worker@example.test',
                    'password' => 'WrongPass!'.$attempt,
                ])
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors(['email' => LoginAttemptLockout::GENERIC_FAILURE]);
            $this->assertGuest();
        }

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => 'lock.worker@example.test',
                'password' => 'WrongPass!5',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => LoginAttemptLockout::MESSAGE]);
        $this->assertGuest();
    }

    public function test_sixth_attempt_and_correct_password_remain_locked(): void
    {
        $this->seedWorker();
        $this->failUntilLocked('lock.worker@example.test');

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => 'lock.worker@example.test',
                'password' => 'WrongPass!6',
            ])
            ->assertSessionHasErrors(['email' => LoginAttemptLockout::MESSAGE]);

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => 'lock.worker@example.test',
                'password' => self::PASSWORD,
            ])
            ->assertSessionHasErrors(['email' => LoginAttemptLockout::MESSAGE]);

        $this->assertGuest();
    }

    public function test_successful_login_before_threshold_clears_failures(): void
    {
        $user = $this->seedWorker();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post(route('login.store'), [
                'email' => 'lock.worker@example.test',
                'password' => 'WrongPass!'.$attempt,
            ])->assertSessionHasErrors(['email' => LoginAttemptLockout::GENERIC_FAILURE]);
        }

        $this->post(route('login.store'), [
            'email' => 'lock.worker@example.test',
            'password' => self::PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => 'lock.worker@example.test',
            'password' => 'WrongPass!after',
        ])->assertSessionHasErrors(['email' => LoginAttemptLockout::GENERIC_FAILURE]);
    }

    public function test_lock_expires_and_correct_login_succeeds(): void
    {
        $user = $this->seedWorker();
        $this->failUntilLocked('lock.worker@example.test');

        $this->travel(LoginAttemptLockout::LOCK_SECONDS + 1)->seconds();

        $this->post(route('login.store'), [
            'email' => 'lock.worker@example.test',
            'password' => self::PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_username_and_email_share_the_worker_lock(): void
    {
        $this->seedWorker();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('login.store'), [
                'email' => 'lock.worker',
                'password' => 'WrongPass!'.$attempt,
            ]);
        }

        $this->post(route('login.store'), [
            'email' => 'lock.worker@example.test',
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors(['email' => LoginAttemptLockout::MESSAGE]);
    }

    private function seedWorker(): User
    {
        $user = User::factory()->create([
            'email' => 'lock.worker@example.test',
            'username' => 'lock.worker',
            'password' => self::PASSWORD,
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => true,
        ]);
        $user->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        return $user;
    }

    private function failUntilLocked(string $identity): void
    {
        for ($attempt = 1; $attempt <= LoginAttemptLockout::MAX_ATTEMPTS; $attempt++) {
            $this->post(route('login.store'), [
                'email' => $identity,
                'password' => 'WrongPass!'.$attempt,
            ]);
        }
    }
}
