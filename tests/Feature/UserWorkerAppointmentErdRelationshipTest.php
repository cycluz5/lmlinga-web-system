<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkerAppointment;
use App\Support\StaffRole;
use App\Support\UserManagementErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class UserWorkerAppointmentErdRelationshipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ClientTestingErdSchema::ensure();
    }

    public function test_appointments_relation_uses_user_id_not_user_user_id(): void
    {
        $this->assertTrue(UserManagementErdMode::isActive());

        $user = $this->createErdStaffUser('erd.worker@lamedalla.local', 'erd.worker');

        $sql = $user->appointments()->toSql();
        $this->assertStringContainsString('user_id', $sql);
        $this->assertStringNotContainsString('user_user_id', $sql);

        WorkerAppointment::query()->create([
            'user_id' => $user->getKey(),
            'role' => 'Barangay Health Worker',
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 2',
            'date_appointed' => now()->toDateString(),
            'end_of_appointment' => null,
        ]);

        $this->assertSame(1, $user->appointments()->count());
        $this->assertSame(
            StaffRole::BHW,
            StaffRole::normalize($user->fresh()->resolveCurrentAppointment()?->role)
        );
    }

    public function test_current_appointment_relation_uses_user_id_not_user_user_id(): void
    {
        $user = $this->createErdStaffUser('erd.current@lamedalla.local', 'erd.current');

        WorkerAppointment::query()->create([
            'user_id' => $user->getKey(),
            'role' => 'Admin',
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => now()->subDay()->toDateString(),
            'end_of_appointment' => null,
        ]);

        $sql = $user->currentAppointment()->toSql();
        $this->assertStringContainsString('user_id', $sql);
        $this->assertStringNotContainsString('user_user_id', $sql);
    }

    private function createErdStaffUser(string $email, string $username): User
    {
        $userId = DB::table('user_management')->insertGetId([
            'first_name' => 'Erd',
            'middle_name' => 'Test',
            'last_name' => 'Worker',
            'email' => $email,
            'username' => $username,
            'password' => bcrypt('test-password'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var User $user */
        $user = User::query()->findOrFail($userId);

        return $user;
    }
}
