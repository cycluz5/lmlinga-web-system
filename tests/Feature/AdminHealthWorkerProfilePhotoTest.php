<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkerAppointment;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffProfilePhotoStorage;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminHealthWorkerProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_edit_form_supports_multipart_photo_upload(): void
    {
        $worker = $this->seedWorker();

        $html = $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<form[^>]*enctype="multipart\/form-data"/', $html);
        $this->assertStringContainsString('name="hw_photo"', $html);
        $this->assertStringContainsString('name="hw_remove_photo"', $html);
    }

    public function test_admin_can_upload_png_profile_photo(): void
    {
        $worker = $this->seedWorker();
        $this->assertNull($worker->photo_path);

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->photoSafePayload($worker, [
                    'hw_photo' => UploadedFile::fake()->image('avatar.png'),
                ])
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('user-management.health-workers.view', ['id' => (string) $worker->id]));

        $worker->refresh();
        $this->assertNotNull($worker->photo_path);
        $this->assertTrue(StaffProfilePhotoStorage::isManagedPath($worker->photo_path));
        Storage::disk('public')->assertExists($worker->photo_path);
        $this->assertSame(1, $worker->appointments()->count());
    }

    public function test_view_and_index_render_uploaded_photo_url(): void
    {
        $worker = $this->seedWorker([
            'first_name' => 'Photo',
            'last_name' => 'Worker',
            'email' => 'photo.worker@example.test',
            'username' => 'photo.worker',
        ]);

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->photoSafePayload($worker, [
                    'hw_photo' => UploadedFile::fake()->image('face.jpg'),
                ])
            )
            ->assertSessionHasNoErrors();

        $worker->refresh();
        $url = $worker->profilePhotoUrl();
        $this->assertNotNull($url);
        $this->assertSame('/storage/'.$worker->photo_path, $url);
        $this->assertStringStartsWith('/storage/health-workers/profile-photos/', $url);
        $this->assertStringNotContainsString('localhost', $url);
        $this->assertStringNotContainsString('127.0.0.1', $url);
        $this->assertDoesNotMatchRegularExpression('#^https?://#', $url);

        $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.view', ['id' => (string) $worker->id]))
            ->assertOk()
            ->assertSee($url, false)
            ->assertSee('lml-hw-view__avatar-img', false);

        $this->actingAsAdminSession()
            ->get(route('user-management.index'))
            ->assertOk()
            ->assertSee($url, false)
            ->assertSee('lml-hw-card__avatar-img', false);
    }

    public function test_replacing_photo_updates_path_and_deletes_previous_file(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->photoSafePayload($worker, [
                    'hw_photo' => UploadedFile::fake()->image('first.png'),
                ])
            )
            ->assertSessionHasNoErrors();

        $worker->refresh();
        $firstPath = $worker->photo_path;
        $this->assertNotNull($firstPath);
        Storage::disk('public')->assertExists($firstPath);

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->photoSafePayload($worker, [
                    'hw_photo' => UploadedFile::fake()->image('second.jpg'),
                ])
            )
            ->assertSessionHasNoErrors();

        $worker->refresh();
        $this->assertNotSame($firstPath, $worker->photo_path);
        Storage::disk('public')->assertExists($worker->photo_path);
        Storage::disk('public')->assertMissing($firstPath);
    }

    public function test_later_edit_without_new_photo_keeps_existing_path(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->photoSafePayload($worker, [
                    'hw_photo' => UploadedFile::fake()->image('keep.png'),
                ])
            )
            ->assertSessionHasNoErrors();

        $worker->refresh();
        $kept = $worker->photo_path;

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->photoSafePayload($worker, [
                    'hw_first_name' => 'Kept',
                    'hw_remove_photo' => '0',
                ])
            )
            ->assertSessionHasNoErrors();

        $worker->refresh();
        $this->assertSame('Kept', $worker->first_name);
        $this->assertSame($kept, $worker->photo_path);
        Storage::disk('public')->assertExists($kept);
    }

    public function test_remove_photo_clears_path_and_deletes_managed_file(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->photoSafePayload($worker, [
                    'hw_photo' => UploadedFile::fake()->image('gone.png'),
                ])
            )
            ->assertSessionHasNoErrors();

        $worker->refresh();
        $removedPath = $worker->photo_path;

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->photoSafePayload($worker, [
                    'hw_remove_photo' => '1',
                ])
            )
            ->assertSessionHasNoErrors();

        $worker->refresh();
        $this->assertNull($worker->photo_path);
        Storage::disk('public')->assertMissing($removedPath);

        $view = $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.view', ['id' => (string) $worker->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('lml-hw-view__avatar-img', $view);
        $this->assertStringContainsString('default avatar', $view);
    }

    public function test_photo_can_be_uploaded_again_after_removal(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->photoSafePayload($worker, [
                    'hw_photo' => UploadedFile::fake()->image('a.png'),
                ])
            )
            ->assertSessionHasNoErrors();

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->photoSafePayload($worker, [
                    'hw_remove_photo' => '1',
                ])
            )
            ->assertSessionHasNoErrors();

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->photoSafePayload($worker, [
                    'hw_photo' => UploadedFile::fake()->image('b.png'),
                    'hw_remove_photo' => '1',
                ])
            )
            ->assertSessionHasNoErrors();

        $worker->refresh();
        $this->assertNotNull($worker->photo_path);
        Storage::disk('public')->assertExists($worker->photo_path);
    }

    public function test_photo_only_edit_does_not_create_appointment_history(): void
    {
        $worker = $this->seedWorker();
        $appointmentId = $worker->currentAppointment?->getKey();
        $this->assertNotNull($appointmentId);
        $this->assertSame(1, WorkerAppointment::query()->where('user_id', $worker->id)->count());

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->photoSafePayload($worker, [
                    'hw_photo' => UploadedFile::fake()->image('history.png'),
                ])
            )
            ->assertSessionHasNoErrors();

        $worker->refresh();
        $this->assertSame(1, WorkerAppointment::query()->where('user_id', $worker->id)->count());
        $this->assertSame($appointmentId, $worker->currentAppointment?->getKey());
        $this->assertSame(StaffRole::BHW, $worker->role);
        $this->assertSame('Zone 1', $worker->currentAppointment?->assigned_zone);
    }

    public function test_authenticated_header_shows_photo_or_placeholder(): void
    {
        $withPhoto = $this->actingAsStaff(StaffRole::BHW, [
            'email' => 'header.photo@example.test',
            'username' => 'header.photo',
            'must_change_password' => false,
        ]);

        $path = StaffProfilePhotoStorage::store(UploadedFile::fake()->image('me.png'));
        $withPhoto->forceFill(['photo_path' => $path])->save();
        $withPhoto->refresh();
        $url = $withPhoto->profilePhotoUrl();
        $this->assertNotNull($url);
        $this->assertSame('/storage/'.$path, $url);
        $this->assertStringStartsWith('/storage/health-workers/profile-photos/', $url);
        $this->assertDoesNotMatchRegularExpression('#^https?://#', $url);

        $photoHtml = $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('lml-topbar__avatar-img', $photoHtml);
        $this->assertStringContainsString($url, $photoHtml);

        $withoutPhoto = $this->actingAsStaff(StaffRole::BNS, [
            'email' => 'header.none@example.test',
            'username' => 'header.none',
            'must_change_password' => false,
            'photo_path' => null,
        ]);
        $this->assertNull($withoutPhoto->profilePhotoUrl());

        $placeholderHtml = $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('lml-topbar__avatar-img', $placeholderHtml);
        $this->assertMatchesRegularExpression('/lml-topbar__avatar[\s\S]*bi-person-fill/', $placeholderHtml);
    }

    public function test_invalid_upload_is_rejected_and_existing_photo_is_kept(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->photoSafePayload($worker, [
                    'hw_photo' => UploadedFile::fake()->image('ok.png'),
                ])
            )
            ->assertSessionHasNoErrors();

        $worker->refresh();
        $kept = $worker->photo_path;

        $this->actingAsAdminSession()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->photoSafePayload($worker, [
                    'hw_photo' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
                ])
            )
            ->assertSessionHasErrors('hw_photo')
            ->assertRedirect();

        $worker->refresh();
        $this->assertSame($kept, $worker->photo_path);
        Storage::disk('public')->assertExists($kept);

        $this->actingAsAdminSession()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->photoSafePayload($worker, [
                    'hw_photo' => UploadedFile::fake()->image('huge.jpg')->size(3072),
                ])
            )
            ->assertSessionHasErrors('hw_photo');

        $worker->refresh();
        $this->assertSame($kept, $worker->photo_path);
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

        return $this->withSession([
            UiRole::SESSION_KEY => StaffRole::ADMIN,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $userOverrides
     */
    private function seedWorker(array $userOverrides = []): User
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
            'photo_path' => null,
            'created_by' => $admin->id,
        ], $userOverrides));

        $worker->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-15',
            'end_of_appointment' => '2030-12-31',
        ]);

        return $worker->fresh(['currentAppointment']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function photoSafePayload(User $worker, array $overrides = []): array
    {
        $appointment = $worker->currentAppointment;

        return array_merge([
            'sex' => (string) $worker->sex,
            'hw_first_name' => (string) $worker->first_name,
            'hw_last_name' => (string) $worker->last_name,
            'hw_middle_name' => (string) $worker->middle_name,
            'hw_suffix' => (string) ($worker->suffix ?? 'N/A'),
            'hw_dob' => $worker->date_of_birth?->format('Y-m-d') ?? '1990-02-02',
            'hw_civil_status' => (string) $worker->civil_status,
            'hw_nationality' => (string) $worker->nationality,
            'hw_mobile' => (string) $worker->mobile_number,
            'hw_email' => (string) $worker->email,
            'hw_house_no' => (string) $worker->house_no,
            'hw_street' => (string) $worker->street,
            'hw_purok_zone' => (string) $worker->purok_zone,
            'hw_barangay' => (string) $worker->barangay,
            'hw_municipality' => (string) $worker->municipality_city,
            'hw_province' => (string) $worker->province,
            'hw_zip' => (string) $worker->zip_code,
            'hw_role' => StaffRole::normalize($appointment?->role) ?? StaffRole::BHW,
            'hw_assigned_barangay' => (string) ($appointment?->assigned_barangay ?? 'La Medalla'),
            'hw_assigned_zone' => (string) ($appointment?->assigned_zone ?? 'Zone 1'),
            'hw_date_appointed' => $appointment?->date_appointed?->format('Y-m-d') ?? '2020-01-15',
            'hw_end_appointment' => $appointment?->end_of_appointment?->format('Y-m-d') ?? '2030-12-31',
            'hw_username' => (string) $worker->username,
            'hw_status' => StaffAccountStatus::ACTIVE,
            'hw_remove_photo' => '0',
        ], $overrides);
    }
}
