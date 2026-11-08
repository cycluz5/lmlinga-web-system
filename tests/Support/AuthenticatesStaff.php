<?php

namespace Tests\Support;

use App\Models\User;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use App\Support\UserManagementErdMode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Shared helpers for feature tests that need a real authenticated staff user.
 */
trait AuthenticatesStaff
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function actingAsStaff(string $role = StaffRole::ADMIN, array $overrides = []): User
    {
        UserManagementErdMode::resetCachedState();

        $defaults = [
            'status' => StaffAccountStatus::ACTIVE,
        ];
        if (Schema::hasColumn((new User)->getTable(), 'must_change_password')) {
            $defaults['must_change_password'] = false;
        }

        $user = User::factory()->create(array_merge($defaults, $overrides));

        $legacyAppointments = Schema::hasTable('users')
            && Schema::hasTable('worker_appointments')
            && Schema::hasColumn('worker_appointments', 'is_current');

        if ($legacyAppointments && ! UserManagementErdMode::isActive()) {
            $user->assignCurrentAppointment([
                'role' => $role,
                'assigned_barangay' => 'La Medalla',
                'assigned_zone' => 'Zone 1',
                'date_appointed' => '2020-01-01',
            ]);
            $user = $user->fresh(['currentAppointment']);
        }

        $this->actingAs($user);
        $user->syncUiRoleSession();
        UiRole::set($role);
        // Keep the session login id aligned with actingAs(). EnsureStaffAccountIsUsable
        // resolves the ACTOR from the session owner, not from a stale prior login id.
        session([
            Auth::guard()->getName() => $user->getAuthIdentifier(),
            DemoStaffLogin::SESSION_DISPLAY_NAME => $user->composeDisplayName(),
            DemoStaffLogin::SESSION_EMAIL => (string) $user->email,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        return $user;
    }

    /**
     * Point the session login id at $user too. EnsureStaffAccountIsUsable resolves the
     * actor from the session, so switching actors with actingAs() alone keeps the previous one.
     * withSession() (re)starts the test session before writing, so the value survives a prior request.
     */
    protected function alignSessionLoginWith(User $user): void
    {
        $this->withSession([Auth::guard()->getName() => $user->getAuthIdentifier()]);
    }

    /**
     * Migration 2026_09_28_100000 seeds an active super admin (maria.santos) into every test
     * database. Tests that need a sole active Admin deactivate it in the disposable test DB.
     */
    protected function deactivateSeededSuperAdmin(): void
    {
        User::query()->where('username', 'maria.santos')->update(['status' => StaffAccountStatus::INACTIVE]);
    }

    /**
     * Same seeded super admin, removed from the disposable test DB for empty-catalog tests.
     */
    protected function removeSeededSuperAdmin(): void
    {
        User::query()->where('username', 'maria.santos')->delete();
    }
}
