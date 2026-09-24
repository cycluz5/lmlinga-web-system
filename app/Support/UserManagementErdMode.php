<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Detects authoritative ERD user_management vs Laravel users staff persistence.
 */
final class UserManagementErdMode
{
    private static ?bool $active = null;

    private static ?bool $hasDeletedAt = null;

    public static function isActive(): bool
    {
        if (self::$active === null) {
            self::$active = Schema::hasTable('user_management')
                && ! Schema::hasTable('users');
        }

        return self::$active;
    }

    public static function staffKeyName(): string
    {
        return self::isActive() ? 'user_id' : 'id';
    }

    public static function staffTableName(): string
    {
        return self::isActive() ? 'user_management' : 'users';
    }

    public static function appointmentKeyName(): string
    {
        if (! self::isActive()) {
            return 'id';
        }

        return Schema::hasColumn('worker_appointments', 'appointment_id')
            ? 'appointment_id'
            : 'id';
    }

    public static function residentAccountKeyName(): string
    {
        if (! Schema::hasTable('resident_accounts')) {
            return 'id';
        }

        if (self::isActive() && Schema::hasColumn('resident_accounts', 'account_id')) {
            return 'account_id';
        }

        return Schema::hasColumn('resident_accounts', 'id') ? 'id' : 'account_id';
    }

    public static function residentAccountsHaveResidentLink(): bool
    {
        return Schema::hasTable('resident_accounts')
            && Schema::hasColumn('resident_accounts', 'resident_id');
    }

    public static function appointmentsHaveIsCurrent(): bool
    {
        return Schema::hasTable('worker_appointments')
            && Schema::hasColumn('worker_appointments', 'is_current');
    }

    /**
     * Persist worker_appointments.role in the shape expected by the active schema.
     */
    public static function appointmentRoleForStorage(string $machineRole): string
    {
        $normalized = StaffRole::normalize($machineRole) ?? $machineRole;

        if (self::isActive()) {
            $label = StaffRole::label($normalized);

            return $label !== '' ? $label : $normalized;
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    public static function appointmentRoleMatchValues(string $machineRole): array
    {
        $normalized = StaffRole::normalize($machineRole) ?? $machineRole;
        $label = StaffRole::label($normalized);

        return array_values(array_unique(array_filter([
            $normalized,
            $label !== '' ? $label : null,
            strtoupper($normalized),
        ])));
    }

    public static function staffHasDeletedAt(): bool
    {
        if (self::$hasDeletedAt === null) {
            $table = self::staffTableName();
            self::$hasDeletedAt = Schema::hasTable($table)
                && Schema::hasColumn($table, 'deleted_at');
        }

        return self::$hasDeletedAt;
    }

    public static function resetCachedState(): void
    {
        self::$active = null;
        self::$hasDeletedAt = null;
    }
}
