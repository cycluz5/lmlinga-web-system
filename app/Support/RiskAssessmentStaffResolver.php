<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Resolve authenticated staff user_id for authoritative risk_assessment writes.
 */
final class RiskAssessmentStaffResolver
{
    public static function resolveUserIdOrFail(): int
    {
        $user = Auth::user();
        if ($user === null) {
            throw ValidationException::withMessages([
                'assessment' => 'An authenticated staff account is required to save this risk assessment.',
            ]);
        }

        $userId = (int) $user->getKey();
        if ($userId <= 0) {
            throw ValidationException::withMessages([
                'assessment' => 'An authenticated staff account is required to save this risk assessment.',
            ]);
        }

        if (! Schema::hasTable('user_management')) {
            throw ValidationException::withMessages([
                'assessment' => 'Staff attribution is not available with the current database configuration.',
            ]);
        }

        if (! DB::table('user_management')->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages([
                'assessment' => 'The authenticated account is not a valid staff user for risk assessment attribution.',
            ]);
        }

        return $userId;
    }
}
