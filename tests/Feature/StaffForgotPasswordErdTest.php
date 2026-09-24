<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Models\User;
use App\Support\StaffRole;
use App\Support\UserManagementErdMode;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class StaffForgotPasswordErdTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'OriginalPass!123';

    private const NEW_PASSWORD = 'ErdResetPass!7';

    protected function setUp(): void
    {
        parent::setUp();

        ClientTestingErdSchema::ensure();
        UserManagementErdMode::resetCachedState();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createErdStaff(string $role, string $email, string $username, array $overrides = []): User
    {
        $userId = (int) DB::table('user_management')->insertGetId([
            'first_name' => $overrides['first_name'] ?? 'Erd',
            'middle_name' => 'Reset',
            'last_name' => $overrides['last_name'] ?? 'Worker',
            'suffix' => null,
            'sex' => 'Female',
            'date_of_birth' => '1990-02-02',
            'civil_status' => 'Single',
            'nationality' => 'Filipino',
            'mobile_number' => '09171234567',
            'email' => $email,
            'username' => $username,
            'house_no' => '12',
            'street' => 'Sampaguita St.',
            'purok_zone' => 'Zone 1',
            'barangay' => 'La Medalla',
            'municipality_city' => 'Iriga City',
            'province' => 'Camarines Sur',
            'zip_code' => '4431',
            'password' => Hash::make(self::OLD_PASSWORD),
            'status' => 'Active',
            'must_change_password' => $overrides['must_change_password'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('worker_appointments')->insert([
            'user_id' => $userId,
            'role' => UserManagementErdMode::appointmentRoleForStorage($role),
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 2',
            'date_appointed' => '2020-01-15',
            'end_of_appointment' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($userId);
    }

    public function test_erd_user_management_password_reset_persists_on_same_row(): void
    {
        $this->assertTrue(UserManagementErdMode::isActive());
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $this->assertFalse(Schema::hasTable('users'));

        Notification::fake();

        $admin = $this->createErdStaff(StaffRole::ADMIN, 'erd.admin@example.test', 'erd.admin');
        $residentPassword = Hash::make('ResidentKeep!1');
        $residentId = (int) DB::table('resident_accounts')->insertGetId([
            'first_name' => 'Portal',
            'middle_name' => 'A',
            'last_name' => 'Resident',
            'zone_purok' => 'Zone 1',
            'email' => 'erd.resident@example.test',
            'password' => $residentPassword,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staffCount = (int) DB::table('user_management')->count();
        $appointmentCount = (int) DB::table('worker_appointments')->count();

        $this->post(route('password.email'), ['email' => $admin->email])
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        Notification::assertSentTo($admin, ResetPassword::class);

        $token = Password::broker()->createToken($admin);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $admin->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('login'));

        $fresh = $admin->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $fresh->password));
        $this->assertFalse($fresh->must_change_password);
        $this->assertSame(StaffRole::ADMIN, $fresh->role);
        $this->assertSame('Active', $fresh->status);
        $this->assertSame($staffCount, (int) DB::table('user_management')->count());
        $this->assertSame($appointmentCount, (int) DB::table('worker_appointments')->count());
        $this->assertSame(
            $residentPassword,
            (string) DB::table('resident_accounts')->where('account_id', $residentId)->value('password')
        );

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => self::OLD_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => self::NEW_PASSWORD,
        ])->assertRedirect(route('dashboard'));
    }

    public function test_erd_bhw_reset_does_not_create_duplicate_staff_row(): void
    {
        $bhw = $this->createErdStaff(StaffRole::BHW, 'erd.bhw@example.test', 'erd.bhw', [
            'must_change_password' => false,
        ]);
        $token = Password::broker()->createToken($bhw);
        $userId = $bhw->getKey();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $bhw->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('login'));

        $this->assertSame(1, (int) DB::table('user_management')->count());
        $this->assertSame($userId, User::query()->first()?->getKey());
        $this->assertSame(StaffRole::BHW, User::query()->first()?->role);
    }
}
