<?php

namespace Tests\Feature;

use App\Models\User;
use App\Rules\DeliverableEmailDomain;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Refinement item 1: no health-worker account for an email whose domain cannot receive mail. */
class AdminHealthWorkerEmailDeliverabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lmlinga.staff_email_dns_check' => true, 'lmlinga.staff_mobile_dummy_check' => true]);
        DeliverableEmailDomain::resolveUsing(
            static fn (string $host, string $type): bool => $type === 'MX' && $host === 'gmail.com'
        );
    }

    protected function tearDown(): void
    {
        DeliverableEmailDomain::resolveUsing(null);
        parent::tearDown();
    }

    private function adminSession(): static
    {
        $admin = User::factory()->create([
            'email' => 'creator.admin@gmail.com',
            'username' => 'creator.admin',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
        ]);
        $admin->assignCurrentAppointment([
            'role' => StaffRole::ADMIN,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);
        $this->actingAs($admin);

        return $this->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $email): array
    {
        return [
            'first_name' => 'Test',
            'last_name' => 'Worker',
            'middle_name' => 'A',
            'email' => $email,
            'mobile' => '09171112222',
            'role' => 'BHW',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => 'TempPass!123',
            'password_confirmation' => 'TempPass!123',
        ];
    }

    public function test_account_is_not_created_for_a_domain_without_a_mail_server(): void
    {
        $session = $this->adminSession();
        $before = User::query()->count();

        $session
            ->post(route('user-management.health-workers.store'), $this->payload('dummy.worker@gmial.co'))
            ->assertSessionHasErrors(['email' => DeliverableEmailDomain::MESSAGE]);

        $this->assertSame($before, User::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'dummy.worker@gmial.co']);
    }

    public function test_account_is_created_for_a_domain_with_a_mail_server(): void
    {
        $this->adminSession()
            ->post(route('user-management.health-workers.store'), $this->payload('real.worker@gmail.com'))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('users', ['email' => 'real.worker@gmail.com']);
    }

    public function test_account_is_not_created_with_a_dummy_mobile_number(): void
    {
        $session = $this->adminSession();
        $before = User::query()->count();

        foreach (['09000000000', '09123456789', '0917123'] as $mobile) {
            $session
                ->post(route('user-management.health-workers.store'), array_merge($this->payload('real.worker@gmail.com'), ['mobile' => $mobile]))
                ->assertSessionHasErrors('mobile');
        }

        $this->assertSame($before, User::query()->count());
    }
}
