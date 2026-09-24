<?php

namespace App\Services;

use App\Models\MaternalPregnancy;
use App\Models\Resident;
use App\Support\AnnouncementAgePreset;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only Announcement audience matching.
 *
 * Resolves official resident rows from server-side criteria only.
 * Does not accept client resident_ids/account_ids, write DB rows,
 * create notifications, or persist announcements.
 */
final class AnnouncementAudienceMatcher
{
    public const TARGET_ALL = 'all';

    public const TARGET_AGE = 'age';

    public const TARGET_ACTIVE_MATERNAL = 'active_maternal';

    public const TARGET_ACTIVE_FP_USER = 'active_fp_user';

    public const ZONE_ALL = 'all';

    public const ZONE_SPECIFIC = 'specific';

    /**
     * @param  array{
     *     target_group: string,
     *     age_presets?: list<string>,
     *     age_range_months?: array{min?: int|null, max?: int|null},
     *     zone_mode: string,
     *     zones?: list<string|int>,
     *     as_of?: CarbonInterface|string|null
     * }  $criteria
     * @return Builder<Resident>
     */
    public function matchingQuery(array $criteria): Builder
    {
        $this->assertNoClientRecipientAuthority($criteria);

        $targetGroup = $this->normalizeTargetGroup($criteria['target_group'] ?? null);
        $zoneMode = $this->normalizeZoneMode($criteria['zone_mode'] ?? null);
        $asOf = $this->resolveAsOf($criteria['as_of'] ?? null);

        $query = Resident::query()->whereHas('household', function (Builder $householdQuery) use ($zoneMode, $criteria): void {
            if ($zoneMode === self::ZONE_SPECIFIC) {
                $this->applyZoneCoverage($householdQuery, $criteria['zones'] ?? []);
            }
        });

        match ($targetGroup) {
            self::TARGET_ALL => null,
            self::TARGET_AGE => $this->applyAgeTarget(
                $query,
                $criteria['age_presets'] ?? [],
                $criteria['age_range_months'] ?? null,
                $asOf,
            ),
            self::TARGET_ACTIVE_MATERNAL => $this->applyActiveMaternal($query),
            self::TARGET_ACTIVE_FP_USER => $this->applyActiveFpUser($query),
        };

        return $query;
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return Collection<int, int|string>
     */
    public function matchingResidentKeys(array $criteria): Collection
    {
        $pk = (new Resident)->getKeyName();

        return $this->matchingQuery($criteria)
            ->orderBy("residents.{$pk}")
            ->pluck($pk)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    public function count(array $criteria): int
    {
        return (int) $this->matchingQuery($criteria)->count();
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function assertNoClientRecipientAuthority(array $criteria): void
    {
        foreach (['resident_ids', 'account_ids', 'resident_id', 'account_id'] as $forbidden) {
            if (array_key_exists($forbidden, $criteria)) {
                throw new InvalidArgumentException(
                    'Client-supplied recipient IDs are not accepted for announcement targeting.'
                );
            }
        }
    }

    private function normalizeTargetGroup(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        if ($normalized === 'active_fp') {
            $normalized = self::TARGET_ACTIVE_FP_USER;
        }

        if (! in_array($normalized, [
            self::TARGET_ALL,
            self::TARGET_AGE,
            self::TARGET_ACTIVE_MATERNAL,
            self::TARGET_ACTIVE_FP_USER,
        ], true)) {
            throw new InvalidArgumentException("Unsupported announcement target_group [{$value}].");
        }

        return $normalized;
    }

    private function normalizeZoneMode(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        if (! in_array($normalized, [self::ZONE_ALL, self::ZONE_SPECIFIC], true)) {
            throw new InvalidArgumentException("Unsupported announcement zone_mode [{$value}].");
        }

        return $normalized;
    }

    private function resolveAsOf(CarbonInterface|string|null $asOf): CarbonInterface
    {
        if ($asOf instanceof CarbonInterface) {
            return $asOf->copy()->startOfDay();
        }

        if (is_string($asOf) && trim($asOf) !== '') {
            return Carbon::parse($asOf)->startOfDay();
        }

        return Carbon::today()->startOfDay();
    }

    /**
     * @param  Builder<\App\Models\Household>  $householdQuery
     * @param  list<string|int>  $zones
     */
    private function applyZoneCoverage(Builder $householdQuery, array $zones): void
    {
        $normalized = collect($zones)
            ->map(fn ($zone) => $this->normalizeZoneLabel((string) $zone))
            ->filter(fn (string $zone) => $zone !== '')
            ->unique()
            ->values();

        if ($normalized->isEmpty()) {
            throw new InvalidArgumentException('Specific zone coverage requires at least one zone.');
        }

        $column = $this->householdZoneColumn();

        $householdQuery->where(function (Builder $outer) use ($normalized, $column): void {
            foreach ($normalized as $zone) {
                $outer->orWhereRaw(
                    'LOWER(TRIM('.$outer->qualifyColumn($column).')) = ?',
                    [strtolower($zone)]
                );
            }
        });
    }

    private function householdZoneColumn(): string
    {
        if (Schema::hasColumn('households', 'purok')) {
            return 'purok';
        }

        if (Schema::hasColumn('households', 'zone')) {
            return 'zone';
        }

        throw new RuntimeException('Households table has no purok/zone column for announcement zone targeting.');
    }

    /**
     * Normalize UI/custom zone labels for comparison with households.purok|zone.
     * Accepts "1", "Zone 1", and free-text custom labels.
     */
    public function normalizeZoneLabel(string $raw): string
    {
        $trimmed = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);

        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^(\d+)$/', $trimmed, $matches) === 1) {
            return 'Zone '.$matches[1];
        }

        if (preg_match('/^zone\s*(\d+)$/i', $trimmed, $matches) === 1) {
            return 'Zone '.$matches[1];
        }

        return $trimmed;
    }

    /**
     * @param  Builder<Resident>  $query
     * @param  list<string>  $presets
     * @param  array{min?: int|null, max?: int|null}|null  $customRange
     */
    private function applyAgeTarget(
        Builder $query,
        array $presets,
        ?array $customRange,
        CarbonInterface $asOf,
    ): void {
        $ranges = [];

        foreach ($presets as $preset) {
            $key = strtolower(trim((string) $preset));
            if ($key === '') {
                continue;
            }
            $ranges[] = AnnouncementAgePreset::rangeFor($key);
        }

        if (is_array($customRange)) {
            $min = array_key_exists('min', $customRange) ? $customRange['min'] : null;
            $max = array_key_exists('max', $customRange) ? $customRange['max'] : null;

            if ($min !== null || $max !== null) {
                if ($min !== null && $max !== null && (int) $min > (int) $max) {
                    throw new InvalidArgumentException('Custom age range min_months cannot exceed max_months.');
                }

                $ranges[] = [
                    $min === null ? 0 : (int) $min,
                    $max === null ? null : (int) $max,
                ];
            }
        }

        if ($ranges === []) {
            throw new InvalidArgumentException(
                'Age targeting requires at least one age preset or a custom month range.'
            );
        }

        $query->where(function (Builder $outer) use ($ranges, $asOf): void {
            foreach ($ranges as [$minMonths, $maxMonths]) {
                $outer->orWhere(function (Builder $inner) use ($minMonths, $maxMonths, $asOf): void {
                    $this->applyCompletedMonthsRange($inner, (int) $minMonths, $maxMonths, $asOf);
                });
            }
        });
    }

    /**
     * Match residents whose completed age in months is in [min, max] inclusive.
     * max = null means no upper bound (seniors / open-ended custom).
     *
     * @param  Builder<Resident>  $query
     */
    private function applyCompletedMonthsRange(
        Builder $query,
        int $minMonths,
        ?int $maxMonths,
        CarbonInterface $asOf,
    ): void {
        if ($minMonths < 0) {
            throw new InvalidArgumentException('Minimum age months cannot be negative.');
        }

        $query->whereDate(
            'birthday',
            '<=',
            $asOf->copy()->subMonthsNoOverflow($minMonths)->toDateString()
        );

        $query->whereDate('birthday', '<=', $asOf->toDateString());

        if ($maxMonths !== null) {
            if ($maxMonths < 0) {
                throw new InvalidArgumentException('Maximum age months cannot be negative.');
            }

            $query->whereDate(
                'birthday',
                '>',
                $asOf->copy()->subMonthsNoOverflow($maxMonths + 1)->toDateString()
            );
        }
    }

    /**
     * @param  Builder<Resident>  $query
     */
    private function applyActiveMaternal(Builder $query): void
    {
        $residentPk = (new Resident)->getKeyName();

        if (Schema::hasTable('maternal_pregnancies') && Schema::hasColumn('maternal_pregnancies', 'status')) {
            $query->whereExists(function ($exists) use ($residentPk): void {
                $exists->selectRaw('1')
                    ->from('maternal_pregnancies')
                    ->whereColumn('maternal_pregnancies.resident_id', "residents.{$residentPk}")
                    ->where('maternal_pregnancies.status', MaternalPregnancy::STATUS_ACTIVE);
            });

            return;
        }

        if (Schema::hasTable('maternal_care') && Schema::hasColumn('maternal_care', 'pregnancy_status')) {
            $query->whereExists(function ($exists) use ($residentPk): void {
                $exists->selectRaw('1')
                    ->from('maternal_care')
                    ->whereColumn('maternal_care.resident_id', "residents.{$residentPk}")
                    ->where('maternal_care.pregnancy_status', 'Active');
            });

            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * Active FP User — demographic current-user flag.
     * Live MySQL: residents.is_fp_user = 1
     * Laravel persistence foundation / tests: residents.fp_user = 'Yes'
     * family_planning visits are history only and are not the authority.
     *
     * @param  Builder<Resident>  $query
     */
    private function applyActiveFpUser(Builder $query): void
    {
        if (Schema::hasColumn('residents', 'is_fp_user')) {
            $query->where('is_fp_user', 1);

            return;
        }

        if (Schema::hasColumn('residents', 'fp_user')) {
            $query->where('fp_user', 'Yes');

            return;
        }

        throw new RuntimeException(
            'Neither residents.is_fp_user nor residents.fp_user exists for Active FP User targeting.'
        );
    }
}
