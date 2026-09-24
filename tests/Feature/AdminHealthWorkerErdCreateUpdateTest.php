<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use App\Support\UserManagementErdMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class AdminHealthWorkerErdCreateUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ClientTestingErdSchema::ensure();
        UserManagementErdMode::resetCachedState();
    }

    public function test_erd_create_redirects_to_worker_tab_and_does_not_fabricate_profile(): void
    {
        $this->assertTrue(UserManagementErdMode::isActive());
        $this->assertSame('user_management', UserManagementErdMode::staffTableName());

        $this->actingAsErdAdmin();

        $response = $this->post(route('user-management.health-workers.store'), [
            'first_name' => 'New',
            'last_name' => 'Worker',
            'middle_name' => 'A',
            'email' => 'erd.new.worker@example.test',
            'username' => 'erd.new.worker',
            'mobile' => '09171112222',
            'role' => 'BHW',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => 'TempPass!123',
            'password_confirmation' => 'TempPass!123',
        ]);

        $response->assertRedirect(route('user-management.index', ['tab' => 'workers']));
        $response->assertSessionHas('status', 'Health Worker account created successfully.');

        $row = DB::table('user_management')->where('email', 'erd.new.worker@example.test')->first();
        $this->assertNotNull($row);
        $this->assertSame('New', $row->first_name);
        $this->assertSame('A', $row->middle_name);
        $this->assertSame('Worker', $row->last_name);
        $this->assertSame('09171112222', $row->mobile_number);
        $this->assertSame('erd.new.worker', $row->username);
        $this->assertTrue(Hash::check('TempPass!123', (string) $row->password));

        $this->assertNull($row->suffix);
        $this->assertNull($row->sex);
        $this->assertNull($row->date_of_birth);
        $this->assertNull($row->civil_status);
        $this->assertNull($row->nationality);
        $this->assertNull($row->house_no);
        $this->assertNull($row->street);
        $this->assertNull($row->purok_zone);
        $this->assertNull($row->barangay);
        $this->assertNull($row->municipality_city);
        $this->assertNull($row->province);
        $this->assertNull($row->zip_code);
        $this->assertNull($row->photo_path);
        $this->assertNotSame('1990-01-15', $row->date_of_birth);
        $this->assertNotSame('Rizal Street', $row->street);
        $this->assertNotSame('123', $row->house_no);

        $appointment = DB::table('worker_appointments')->where('user_id', $row->user_id)->first();
        $this->assertNotNull($appointment);
        $this->assertSame(StaffRole::BHW, StaffRole::normalize($appointment->role));
        $this->assertNull($appointment->assigned_barangay);
        $this->assertNull($appointment->assigned_zone);
        $this->assertNull($appointment->date_appointed);
        $this->assertNull($appointment->end_of_appointment);
    }

    public function test_erd_first_edit_completes_open_appointment_stub_in_place(): void
    {
        $this->actingAsErdAdmin();

        $this->post(route('user-management.health-workers.store'), [
            'first_name' => 'Adrian',
            'last_name' => 'Reyes',
            'middle_name' => 'Miguel',
            'email' => 'erd.adrian.complete@example.test',
            'username' => 'erd.adrian.complete',
            'mobile' => '09171112227',
            'role' => 'Admin',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => 'TempPass!123',
            'password_confirmation' => 'TempPass!123',
        ])->assertRedirect();

        $user = User::query()->where('email', 'erd.adrian.complete@example.test')->first();
        $this->assertNotNull($user);
        $stubId = (int) DB::table('worker_appointments')->where('user_id', $user->getKey())->value('appointment_id');
        $this->assertGreaterThan(0, $stubId);
        $this->assertSame(1, DB::table('worker_appointments')->where('user_id', $user->getKey())->count());

        $this->put(
            route('user-management.health-workers.update', ['id' => (string) $user->getKey()]),
            $this->erdUpdatePayload($user, [
                'hw_first_name' => 'Adrian',
                'hw_last_name' => 'Reyes',
                'hw_middle_name' => 'Miguel',
                'hw_role' => 'Admin',
                'hw_assigned_barangay' => 'La Medalla',
                'hw_assigned_zone' => 'Zone 1',
                'hw_date_appointed' => '2026-09-02',
                'hw_end_appointment' => '',
            ])
        )->assertRedirect(route('user-management.health-workers.view', ['id' => (string) $user->getKey()]));

        $this->assertSame(1, DB::table('worker_appointments')->where('user_id', $user->getKey())->count());
        $row = DB::table('worker_appointments')->where('user_id', $user->getKey())->first();
        $this->assertSame($stubId, (int) $row->appointment_id);
        $this->assertSame('La Medalla', $row->assigned_barangay);
        $this->assertSame('Zone 1', $row->assigned_zone);
        $this->assertSame('2026-09-02', substr((string) $row->date_appointed, 0, 10));
        $this->assertNull($row->end_of_appointment);

        $presented = \App\Support\HealthWorkerUiCatalog::presentUser($user->fresh());
        $this->assertSame('La Medalla', $presented['assigned_barangay']);
        $this->assertSame('Zone 1', $presented['assigned_zone']);
        $this->assertSame('2026-09-02', $presented['date_appointed']);
        $this->assertSame('', $presented['end_of_appointment']);
    }

    public function test_erd_edit_blank_suffix_succeeds(): void
    {
        $worker = $this->createErdWorker('erd.suffix@example.test', 'erd.suffix', [
            'suffix' => 'Jr.',
        ]);

        $this->actingAsErdAdmin()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->getKey()]),
                $this->erdUpdatePayload($worker, ['hw_suffix' => ''])
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNull(DB::table('user_management')->where('user_id', $worker->getKey())->value('suffix'));
    }

    public function test_erd_edit_retains_own_username_and_email(): void
    {
        $worker = $this->createErdWorker('erd.keep@example.test', 'erd.keep');

        $this->actingAsErdAdmin()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->getKey()]),
                $this->erdUpdatePayload($worker)
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $row = DB::table('user_management')->where('user_id', $worker->getKey())->first();
        $this->assertSame('erd.keep@example.test', $row->email);
        $this->assertSame('erd.keep', $row->username);
    }

    public function test_erd_duplicate_username_and_email_are_rejected(): void
    {
        $worker = $this->createErdWorker('erd.one@example.test', 'erd.one');
        $this->createErdWorker('erd.two@example.test', 'erd.two');

        $this->actingAsErdAdmin()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->getKey()]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->getKey()]),
                $this->erdUpdatePayload($worker, ['hw_username' => 'erd.two'])
            )
            ->assertSessionHasErrors('hw_username');

        $this->actingAsErdAdmin()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->getKey()]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->getKey()]),
                $this->erdUpdatePayload($worker, ['hw_email' => 'erd.two@example.test'])
            )
            ->assertSessionHasErrors('hw_email');
    }

    public function test_live_erd_not_null_profile_columns_stop_create_without_fabricating(): void
    {
        $this->installLiveShapedStaffTables();
        UserManagementErdMode::resetCachedState();
        $this->assertTrue(UserManagementErdMode::isActive());
        $this->assertFalse(Schema::hasTable('users'));

        $admin = $this->createErdWorker('erd.live.admin@example.test', 'erd.live.admin', [
            'role' => StaffRole::ADMIN,
        ]);
        $this->actingAs($admin)->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $before = DB::table('user_management')->count();

        $this->from(route('user-management.health-workers.create'))
            ->post(route('user-management.health-workers.store'), [
                'first_name' => 'Live',
                'last_name' => 'Conflict',
                'middle_name' => '',
                'email' => 'erd.live.conflict@example.test',
                'username' => 'erd.live.conflict',
                'mobile' => '09170009999',
                'role' => 'BHW',
                'status' => StaffAccountStatus::ACTIVE,
                'password' => 'TempPass!123',
                'password_confirmation' => 'TempPass!123',
            ])
            ->assertSessionHasErrors('email');

        $message = (string) session('errors')->first('email');
        $this->assertStringContainsString('user_management.sex', $message);
        $this->assertStringContainsString('user_management.house_no', $message);
        $this->assertStringContainsString('worker_appointments.assigned_barangay', $message);
        $this->assertStringContainsString('placeholder profile values were not written', $message);
        $this->assertStringNotContainsString('Rizal Street', $message);

        $this->assertSame($before, DB::table('user_management')->count());
        $this->assertNull(
            DB::table('user_management')->where('email', 'erd.live.conflict@example.test')->first()
        );
    }

    private function actingAsErdAdmin(): static
    {
        $admin = User::query()->where('email', 'erd.admin@example.test')->first()
            ?? $this->createErdWorker('erd.admin@example.test', 'erd.admin', [
                'role' => StaffRole::ADMIN,
            ]);

        $this->actingAs($admin);

        return $this->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createErdWorker(string $email, string $username, array $overrides = []): User
    {
        $role = $overrides['role'] ?? StaffRole::BHW;
        unset($overrides['role']);

        $userId = (int) DB::table('user_management')->insertGetId([
            'first_name' => $overrides['first_name'] ?? 'Erd',
            'middle_name' => 'Test',
            'last_name' => 'Worker',
            'suffix' => $overrides['suffix'] ?? 'Jr.',
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
            'password' => Hash::make('OriginalPass!123'),
            'status' => 'Active',
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('worker_appointments')->insert([
            'user_id' => $userId,
            'role' => UserManagementErdMode::appointmentRoleForStorage($role),
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-15',
            'end_of_appointment' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($userId);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function erdUpdatePayload(User $worker, array $overrides = []): array
    {
        return array_merge([
            'sex' => 'Female',
            'hw_first_name' => 'Erd',
            'hw_last_name' => 'Worker',
            'hw_middle_name' => 'Test',
            'hw_suffix' => $worker->suffix,
            'hw_dob' => '1990-02-02',
            'hw_civil_status' => 'Married',
            'hw_nationality' => 'Filipino',
            'hw_mobile' => '09179876543',
            'hw_email' => $worker->email,
            'hw_house_no' => '99',
            'hw_street' => 'Updated St.',
            'hw_purok_zone' => 'Zone 2',
            'hw_barangay' => 'La Medalla',
            'hw_municipality' => 'Iriga City',
            'hw_province' => 'Camarines Sur',
            'hw_zip' => '4431',
            'hw_role' => 'BNS',
            'hw_assigned_barangay' => 'La Medalla',
            'hw_assigned_zone' => 'Zone 2',
            'hw_date_appointed' => '2020-01-15',
            'hw_end_appointment' => '2031-12-31',
            'hw_username' => $worker->username,
            'hw_status' => StaffAccountStatus::ACTIVE,
        ], $overrides);
    }

    /**
     * Mirror live lmlinga_erd_reference user_management / worker_appointments
     * NOT NULL profile columns (no schema alter in production).
     */
    private function installLiveShapedStaffTables(): void
    {
        Schema::dropIfExists('worker_appointment_zones');
        Schema::dropIfExists('worker_appointments');
        Schema::dropIfExists('user_management');

        Schema::create('user_management', function (Blueprint $table): void {
            $table->id('user_id');
            $table->string('photo_path')->nullable();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->string('sex');
            $table->date('date_of_birth');
            $table->string('civil_status');
            $table->string('nationality');
            $table->string('mobile_number', 32);
            $table->string('email')->unique();
            $table->string('username')->unique();
            $table->string('house_no');
            $table->string('street');
            $table->string('purok_zone');
            $table->string('barangay');
            $table->string('municipality_city');
            $table->string('province');
            $table->string('zip_code');
            $table->string('password');
            $table->string('status')->default('Active');
            $table->boolean('must_change_password')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('worker_appointments', function (Blueprint $table): void {
            $table->id('appointment_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->string('assigned_barangay');
            $table->string('assigned_zone');
            $table->date('date_appointed');
            $table->date('end_of_appointment')->nullable();
            $table->timestamps();
        });

        ClientTestingErdSchema::ensureWorkerAppointmentZones();
    }
}
