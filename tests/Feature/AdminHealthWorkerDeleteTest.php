<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Models\User;
use App\Models\WorkerAppointment;
use App\Models\WorkerAppointmentZone;
use App\Services\HealthWorkerAccountService;
use App\Support\DemoStaffLogin;
use App\Support\HealthWorkerUiCatalog;
use App\Support\LoginAttemptLockout;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use App\Support\UserManagementErdMode;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class AdminHealthWorkerDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_health_worker_can_be_deactivated_without_deleted_at(): void
    {
        $worker = $this->seedWorker([
            'email' => 'deactivate.keep@example.test',
            'username' => 'deactivate.keep',
            'password' => 'WorkerPass!123',
        ]);
        $appointmentId = $worker->currentAppointment?->getKey();
        $beforeUsers = User::query()->count();
        $beforeAppointments = WorkerAppointment::query()->where('user_id', $worker->id)->count();

        $this->actingAsAdminSession();
        $this->from(route('user-management.index'))
            ->post(route('user-management.health-workers.deactivate', ['id' => (string) $worker->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']))
            ->assertSessionHas('status');

        $this->assertSame($beforeUsers, User::query()->count());
        $worker->refresh();
        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($worker->status));
        $this->assertNull($worker->deleted_at);
        $this->assertFalse($worker->isDeleted());
        $this->assertSame($beforeAppointments, WorkerAppointment::query()->where('user_id', $worker->id)->count());
        $this->assertSame($appointmentId, $worker->fresh(['currentAppointment'])->currentAppointment?->getKey());
        $this->assertTrue((bool) $worker->fresh()->currentAppointment?->is_current);
    }

    public function test_deactivation_preserves_appointment_zones_and_blocks_new_login(): void
    {
        $worker = $this->seedMultiZoneWorker('zones.deact@example.test', 'zones.deact');
        $appointmentId = $worker->currentAppointment?->getKey();
        $this->assertSame(2, WorkerAppointmentZone::query()->where('appointment_id', $appointmentId)->count());

        $this->actingAsAdminSession()
            ->post(route('user-management.health-workers.deactivate', ['id' => (string) $worker->id]))
            ->assertRedirect();

        $fresh = $worker->fresh(['currentAppointment']);
        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($fresh->status));
        $this->assertNull($fresh->deleted_at);
        $this->assertSame($appointmentId, $fresh->currentAppointment?->getKey());
        $this->assertSame(2, WorkerAppointmentZone::query()->where('appointment_id', $appointmentId)->count());
        $this->assertSame(['Zone 2', 'Zone 3'], $fresh->currentAppointment?->assignedZoneLabels());

        $this->app['auth']->logout();
        $this->flushSession();
        $this->post(route('login.store'), [
            'email' => 'zones.deact@example.test',
            'password' => 'WorkerPass!123',
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(LoginAttemptLockout::GENERIC_FAILURE, session('errors')->first('email'));
    }

    public function test_existing_session_after_deactivation_is_rejected_on_next_request(): void
    {
        $worker = $this->seedWorker([
            'email' => 'session.deact@example.test',
            'username' => 'session.deact',
            'password' => 'WorkerPass!123',
        ]);

        $this->actingAsWorkerSession($worker);
        $this->get(route('dashboard'))->assertOk();

        $this->actingAsAdminSession()
            ->post(route('user-management.health-workers.deactivate', ['id' => (string) $worker->id]))
            ->assertRedirect();

        $this->actingAsWorkerSession($worker->fresh());
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_self_and_already_inactive_deactivate_protections_remain(): void
    {
        $this->actingAsAdminSession();
        $admin = $this->adminUser();
        $peerAdmin = $this->seedWorker([
            'email' => 'peer.admin.delete@example.test',
            'username' => 'peer.admin.delete',
        ], ['role' => StaffRole::ADMIN]);
        $inactive = $this->seedWorker([
            'email' => 'already.inactive.delete@example.test',
            'username' => 'already.inactive.delete',
        ]);
        $inactive->deactivate();

        $this->actingAsAdminSession()
            ->post(route('user-management.health-workers.deactivate', ['id' => (string) $peerAdmin->id]))
            ->assertSessionHasErrors('hw_status');
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($peerAdmin->fresh()->status));

        $this->actingAsAdminSession()
            ->post(route('user-management.health-workers.deactivate', ['id' => (string) $admin->id]))
            ->assertSessionHasErrors('hw_status');
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($admin->fresh()->status));

        $before = User::query()->count();
        $this->actingAsAdminSession()
            ->post(route('user-management.health-workers.deactivate', ['id' => (string) $inactive->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']));
        $this->assertSame($before, User::query()->count());
        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($inactive->fresh()->status));
        $this->assertNull($inactive->fresh()->deleted_at);
    }

    public function test_edit_can_reactivate_inactive_worker(): void
    {
        $worker = $this->seedWorker([
            'email' => 'reactivate.ok@example.test',
            'username' => 'reactivate.ok',
            'password' => 'WorkerPass!123',
        ]);
        $worker->deactivate();

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, ['hw_status' => StaffAccountStatus::ACTIVE])
            )
            ->assertRedirect();

        $worker->refresh();
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($worker->status));
        $this->assertNull($worker->deleted_at);

        $this->app['auth']->logout();
        $this->flushSession();
        $this->post(route('login.store'), [
            'email' => 'reactivate.ok@example.test',
            'password' => 'WorkerPass!123',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_active_and_inactive_health_workers_can_be_soft_deleted(): void
    {
        $active = $this->seedWorker([
            'email' => 'delete.active@example.test',
            'username' => 'delete.active',
            'password' => 'WorkerPass!123',
        ]);
        $inactive = $this->seedWorker([
            'email' => 'delete.inactive@example.test',
            'username' => 'delete.inactive',
            'password' => 'WorkerPass!123',
        ]);
        $inactive->deactivate();

        $this->actingAsAdminSession()
            ->from(route('user-management.index'))
            ->delete(route('user-management.health-workers.destroy', ['id' => (string) $active->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']))
            ->assertSessionHas('status');

        $this->actingAsAdminSession()
            ->delete(route('user-management.health-workers.destroy', ['id' => (string) $inactive->id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']));

        $deletedActive = User::queryWithDeleted()->find($active->id);
        $deletedInactive = User::queryWithDeleted()->find($inactive->id);
        $this->assertNotNull($deletedActive?->deleted_at);
        $this->assertNotNull($deletedInactive?->deleted_at);
        $this->assertSame(StaffAccountStatus::ACTIVE, StaffAccountStatus::normalize($deletedActive->status));
        $this->assertSame(StaffAccountStatus::INACTIVE, StaffAccountStatus::normalize($deletedInactive->status));
        $this->assertNull(User::query()->find($active->id));
        $this->assertNull(User::query()->find($inactive->id));
        $this->assertTrue(DB::table('users')->where('id', $active->id)->exists());
        $this->assertTrue(DB::table('users')->where('id', $inactive->id)->exists());
    }

    public function test_delete_hides_catalog_rejects_view_edit_and_login(): void
    {
        $worker = $this->seedWorker([
            'email' => 'delete.hide@example.test',
            'username' => 'delete.hide',
            'password' => 'WorkerPass!123',
        ]);
        $id = (string) $worker->id;

        $this->actingAsAdminSession()
            ->delete(route('user-management.health-workers.destroy', ['id' => $id]))
            ->assertRedirect();

        $ids = array_column(HealthWorkerUiCatalog::all(), 'id');
        $this->assertNotContains($id, $ids);
        $this->assertNull(HealthWorkerUiCatalog::find($id));
        $this->assertNull(HealthWorkerUiCatalog::findMutableUser($id));

        $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.view', ['id' => $id]))
            ->assertNotFound();
        $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.edit', ['id' => $id]))
            ->assertNotFound();

        $index = $this->actingAsAdminSession()
            ->get(route('user-management.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('delete.hide@example.test', $index);
        $this->assertStringNotContainsString('data-hw-id="'.$id.'"', $index);

        $this->app['auth']->logout();
        $this->flushSession();
        $this->post(route('login.store'), [
            'email' => 'delete.hide@example.test',
            'password' => 'WorkerPass!123',
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(LoginAttemptLockout::GENERIC_FAILURE, session('errors')->first('email'));
    }

    public function test_deleted_account_does_not_receive_password_reset(): void
    {
        Notification::fake();
        $worker = $this->seedWorker([
            'email' => 'delete.reset@example.test',
            'username' => 'delete.reset',
        ]);

        $this->actingAsAdminSession()
            ->delete(route('user-management.health-workers.destroy', ['id' => (string) $worker->id]))
            ->assertRedirect();

        $this->app['auth']->logout();
        $this->flushSession();

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'delete.reset@example.test'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        Notification::assertNothingSent();
        Notification::assertNotSentTo(
            User::queryWithDeleted()->findOrFail($worker->id),
            ResetPassword::class
        );
    }

    public function test_existing_session_after_deletion_is_rejected(): void
    {
        $worker = $this->seedWorker([
            'email' => 'session.delete@example.test',
            'username' => 'session.delete',
            'password' => 'WorkerPass!123',
        ]);

        $this->actingAsWorkerSession($worker);
        $this->get(route('dashboard'))->assertOk();

        $this->actingAsAdminSession()
            ->delete(route('user-management.health-workers.destroy', ['id' => (string) $worker->id]))
            ->assertRedirect();

        $deleted = User::queryWithDeleted()->findOrFail($worker->id);
        $this->actingAsWorkerSession($deleted);
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deletion_preserves_appointments_and_closes_current_without_removing_zones(): void
    {
        $worker = $this->seedWorker([
            'email' => 'history.delete@example.test',
            'username' => 'history.delete',
        ]);
        $historical = $worker->currentAppointment;
        $this->assertNotNull($historical);
        $historicalId = $historical->getKey();
        $historical->syncAssignedZones(['Zone 1']);

        $worker->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 2',
            'assigned_zones' => ['Zone 2', 'Zone 3'],
            'date_appointed' => '2021-06-01',
        ]);
        $currentId = $worker->fresh()->currentAppointment?->getKey();
        $this->assertNotNull($currentId);
        $this->assertNotSame($historicalId, $currentId);
        $historicalEnd = WorkerAppointment::query()->findOrFail($historicalId)->end_of_appointment?->format('Y-m-d');

        $this->actingAsAdminSession()
            ->delete(route('user-management.health-workers.destroy', ['id' => (string) $worker->id]))
            ->assertRedirect();

        $this->assertSame(2, WorkerAppointment::query()->where('user_id', $worker->id)->count());
        $this->assertTrue(WorkerAppointment::query()->whereKey($historicalId)->exists());
        $this->assertTrue(WorkerAppointment::query()->whereKey($currentId)->exists());
        $this->assertSame(
            $historicalEnd,
            WorkerAppointment::query()->findOrFail($historicalId)->end_of_appointment?->format('Y-m-d')
        );

        $closed = WorkerAppointment::query()->findOrFail($currentId);
        $this->assertFalse((bool) $closed->is_current);
        $this->assertNotNull($closed->end_of_appointment);
        $this->assertSame(1, WorkerAppointmentZone::query()->where('appointment_id', $historicalId)->count());
        $this->assertSame(2, WorkerAppointmentZone::query()->where('appointment_id', $currentId)->count());
        $this->assertSame(['Zone 2', 'Zone 3'], $closed->assignedZoneLabels());
    }

    public function test_deletion_preserves_clinical_authorship_and_does_not_raise_fk_errors(): void
    {
        $worker = $this->seedWorker([
            'email' => 'author.delete@example.test',
            'username' => 'author.delete',
        ]);

        DB::table('announcements')->insert([
            'title' => 'Keep authorship',
            'message' => 'Posted by worker before deletion.',
            'event_date' => '2026-09-01',
            'target_group' => 'all',
            'zone_mode' => 'all',
            'audience_label' => 'All Residents',
            'posted_by_user_id' => $worker->id,
            'posted_by_name' => 'Maria Reyes',
            'posted_by_role' => 'BHW',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdminSession()
            ->delete(route('user-management.health-workers.destroy', ['id' => (string) $worker->id]))
            ->assertRedirect();

        $this->assertTrue(DB::table('users')->where('id', $worker->id)->exists());
        $this->assertSame(
            $worker->id,
            (int) DB::table('announcements')->where('title', 'Keep authorship')->value('posted_by_user_id')
        );
    }

    public function test_self_admin_and_last_admin_cannot_be_deleted(): void
    {
        $this->actingAsAdminSession();
        $admin = $this->adminUser();
        $peerAdmin = $this->seedWorker([
            'email' => 'keep.admin.delete@example.test',
            'username' => 'keep.admin.delete',
        ], ['role' => StaffRole::ADMIN]);

        $this->actingAsAdminSession()
            ->delete(route('user-management.health-workers.destroy', ['id' => (string) $admin->id]))
            ->assertSessionHasErrors('hw_status');
        $this->assertNull(User::queryWithDeleted()->findOrFail($admin->id)->deleted_at);

        $this->actingAsAdminSession()
            ->delete(route('user-management.health-workers.destroy', ['id' => (string) $peerAdmin->id]))
            ->assertSessionHasErrors('hw_status');
        $this->assertNull($peerAdmin->fresh()->deleted_at);
        $this->assertSame(StaffRole::ADMIN, $peerAdmin->fresh()->role);

        try {
            app(HealthWorkerAccountService::class)->deleteAccount($peerAdmin);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hw_status', $exception->errors());
        }
    }

    public function test_non_admin_cannot_delete_and_worker_b_is_unaffected(): void
    {
        $workerA = $this->seedMultiZoneWorker('worker.a.delete@example.test', 'worker.a.delete');
        $workerB = $this->seedMultiZoneWorker('worker.b.delete@example.test', 'worker.b.delete');
        $bAppointmentId = $workerB->currentAppointment?->getKey();
        $bZones = $workerB->currentAppointment?->assignedZoneLabels();

        $this->actingAsBhwActor([
            'email' => 'actor.bhw.delete@example.test',
            'username' => 'actor.bhw.delete',
        ]);
        $this->delete(route('user-management.health-workers.destroy', ['id' => (string) $workerA->id]))
            ->assertForbidden();
        $this->assertNull($workerA->fresh()->deleted_at);

        $this->actingAsAdminSession()
            ->delete(route('user-management.health-workers.destroy', ['id' => (string) $workerA->id]))
            ->assertRedirect();

        $this->assertNotNull(User::queryWithDeleted()->find($workerA->id)?->deleted_at);
        $this->assertNull(User::query()->find($workerA->id));
        $this->assertNotNull(User::query()->find($workerB->id));
        $this->assertNull($workerB->fresh()->deleted_at);
        $this->assertSame($bAppointmentId, $workerB->fresh()->currentAppointment?->getKey());
        $this->assertSame($bZones, $workerB->fresh()->currentAppointment?->assignedZoneLabels());
        $this->assertTrue((bool) $workerB->fresh()->currentAppointment?->is_current);
    }

    public function test_ui_distinguishes_delete_from_deactivate(): void
    {
        $worker = $this->seedWorker([
            'email' => 'ui.delete@example.test',
            'username' => 'ui.delete',
        ]);
        $inactive = $this->seedWorker([
            'email' => 'ui.inactive.delete@example.test',
            'username' => 'ui.inactive.delete',
        ]);
        $inactive->deactivate();
        $this->actingAsAdminSession();
        $admin = $this->adminUser();

        $index = $this->actingAsAdminSession()
            ->get(route('user-management.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-hw-deactivate-id="'.$worker->id.'"', $index);
        $this->assertStringContainsString('Deactivate Account', $index);
        $this->assertStringContainsString('data-hw-delete-id="'.$worker->id.'"', $index);
        $this->assertStringContainsString('Delete Account', $index);
        $this->assertStringContainsString('data-hw-delete-modal', $index);
        $this->assertStringContainsString('data-hw-deactivate-modal', $index);
        $this->assertStringContainsString('name="_method"', $index);
        $this->assertStringContainsString('value="DELETE"', $index);
        $this->assertStringContainsString('data-hw-deactivate-base', $index);
        $this->assertStringContainsString('data-hw-action="deactivate"', $index);
        $this->assertStringContainsString('data-hw-action="delete"', $index);
        $this->assertStringContainsString('data-hw-delete-base="'.url('/user-management/health-workers').'"', $index);
        $this->assertStringNotContainsString('data-hw-delete-base="'.url('/user-management/health-workers').'/deactivate"', $index);
        $this->assertStringNotContainsString('data-hw-deactivate-id="'.$inactive->id.'"', $index);
        $this->assertStringContainsString('data-hw-delete-id="'.$inactive->id.'"', $index);
        $this->assertStringNotContainsString('data-hw-delete-id="'.$admin->id.'"', $index);
        $this->assertStringContainsString('This is different from deactivating', $index);

        $view = $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.view', ['id' => (string) $worker->id]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-hw-deactivate-id="'.$worker->id.'"', $view);
        $this->assertStringContainsString('data-hw-delete-id="'.$worker->id.'"', $view);
    }

    public function test_unauthenticated_delete_redirects_to_login(): void
    {
        $worker = $this->seedWorker([
            'email' => 'guest.delete@example.test',
            'username' => 'guest.delete',
        ]);

        $this->app['auth']->logout();
        $this->flushSession();
        $this->delete(route('user-management.health-workers.destroy', ['id' => (string) $worker->id]))
            ->assertRedirect(route('login'));
        $this->assertNull($worker->fresh()->deleted_at);
    }

    public function test_erd_mode_soft_deletes_without_removing_appointment_zones_or_authorship(): void
    {
        ClientTestingErdSchema::ensure();
        UserManagementErdMode::resetCachedState();
        $this->assertTrue(UserManagementErdMode::isActive());
        $this->assertTrue(UserManagementErdMode::staffHasDeletedAt());

        $admin = $this->createErdStaff('erd.admin.delete@example.test', 'erd.admin.delete', StaffRole::ADMIN);
        $worker = $this->createErdStaff('erd.bhw.delete@example.test', 'erd.bhw.delete', StaffRole::BHW, 'WorkerPass!123');
        $peer = $this->createErdStaff('erd.peer.delete@example.test', 'erd.peer.delete', StaffRole::BNS);

        $appointmentId = (int) DB::table('worker_appointments')->where('user_id', $worker->getKey())->value('appointment_id');
        $this->assertGreaterThan(0, $appointmentId);
        WorkerAppointment::query()->findOrFail($appointmentId)->syncAssignedZones(['Zone 1', 'Zone 4']);
        $this->assertSame(2, WorkerAppointmentZone::query()->where('appointment_id', $appointmentId)->count());

        $householdId = (int) DB::table('households')->insertGetId([
            'household_no' => 'HH-1BDEL',
            'purok' => 'Zone 1',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'household_id');
        $residentId = (int) DB::table('residents')->insertGetId([
            'household_id' => $householdId,
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'birthday' => '1990-01-01',
            'sex' => 'Male',
            'civil_status' => 'Single',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'resident_id');

        DB::table('risk_assessment')->insert([
            'resident_id' => $residentId,
            'user_id' => $worker->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('environmental_sanitation')->insert([
            'household_id' => $householdId,
            'user_id' => $worker->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('death_records')->insert([
            'resident_id' => $residentId,
            'cause_of_death' => 'Natural causes',
            'date_of_death' => '2026-01-01',
            'death_certificate_no' => 'DC-1B',
            'death_certificate_file_path' => 'certificates/dc-1b.pdf',
            'verification_status' => 'Pending Verification',
            'submitted_by' => $worker->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin);
        $this->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $beforeUsers = (int) DB::table('user_management')->count();
        $this->delete(route('user-management.health-workers.destroy', ['id' => (string) $worker->getKey()]))
            ->assertRedirect(route('user-management.index', ['tab' => 'workers']));

        $this->assertSame($beforeUsers, (int) DB::table('user_management')->count());
        $row = DB::table('user_management')->where('user_id', $worker->getKey())->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame('Active', $row->status);
        $this->assertNull(User::query()->find($worker->getKey()));

        $appointment = DB::table('worker_appointments')->where('appointment_id', $appointmentId)->first();
        $this->assertNotNull($appointment);
        $this->assertNotNull($appointment->end_of_appointment);
        $this->assertSame(2, WorkerAppointmentZone::query()->where('appointment_id', $appointmentId)->count());

        $this->assertSame($worker->getKey(), (int) DB::table('risk_assessment')->value('user_id'));
        $this->assertSame($worker->getKey(), (int) DB::table('environmental_sanitation')->value('user_id'));
        $this->assertSame($worker->getKey(), (int) DB::table('death_records')->value('submitted_by'));

        $this->assertNotNull(User::query()->find($peer->getKey()));
        $this->assertNull(DB::table('user_management')->where('user_id', $peer->getKey())->value('deleted_at'));

        $this->app['auth']->logout();
        $this->flushSession();
        $this->post(route('login.store'), [
            'email' => 'erd.bhw.delete@example.test',
            'password' => 'WorkerPass!123',
        ])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_delete_does_not_call_eloquent_hard_delete(): void
    {
        $worker = $this->seedWorker([
            'email' => 'nohard.delete@example.test',
            'username' => 'nohard.delete',
        ]);
        $appointmentId = $worker->currentAppointment?->getKey();

        app(HealthWorkerAccountService::class)->deleteAccount($worker);

        $this->assertTrue(DB::table('users')->where('id', $worker->id)->exists());
        $this->assertTrue(DB::table('worker_appointments')->where('id', $appointmentId)->exists());
        $this->assertNotNull(DB::table('users')->where('id', $worker->id)->value('deleted_at'));
    }

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
        $this->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        return $this;
    }

    private function adminUser(): User
    {
        return User::query()->where('email', 'creator.admin@example.test')->firstOrFail();
    }

    private function actingAsWorkerSession(User $worker): void
    {
        $this->actingAs($worker);
        $this->withSession([
            UiRole::SESSION_KEY => StaffRole::normalize($worker->role) ?? StaffRole::BHW,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $userOverrides
     * @param  array<string, mixed>  $appointmentOverrides
     */
    private function seedWorker(array $userOverrides = [], array $appointmentOverrides = []): User
    {
        $this->actingAsAdminSession();

        $worker = User::factory()->create(array_merge([
            'first_name' => 'Maria',
            'middle_name' => 'Cruz',
            'last_name' => 'Reyes',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
            'password' => 'WorkerPass!123',
        ], $userOverrides));

        $worker->assignCurrentAppointment(array_merge([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-15',
            'end_of_appointment' => '2030-12-31',
        ], $appointmentOverrides));

        return $worker->fresh(['currentAppointment']);
    }

    private function seedMultiZoneWorker(string $email, string $username): User
    {
        $worker = $this->seedWorker([
            'email' => $email,
            'username' => $username,
            'password' => 'WorkerPass!123',
        ]);

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_email' => $email,
                    'hw_username' => $username,
                    'hw_assigned_zone' => ['Zone 2', 'Zone 3'],
                ])
            )
            ->assertRedirect();

        return $worker->fresh(['currentAppointment']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validUpdatePayload(User $worker, array $overrides = []): array
    {
        $appointment = $worker->currentAppointment ?? $worker->fresh(['currentAppointment'])->currentAppointment;

        return array_merge([
            'sex' => (string) ($worker->sex ?: 'Female'),
            'hw_first_name' => (string) $worker->first_name,
            'hw_last_name' => (string) $worker->last_name,
            'hw_middle_name' => (string) ($worker->middle_name ?: 'Cruz'),
            'hw_suffix' => (string) ($worker->suffix ?? 'N/A'),
            'hw_dob' => $worker->date_of_birth?->format('Y-m-d') ?? '1990-02-02',
            'hw_civil_status' => (string) ($worker->civil_status ?: 'Single'),
            'hw_nationality' => (string) ($worker->nationality ?: 'Filipino'),
            'hw_mobile' => (string) ($worker->mobile_number ?: '09171234567'),
            'hw_email' => (string) $worker->email,
            'hw_house_no' => (string) ($worker->house_no ?: '12'),
            'hw_street' => (string) ($worker->street ?: 'Sampaguita St.'),
            'hw_purok_zone' => (string) ($worker->purok_zone ?: 'Zone 1'),
            'hw_barangay' => (string) ($worker->barangay ?: 'La Medalla'),
            'hw_municipality' => (string) ($worker->municipality_city ?: 'Iriga City'),
            'hw_province' => (string) ($worker->province ?: 'Camarines Sur'),
            'hw_zip' => (string) ($worker->zip_code ?: '4431'),
            'hw_role' => StaffRole::normalize($appointment?->role) ?? StaffRole::BHW,
            'hw_assigned_barangay' => (string) ($appointment?->assigned_barangay ?? 'La Medalla'),
            'hw_assigned_zone' => (string) ($appointment?->assigned_zone ?? 'Zone 1'),
            'hw_date_appointed' => $appointment?->date_appointed?->format('Y-m-d') ?? '2020-01-01',
            'hw_end_appointment' => $appointment?->end_of_appointment?->format('Y-m-d') ?? '2030-12-31',
            'hw_username' => (string) $worker->username,
            'hw_status' => StaffAccountStatus::ACTIVE,
        ], $overrides);
    }

    private function actingAsBhwActor(array $overrides = []): User
    {
        $worker = $this->seedWorker(array_merge([
            'email' => 'staff.actor@example.test',
            'username' => 'staff.actor',
        ], $overrides), ['role' => StaffRole::BHW]);

        $this->actingAs($worker);
        $this->withSession([
            UiRole::SESSION_KEY => StaffRole::BHW,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        return $worker;
    }

    private function createErdStaff(string $email, string $username, string $role, string $password = 'AdminPass!123'): User
    {
        $userId = (int) DB::table('user_management')->insertGetId([
            'first_name' => 'Erd',
            'middle_name' => 'Test',
            'last_name' => 'Staff',
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
            'password' => Hash::make($password),
            'status' => 'Active',
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'user_id');

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
}
