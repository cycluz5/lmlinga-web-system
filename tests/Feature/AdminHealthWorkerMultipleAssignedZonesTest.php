<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkerAppointment;
use App\Models\WorkerAppointmentZone;
use App\Support\DemoStaffLogin;
use App\Support\HealthWorkerUiCatalog;
use App\Support\Offline\OfflineFieldHasher;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use App\Support\WorkerAssignedZones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminHealthWorkerMultipleAssignedZonesTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdminSession(): static
    {
        $admin = User::query()->where('email', 'creator.admin@example.test')->first();
        if ($admin === null) {
            $admin = User::factory()->create([
                'email' => 'creator.admin@example.test',
                'username' => 'creator.admin',
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
        }

        $this->actingAs($admin);

        return $this->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);
    }

    private function seedWorker(array $userOverrides = [], array $appointmentOverrides = []): User
    {
        $admin = User::query()->where('email', 'creator.admin@example.test')->first();
        if ($admin === null) {
            $this->actingAsAdminSession();
        }

        $worker = User::factory()->create(array_merge([
            'first_name' => 'Maria',
            'middle_name' => 'Cruz',
            'last_name' => 'Reyes',
            'suffix' => 'N/A',
            'sex' => 'Female',
            'date_of_birth' => '1990-02-02',
            'civil_status' => 'Single',
            'nationality' => 'Filipino',
            'mobile_number' => '09171234567',
            'email' => 'maria.reyes.zones@example.test',
            'username' => 'maria.reyes.zones',
            'house_no' => '12',
            'street' => 'Sampaguita St.',
            'purok_zone' => 'Zone 1',
            'barangay' => 'La Medalla',
            'municipality_city' => 'Iriga City',
            'province' => 'Camarines Sur',
            'zip_code' => '4431',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => 'OriginalPass!123',
            'must_change_password' => false,
        ], $userOverrides));

        $worker->assignCurrentAppointment(array_merge([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-15',
            'end_of_appointment' => null,
        ], $appointmentOverrides));

        return $worker->fresh(['currentAppointment.assignedZones']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validUpdatePayload(User $worker, array $overrides = []): array
    {
        $appointment = $worker->currentAppointment ?? $worker->resolveCurrentAppointment();

        return array_merge([
            'sex' => 'Female',
            'hw_first_name' => (string) $worker->first_name,
            'hw_last_name' => (string) $worker->last_name,
            'hw_middle_name' => (string) $worker->middle_name,
            'hw_suffix' => $worker->suffix,
            'hw_dob' => $worker->date_of_birth?->format('Y-m-d') ?? '1990-02-02',
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
            'hw_role' => StaffRole::label($appointment?->role) ?: 'BHW',
            'hw_assigned_barangay' => (string) ($appointment?->assigned_barangay ?? 'La Medalla'),
            'hw_assigned_zone' => ['Zone 1'],
            'hw_date_appointed' => $appointment?->date_appointed?->format('Y-m-d') ?? '2020-01-15',
            'hw_end_appointment' => $appointment?->end_of_appointment?->format('Y-m-d') ?? '',
            'hw_username' => $worker->username,
            'hw_status' => StaffAccountStatus::ACTIVE,
        ], $overrides);
    }

    public function test_existing_one_zone_appointment_backfills_into_one_pivot_row(): void
    {
        $worker = $this->seedWorker();
        $appointment = $worker->currentAppointment;
        $this->assertNotNull($appointment);

        DB::table('worker_appointment_zones')
            ->where('appointment_id', $appointment->getKey())
            ->delete();
        $this->assertSame(0, $appointment->assignedZones()->count());
        $this->assertSame('Zone 1', $appointment->assigned_zone);

        WorkerAssignedZones::backfillPivotFromScalarAppointments();

        $rows = DB::table('worker_appointment_zones')
            ->where('appointment_id', $appointment->getKey())
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Zone 1', $rows[0]->assigned_zone);
    }

    public function test_two_zones_exist_on_one_current_appointment_without_changing_role(): void
    {
        $worker = $this->seedWorker();
        $appointmentId = $worker->currentAppointment?->getKey();
        $role = $worker->role;

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_assigned_zone' => ['Zone 1', 'Zone 2'],
                ])
            )
            ->assertRedirect();

        $worker->refresh();
        $current = $worker->fresh(['currentAppointment.assignedZones'])->currentAppointment;
        $this->assertNotNull($current);
        $this->assertSame($appointmentId, $current->getKey());
        $this->assertSame(1, $worker->appointments()->count());
        $this->assertSame(1, $worker->appointments()->where('is_current', true)->count());
        $this->assertSame($role, $worker->role);
        $this->assertSame(StaffRole::BHW, $current->role);
        $this->assertSame('Zone 1', $current->assigned_zone);
        $this->assertSame(['Zone 1', 'Zone 2'], $current->assignedZoneLabels());
    }

    public function test_zone_addition_and_removal_do_not_create_another_appointment_row(): void
    {
        $worker = $this->seedWorker();
        $appointmentId = $worker->currentAppointment?->getKey();

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_assigned_zone' => ['Zone 1', 'Zone 2'],
                ])
            )
            ->assertRedirect();

        $this->assertSame(1, $worker->fresh()->appointments()->count());

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker->fresh(), [
                    'hw_assigned_zone' => ['Zone 2'],
                ])
            )
            ->assertRedirect();

        $worker->refresh();
        $current = $worker->currentAppointment;
        $this->assertNotNull($current);
        $this->assertSame($appointmentId, $current->getKey());
        $this->assertSame(1, $worker->appointments()->count());
        $this->assertSame('Zone 2', $current->assigned_zone);
        $this->assertSame(['Zone 2'], $current->assignedZoneLabels());
        $this->assertSame(StaffRole::BHW, $worker->role);
    }

    public function test_scalar_assigned_zone_stays_synchronized_with_first_selected_zone(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_assigned_zone' => ['Zone 3', 'Zone 1'],
                ])
            )
            ->assertRedirect();

        $current = $worker->fresh(['currentAppointment'])->currentAppointment;
        $this->assertNotNull($current);
        $this->assertSame('Zone 3', $current->assigned_zone);
        $this->assertSame(['Zone 3', 'Zone 1'], $current->assignedZoneLabels());
    }

    public function test_sequential_appointment_history_keeps_prior_pivot_rows(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_assigned_zone' => ['Zone 1', 'Zone 2'],
                ])
            )
            ->assertRedirect();

        $priorId = $worker->fresh()->currentAppointment?->getKey();
        $this->assertNotNull($priorId);

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker->fresh(), [
                    'hw_role' => 'BNS',
                    'hw_assigned_zone' => ['Zone 1', 'Zone 2'],
                    'hw_date_appointed' => '2021-03-01',
                ])
            )
            ->assertRedirect();

        $worker->refresh();
        $this->assertSame(2, $worker->appointments()->count());
        $current = $worker->currentAppointment;
        $this->assertNotNull($current);
        $this->assertNotSame($priorId, $current->getKey());
        $this->assertSame(StaffRole::BNS, $current->role);
        $this->assertSame(['Zone 1', 'Zone 2'], $current->assignedZoneLabels());

        $prior = WorkerAppointment::query()->find($priorId);
        $this->assertNotNull($prior);
        $this->assertFalse((bool) $prior->is_current);
        $this->assertNotNull($prior->end_of_appointment);
        $this->assertSame(StaffRole::BHW, StaffRole::normalize($prior->role));
        $this->assertSame(['Zone 1', 'Zone 2'], $prior->assignedZoneLabels());
        $this->assertSame('Zone 1', $prior->assigned_zone);
    }

    public function test_slim_create_remains_zone_less_without_pivot_rows(): void
    {
        $this->actingAsAdminSession()
            ->post(route('user-management.health-workers.store'), [
                'first_name' => 'New',
                'last_name' => 'Worker',
                'middle_name' => 'A',
                'email' => 'new.zones.stub@example.test',
                'mobile' => '09171112222',
                'role' => 'BHW',
                'status' => StaffAccountStatus::ACTIVE,
                'password' => 'TempPass!123',
                'password_confirmation' => 'TempPass!123',
            ])
            ->assertRedirect();

        $user = User::query()->where('email', 'new.zones.stub@example.test')->firstOrFail();
        $appointment = $user->currentAppointment;
        $this->assertNotNull($appointment);
        $this->assertNull($appointment->assigned_zone);
        $this->assertSame([], $appointment->assignedZoneLabels());
        $this->assertSame(0, $appointment->assignedZones()->count());
    }

    public function test_duplicate_zone_and_invalid_zone_are_rejected(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_assigned_zone' => ['Zone 1', 'Zone 1'],
                ])
            )
            ->assertSessionHasErrors();

        $this->actingAsAdminSession()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_assigned_zone' => ['Zone 6'],
                ])
            )
            ->assertSessionHasErrors();

        $worker->refresh();
        $this->assertSame(['Zone 1'], $worker->currentAppointment?->assignedZoneLabels());
        $this->assertSame(1, $worker->appointments()->count());
    }

    public function test_worker_a_zones_never_appear_for_worker_b(): void
    {
        $workerA = $this->seedWorker([
            'email' => 'worker.a.zones@example.test',
            'username' => 'worker.a.zones',
        ]);
        $workerB = $this->seedWorker([
            'email' => 'worker.b.zones@example.test',
            'username' => 'worker.b.zones',
        ], [
            'assigned_zone' => 'Zone 5',
        ]);

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $workerA->id]),
                $this->validUpdatePayload($workerA, [
                    'hw_email' => $workerA->email,
                    'hw_username' => $workerA->username,
                    'hw_assigned_zone' => ['Zone 1', 'Zone 4'],
                ])
            )
            ->assertRedirect();

        $aLabels = $workerA->fresh(['currentAppointment'])->currentAppointment?->assignedZoneLabels();
        $bLabels = $workerB->fresh(['currentAppointment'])->currentAppointment?->assignedZoneLabels();
        $this->assertSame(['Zone 1', 'Zone 4'], $aLabels);
        $this->assertSame(['Zone 5'], $bLabels);

        $aAppointmentId = $workerA->fresh()->currentAppointment?->getKey();
        $bZones = WorkerAppointmentZone::query()
            ->where('appointment_id', $workerB->fresh()->currentAppointment?->getKey())
            ->pluck('assigned_zone')
            ->all();
        $this->assertNotContains('Zone 4', $bZones);
        $this->assertSame(
            0,
            WorkerAppointmentZone::query()
                ->where('appointment_id', $aAppointmentId)
                ->where('assigned_zone', 'Zone 5')
                ->count()
        );
    }

    public function test_deactivated_worker_retains_appointment_and_pivot_rows(): void
    {
        $worker = $this->seedWorker([
            'email' => 'zones.deactivate@example.test',
            'username' => 'zones.deactivate',
        ]);

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_email' => $worker->email,
                    'hw_username' => $worker->username,
                    'hw_assigned_zone' => ['Zone 2', 'Zone 3'],
                ])
            )
            ->assertRedirect();

        $appointmentId = $worker->fresh()->currentAppointment?->getKey();
        $pivotBefore = WorkerAppointmentZone::query()->where('appointment_id', $appointmentId)->count();
        $this->assertSame(2, $pivotBefore);

        $this->actingAsAdminSession()
            ->post(route('user-management.health-workers.deactivate', ['id' => (string) $worker->id]))
            ->assertRedirect();

        $fresh = $worker->fresh(['currentAppointment']);
        $this->assertSame(StaffAccountStatus::INACTIVE, $fresh->status);
        $this->assertSame($appointmentId, $fresh->currentAppointment?->getKey());
        $this->assertSame(2, WorkerAppointmentZone::query()->where('appointment_id', $appointmentId)->count());
        $this->assertSame(['Zone 2', 'Zone 3'], $fresh->currentAppointment?->assignedZoneLabels());
    }

    public function test_ui_list_view_edit_and_profile_display_all_assigned_zones(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_assigned_zone' => ['Zone 1', 'Zone 2'],
                ])
            )
            ->assertRedirect();

        $presented = HealthWorkerUiCatalog::presentUser($worker->fresh(['currentAppointment']));
        $this->assertSame('Zone 1, Zone 2', $presented['zone']);
        $this->assertSame('Zone 1, Zone 2', $presented['assigned_zones_display']);
        $this->assertSame(['Zone 1', 'Zone 2'], $presented['assigned_zones']);
        $this->assertSame('Zone 1', $presented['assigned_zone']);

        $index = $this->actingAsAdminSession()
            ->get(route('user-management.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Zone 1, Zone 2', $index);
        $this->assertStringContainsString('data-hw-zone="Zone 1, Zone 2"', $index);

        $view = $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.view', ['id' => (string) $worker->id]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Assigned Zones', $view);
        $this->assertStringContainsString('Zone 1, Zone 2', $view);

        $edit = $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('name="hw_assigned_zone[]"', $edit);
        $this->assertStringContainsString('value="Zone 1"', $edit);
        $this->assertStringContainsString('value="Zone 2"', $edit);

        $this->actingAs($worker->fresh())->withSession([
            UiRole::SESSION_KEY => StaffRole::BHW,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);
        $profile = $this->get(route('profile.show'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Assigned Zones', $profile);
        $this->assertStringContainsString('Zone 1, Zone 2', $profile);
        $this->assertStringContainsString('data-profile-assigned-zone', $profile);
    }

    public function test_offline_zone_set_representation_is_deterministic(): void
    {
        $worker = $this->seedWorker();
        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_assigned_zone' => ['Zone 2', 'Zone 4'],
                ])
            )
            ->assertRedirect();

        $fresh = $worker->fresh(['currentAppointment.assignedZones']);
        $snapshot = OfflineFieldHasher::healthWorkerSnapshot($fresh);
        $this->assertSame(['Zone 2', 'Zone 4'], $snapshot['hw_assigned_zone']);

        $first = OfflineFieldHasher::healthWorker($fresh);
        $second = OfflineFieldHasher::healthWorker($fresh->fresh(['currentAppointment.assignedZones']));
        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first));
    }
}
