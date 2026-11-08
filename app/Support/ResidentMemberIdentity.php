<?php

namespace App\Support;

use App\Models\Resident;
use Illuminate\Support\Facades\Schema;

/**
 * Stable member identifiers when ERD residents lack member_no.
 */
final class ResidentMemberIdentity
{
    public static function hasMemberNoColumn(): bool
    {
        return Schema::hasColumn((new Resident)->getTable(), 'member_no');
    }

    public static function memberIdFor(Resident $resident): string
    {
        if (self::hasMemberNoColumn()) {
            $stored = trim((string) ($resident->getAttributes()['member_no'] ?? ''));
            if ($stored !== '') {
                return DemoCatalog::normalizeMemberId($stored);
            }
        }

        return self::syntheticMemberId((int) $resident->id);
    }

    public static function syntheticMemberId(int $residentId): string
    {
        return 'MB-'.str_pad((string) max(1, $residentId), 3, '0', STR_PAD_LEFT);
    }

    public static function parseResidentIdFromMemberId(string $memberId): ?int
    {
        $normalized = DemoCatalog::normalizeMemberId($memberId);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^MB-(\d+)$/i', $normalized, $matches) !== 1) {
            return null;
        }

        $value = (int) $matches[1];

        return $value > 0 ? $value : null;
    }
}
