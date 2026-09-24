<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminHealthWorkerDateRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dob_yesterday_is_accepted(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_dob' => '2026-09-10',
                ])
            )
            ->assertSessionDoesntHaveErrors('hw_dob')
            ->assertRedirect();

        $this->assertSame('2026-09-10', $worker->fresh()->date_of_birth?->format('Y-m-d'));
    }

    public function test_dob_today_is_rejected(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_dob' => '2026-09-11',
                ])
            )
            ->assertSessionHasErrors('hw_dob');

        $this->assertSame('1990-02-02', $worker->fresh()->date_of_birth?->format('Y-m-d'));
    }

    public function test_dob_future_date_is_rejected(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_dob' => '2026-09-12',
                ])
            )
            ->assertSessionHasErrors('hw_dob');

        $this->assertSame('1990-02-02', $worker->fresh()->date_of_birth?->format('Y-m-d'));
    }

    public function test_date_appointed_today_is_accepted(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_date_appointed' => '2026-09-11',
                    'hw_end_appointment' => '2031-12-31',
                ])
            )
            ->assertSessionDoesntHaveErrors('hw_date_appointed')
            ->assertRedirect();

        $this->assertSame(
            '2026-09-11',
            $worker->fresh(['currentAppointment'])->currentAppointment?->date_appointed?->format('Y-m-d')
        );
    }

    public function test_date_appointed_past_date_is_accepted(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_date_appointed' => '2020-01-15',
                    'hw_end_appointment' => '2031-12-31',
                ])
            )
            ->assertSessionDoesntHaveErrors('hw_date_appointed')
            ->assertRedirect();

        $this->assertSame(
            '2020-01-15',
            $worker->fresh(['currentAppointment'])->currentAppointment?->date_appointed?->format('Y-m-d')
        );
    }

    public function test_date_appointed_future_date_is_rejected(): void
    {
        $worker = $this->seedWorker();

        $this->actingAsAdminSession()
            ->from(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->put(
                route('user-management.health-workers.update', ['id' => (string) $worker->id]),
                $this->validUpdatePayload($worker, [
                    'hw_date_appointed' => '2026-09-12',
                ])
            )
            ->assertSessionHasErrors('hw_date_appointed');

        $this->assertSame(
            '2020-01-15',
            $worker->fresh(['currentAppointment'])->currentAppointment?->date_appointed?->format('Y-m-d')
        );
    }

    public function test_edit_page_renders_dob_and_appointed_max_from_app_today(): void
    {
        $worker = $this->seedWorker();

        $html = $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.edit', ['id' => (string) $worker->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/name="hw_dob"[^>]*max="2026-09-10"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="hw_date_appointed"[^>]*max="2026-09-11"/',
            $html
        );
    }

    public function test_create_page_does_not_contain_dob_or_appointed_inputs(): void
    {
        $html = $this->actingAsAdminSession()
            ->get(route('user-management.health-workers.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('name="hw_dob"', $html);
        $this->assertStringNotContainsString('name="hw_date_appointed"', $html);
        $this->assertStringNotContainsString('id="hw_dob"', $html);
        $this->assertStringNotContainsString('id="hw_date_appointed"', $html);
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

    private function seedWorker(): User
    {
        $this->actingAsAdminSession();

        $worker = User::factory()->create([
            'first_name' => 'Maria',
            'middle_name' => 'Cruz',
            'last_name' => 'Reyes',
            'suffix' => 'N/A',
            'sex' => 'Female',
            'date_of_birth' => '1990-02-02',
            'civil_status' => 'Single',
            'nationality' => 'Filipino',
            'mobile_number' => '09171234567',
            'email' => 'maria.reyes.dates@example.test',
            'username' => 'maria.reyes.dates',
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
            'hw_role' => StaffRole::BHW,
            'hw_assigned_barangay' => 'La Medalla',
            'hw_assigned_zone' => 'Zone 2',
            'hw_date_appointed' => $appointment?->date_appointed?->format('Y-m-d') ?? '2020-01-15',
            'hw_end_appointment' => '2031-12-31',
            'hw_username' => $worker->username,
            'hw_status' => StaffAccountStatus::ACTIVE,
        ], $overrides);
    }
}
