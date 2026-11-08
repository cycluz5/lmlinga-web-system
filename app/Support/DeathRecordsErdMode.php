<?php

namespace App\Support;

use App\Models\DeathRequest;
use Illuminate\Support\Facades\Schema;

/**
 * Detects authoritative ERD death_records persistence vs Laravel death_requests.
 */
final class DeathRecordsErdMode
{
    private static ?bool $active = null;

    public static function isActive(): bool
    {
        if (self::$active === null) {
            self::$active = Schema::hasTable('death_records')
                && ! Schema::hasTable('death_requests');
        }

        return self::$active;
    }

    public static function submittedAtColumn(): string
    {
        return self::isActive() ? 'created_at' : 'submitted_at';
    }

    public static function appStatusFromErd(?string $verificationStatus): string
    {
        return match ((string) $verificationStatus) {
            'Verified' => DeathRequest::STATUS_APPROVED,
            'Rejected' => DeathRequest::STATUS_REJECTED,
            'Pending Verification' => DeathRequest::STATUS_PENDING,
            default => DeathRequest::STATUS_PENDING,
        };
    }

    public static function erdStatusFromApp(string $status): string
    {
        return match ($status) {
            DeathRequest::STATUS_APPROVED => 'Verified',
            DeathRequest::STATUS_REJECTED => 'Rejected',
            default => 'Pending Verification',
        };
    }

    public static function pendingVerificationStatus(): string
    {
        return 'Pending Verification';
    }

    public static function resetCachedState(): void
    {
        self::$active = null;
    }
}
