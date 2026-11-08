<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB-05 Phase 3 — idempotent resident_id backfill for death tables.
 *
 * Matches only when household_no + member_id resolve to a single persisted
 * household-scoped resident. Never creates households/residents.
 */
final class DeathRequestResidentBackfill
{
    /**
     * @return array{death_requests: int, resident_statuses: int}
     */
    public static function run(): array
    {
        return [
            'death_requests' => self::backfillTable('death_requests'),
            'resident_statuses' => self::backfillTable('resident_statuses'),
        ];
    }

    private static function backfillTable(string $table): int
    {
        if (! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'resident_id')
            || ! Schema::hasTable('households')
            || ! Schema::hasTable('residents')) {
            return 0;
        }

        $rows = DB::table($table)
            ->whereNull('resident_id')
            ->select(['id', 'household_no', 'member_id'])
            ->get();

        $updated = 0;

        foreach ($rows as $row) {
            $householdNo = DemoCatalog::normalizeHouseholdNo((string) ($row->household_no ?? ''));
            $memberNo = DemoCatalog::normalizeMemberId((string) ($row->member_id ?? ''));

            if ($householdNo === '' || $memberNo === '') {
                continue;
            }

            $residentId = self::resolveResidentId($householdNo, $memberNo);
            if ($residentId === null) {
                continue;
            }

            $affected = DB::table($table)
                ->where('id', $row->id)
                ->whereNull('resident_id')
                ->update(['resident_id' => $residentId]);

            $updated += (int) $affected;
        }

        return $updated;
    }

    private static function resolveResidentId(string $householdNo, string $memberNo): ?int
    {
        $householdId = DB::table('households')
            ->where('household_no', $householdNo)
            ->value('id');

        if ($householdId === null) {
            return null;
        }

        $residentId = DB::table('residents')
            ->where('household_id', $householdId)
            ->where('member_no', $memberNo)
            ->value('id');

        return $residentId !== null ? (int) $residentId : null;
    }
}
