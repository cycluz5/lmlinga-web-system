<?php

namespace App\Services;

use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Support\HouseholdZoneResolver;
use App\Support\NonResidentFamilyPlanningService;
use App\Support\StaffRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Batch 4 — scoped name suggestions for staff name-entry fields.
 *
 * Purpose: while a health worker types a person's name, show registered residents with a similar name so an
 * existing record can be opened instead of creating a duplicate. Manual entry is never blocked.
 *
 * Privacy and access (enforced here, on the server):
 * - Staff only (Admin, BHW, BNS, BSPO). Linguists and guests get nothing (the route also refuses them).
 * - Admin: every zone. BHW/BNS/BSPO: only households in the zones of their current appointment; a worker with
 *   no assigned zone gets no suggestions (fail closed).
 * - At least MIN_QUERY_LENGTH characters; at most MAX_RESULTS rows.
 * - Each row carries only the name, zone and household number (to tell same-name people apart) and a link to
 *   the existing member page. Never birth dates, sex, contact details, health or vital-status data.
 * - Non-resident family-planning clients (sentinel household) and deleted rows are excluded.
 */
final class StaffNameSuggestionService
{
    public const MIN_QUERY_LENGTH = 2;

    public const MAX_RESULTS = 8;

    private const SEARCHED_ROLES = [StaffRole::ADMIN, StaffRole::BHW, StaffRole::BNS, StaffRole::BSPO];

    public static function mayUse(?User $actor): bool
    {
        return $actor instanceof User
            && $actor->isActive()
            && in_array(StaffRole::normalize($actor->role), self::SEARCHED_ROLES, true);
    }

    /**
     * @return array{items: list<array{name: string, zone: string, household_no: string, url: string|null}>, more: bool}
     */
    public function residents(User $actor, string $rawQuery): array
    {
        $empty = ['items' => [], 'more' => false];
        if (! self::mayUse($actor)) {
            return $empty;
        }

        $tokens = $this->tokens($rawQuery);
        if ($tokens === []) {
            return $empty;
        }

        $zones = $this->zonesFor($actor);
        if ($zones === []) {
            return $empty;
        }

        $query = Resident::query()->with('household');

        foreach ($tokens as $token) {
            $like = $this->escapeLike($token).'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('middle_name', 'like', $like);
            });
        }

        $residentTable = (new Resident)->getTable();
        if (Schema::hasColumn($residentTable, 'deleted_at')) {
            $query->whereNull($residentTable.'.deleted_at');
        }

        $query->whereHas('household', function (Builder $household) use ($zones): void {
            $table = (new Household)->getTable();
            if (Schema::hasColumn($table, 'household_no')) {
                $household->where('household_no', '!=', NonResidentFamilyPlanningService::SENTINEL_HOUSEHOLD_NO);
            }
            if (Schema::hasColumn($table, 'deleted_at')) {
                $household->whereNull($table.'.deleted_at');
            }
            if ($zones !== ['*']) {
                $household->where(function (Builder $inZone) use ($zones): void {
                    foreach ($zones as $zone) {
                        $inZone->orWhere(static fn (Builder $z) => HouseholdZoneResolver::applyZoneLabelScope($z, $zone));
                    }
                });
            }
        });

        $rows = $query->orderBy('last_name')->orderBy('first_name')->limit(self::MAX_RESULTS + 1)->get();

        $items = $rows->take(self::MAX_RESULTS)->map(fn (Resident $r): array => $this->present($r))->values()->all();

        return ['items' => $items, 'more' => $rows->count() > self::MAX_RESULTS];
    }

    /**
     * @return list<string>  ['*'] for every zone
     */
    public function zonesFor(User $actor): array
    {
        if (StaffRole::normalize($actor->role) === StaffRole::ADMIN) {
            return ['*'];
        }

        $appointment = $actor->currentAppointment;

        return $appointment !== null ? array_values($appointment->assignedZoneLabels()) : [];
    }

    /**
     * @return list<string>
     */
    private function tokens(string $raw): array
    {
        $clean = trim(preg_replace('/\s+/u', ' ', mb_substr($raw, 0, 100)) ?? '');
        if (mb_strlen(str_replace(' ', '', $clean)) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $tokens = array_values(array_filter(
            explode(' ', $clean),
            static fn (string $t): bool => mb_strlen($t) >= 1
        ));

        return array_slice($tokens, 0, 4);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return array{name: string, zone: string, household_no: string, url: string|null}
     */
    private function present(Resident $resident): array
    {
        $household = $resident->household;
        $householdNo = (string) ($household?->household_no ?? '');
        $middle = trim((string) ($resident->middle_name ?? ''));
        $name = trim((string) $resident->last_name).', '.trim((string) $resident->first_name)
            .($middle !== '' ? ' '.mb_substr($middle, 0, 1).'.' : '');

        $zone = '';
        if ($household instanceof Household) {
            try {
                $zone = HouseholdZoneResolver::displayLabelFromStoredValue(
                    HouseholdZoneResolver::storedValueFromHousehold($household)
                );
            } catch (Throwable) {
                $zone = '';
            }
        }

        $url = null;
        $memberNo = (string) ($resident->member_no ?? '');
        if ($householdNo !== '' && $memberNo !== '') {
            try {
                $url = route('household-profiling.members.show', ['householdNo' => $householdNo, 'memberId' => $memberNo]);
            } catch (Throwable) {
                $url = null;
            }
        }

        return ['name' => $name, 'zone' => $zone, 'household_no' => $householdNo, 'url' => $url];
    }
}
