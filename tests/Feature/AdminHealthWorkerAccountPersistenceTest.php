<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkerAppointment;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminHealthWorkerAccountPersistenceTest extends TestCase
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
            $admin = User::factory()->create([
                'email' => 'creator.admin@example.test',
                'username' => 'creator.admin',
            ]);
            $admin->assignCurrentAppointment([
                'role' => StaffRole::ADMIN,
                'assigned_barangay' => 'La Medalla',
                'assigned_zone' => 'Zone 1',
                'date_appointed' => '2020-01-01',
            ]);
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
            'email' => 'maria.reyes.db@example.test',
            'username' => 'maria.reyes.db',
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
            'created_by' => $admin->id,
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

    /**
     * @return array<string, mixed>
     */
    private function validUpdatePayload(User $worker, array $overrides = []): array
    {
        $appointment = $worker->currentAppointment;

        return array_merge([
            'sex' => 'Female',
            'hw_first_name' => 'Maria',
            'hw_last_name' => 'Reyes',
            'hw_middle_name' => 'Cruz',
            'hw_suffix' => 'N/A',
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
            'hw_date_appointed' => $appointment?->date_appointed?->format('Y-m-d') ?? '2020-01-15',
            'hw_end_appointment' => '2031-12-31',
            'hw_username' => $worker->username,
            'hw_status' => StaffAccountStatus::ACTIVE,
        ], $overrides);
    }

    public function test_admin_can_create_health_worker_account_with_incomplete_profile(): void
    {
        $this->actingAsAdminSession();
        $beforeUsers = User::query()->count();
        $beforeAppointments = WorkerAppointment::query()->count();

        $response = $this->post(route('user-management.health-workers.store'), [
                'first_name' => 'New',
                'last_name' => 'Worker',
                'middle_name' => 'A',
                'email' => 'new.worker@example.test',
                'mobile' => '09171112222',
                'role' => 'BHW',
                'status' => StaffAccountStatus::ACTIVE,
                'password' => 'TempPass!123',
                'password_confirmation' => 'TempPass!123',
            ]);

        $this->assertSame($beforeUsers + 1, User::query()->count());
        $this->assertSame($beforeAppointments + 1, WorkerAppointment::query()->count());

        $user = User::query()->where('email', 'new.worker@example.test')->first();
        $this->assertNotNull($user);
        $response->assertRedirect(route('user-management.index', ['tab' => 'workers']));
        $response->assertSessionHas('status', 'Health Worker account created successfully.');

        $this->assertSame('New', $user->first_name);
        $this->assertSame('A', $user->middle_name);
        $this->assertSame('Worker', $user->last_name);
        $this->assertSame('09171112222', $user->mobile_number);
        $this->assertSame(StaffAccountStatus::ACTIVE, $user->status);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('TempPass!123', (string) DB::table('users')->where('id', $user->id)->value('password')));
        $this->assertNotSame('TempPass!123', (string) DB::table('users')->where('id', $user->id)->value('password'));

        // Incomplete profile — never invent demographics / address / username / photo.
        $this->assertNull($user->sex);
        $this->assertNull($user->date_of_birth);
        $this->assertNull($user->civil_status);
        $this->assertNull($user->nationality);
        $this->assertNull($user->suffix);
        $this->assertNull($user->house_no);
        $this->assertNull($user->street);
        $this->assertNull($user->purok_zone);
        $this->assertNull($user->barangay);
        $this->assertNull($user->municipality_city);
        $this->assertNull($user->province);
        $this->assertNull($user->zip_code);
        $this->assertNull($user->photo_path);
        $this->assertNull($user->username);

        $appointment = $user->currentAppointment;
        $this->assertNotNull($appointment);
        $this->assertSame(StaffRole::BHW, $appointment->role);
        $this->assertNull($appointment->assigned_barangay);
        $this->assertNull($appointment->assigned_zone);
        $this->assertNull($appointment->date_appointed);
        $this->assertNull($appointment->end_of_appointment);
        $this->assertTrue($appointment->is_current);
    }

    public function test_first_edit_completes_slim_create_appointment_stub_in_place(): void
    {
        $this->actingAsAdminSession()
            ->post(route('user-management.health-workers.store'), [
                'first_name' => 'Adrian',
                'last_name' => 'Reyes',
                'middle_name' => 'Miguel',
                'email' => 'adrian.first.complete@example.test',
                'mobile' => '09170000027',
                'role' => 'Admin',
                'status' => StaffAccountStatus::ACTIVE,
                'password' => 'TempPass!123',
                'password_confirmation' => 'TempPass!123',
            ])
            ->assertRedirect();

        $user = User::query()->where('email', 'adrian.first.complete@example.test')->first();
        $this->assertNotNull($user);
        $stubId = $user->currentAppointment?->id;
        $this->assertNotNull($stubId);
        $this->assertSame(1, $user->appointments()->count());

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $user->id]),
                $this->validUpdatePayload($user, [
                    'hw_first_name' => 'Adrian',
                    'hw_last_name' => 'Reyes',
                    'hw_middle_name' => 'Miguel',
                    'hw_role' => 'Admin',
                    'hw_assigned_barangay' => 'La Medalla',
                    'hw_assigned_zone' => 'Zone 1',
                    'hw_date_appointed' => '2026-09-02',
                    'hw_end_appointment' => '',
                    'hw_email' => $user->email,
                    'hw_username' => 'adrian.first.complete',
                ])
            )
            ->assertRedirect(route('user-management.health-workers.view', ['id' => (string) $user->id]));

        $user->refresh();
        $this->assertSame(1, $user->appointments()->count());
        $appointment = $user->currentAppointment;
        $this->assertNotNull($appointment);
        $this->assertSame($stubId, $appointment->id);
        $this->assertSame(StaffRole::ADMIN, $appointment->role);
        $this->assertSame('La Medalla', $appointment->assigned_barangay);
        $this->assertSame('Zone 1', $appointment->assigned_zone);
        $this->assertSame('2026-09-02', $appointment->date_appointed?->format('Y-m-d'));
        $this->assertNull($appointment->end_of_appointment);
        $this->assertTrue($appointment->is_current);
    }

    public function test_view_catalog_uses_completed_open_appointment_after_first_edit(): void
    {
        $this->actingAsAdminSession()
            ->post(route('user-management.health-workers.store'), [
                'first_name' => 'View',
                'last_name' => 'Catalog',
                'middle_name' => 'Open',
                'email' => 'view.catalog.appt@example.test',
                'mobile' => '09170000028',
                'role' => 'Admin',
                'status' => StaffAccountStatus::ACTIVE,
                'password' => 'TempPass!123',
                'password_confirmation' => 'TempPass!123',
            ])
            ->assertRedirect();

        $user = User::query()->where('email', 'view.catalog.appt@example.test')->firstOrFail();

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $user->id]),
                $this->validUpdatePayload($user, [
                    'hw_first_name' => 'View',
                    'hw_last_name' => 'Catalog',
                    'hw_middle_name' => 'Open',
                    'hw_role' => 'Admin',
                    'hw_assigned_barangay' => 'La Medalla',
                    'hw_assigned_zone' => 'Zone 1',
                    'hw_date_appointed' => '2026-09-02',
                    'hw_end_appointment' => '',
                    'hw_email' => $user->email,
                    'hw_username' => 'view.catalog.appt',
                ])
            )
            ->assertRedirect();

        $presented = \App\Support\HealthWorkerUiCatalog::presentUser($user->fresh(['currentAppointment']));
        $this->assertSame('La Medalla', $presented['assigned_barangay']);
        $this->assertSame('Zone 1', $presented['assigned_zone']);
        $this->assertSame('Zone 1', $presented['zone']);
        $this->assertSame('2026-09-02', $presented['date_appointed']);
        $this->assertSame('', $presented['end_of_appointment']);

        $html = $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.view', ['id' => (string) $user->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('La Medalla', $html);
        $this->assertStringContainsString('Zone 1', $html);
        $this->assertStringContainsString('09/02/2026', $html);
    }

    public function test_completed_assignment_zone_change_updates_current_appointment_in_place(): void
    {
        $this->actingAsAdminSession()
            ->post(route('user-management.health-workers.store'), [
                'first_name' => 'History',
                'last_name' => 'Zone',
                'middle_name' => 'A',
                'email' => 'history.zone@example.test',
                'mobile' => '09170000029',
                'role' => 'BHW',
                'status' => StaffAccountStatus::ACTIVE,
                'password' => 'TempPass!123',
                'password_confirmation' => 'TempPass!123',
            ])
            ->assertRedirect();

        $user = User::query()->where('email', 'history.zone@example.test')->firstOrFail();
        $stubId = $user->currentAppointment?->id;

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $user->id]),
                $this->validUpdatePayload($user, [
                    'hw_first_name' => 'History',
                    'hw_last_name' => 'Zone',
                    'hw_middle_name' => 'A',
                    'hw_role' => 'BHW',
                    'hw_assigned_barangay' => 'La Medalla',
                    'hw_assigned_zone' => 'Zone 1',
                    'hw_date_appointed' => '2026-09-02',
                    'hw_end_appointment' => '',
                    'hw_email' => $user->email,
                    'hw_username' => 'history.zone',
                ])
            )
            ->assertRedirect();

        $this->assertSame(1, $user->fresh()->appointments()->count());

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $user->id]),
                $this->validUpdatePayload($user->fresh(), [
                    'hw_first_name' => 'History',
                    'hw_last_name' => 'Zone',
                    'hw_middle_name' => 'A',
                    'hw_role' => 'BHW',
                    'hw_assigned_barangay' => 'La Medalla',
                    'hw_assigned_zone' => 'Zone 3',
                    'hw_date_appointed' => '2026-09-02',
                    'hw_end_appointment' => '',
                    'hw_email' => $user->email,
                    'hw_username' => 'history.zone',
                ])
            )
            ->assertRedirect();

        $user->refresh();
        $this->assertSame(1, $user->appointments()->count());
        $current = $user->currentAppointment;
        $this->assertNotNull($current);
        $this->assertSame($stubId, $current->id);
        $this->assertSame('Zone 3', $current->assigned_zone);
        $this->assertNull($current->end_of_appointment);
        $this->assertTrue($current->is_current);
        $this->assertSame(['Zone 3'], $current->assignedZoneLabels());
    }

    public function test_new_account_view_does_not_show_sarah_demo_profile_values(): void
    {
        $this->actingAsAdminSession()
            ->post(route('user-management.health-workers.store'), [
                'first_name' => 'Jordan',
                'last_name' => 'Nguyen',
                'middle_name' => '',
                'email' => 'jordan.nguyen@example.test',
                'mobile' => '09175550001',
                'role' => 'BNS',
                'status' => StaffAccountStatus::ACTIVE,
                'password' => 'TempPass!123',
                'password_confirmation' => 'TempPass!123',
            ])
            ->assertRedirect();

        $user = User::query()->where('email', 'jordan.nguyen@example.test')->firstOrFail();

        $html = $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.view', ['id' => (string) $user->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Jordan', $html);
        $this->assertStringContainsString('Nguyen', $html);
        $this->assertStringContainsString('jordan.nguyen@example.test', $html);
        $this->assertStringContainsString('09175550001', $html);
        $this->assertStringContainsString('BNS', $html);

        $this->assertStringNotContainsString('Sarah', $html);
        $this->assertStringNotContainsString('Santos', $html);
        $this->assertStringNotContainsString('Cruz', $html);
        $this->assertStringNotContainsString('04/01/1990', $html);
        $this->assertStringNotContainsString('Filipino', $html);
        $this->assertStringNotContainsString('Single', $html);
        $this->assertStringNotContainsString('Female', $html);
        $this->assertStringNotContainsString('Sampaguita', $html);
        $this->assertStringNotContainsString('sarah.santos@example.com', $html);

        // Empty DOB → Age em dash, not fabricated numeric age.
        $this->assertStringContainsString('<dt>Age <span class="lml-hw-view__hint">(Auto-Computed)</span></dt>', $html);
        $this->assertMatchesRegularExpression(
            '/\(Auto-Computed\)<\/span><\/dt>\s*<dd>\s*—\s*<\/dd>/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<dt>\s*Sex\s*<\/dt>\s*<dd>\s*—\s*<\/dd>/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<dt>\s*Date of Birth\s*<\/dt>\s*<dd>\s*—\s*<\/dd>/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<dt>\s*Assigned Barangay\s*<\/dt>\s*<dd>\s*—\s*<\/dd>/u',
            $html
        );
    }

    public function test_worker_profiles_do_not_cross_leak_between_accounts(): void
    {
        $first = $this->seedWorker([
            'first_name' => 'Alpha',
            'middle_name' => 'One',
            'last_name' => 'Worker',
            'email' => 'alpha.worker@example.test',
            'username' => 'alpha.worker',
            'date_of_birth' => '1985-03-15',
            'sex' => 'Male',
        ]);
        $second = $this->seedWorker([
            'first_name' => 'Beta',
            'middle_name' => 'Two',
            'last_name' => 'Worker',
            'email' => 'beta.worker@example.test',
            'username' => 'beta.worker',
            'date_of_birth' => '1992-08-20',
            'sex' => 'Female',
            'nationality' => 'Filipino',
        ]);

        $firstHtml = $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.view', ['id' => (string) $first->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Alpha', $firstHtml);
        $this->assertStringContainsString('03/15/1985', $firstHtml);
        $this->assertStringNotContainsString('Beta', $firstHtml);
        $this->assertStringNotContainsString('08/20/1992', $firstHtml);
        $this->assertStringNotContainsString('beta.worker@example.test', $firstHtml);

        $secondHtml = $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.view', ['id' => (string) $second->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Beta', $secondHtml);
        $this->assertStringContainsString('08/20/1992', $secondHtml);
        $this->assertStringNotContainsString('Alpha', $secondHtml);
        $this->assertStringNotContainsString('03/15/1985', $secondHtml);
        $this->assertStringNotContainsString('alpha.worker@example.test', $secondHtml);
    }

    public function test_create_does_not_use_hw001_demo_profile_url(): void
    {
        $html = $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-profile-url', $html);
        $this->assertStringNotContainsString(
            route('user-management.health-workers.view', ['id' => 'hw-001']),
            $html
        );
    }

    public function test_non_admin_cannot_update_health_worker(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsStaff(StaffRole::BHW);
        $this->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker)
            )
            ->assertForbidden();
    }

    public function test_unauthenticated_cannot_update_health_worker(): void
    {
        $worker = $this->seedWorker();

        $this->put(
            route('user-management.health-workers.update', ['id' => (string) $worker->id]),
            $this->validUpdatePayload($worker)
        )->assertRedirect(route('login'));
    }

    public function test_admin_can_update_profile_and_creates_appointment_history_on_role_change(): void
    {
        $worker = $this->seedWorker();
        $appointmentId = $worker->currentAppointment->id;

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker)
            )
            ->assertRedirect(route('user-management.health-workers.view', ['id' => (string) $worker->id]));

        $worker->refresh();
        $this->assertSame('Married', $worker->civil_status);
        $this->assertSame('09179876543', $worker->mobile_number);
        $this->assertSame('99', $worker->house_no);
        $this->assertSame('Updated St.', $worker->street);
        $this->assertSame('Zone 2', $worker->purok_zone);
        $this->assertSame(StaffAccountStatus::ACTIVE, $worker->status);

        $appointment = $worker->currentAppointment;
        $this->assertNotNull($appointment);
        $this->assertNotSame($appointmentId, $appointment->id);
        $this->assertSame(StaffRole::BNS, $appointment->role);
        $this->assertSame('Zone 2', $appointment->assigned_zone);
        $this->assertSame('2031-12-31', $appointment->end_of_appointment->format('Y-m-d'));
        $this->assertTrue($appointment->is_current);
        $this->assertSame(2, $worker->appointments()->count());
        $this->assertSame(1, $worker->appointments()->where('is_current', true)->count());

        $prior = WorkerAppointment::query()->find($appointmentId);
        $this->assertNotNull($prior);
        $this->assertFalse((bool) $prior->is_current);
        $this->assertNotNull($prior->end_of_appointment);
    }

    public function test_edit_without_password_keeps_existing_hash(): void
    {
        $worker = $this->seedWorker();
        $originalHash = (string) DB::table('users')->where('id', $worker->id)->value('password');

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_password' => '',
                    'hw_password_confirmation' => '',
                ])
            )
            ->assertRedirect();

        $freshHash = (string) DB::table('users')->where('id', $worker->id)->value('password');
        $this->assertSame($originalHash, $freshHash);
        $this->assertTrue(Hash::check('OriginalPass!123', $freshHash));
        $this->assertFalse($worker->fresh()->must_change_password);
    }

    public function test_edit_with_password_hashes_and_sets_must_change_password(): void
    {
        $worker = $this->seedWorker();
        $newPassword = 'BrandNewPass!99';

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_password' => $newPassword,
                    'hw_password_confirmation' => $newPassword,
                ])
            )
            ->assertRedirect();

        $raw = (string) DB::table('users')->where('id', $worker->id)->value('password');
        $this->assertNotSame($newPassword, $raw);
        $this->assertTrue(Hash::check($newPassword, $raw));
        $this->assertTrue($worker->fresh()->must_change_password);
    }

    public function test_duplicate_username_and_email_are_rejected(): void
    {
        $worker = $this->seedWorker();
        User::factory()->create([
            'email' => 'other@example.test',
            'username' => 'taken.username',
        ]);

        $this->actingAsAdminSession()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_username' => 'taken.username',
                ])
            )
            ->assertSessionHasErrors('hw_username');

        $this->actingAsAdminSession()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_email' => 'other@example.test',
                ])
            )
            ->assertSessionHasErrors('hw_email');
    }

    public function test_edit_worker_with_blank_suffix_succeeds(): void
    {
        $worker = $this->seedWorker(['suffix' => 'Jr.']);

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_suffix' => '',
                ])
            )
            ->assertRedirect(route('user-management.health-workers.view', ['id' => (string) $worker->id]))
            ->assertSessionHasNoErrors();

        $this->assertNull($worker->fresh()->suffix);
    }

    public function test_edit_retains_own_username_and_email(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_email' => $worker->email,
                    'hw_username' => $worker->username,
                ])
            )
            ->assertRedirect(route('user-management.health-workers.view', ['id' => (string) $worker->id]))
            ->assertSessionHasNoErrors();

        $fresh = $worker->fresh();
        $this->assertSame('maria.reyes.db@example.test', $fresh->email);
        $this->assertSame('maria.reyes.db', $fresh->username);
    }

    public function test_edit_creates_first_appointment_when_missing(): void
    {
        $worker = User::factory()->create([
            'email' => 'no.appt@example.test',
            'username' => 'no.appt',
            'first_name' => 'No',
            'middle_name' => 'A',
            'last_name' => 'Appt',
            'suffix' => 'N/A',
            'sex' => 'Male',
            'date_of_birth' => '1988-01-01',
            'civil_status' => 'Single',
            'nationality' => 'Filipino',
            'mobile_number' => '09170000000',
            'house_no' => '1',
            'street' => 'Main',
            'purok_zone' => 'Zone 1',
            'barangay' => 'La Medalla',
            'municipality_city' => 'Iriga City',
            'province' => 'Camarines Sur',
            'zip_code' => '4431',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => 'TempPass!123',
        ]);

        $this->assertNull($worker->currentAppointment);

        $payload = $this->validUpdatePayload($worker, [
            'sex' => 'Male',
            'hw_first_name' => 'No',
            'hw_last_name' => 'Appt',
            'hw_middle_name' => 'A',
            'hw_email' => 'no.appt@example.test',
            'hw_username' => 'no.appt',
            'hw_role' => 'BSPO',
            'hw_assigned_barangay' => 'La Medalla',
            'hw_assigned_zone' => 'Zone 3',
            'hw_date_appointed' => '2024-06-01',
            'hw_end_appointment' => '2030-06-01',
        ]);

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $payload
            )
            ->assertRedirect();

        $worker->refresh();
        $this->assertNotNull($worker->currentAppointment);
        $this->assertSame(StaffRole::BSPO, $worker->currentAppointment->role);
        $this->assertSame(1, $worker->appointments()->where('is_current', true)->count());
    }

    public function test_index_and_view_include_database_workers(): void
    {
        $worker = $this->seedWorker();

        $index = $this->actingAsAdminSession()
            ->get(route('user-management.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString((string) $worker->id, $index);
        $this->assertStringContainsString('Maria Cruz Reyes', $index);

        $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.view', ['id' => (string) $worker->id]))
            ->assertOk()
            ->assertSee('Maria')
            ->assertSee('Reyes');
    }

    public function test_demo_hw_edit_is_not_served_and_is_not_mutable(): void
    {
        $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.edit', ['id' => 'hw-001']))
            ->assertOk()
            ->assertSee('Health worker not found', false)
            ->assertDontSee('Admin Sarah', false)
            ->assertDontSee('sarah.santos', false)
            ->assertDontSee('data-hw-mutable="0"', false);

        $this->actingAsAdminSession()
            ->from(route('user-management.health-workers.edit', ['id' => 'hw-001']))
            ->put(
                route('user-management.health-workers.update', ['id' => 'hw-001']),
                $this->validUpdatePayload($this->seedWorker(), [
                    'hw_email' => 'sarah.santos@example.com',
                    'hw_username' => 'sarah.santos',
                ])
            )
            ->assertRedirect(route('user-management.health-workers.edit', ['id' => 'hw-001']))
            ->assertSessionHasErrors('hw_email');
    }

    public function test_end_before_appointed_is_rejected(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_date_appointed' => '2025-01-01',
                    'hw_end_appointment' => '2024-01-01',
                ])
            )
            ->assertSessionHasErrors('hw_end_appointment');
    }

    public function test_failed_edit_validation_does_not_flash_password_fields(): void
    {
        $worker = $this->seedWorker();
        $secret = 'ShouldNeverFlash!99';

        $this->actingAsAdminSession()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_first_name' => '',
                    'hw_password' => $secret,
                    'hw_password_confirmation' => $secret,
                ])
            )
            ->assertSessionHasErrors('hw_first_name');

        $this->assertNull(session()->getOldInput('hw_password'));
        $this->assertNull(session()->getOldInput('hw_password_confirmation'));
        $this->assertSame('', (string) old('hw_password'));
        $this->assertSame('', (string) old('hw_password_confirmation'));

        $oldInput = session()->get('_old_input', []);
        $this->assertIsArray($oldInput);
        $this->assertArrayNotHasKey('hw_password', $oldInput);
        $this->assertArrayNotHasKey('hw_password_confirmation', $oldInput);
        $this->assertStringNotContainsString($secret, json_encode($oldInput) ?: '');
    }

    public function test_blank_end_of_appointment_persists_as_null(): void
    {
        $worker = $this->seedWorker();
        $appointmentId = $worker->currentAppointment->id;

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_role' => 'BHW',
                    'hw_assigned_barangay' => 'La Medalla',
                    'hw_assigned_zone' => 'Zone 1',
                    'hw_date_appointed' => '2020-01-15',
                    'hw_end_appointment' => '',
                ])
            )
            ->assertRedirect(route('user-management.health-workers.view', ['id' => (string) $worker->id]));

        $appointment = $worker->fresh()->currentAppointment;
        $this->assertNotNull($appointment);
        $this->assertSame($appointmentId, $appointment->id);
        $this->assertNull($appointment->end_of_appointment);
        $this->assertTrue($appointment->is_current);
        $this->assertSame(1, $worker->appointments()->count());
        $this->assertNull(
            DB::table('worker_appointments')->where('id', $appointment->id)->value('end_of_appointment')
        );
    }

    public function test_supplied_end_of_appointment_persists(): void
    {
        $worker = $this->seedWorker();
        $appointmentId = $worker->currentAppointment->id;

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_role' => 'BHW',
                    'hw_assigned_barangay' => 'La Medalla',
                    'hw_assigned_zone' => 'Zone 1',
                    'hw_date_appointed' => '2020-01-15',
                    'hw_end_appointment' => '2032-06-30',
                ])
            )
            ->assertRedirect();

        $appointment = $worker->fresh()->currentAppointment;
        $this->assertNotNull($appointment);
        $this->assertSame($appointmentId, $appointment->id);
        $this->assertSame('2032-06-30', $appointment->end_of_appointment->format('Y-m-d'));
        $this->assertTrue($appointment->is_current);
        $this->assertSame(1, $worker->appointments()->count());
    }

    public function test_profile_only_edit_does_not_create_appointment_history(): void
    {
        $worker = $this->seedWorker();
        $appointmentId = $worker->currentAppointment->id;

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_role' => 'BHW',
                    'hw_assigned_barangay' => 'La Medalla',
                    'hw_assigned_zone' => 'Zone 1',
                    'hw_date_appointed' => '2020-01-15',
                    'hw_end_appointment' => '2030-12-31',
                    'hw_civil_status' => 'Widowed',
                    'hw_mobile' => '09170009999',
                ])
            )
            ->assertRedirect();

        $worker->refresh();
        $this->assertSame('Widowed', $worker->civil_status);
        $this->assertSame('09170009999', $worker->mobile_number);
        $this->assertSame($appointmentId, $worker->currentAppointment->id);
        $this->assertSame(1, $worker->appointments()->count());
    }

    public function test_index_shows_inactive_and_excludes_demo_hw_ids(): void
    {
        $active = $this->seedWorker([
            'email' => 'active.list@example.test',
            'username' => 'active.list',
            'status' => StaffAccountStatus::ACTIVE,
            'first_name' => 'Active',
            'middle_name' => null,
            'last_name' => 'Lister',
        ]);
        $inactive = $this->seedWorker([
            'email' => 'inactive.list@example.test',
            'username' => 'inactive.list',
            'status' => StaffAccountStatus::INACTIVE,
            'first_name' => 'Inactive',
            'middle_name' => null,
            'last_name' => 'Lister',
        ]);

        $html = $this->actingAsAdminSession()
            ->get(route('user-management.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString((string) $active->id, $html);
        $this->assertStringContainsString('Active Lister', $html);
        $this->assertStringContainsString((string) $inactive->id, $html);
        $this->assertStringContainsString('Inactive Lister', $html);
        $this->assertStringNotContainsString('hw-001', $html);
        $this->assertStringNotContainsString('data-hw-id="hw-', $html);
    }

    public function test_inactive_staff_cannot_authenticate(): void
    {
        $worker = $this->seedWorker([
            'email' => 'inactive.auth@example.test',
            'username' => 'inactive.auth',
            'password' => 'OriginalPass!123',
            'status' => StaffAccountStatus::INACTIVE,
            'must_change_password' => false,
        ]);

        $result = \App\Support\StaffAuthenticator::attempt('inactive.auth@example.test', 'OriginalPass!123');
        $this->assertNull($result['user']);
        $this->assertNull($result['via']);
        $this->assertFalse(\Illuminate\Support\Facades\Auth::check());

        $this->actingAsAdminSession()
            ->get(route('user-management.index'))
            ->assertOk()
            ->assertSee((string) $worker->id);
    }

    public function test_admin_can_create_another_admin(): void
    {
        $this->actingAsAdminSession()
            ->post(route('user-management.health-workers.store'), [
                'first_name' => 'Second',
                'last_name' => 'Admin',
                'middle_name' => '',
                'email' => 'second.admin@example.test',
                'mobile' => '09171113333',
                'role' => 'Admin',
                'status' => StaffAccountStatus::ACTIVE,
                'password' => 'TempPass!123',
                'password_confirmation' => 'TempPass!123',
            ])
            ->assertRedirect();

        $user = User::query()->where('email', 'second.admin@example.test')->first();
        $this->assertNotNull($user);
        $this->assertSame(StaffRole::ADMIN, $user->fresh(['currentAppointment'])->role);
    }

    public function test_last_active_admin_cannot_be_demoted_or_deactivated(): void
    {
        // Do not use seedWorker() — it creates an extra creator.admin Active Admin.
        $admin = User::factory()->create([
            'first_name' => 'Sole',
            'middle_name' => 'A',
            'last_name' => 'Admin',
            'suffix' => 'N/A',
            'sex' => 'Female',
            'date_of_birth' => '1990-02-02',
            'civil_status' => 'Single',
            'nationality' => 'Filipino',
            'mobile_number' => '09171234567',
            'email' => 'sole.admin@example.test',
            'username' => 'sole.admin',
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
        ]);
        $admin->assignCurrentAppointment([
            'role' => StaffRole::ADMIN,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-15',
            'end_of_appointment' => '2030-12-31',
        ]);
        $admin = $admin->fresh(['currentAppointment']);

        $this->assertSame(1, User::query()
            ->where('status', StaffAccountStatus::ACTIVE)
            ->whereHas('currentAppointment', fn ($q) => $q->where('role', StaffRole::ADMIN))
            ->count());

        $this->actingAs($admin);
        session([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $this->from(route('user-management.health-workers.edit', ['id' => (string) $admin->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $admin->id]),
                $this->validUpdatePayload($admin, [
                    'hw_role' => 'BHW',
                    'hw_email' => $admin->email,
                    'hw_username' => $admin->username,
                ])
            )
            ->assertSessionHasErrors('hw_role');

        $this->actingAs($admin);
        session([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $this->from(route('user-management.health-workers.edit', ['id' => (string) $admin->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $admin->id]),
                $this->validUpdatePayload($admin, [
                    'hw_role' => 'Admin',
                    'hw_status' => StaffAccountStatus::INACTIVE,
                    'hw_email' => $admin->email,
                    'hw_username' => $admin->username,
                    'hw_assigned_barangay' => 'La Medalla',
                    'hw_assigned_zone' => 'Zone 1',
                    'hw_date_appointed' => '2020-01-15',
                ])
            )
            ->assertSessionHasErrors('hw_status');

        $admin->refresh();
        $this->assertSame(StaffAccountStatus::ACTIVE, $admin->status);
        $this->assertSame(StaffRole::ADMIN, $admin->role);
    }

    public function test_admin_may_demote_when_another_active_admin_exists(): void
    {
        $first = $this->seedWorker([
            'email' => 'admin.one@example.test',
            'username' => 'admin.one',
        ], [
            'role' => StaffRole::ADMIN,
        ]);
        $second = $this->seedWorker([
            'email' => 'admin.two@example.test',
            'username' => 'admin.two',
        ], [
            'role' => StaffRole::ADMIN,
        ]);

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $first->id]),
                $this->validUpdatePayload($first, [
                    'hw_role' => 'BHW',
                    'hw_email' => $first->email,
                    'hw_username' => $first->username,
                ])
            )
            ->assertRedirect(route('user-management.health-workers.view', ['id' => (string) $first->id]));

        $first->refresh();
        $this->assertSame(StaffRole::BHW, $first->role);
        $this->assertSame(StaffRole::ADMIN, $second->fresh()->role);
    }

    public function test_non_admin_can_be_deactivated_and_remains_listed(): void
    {
        $worker = $this->seedWorker([
            'email' => 'deactivate.me@example.test',
            'username' => 'deactivate.me',
        ]);

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_role' => 'BHW',
                    'hw_status' => StaffAccountStatus::INACTIVE,
                    'hw_assigned_barangay' => 'La Medalla',
                    'hw_assigned_zone' => 'Zone 1',
                    'hw_date_appointed' => '2020-01-15',
                    'hw_end_appointment' => '2030-12-31',
                ])
            )
            ->assertRedirect();

        $worker->refresh();
        $this->assertSame(StaffAccountStatus::INACTIVE, $worker->status);
        $this->actingAsAdminSession()
            ->get(route('user-management.index'))
            ->assertOk()
            ->assertSee((string) $worker->id);
    }

    public function test_unauthenticated_cannot_store_health_worker(): void
    {
        $beforeUsers = User::query()->count();
        $beforeAppointments = WorkerAppointment::query()->count();

        $this->post(route('user-management.health-workers.store'), [
            'first_name' => 'Blocked',
            'last_name' => 'Create',
            'email' => 'blocked.create@example.test',
            'mobile' => '09170001111',
            'role' => 'BHW',
            'status' => StaffAccountStatus::ACTIVE,
            'password' => 'TempPass!123',
            'password_confirmation' => 'TempPass!123',
        ])->assertRedirect(route('login'));

        $this->assertSame($beforeUsers, User::query()->count());
        $this->assertSame($beforeAppointments, WorkerAppointment::query()->count());
    }

    public function test_non_admin_cannot_store_health_worker(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $beforeUsers = User::query()->count();
        $beforeAppointments = WorkerAppointment::query()->count();

        $this->post(route('user-management.health-workers.store'), [
                'first_name' => 'Blocked',
                'last_name' => 'Create',
                'email' => 'blocked.bhw.create@example.test',
                'mobile' => '09170002222',
                'role' => 'BHW',
                'status' => StaffAccountStatus::ACTIVE,
                'password' => 'TempPass!123',
                'password_confirmation' => 'TempPass!123',
            ])
            ->assertForbidden();

        $this->assertSame($beforeUsers, User::query()->count());
        $this->assertSame($beforeAppointments, WorkerAppointment::query()->count());
    }
}
