<?php

namespace App\Support;

use App\Models\Resident;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suggests a CPAB answer for a child from the mother's Td doses (td_immunization).
 *
 * Only a suggestion: Birth History pre-selects it while no CPAB is saved and
 * staff confirm or change it before saving.
 *
 * Mother, most reliable first:
 *  1. a household woman whose Maternal Care delivery date is the child's birthday;
 *  2. otherwise the only household woman aged 15–50 at the birth with Td doses.
 *
 * Rules (doses dated before the birth):
 *  - 3 or more doses                          → TT3/TD3 – TT5/TD5 anytime prior;
 *  - 2 doses, the latest ≥ 1 month before birth → at least 2 doses, 1 month prior.
 */
final class CpabSuggestion
{
    private const MIN_MOTHER_AGE = 15;

    private const MAX_MOTHER_AGE = 50;

    /**
     * @return array{value: string, label: string, mother_name: string, source: 'delivery'|'household', dose_dates: list<string>}|null
     */
    public static function forChild(Resident $child): ?array
    {
        if (! Schema::hasTable('td_immunization') || ! $child->birthday) {
            return null;
        }

        $birth = Carbon::parse($child->birthday)->startOfDay();
        [$mother, $source] = self::findMother($child, $birth);
        if ($mother === null) {
            return null;
        }

        $doses = DB::table('td_immunization')
            ->where('resident_id', $mother->getKey())
            ->whereNotNull('date_given')
            ->where('date_given', '<', $birth->toDateString())
            ->orderBy('date_given')
            ->pluck('date_given')
            ->map(fn ($d) => Carbon::parse($d)->startOfDay())
            ->values();

        $value = null;
        if ($doses->count() >= 3) {
            $value = ChildBirthHistoryService::PCAB_TT3_TD3_TO_TT5_TD5;
        } elseif ($doses->count() === 2 && $doses->last()->lte($birth->copy()->subMonthNoOverflow())) {
            $value = ChildBirthHistoryService::PCAB_AT_LEAST_2_DOSES;
        }

        if ($value === null) {
            return null;
        }

        return [
            'value' => $value,
            'label' => ChildBirthHistoryService::pcabLabels()[$value],
            'mother_name' => trim(implode(' ', array_filter([
                (string) $mother->first_name,
                (string) $mother->last_name,
            ]))),
            'source' => $source,
            'dose_dates' => $doses->map(fn (Carbon $d) => DisplayDate::format($d->toDateString()) ?: $d->toDateString())->all(),
        ];
    }

    /**
     * @return array{0: Resident|null, 1: 'delivery'|'household'|null}
     */
    private static function findMother(Resident $child, Carbon $birth): array
    {
        $householdWomen = Resident::query()
            ->where('household_id', $child->household_id)
            ->whereKeyNot($child->getKey())
            ->where('sex', 'Female')
            ->get();

        if ($householdWomen->isEmpty()) {
            return [null, null];
        }

        // 1. Maternal Care delivery on the child's birthday.
        if (Schema::hasTable('maternal_care') && Schema::hasTable('delivery_outcomes')) {
            $deliveredIds = DB::table('delivery_outcomes')
                ->join('maternal_care', 'maternal_care.maternal_care_id', '=', 'delivery_outcomes.maternal_care_id')
                ->whereIn('maternal_care.resident_id', $householdWomen->map->getKey()->all())
                ->whereDate('delivery_outcomes.date_time_of_delivery', $birth->toDateString())
                ->pluck('maternal_care.resident_id')
                ->unique()
                ->values();

            if ($deliveredIds->count() === 1) {
                return [$householdWomen->first(fn (Resident $r) => (string) $r->getKey() === (string) $deliveredIds->first()), 'delivery'];
            }
        }

        // 2. The only woman of childbearing age at the birth who has Td doses.
        $candidates = $householdWomen->filter(function (Resident $woman) use ($birth): bool {
            if (! $woman->birthday) {
                return false;
            }
            $ageAtBirth = Carbon::parse($woman->birthday)->diffInYears($birth);

            return $ageAtBirth >= self::MIN_MOTHER_AGE
                && $ageAtBirth <= self::MAX_MOTHER_AGE
                && DB::table('td_immunization')->where('resident_id', $woman->getKey())->whereNotNull('date_given')->exists();
        })->values();

        return $candidates->count() === 1 ? [$candidates->first(), 'household'] : [null, null];
    }
}
