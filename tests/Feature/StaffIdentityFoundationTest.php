<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\StaffAccountStatus;
use App\Support\StaffAuthenticator;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StaffIdentityFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_account_can_be_persisted_with_profile_fields(): void
    {
        $admin = User::factory()->create([
            'email' => 'creator@example.test',
            'username' => 'creator.admin',
        ]);

        $worker = User::factory()->create([
            'first_name' => 'Maria',
            'middle_name' => 'Cruz',
            'last_name' => 'Reyes',
            'email' => 'maria.reyes@example.test',
            'username' => 'maria.reyes',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => true,
            'created_by' => $admin->id,
            'password' => 'TempPass!123',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $worker->id,
            'email' => 'maria.reyes@example.test',
            'username' => 'maria.reyes',
            'first_name' => 'Maria',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => true,
            'created_by' => $admin->id,
        ]);
        $this->assertSame('Maria Cruz Reyes', $worker->fresh()->name);
        $this->assertTrue($worker->creator->is($admin));
    }

    public function test_username_and_email_are_unique(): void
    {
        User::factory()->create([
            'email' => 'unique@example.test',
            'username' => 'unique.user',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        User::factory()->create([
            'email' => 'other@example.test',
            'username' => 'unique.user',
        ]);
    }

    public function test_email_uniqueness_is_enforced(): void
    {
        User::factory()->create([
            'email' => 'dup@example.test',
            'username' => 'user.one',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        User::factory()->create([
            'email' => 'dup@example.test',
            'username' => 'user.two',
        ]);
    }

    public function test_password_is_hashed_and_plaintext_is_never_persisted(): void
    {
        $plain = 'SuperSecret!99';
        $user = User::factory()->create([
            'password' => $plain,
            'email' => 'hash-check@example.test',
            'username' => 'hash.check',
        ]);

        $raw = DB::table('users')->where('id', $user->id)->value('password');

        $this->assertIsString($raw);
        $this->assertNotSame($plain, $raw);
        $this->assertTrue(Hash::check($plain, $raw));
        $this->assertStringNotContainsString($plain, (string) $raw);
    }

    public function test_must_change_password_flag_is_persistable(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'email' => 'force-change@example.test',
            'username' => 'force.change',
        ]);

        $this->assertTrue($user->fresh()->must_change_password);

        $user->forceFill(['must_change_password' => false])->save();
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_worker_appointment_belongs_to_staff_and_supports_history(): void
    {
        $user = User::factory()->create([
            'email' => 'history@example.test',
            'username' => 'history.user',
        ]);

        $ended = $user->appointments()->create([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
            'end_of_appointment' => '2024-12-31',
            'is_current' => false,
        ]);

        $current = $user->assignCurrentAppointment([
            'role' => StaffRole::BNS,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 2',
            'date_appointed' => '2025-01-01',
            'end_of_appointment' => null,
        ]);

        $this->assertTrue($current->is_current);
        $this->assertSame(StaffRole::BNS, $user->fresh()->role);
        $this->assertSame(2, $user->appointments()->count());
        $this->assertFalse($ended->fresh()->is_current);
        $this->assertTrue($current->user->is($user));
    }

    public function test_duplicate_current_appointment_is_prevented(): void
    {
        $user = User::factory()->create([
            'email' => 'one-current@example.test',
            'username' => 'one.current',
        ]);

        $user->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2024-01-01',
        ]);

        $user->assignCurrentAppointment([
            'role' => StaffRole::BSPO,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 3',
            'date_appointed' => '2025-06-01',
        ]);

        $this->assertSame(1, $user->appointments()->where('is_current', true)->count());
        $this->assertSame(StaffRole::BSPO, $user->fresh()->role);
        $this->assertSame(2, $user->appointments()->count());
    }

    public function test_database_rejects_second_current_row_for_same_user(): void
    {
        $user = User::factory()->create([
            'email' => 'db-reject@example.test',
            'username' => 'db.reject',
        ]);

        $user->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2024-01-01',
        ]);

        $now = now();

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Bypass Eloquent hooks — prove the DB unique guard itself.
        DB::table('worker_appointments')->insert([
            'user_id' => $user->id,
            'role' => StaffRole::BNS,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 2',
            'date_appointed' => '2025-01-01',
            'end_of_appointment' => null,
            'is_current' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_two_users_may_each_have_one_current_appointment(): void
    {
        $a = User::factory()->create([
            'email' => 'user-a@example.test',
            'username' => 'user.a',
        ]);
        $b = User::factory()->create([
            'email' => 'user-b@example.test',
            'username' => 'user.b',
        ]);

        $a->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => now()->toDateString(),
        ]);
        $b->assignCurrentAppointment([
            'role' => StaffRole::BNS,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 2',
            'date_appointed' => now()->toDateString(),
        ]);

        $this->assertSame(1, $a->appointments()->where('is_current', true)->count());
        $this->assertSame(1, $b->appointments()->where('is_current', true)->count());
        $this->assertSame(StaffRole::BHW, $a->fresh()->role);
        $this->assertSame(StaffRole::BNS, $b->fresh()->role);
    }

    public function test_invalid_role_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'bad-role@example.test',
            'username' => 'bad.role',
        ]);

        $this->expectException(ValidationException::class);
        $user->appointments()->create([
            'role' => 'not-a-role',
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2024-01-01',
            'is_current' => true,
        ]);
    }

    public function test_role_machine_values_are_accepted_and_normalized(): void
    {
        $user = User::factory()->create([
            'email' => 'roles@example.test',
            'username' => 'roles.user',
        ]);

        foreach (['Admin', 'BHW', 'bns', 'BSPO'] as $input) {
            $appointment = $user->assignCurrentAppointment([
                'role' => $input,
                'assigned_barangay' => 'La Medalla',
                'assigned_zone' => 'Zone 1',
                'date_appointed' => now()->toDateString(),
            ]);
            $this->assertContains($appointment->role, StaffRole::ALL);
            $this->assertSame(StaffRole::normalize($input), $user->fresh()->role);
        }
    }

    public function test_deactivation_preserves_rows_and_blocks_login(): void
    {
        $user = User::factory()->create([
            'email' => 'deactivate@example.test',
            'username' => 'deactivate.user',
            'password' => 'ValidPass!123',
            'status' => StaffAccountStatus::ACTIVE,
        ]);
        $user->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => now()->toDateString(),
        ]);

        $user->deactivate();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => StaffAccountStatus::INACTIVE,
        ]);
        $this->assertSame(1, $user->appointments()->count());

        $result = StaffAuthenticator::attempt('deactivate@example.test', 'ValidPass!123');
        $this->assertNull($result['via']);
        $this->assertGuest();
    }

    public function test_database_login_sets_auth_and_ui_role(): void
    {
        $user = User::factory()->create([
            'email' => 'login-db@example.test',
            'username' => 'login.db',
            'password' => 'LoginPass!123',
            'must_change_password' => false,
        ]);
        $user->assignCurrentAppointment([
            'role' => StaffRole::ADMIN,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => now()->toDateString(),
        ]);

        $response = $this->post(route('login.store'), [
            'email' => 'login-db@example.test',
            'password' => 'LoginPass!123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(StaffRole::ADMIN, session(UiRole::SESSION_KEY));
    }

    public function test_database_login_with_must_change_password_reaches_dashboard(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'email' => 'must-change@example.test',
            'username' => 'must.change',
            'password' => 'TempPass!123',
        ]);
        $user->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => now()->toDateString(),
        ]);

        $this->post(route('login.store'), [
            'email' => 'must-change@example.test',
            'password' => 'TempPass!123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_creator_restrict_on_delete_blocks_hard_delete(): void
    {
        $admin = User::factory()->create([
            'email' => 'keep-admin@example.test',
            'username' => 'keep.admin',
        ]);
        User::factory()->create([
            'email' => 'child@example.test',
            'username' => 'child.user',
            'created_by' => $admin->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $admin->delete();
    }

    public function test_staff_schema_columns_exist(): void
    {
        foreach ([
            'photo_path', 'first_name', 'middle_name', 'last_name', 'suffix', 'sex',
            'date_of_birth', 'civil_status', 'nationality', 'mobile_number', 'username',
            'house_no', 'street', 'purok_zone', 'barangay', 'municipality_city',
            'province', 'zip_code', 'status', 'must_change_password', 'created_by',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column), "Missing users.{$column}");
        }

        $this->assertTrue(Schema::hasTable('worker_appointments'));
        foreach ([
            'user_id', 'role', 'assigned_barangay', 'assigned_zone',
            'date_appointed', 'end_of_appointment', 'is_current',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('worker_appointments', $column), "Missing worker_appointments.{$column}");
        }

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->assertTrue(
                Schema::hasColumn('worker_appointments', 'current_user_id'),
                'MariaDB/MySQL one-current guard column missing'
            );
        }
    }

    public function test_password_reset_tokens_table_remains_for_laravel_broker(): void
    {
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $this->assertFalse(Schema::hasTable('staff_password_resets'));
        $this->assertFalse(Schema::hasTable('user_management'));
    }
}
