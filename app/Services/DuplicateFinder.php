<?php

namespace App\Services;

use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Support\HouseholdZoneResolver;
use App\Support\NonResidentFamilyPlanningService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Possible-duplicate lookup for household members and household heads.
 *
 * A match needs the same first name, last name (case, spacing and accents ignored, middle name ignored) AND the
 * same birthday. Each item carries only name, relation, household number and a member-page link (never birth
 * date, health or contact data). Deleted rows and the non-resident sentinel household are ignored.
 */
final class DuplicateFinder
{
    public const MAX_RESULTS = 3;

    /**
     * Residents of $household matching the submitted identity.
     *
     * @return list<array{name: string, relation: string, household_no: string, url: string|null}>
     */
    public function forMember(Household $household, string $firstName, string $lastName, ?string $birthday, ?int $excludeResidentId = null): array
    {
        $date = $this->normalizeBirthday($birthday);
        if ($date === null) {
            return [];
        }

        $query = $this->baseQuery($date)
            ->where('household_id', $household->getKey());

        if ($excludeResidentId !== null) {
            $query->whereKeyNot($excludeResidentId);
        }

        return $this->collect($query, $firstName, $lastName);
    }

    /**
     * Residents in any household the actor may access matching the submitted head identity.
     *
     * @return list<array{name: string, relation: string, household_no: string, url: string|null}>
     */
    public function forHead(User $actor, string $firstName, string $lastName, ?string $birthday): array
    {
        $date = $this->normalizeBirthday($birthday);
        if ($date === null || ! StaffNameSuggestionService::mayUse($actor)) {
            return [];
        }

        $zones = app(StaffNameSuggestionService::class)->zonesFor($actor);
        if ($zones === []) {
            return [];
        }

        $query = $this->baseQuery($date)
            ->whereHas('household', function (Builder $household) use ($zones): void {
                $table = (new Household)->getTable();
                if (Schema::hasColumn($table, 'household_no')) {
                    $household->where(static fn (Builder $q) => $q->whereNull('household_no')
                        ->orWhere('household_no', '!=', NonResidentFamilyPlanningService::SENTINEL_HOUSEHOLD_NO));
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

        return $this->collect($query, $firstName, $lastName);
    }

    /**
     * Trim, collapse whitespace, strip accents and lower-case a name part.
     */
    public static function normalizeName(?string $name): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $name));

        return mb_strtolower(Str::ascii($clean));
    }

    private function normalizeBirthday(?string $birthday): ?string
    {
        $birthday = trim((string) $birthday);
        if ($birthday === '') {
            return null;
        }

        try {
            return Carbon::parse($birthday)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return Builder<Resident>
     */
    private function baseQuery(string $date): Builder
    {
        $query = Resident::query()->with('household')->whereDate('birthday', $date);

        $table = (new Resident)->getTable();
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull($table.'.deleted_at');
        }

        return $query;
    }

    /**
     * @param  Builder<Resident>  $query
     * @return list<array{name: string, relation: string, household_no: string, url: string|null}>
     */
    private function collect(Builder $query, string $firstName, string $lastName): array
    {
        $first = self::normalizeName($firstName);
        $last = self::normalizeName($lastName);
        if ($first === '' || $last === '') {
            return [];
        }

        return $query->orderBy('last_name')->orderBy('first_name')->get()
            ->filter(static fn (Resident $r): bool => self::normalizeName($r->first_name) === $first
                && self::normalizeName($r->last_name) === $last)
            ->take(self::MAX_RESULTS)
            ->map(fn (Resident $r): array => $this->present($r))
            ->values()
            ->all();
    }

    /**
     * @return array{name: string, relation: string, household_no: string, url: string|null}
     */
    private function present(Resident $resident): array
    {
        $householdNo = (string) ($resident->household?->household_no ?? '');
        $middle = trim((string) ($resident->middle_name ?? ''));
        $name = trim((string) $resident->last_name).', '.trim((string) $resident->first_name)
            .($middle !== '' ? ' '.mb_substr($middle, 0, 1).'.' : '');

        $url = null;
        $memberNo = (string) ($resident->member_no ?? '');
        if ($householdNo !== '' && $memberNo !== '') {
            try {
                $url = route('household-profiling.members.show', ['householdNo' => $householdNo, 'memberId' => $memberNo]);
            } catch (Throwable) {
                $url = null;
            }
        }

        return [
            'name' => $name,
            'relation' => trim((string) ($resident->relation ?? '')),
            'household_no' => $householdNo,
            'url' => $url,
        ];
    }
}
