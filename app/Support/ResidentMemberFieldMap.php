<?php

namespace App\Support;

use App\Http\Requests\ValidatesHouseholdResidentMember;
use App\Models\Resident;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UI ↔ authoritative ERD value mapping for household member persistence.
 */
final class ResidentMemberFieldMap
{
    /**
     * @return list<string>
     */
    public static function occupationOptions(): array
    {
        return ValidatesHouseholdResidentMember::occupations();
    }

    /**
     * @return list<string>
     */
    public static function religionOptions(): array
    {
        return ValidatesHouseholdResidentMember::religions();
    }

    public static function isOtherChoice(?string $value): bool
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized === 'other' || $normalized === 'others';
    }

    public static function lookupOccupationId(string $name): ?int
    {
        if ($name === '' || ! Schema::hasTable('occupation')) {
            return null;
        }

        if (self::isOtherChoice($name)) {
            foreach (['Other', 'Others'] as $label) {
                $id = DB::table('occupation')->where('occupation_name', $label)->value('occupation_id');
                if ($id !== null) {
                    return (int) $id;
                }
            }

            return null;
        }

        $id = DB::table('occupation')->where('occupation_name', $name)->value('occupation_id');

        return $id !== null ? (int) $id : null;
    }

    public static function lookupReligionId(string $name): ?int
    {
        if ($name === '' || ! Schema::hasTable('religion')) {
            return null;
        }

        if (self::isOtherChoice($name)) {
            foreach (['Other', 'Others'] as $label) {
                $id = DB::table('religion')->where('religion_name', $label)->value('religion_id');
                if ($id !== null) {
                    return (int) $id;
                }
            }

            return null;
        }

        $candidates = [$name];
        if (strcasecmp($name, 'Born Again') === 0) {
            $candidates[] = 'Born Again Christian';
        }

        foreach ($candidates as $label) {
            $id = DB::table('religion')->where('religion_name', $label)->value('religion_id');
            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
    }

    public static function civilStatusForStorage(string $uiValue): string
    {
        return strcasecmp($uiValue, 'Live-in') === 0 ? 'Live-In' : $uiValue;
    }

    public static function relationshipStatusFromStored(string $stored): string
    {
        return strcasecmp($stored, 'Live-In') === 0 ? 'Live-in' : $stored;
    }

    public static function educationalAttainmentForStorage(string $uiValue): string
    {
        return match ($uiValue) {
            'Post-Graduate' => 'Post Graduate',
            default => $uiValue,
        };
    }

    public static function educationFromStored(string $stored): string
    {
        return match ($stored) {
            'Post Graduate' => 'Post-Graduate',
            default => $stored,
        };
    }

    public static function monthlyIncomeForStorage(string $uiValue): string
    {
        $table = (new Resident)->getTable();
        $erdIncome = Schema::hasColumn($table, 'occupation_id')
            && ! Schema::hasColumn($table, 'occupation');

        if (! $erdIncome) {
            return $uiValue;
        }

        return match ($uiValue) {
            'None / N/A' => 'None',
            '5,000 – 9,999' => '5,000-9,999',
            '10,000 – 19,999' => '10,000-19,999',
            '20,000 – 29,999' => '20,000-29,999',
            '30,000 – 49,999' => '30,000-49,999',
            default => $uiValue,
        };
    }

    public static function monthlyIncomeFromStored(string $stored): string
    {
        return match ($stored) {
            'None' => 'None / N/A',
            '5,000-9,999' => '5,000 – 9,999',
            '10,000-19,999' => '10,000 – 19,999',
            '20,000-29,999' => '20,000 – 29,999',
            '30,000-49,999' => '30,000 – 49,999',
            default => $stored,
        };
    }
}
