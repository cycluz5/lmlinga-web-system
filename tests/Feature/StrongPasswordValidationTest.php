<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\StrongPassword;
use App\Support\UserManagementErdMode;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StrongPasswordValidationTest extends TestCase
{
    use RefreshDatabase;

    private function authenticateAdmin(): User
    {
        $admin = User::factory()->create([
            'email' => 'admin.password@example.test',
            'username' => 'admin.password',
            'password' => 'AdminPass!123',
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
        session([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function validWorkerPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'New',
            'last_name' => 'Worker',
            'middle_name' => 'Q',
            'email' => 'new.worker@example.test',
            'username' => 'new.worker',
            'mobile' => '09170001111',
            'role' => 'BHW',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => 'StrongPass!1',
            'password_confirmation' => 'StrongPass!1',
        ], $overrides);
    }

    public function test_letters_only_password_rejected(): void
    {
        $this->authenticateAdmin();
        $this->post(route('user-management.health-workers.store'), $this->validWorkerPayload([
                'password' => 'abcdefgh',
                'password_confirmation' => 'abcdefgh',
            ]))
            ->assertSessionHasErrors('password');
    }

    public function test_numbers_only_password_rejected(): void
    {
        $this->authenticateAdmin();
        $this->post(route('user-management.health-workers.store'), $this->validWorkerPayload([
                'password' => '12345678',
                'password_confirmation' => '12345678',
            ]))
            ->assertSessionHasErrors('password');
    }

    public function test_letters_and_numbers_without_symbol_rejected(): void
    {
        $this->authenticateAdmin();
        $this->post(route('user-management.health-workers.store'), $this->validWorkerPayload([
                'password' => 'Abcdefg1',
                'password_confirmation' => 'Abcdefg1',
            ]))
            ->assertSessionHasErrors('password');
    }

    public function test_strong_password_accepted_and_hashed(): void
    {
        $this->authenticateAdmin();
        $this->post(route('user-management.health-workers.store'), $this->validWorkerPayload())
            ->assertRedirect();

        $user = User::query()->where('email', 'new.worker@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('StrongPass!1', (string) $user->password));
        $this->assertNotSame('StrongPass!1', (string) $user->getAttributes()['password']);
    }

    public function test_strength_levels_match_contract(): void
    {
        $this->assertSame(StrongPassword::STRENGTH_WEAK, StrongPassword::strengthLevel('abc'));
        $this->assertSame(StrongPassword::STRENGTH_WEAK, StrongPassword::strengthLevel('12345678'));
        $this->assertSame(StrongPassword::STRENGTH_MEDIUM, StrongPassword::strengthLevel('Abcdefg1'));
        $this->assertSame(StrongPassword::STRENGTH_STRONG, StrongPassword::strengthLevel('StrongPass!1'));
    }

    public function test_change_password_page_shows_strong_password_hint(): void
    {
        $user = User::factory()->create([
            'email' => 'must.change@example.test',
            'username' => 'must.change',
            'password' => 'TempPass!1',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => true,
        ]);
        $user->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);
        $this->actingAs($user);

        $this->get(route('password.change.required'))
            ->assertOk()
            ->assertSee(StrongPassword::MESSAGE, false);
    }

    public function test_change_password_rejects_letters_only(): void
    {
        $user = User::factory()->create([
            'email' => 'must.change.weak@example.test',
            'username' => 'must.change.weak',
            'password' => 'TempPass!1',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => true,
        ]);
        $user->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);
        $this->actingAs($user);

        $this->post(route('password.change.store'), [
            'new_password' => 'abcdefgh',
            'new_password_confirmation' => 'abcdefgh',
        ])->assertSessionHasErrors('new_password');
    }
}
