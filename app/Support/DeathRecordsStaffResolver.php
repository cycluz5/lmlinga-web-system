<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Resolve user_management.user_id for ERD death_records submit/review writes.
 */
final class DeathRecordsStaffResolver
{
    public static function resolveUserIdOrFail(string $errorKey = 'cause_of_death'): int
    {
        $user = Auth::user();
        if ($user === null) {
            throw ValidationException::withMessages([
                $errorKey => 'An authenticated staff account is required for death record attribution.',
            ]);
        }

        $userId = (int) $user->getKey();
        if ($userId <= 0) {
            throw ValidationException::withMessages([
                $errorKey => 'An authenticated staff account is required for death record attribution.',
            ]);
        }

        if (! Schema::hasTable('user_management')) {
            throw ValidationException::withMessages([
                $errorKey => 'Staff attribution is not available with the current database configuration.',
            ]);
        }

        if (! DB::table('user_management')->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages([
                $errorKey => 'The authenticated account is not a valid staff user for death record attribution.',
            ]);
        }

        return $userId;
    }
}
