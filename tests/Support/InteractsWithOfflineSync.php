<?php

namespace Tests\Support;

use App\Models\Household;
use App\Models\OfflineSyncReceipt;
use App\Models\User;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Shared payloads and assertions for OFFLINE-3 sync tests.
 */
trait InteractsWithOfflineSync
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function offlineEnvelope(string $operationType, array $payload, array $overrides = []): array
    {
        return array_merge([
            'operation_id' => (string) Str::uuid(),
            'schema_version' => 1,
            'operation_type' => $operationType,
            'payload' => $payload,
            'base_snapshot' => null,
            'parent_server' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function householdCreatePayload(array $overrides = []): array
    {
        return array_merge([
            'household_no' => '121',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'address' => '123 Layuan St., Brgy. La Medalla',
            'latitude' => '7.12345678',
            'longitude' => '125.12345678',
            'accomplished_by' => 'Maria BHW',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function plotHouseholdPayload(array $overrides = []): array
    {
        return array_merge([
            'household_no' => '121',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'birthday' => '1988-03-12',
            'sex' => 'Female',
            'civil_status' => 'Live-In',
            'zone' => '1',
            'household_type' => 'HHTS',
            'date_registered' => now()->toDateString(),
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ], $overrides);
    }

    /**
     * Observed IndexedDB PLOT_HOUSEHOLD_WITH_HEAD payload from OFFLINE-7 field replay.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function observedIndexedDbPlotHouseholdPayload(array $overrides = []): array
    {
        return array_merge([
            'birthday' => '1994-06-07',
            'civil_status' => 'Single',
            'client_marker_id' => 'demo-temp-3fa85f64-5717-4562-b3fc-2c963f66afa6',
            'consent' => true,
            'date_registered' => '2026-09-06',
            'first_name' => 'Zai',
            'household_no' => '210',
            'household_type' => 'HHTS',
            'last_name' => 'Kluz',
            'lat' => 13.376191299053865,
            'lng' => 123.43171525236092,
            'middle_name' => '',
            'sex' => 'Male',
            'zone' => 5,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function residentMemberPayload(array $overrides = []): array
    {
        return array_merge([
            'last_name' => 'Santos',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'relation' => 'Spouse',
            'birthday' => '1990-03-15',
            'sex' => 'Female',
            'relationship_status' => 'Married',
            'occupation' => 'Teacher',
            'monthly_income' => '20,000 – 29,999',
            'religion' => 'Roman Catholic',
            'education' => 'College Graduate',
            'fp_user' => 'No',
            'philhealth' => '123456789012',
            'disability' => ['none'],
            'disability_others' => null,
            'medical_history' => ['none'],
            'medical_others' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function householdUpdatePayload(Household $household, array $overrides = []): array
    {
        return array_merge([
            'zone' => (string) ($household->zone ?? 'Zone 2'),
            'street' => (string) ($household->street ?? 'Layuan St.'),
            'date_registered' => $household->date_registered?->format('Y-m-d') ?? '2026-01-15',
            'address' => $household->address,
            'latitude' => $household->latitude,
            'longitude' => $household->longitude,
            'accomplished_by' => $household->accomplished_by,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    protected function postOfflineSync(array $envelope, array $headers = []): TestResponse
    {
        return $this->postJson(route('offline.sync'), $envelope, $headers);
    }

    protected function assertNoAppliedReceipt(?string $operationId = null): void
    {
        $query = OfflineSyncReceipt::query();
        if ($operationId !== null) {
            $query->where('operation_id', $operationId);
        }

        $this->assertSame(0, $query->count());
    }

    protected function actingAsFieldStaff(string $role = StaffRole::BHW, array $overrides = []): User
    {
        return $this->actingAsStaff($role, $overrides);
    }

    protected function actingAsErdFieldStaff(string $role = StaffRole::BHW): User
    {
        $userId = (int) DB::table('user_management')->insertGetId([
            'first_name' => 'Erd',
            'last_name' => 'Plotter',
            'email' => 'erd.plotter.'.Str::random(8).'@example.test',
            'username' => 'erd.plotter.'.Str::random(8),
            'password' => 'hashed-placeholder',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'user_id');

        /** @var User $user */
        $user = User::query()->findOrFail($userId);
        $user->assignCurrentAppointment([
            'role' => $role,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);
        $user = $user->fresh(['currentAppointment']);

        $this->actingAs($user);
        $user->syncUiRoleSession();
        session([
            UiRole::SESSION_KEY => $role,
            DemoStaffLogin::SESSION_DISPLAY_NAME => $user->composeDisplayName(),
            DemoStaffLogin::SESSION_EMAIL => (string) $user->email,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        return $user;
    }
}
