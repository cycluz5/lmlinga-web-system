<?php

namespace Tests\Feature\Offline;

use App\Models\User;
use App\Models\WorkerAppointment;
use App\Support\Offline\OfflineFieldHasher;
use App\Support\Offline\OfflineOperationType;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithOfflineSync;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Normalization guard (Phase 1): the offline Health Worker edit conflict hash is driven by the
 * authoritative zone list (worker_appointment_zones via WorkerAppointment::assignedZoneLabels()),
 * not by the scalar worker_appointments.assigned_zone. Protects a future zone-source migration:
 * queued offline edits must not start failing with false "record changed" conflicts.
 */
class NormalizationGuardHealthWorkerZoneHashTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    private function seedWorker(): User
    {
        $worker = User::factory()->create([
            'first_name' => 'Zone',
            'last_name' => 'Worker',
            'sex' => 'Female',
            'email' => 'zone.worker@example.test',
            'username' => 'zone.worker',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
        ]);

        $worker->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zones' => ['Zone 2', 'Zone 1'],
            'date_appointed' => '2020-01-15',
            'end_of_appointment' => '2030-12-31',
        ]);

        return $worker->fresh(['currentAppointment']);
    }

    private function appointment(User $worker): WorkerAppointment
    {
        return $worker->fresh(['currentAppointment'])->resolveCurrentAppointment();
    }

    public function test_hash_uses_the_zone_list_in_its_stored_order(): void
    {
        $worker = $this->seedWorker();

        $this->assertSame(['Zone 2', 'Zone 1'], $this->appointment($worker)->assignedZoneLabels());
        $this->assertSame(['Zone 2', 'Zone 1'], OfflineFieldHasher::healthWorkerSnapshot($worker)['hw_assigned_zone']);
        $this->assertSame('Zone 2', (string) $this->appointment($worker)->assigned_zone, 'Scalar holds the primary (first) zone.');
    }

    public function test_hash_depends_only_on_the_zone_list(): void
    {
        // The scalar worker_appointments.assigned_zone copy was dropped (3NF), so the
        // zone list in worker_appointment_zones is the only zone input to the hash.
        $this->assertFalse(Schema::hasColumn('worker_appointments', 'assigned_zone'));

        $worker = $this->seedWorker();
        $before = OfflineFieldHasher::healthWorker($worker->fresh());

        $this->assertSame($before, OfflineFieldHasher::healthWorker($worker->fresh()));
    }

    public function test_changing_the_zone_list_changes_the_hash(): void
    {
        $worker = $this->seedWorker();
        $before = OfflineFieldHasher::healthWorker($worker->fresh());

        $this->appointment($worker)->assignedZones()->create(['assigned_zone' => 'Zone 3']);

        $this->assertNotSame($before, OfflineFieldHasher::healthWorker($worker->fresh()));
    }

    public function test_edit_page_hash_matches_server_and_queued_edit_syncs(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $worker = $this->seedWorker();
        $serverHash = OfflineFieldHasher::healthWorker($worker->fresh());

        $html = $this->get(route('user-management.health-workers.edit', ['id' => $worker->getKey()]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('data-offline-field-hash="'.$serverHash.'"', $html);

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_WORKER_UPDATE,
            [
                'sex' => 'Female',
                'hw_first_name' => 'Zone',
                'hw_last_name' => 'Worker',
                'hw_middle_name' => 'N/A',
                'hw_suffix' => 'N/A',
                'hw_dob' => '1990-02-02',
                'hw_civil_status' => 'Single',
                'hw_nationality' => 'Filipino',
                'hw_mobile' => '09171234567',
                'hw_email' => 'zone.worker@example.test',
                'hw_house_no' => '1',
                'hw_street' => 'Guard St.',
                'hw_purok_zone' => 'Zone 1',
                'hw_barangay' => 'La Medalla',
                'hw_municipality' => 'Iriga City',
                'hw_province' => 'Camarines Sur',
                'hw_zip' => '4431',
                'hw_role' => 'BHW',
                'hw_assigned_barangay' => 'La Medalla',
                'hw_assigned_zone' => ['Zone 3', 'Zone 1'],
                'hw_date_appointed' => '2020-01-15',
                'hw_end_appointment' => '2030-12-31',
                'hw_username' => 'zone.worker',
                'hw_status' => StaffAccountStatus::ACTIVE,
            ],
            [
                'parent_server' => ['user_id' => $worker->getKey()],
                'base_snapshot' => ['field_hash' => $serverHash],
            ],
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $this->assertSame(['Zone 3', 'Zone 1'], $this->appointment($worker)->assignedZoneLabels());
        $this->assertSame('Zone 3', (string) $this->appointment($worker)->assigned_zone);
    }
}
