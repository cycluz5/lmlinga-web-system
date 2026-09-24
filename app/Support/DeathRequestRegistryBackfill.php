<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time compatibility backfill for D-01.
 *
 * Historical death_requests created before Registry No. was introduced may
 * store the identifying number only in certificate_no while registry_no is blank.
 * Registry No. is the sole user-facing identifier; certificate_no remains internal.
 */
final class DeathRequestRegistryBackfill
{
    /**
     * Copy legitimate historical certificate_no values into blank registry_no rows.
     * Never overwrites a non-empty registry_no.
     *
     * @return int Number of rows updated
     */
    public static function run(): int
    {
        if (! Schema::hasTable('death_requests')) {
            return 0;
        }

        if (! Schema::hasColumn('death_requests', 'registry_no')
            || ! Schema::hasColumn('death_requests', 'certificate_no')) {
            return 0;
        }

        $candidates = DB::table('death_requests')
            ->where(static function ($query): void {
                $query->whereNull('registry_no')->orWhere('registry_no', '');
            })
            ->whereNotNull('certificate_no')
            ->where('certificate_no', '!=', '')
            ->select(['id', 'registry_no', 'certificate_no'])
            ->get();

        $updated = 0;

        foreach ($candidates as $row) {
            $registry = trim((string) ($row->registry_no ?? ''));
            $certificate = trim((string) ($row->certificate_no ?? ''));

            if ($registry !== '' || $certificate === '') {
                continue;
            }

            $affected = DB::table('death_requests')
                ->where('id', $row->id)
                ->where(static function ($query): void {
                    $query->whereNull('registry_no')->orWhere('registry_no', '');
                })
                ->update(['registry_no' => $certificate]);

            $updated += (int) $affected;
        }

        return $updated;
    }
}
