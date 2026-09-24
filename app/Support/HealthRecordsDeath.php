<?php

namespace App\Support;

use App\Models\DeathRequest;
use App\Models\Household;
use App\Models\Resident;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Health Records → Death listing helpers.
 * Listing rows come from persisted death_requests. Resident candidates come from DB residents.
 */
final class HealthRecordsDeath
{
    public const EMPTY = '—';

    public const LISTING_PER_PAGE = 7;

    /**
     * Distinct non-empty zones from persisted households.
     *
     * @return list<string>
     */
    public static function zones(): array
    {
        $column = Schema::hasColumn((new Household)->getTable(), 'zone')
            ? 'zone'
            : (Schema::hasColumn((new Household)->getTable(), 'purok') ? 'purok' : null);

        if ($column === null) {
            return [];
        }

        return Household::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(static fn (mixed $zone): string => trim((string) $zone))
            ->filter(static fn (string $zone): bool => $zone !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Builder<DeathRequest>
     */
    public static function listingQuery(): Builder
    {
        $query = DeathRequest::query();

        if (DeathRecordsErdMode::isActive()) {
            return $query
                ->with(['resident.household'])
                ->orderByDesc(DeathRecordsErdMode::submittedAtColumn());
        }

        return $query
            ->with('resident')
            ->orderByDesc('submitted_at');
    }

    /**
     * @return array{search: string, zone: string, cause: string, sex: string, year: string, month: string}
     */
    public static function listingFiltersFromRequest(Request $request): array
    {
        return [
            'search' => trim((string) $request->query('search', '')),
            'zone' => (string) $request->query('zone', 'all'),
            'cause' => (string) $request->query('cause', 'all'),
            'sex' => (string) $request->query('sex', 'all'),
            'year' => (string) $request->query('year', 'all'),
            'month' => (string) $request->query('month', 'all'),
        ];
    }

    /**
     * @param  Builder<DeathRequest>  $query
     * @param  array{search?: string, zone?: string, cause?: string, sex?: string, year?: string, month?: string}  $filters
     * @return Builder<DeathRequest>
     */
    public static function applyListingFilters(Builder $query, array $filters): Builder
    {
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $zone = (string) ($filters['zone'] ?? 'all');
        $cause = (string) ($filters['cause'] ?? 'all');
        $sex = (string) ($filters['sex'] ?? 'all');
        $year = (string) ($filters['year'] ?? 'all');
        $month = (string) ($filters['month'] ?? 'all');

        if ($search !== '') {
            if (DeathRecordsErdMode::isActive()) {
                $query->where(function (Builder $builder) use ($search): void {
                    $builder->whereHas('resident', function (Builder $residentQuery) use ($search): void {
                        $residentQuery->whereRaw(
                            'LOWER(CONCAT(COALESCE(first_name, \'\'), \' \', COALESCE(last_name, \'\'))) LIKE ?',
                            ['%'.$search.'%']
                        );
                    });

                    $residentId = ResidentMemberIdentity::parseResidentIdFromMemberId($search);
                    if ($residentId !== null) {
                        $builder->orWhere('resident_id', $residentId);
                    }
                });
            } else {
                $query->where(function (Builder $builder) use ($search): void {
                    $builder
                        ->whereRaw('LOWER(resident_name) LIKE ?', ['%'.$search.'%'])
                        ->orWhereRaw('LOWER(member_id) LIKE ?', ['%'.$search.'%']);
                });
            }
        }

        if ($zone !== 'all' && $zone !== '') {
            if (DeathRecordsErdMode::isActive()) {
                $zoneColumn = Schema::hasColumn((new Household)->getTable(), 'zone') ? 'zone' : 'purok';
                $query->whereHas('resident.household', function (Builder $householdQuery) use ($zone, $zoneColumn): void {
                    $householdQuery->where($zoneColumn, $zone);
                });
            } else {
                $query->where('zone', $zone);
            }
        }

        if ($cause !== 'all' && $cause !== '') {
            self::whereCauseOfDeath($query, $cause);
        }

        if ($sex === 'female') {
            if (DeathRecordsErdMode::isActive()) {
                $query->whereHas('resident', function (Builder $residentQuery): void {
                    $residentQuery->whereIn('sex', ['Female', 'female', 'F', 'f', 'Woman', 'woman', 'Girl', 'girl', 'Female/Girl', 'female/girl']);
                });
            } else {
                $query->whereIn('resident_sex', ['Female', 'female', 'F', 'f', 'Woman', 'woman', 'Girl', 'girl', 'Female/Girl', 'female/girl']);
            }
        } elseif ($sex === 'male') {
            if (DeathRecordsErdMode::isActive()) {
                $query->whereHas('resident', function (Builder $residentQuery): void {
                    $residentQuery->whereIn('sex', ['Male', 'male', 'M', 'm', 'Man', 'man', 'Boy', 'boy', 'Male/Boy', 'male/boy']);
                });
            } else {
                $query->whereIn('resident_sex', ['Male', 'male', 'M', 'm', 'Man', 'man', 'Boy', 'boy', 'Male/Boy', 'male/boy']);
            }
        }

        if ($year !== 'all' && $year !== '') {
            $query->whereYear('date_of_death', $year);
        }

        if ($month !== 'all' && $month !== '') {
            $query->whereMonth('date_of_death', (int) $month);
        }

        return $query;
    }

    /**
     * cause_of_death is ciphertext at rest, so match on decrypted values in
     * PHP (case-insensitive, like the former SQL comparison) and filter by key.
     *
     * @param  Builder<DeathRequest>  $query
     */
    private static function whereCauseOfDeath(Builder $query, string $cause): void
    {
        $keyName = (new DeathRequest)->getKeyName();
        $needle = trim($cause);

        $keys = DeathRequest::query()
            ->get([$keyName, 'cause_of_death'])
            ->filter(static fn (DeathRequest $row): bool => strcasecmp(trim((string) $row->cause_of_death), $needle) === 0)
            ->map(static fn (DeathRequest $row): mixed => $row->getKey())
            ->values()
            ->all();

        $query->whereIn($query->getModel()->qualifyColumn($keyName), $keys);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function paginatedListing(Request $request, ?int $perPage = null): LengthAwarePaginator
    {
        $perPage = $perPage ?? self::LISTING_PER_PAGE;
        $filters = self::listingFiltersFromRequest($request);
        $query = self::applyListingFilters(self::listingQuery(), $filters);
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, (int) $request->query('page', 1));
        if ($page > $lastPage) {
            $page = $lastPage;
        }

        /** @var LengthAwarePaginator<int, array<string, mixed>> $paginator */
        $paginator = $query
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString()
            ->through(fn (DeathRequest $row): array => self::fromRequest($row));

        return $paginator;
    }

    /**
     * Filtered Death Records for export (not limited to the current pagination page).
     *
     * @return list<array<string, mixed>>
     */
    public static function filteredListingRows(Request $request): array
    {
        $filters = self::listingFiltersFromRequest($request);

        return self::applyListingFilters(self::listingQuery(), $filters)
            ->get()
            ->map(fn (DeathRequest $row): array => self::fromRequest($row))
            ->all();
    }

    /**
     * Admin-verified (approved) death records only, filtered by zone/year/
     * month — the Death Report Builder's data source.
     *
     * @return list<array<string, mixed>>
     */
    public static function verifiedExportRows(?string $zone = null, ?string $year = null, ?string $month = null): array
    {
        $query = self::listingQuery()->approved();

        $filters = array_filter([
            'zone' => $zone,
            'year' => $year,
            'month' => $month,
        ], static fn (?string $value): bool => $value !== null);

        return self::applyListingFilters($query, $filters)
            ->get()
            ->map(fn (DeathRequest $row): array => self::fromRequest($row))
            ->all();
    }

    /**
     * @param  array{search?: string, zone?: string, cause?: string, sex?: string, year?: string, month?: string}  $filters
     * @return list<string>
     */
    public static function filterLabels(array $filters): array
    {
        $labels = [];
        $search = trim((string) ($filters['search'] ?? ''));
        $zone = (string) ($filters['zone'] ?? 'all');
        $cause = (string) ($filters['cause'] ?? 'all');
        $sex = (string) ($filters['sex'] ?? 'all');
        $year = (string) ($filters['year'] ?? 'all');
        $month = (string) ($filters['month'] ?? 'all');

        if ($search !== '') {
            $labels[] = 'Search: '.$search;
        }
        if ($zone !== 'all' && $zone !== '') {
            $labels[] = 'Zone: '.$zone;
        }
        if ($cause !== 'all' && $cause !== '') {
            $labels[] = 'Cause of Death: '.$cause;
        }
        if ($sex === 'female') {
            $labels[] = 'Sex: Female';
        } elseif ($sex === 'male') {
            $labels[] = 'Sex: Male';
        }
        if ($year !== 'all' && $year !== '') {
            $labels[] = 'Year: '.$year;
        }
        if ($month !== 'all' && $month !== '') {
            $labels[] = 'Month: '.self::monthLabel($month);
        }

        return $labels;
    }

    /**
     * @param  array{search?: string, zone?: string, cause?: string, sex?: string, year?: string, month?: string}  $filters
     * @return array<string, string>
     */
    public static function exportQuery(array $filters): array
    {
        return array_filter(
            $filters,
            static fn (string $value): bool => $value !== '' && $value !== 'all'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listingRows(): array
    {
        return self::listingQuery()
            ->get()
            ->map(fn (DeathRequest $row): array => self::fromRequest($row))
            ->all();
    }

    /**
     * Persisted residents a health worker may open a Death form for.
     *
     * @return list<array<string, mixed>>
     */
    public static function residentCandidates(): array
    {
        $latestByMember = DeathRequest::query()
            ->with(['resident.household'])
            ->when(
                DeathRecordsErdMode::isActive(),
                fn (Builder $builder) => $builder->orderByDesc('created_at'),
                fn (Builder $builder) => $builder->orderByDesc((new DeathRequest)->getKeyName())
            )
            ->get()
            ->unique(static fn (DeathRequest $row): string => $row->household_no.'|'.$row->member_id)
            ->keyBy(static fn (DeathRequest $row): string => $row->household_no.'|'.$row->member_id);

        $approved = array_fill_keys(ResidentVitalStatus::deceasedKeys(), true);
        $rows = [];

        $residentKey = (new Resident)->getKeyName();
        $residents = Resident::query()
            ->with('household')
            ->whereHas('household')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->when(
                ResidentMemberIdentity::hasMemberNoColumn(),
                fn (Builder $builder) => $builder->orderBy('member_no'),
                fn (Builder $builder) => $builder->orderBy($residentKey)
            )
            ->get();

        foreach ($residents as $resident) {
            $household = $resident->household;
            if ($household === null) {
                continue;
            }

            $member = HouseholdProfilingPresenter::memberFromModel($resident);
            $householdPresentation = HouseholdProfilingPresenter::fromModel($household);
            $hh = DemoCatalog::normalizeHouseholdNo((string) $household->household_no);
            $memberId = ResidentMemberIdentity::memberIdFor($resident);
            if ($memberId === '') {
                continue;
            }

            $key = $hh.'|'.$memberId;
            $latest = $latestByMember->get($key);
            $isDeceased = isset($approved[$key]);
            $fullName = (string) ($member['name'] ?? HouseholdProfilingPresenter::fullName($resident));
            $sex = (string) ($member['sex'] ?? '');
            $age = $member['age'] !== null && $member['age'] !== '' ? (string) $member['age'] : self::EMPTY;
            $relationship = (string) ($member['relationship'] ?? $member['relation'] ?? '');
            $birthday = function_exists('lml_demo_member_display')
                ? lml_demo_member_display($member, 'birthday')
                : (string) ($member['birthday'] ?? '');

            $rows[] = [
                'household_no' => $hh,
                'member_id' => $memberId,
                'full_name' => $fullName,
                'sex' => $sex,
                'age' => $age,
                'relationship' => $relationship,
                'birthday_display' => $birthday,
                'identity_search' => strtolower(trim(implode(' ', array_filter([
                    $fullName,
                    $memberId,
                    $relationship,
                    $sex,
                    $birthday,
                ])))),
                'zone' => self::householdZoneLabel($household, $householdPresentation),
                'household_display' => (string) ($householdPresentation['displayNo'] ?? $hh),
                'vital_label' => $isDeceased
                    ? ResidentVitalStatus::DECEASED
                    : ($latest?->statusLabel() ?? 'Active'),
                'status' => $latest?->status ?? 'none',
                'open_url' => route('health-records.death.show', [
                    'householdNo' => $hh,
                    'memberId' => $memberId,
                ]),
                'can_submit' => ! $isDeceased && ($latest === null || $latest->isRejected()),
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                $byName = strnatcasecmp((string) $a['full_name'], (string) $b['full_name']);

                return $byName !== 0
                    ? $byName
                    : strnatcasecmp((string) $a['member_id'], (string) $b['member_id']);
            }
        );

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $householdPresentation
     */
    private static function householdZoneLabel(Household $household, array $householdPresentation): string
    {
        $table = $household->getTable();

        if (Schema::hasColumn($table, 'zone')) {
            $zone = trim((string) ($household->getAttributes()['zone'] ?? ''));

            return $zone !== '' ? $zone : trim((string) ($householdPresentation['zone'] ?? ''));
        }

        if (Schema::hasColumn($table, 'purok')) {
            return trim((string) ($household->getAttributes()['purok'] ?? ''));
        }

        return trim((string) ($householdPresentation['zone'] ?? $householdPresentation['purok'] ?? ''));
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $letters !== '' ? $letters : '?';
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return array{total: int, female: int, male: int, pending: int}
     */
    public static function summaryCounts(?array $rows = null): array
    {
        $rows ??= self::listingRows();
        $approved = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['status'] ?? '') === DeathRequest::STATUS_APPROVED
        ));
        $female = 0;
        $male = 0;

        foreach ($approved as $row) {
            $sex = (string) ($row['sex'] ?? '');
            if (HealthRecordsMaternal::isFemaleSex($sex)) {
                $female++;
            } elseif (HealthRecordsMaternal::isMaleSex($sex)) {
                $male++;
            }
        }

        $pending = count(array_filter(
            $rows,
            static fn (array $row): bool => ($row['status'] ?? '') === DeathRequest::STATUS_PENDING
        ));

        return [
            'total' => count($approved),
            'female' => $female,
            'male' => $male,
            'pending' => $pending,
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return list<string>
     */
    public static function years(?array $rows = null): array
    {
        $rows ??= self::listingRows();
        $years = [];

        foreach ($rows as $row) {
            $year = trim((string) ($row['year'] ?? ''));
            if ($year !== '') {
                $years[$year] = true;
            }
        }

        $list = array_map('strval', array_keys($years));
        rsort($list, SORT_NUMERIC);

        return $list;
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return list<string>
     */
    public static function causes(?array $rows = null): array
    {
        $rows ??= self::listingRows();
        $causes = [];

        foreach ($rows as $row) {
            $cause = trim((string) ($row['cause_of_death'] ?? ''));
            if ($cause !== '' && $cause !== self::EMPTY) {
                $causes[$cause] = true;
            }
        }

        $list = array_keys($causes);
        natcasesort($list);

        return array_values($list);
    }

    /**
     * Same matching rules as the listing's client-side filters.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array{search?: string, zone?: string, cause?: string, sex?: string, year?: string, month?: string}  $filters
     * @return list<array<string, mixed>>
     */
    public static function filterRows(array $rows, array $filters): array
    {
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $zone = (string) ($filters['zone'] ?? 'all');
        $cause = (string) ($filters['cause'] ?? 'all');
        $sex = (string) ($filters['sex'] ?? 'all');
        $year = (string) ($filters['year'] ?? 'all');
        $month = (string) ($filters['month'] ?? 'all');

        $matched = [];

        foreach ($rows as $row) {
            $name = strtolower(trim(
                (string) ($row['full_name'] ?? '').' '.(string) ($row['member_id'] ?? '')
            ));
            $rowZone = (string) ($row['zone'] ?? '');
            $rowCause = (string) ($row['cause_of_death'] ?? '');
            $rowSex = (string) ($row['sex_filter'] ?? '');
            $rowYear = (string) ($row['year'] ?? '');
            $rowMonth = (string) ($row['month'] ?? '');

            $matchesSearch = $search === '' || str_contains($name, $search);
            $matchesZone = $zone === 'all' || $rowZone === $zone;
            $matchesCause = $cause === 'all' || $rowCause === $cause;
            $matchesSex = $sex === 'all' || $rowSex === $sex;
            $matchesYear = $year === 'all' || $rowYear === $year;
            $matchesMonth = $month === 'all' || $rowMonth === $month;

            if ($matchesSearch && $matchesZone && $matchesCause && $matchesSex && $matchesYear && $matchesMonth) {
                $matched[] = $row;
            }
        }

        return $matched;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function monthOptions(): array
    {
        return [
            ['value' => '01', 'label' => 'January'],
            ['value' => '02', 'label' => 'February'],
            ['value' => '03', 'label' => 'March'],
            ['value' => '04', 'label' => 'April'],
            ['value' => '05', 'label' => 'May'],
            ['value' => '06', 'label' => 'June'],
            ['value' => '07', 'label' => 'July'],
            ['value' => '08', 'label' => 'August'],
            ['value' => '09', 'label' => 'September'],
            ['value' => '10', 'label' => 'October'],
            ['value' => '11', 'label' => 'November'],
            ['value' => '12', 'label' => 'December'],
        ];
    }

    public static function monthLabel(string $month): string
    {
        $normalized = str_pad(trim($month), 2, '0', STR_PAD_LEFT);

        foreach (self::monthOptions() as $option) {
            if ($option['value'] === $normalized) {
                return $option['label'];
            }
        }

        return $month;
    }

    /**
     * "All Time" / "Year 2026" / "September 2026" — depends on which of
     * the year/month filters are active. Used for the Death report's
     * summary period label.
     */
    public static function periodLabel(string $year, string $month): string
    {
        $year = trim($year);
        $month = trim($month);

        if ($year === '' || $year === 'all') {
            return 'All Time';
        }

        if ($month !== '' && $month !== 'all') {
            return self::monthLabel($month).' '.$year;
        }

        return 'Year '.$year;
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(DeathRequest $request): array
    {
        $iso = $request->date_of_death?->format('Y-m-d') ?? '';
        $year = preg_match('/^(\d{4})-/', $iso, $match) ? $match[1] : '';
        $month = preg_match('/^\d{4}-(\d{2})-/', $iso, $monthMatch) ? $monthMatch[1] : '';
        $sex = (string) $request->resident_sex;
        $birthdayIso = '';
        $resident = $request->relationLoaded('resident') ? $request->resident : null;
        if ($resident !== null && $resident->birthday !== null) {
            $birthdayIso = $resident->birthday->format('Y-m-d');
        }
        $birthdayDisplay = $birthdayIso !== ''
            ? (DisplayDate::format($birthdayIso) ?: self::EMPTY)
            : self::EMPTY;

        return [
            'key' => (string) $request->id,
            'request_id' => $request->id,
            'household_no' => $request->household_no,
            'member_id' => $request->member_id,
            'full_name' => $request->resident_name,
            'age' => $request->resident_age !== null ? (string) $request->resident_age : self::EMPTY,
            'birthday' => $birthdayDisplay,
            'birthday_iso' => $birthdayIso,
            'sex' => $sex !== '' ? $sex : self::EMPTY,
            'sex_filter' => HealthRecordsMaternal::isFemaleSex($sex)
                ? 'female'
                : (HealthRecordsMaternal::isMaleSex($sex) ? 'male' : ''),
            'zone' => (string) ($request->zone ?: self::EMPTY),
            'cause_of_death' => $request->cause_of_death,
            'date_of_death' => $request->formattedDateOfDeath(),
            'date_of_death_iso' => $iso,
            'year' => $year,
            'month' => $month,
            'registry_no' => $request->displayRegistryNo() !== '' ? $request->displayRegistryNo() : self::EMPTY,
            'status' => $request->status,
            'status_label' => $request->statusLabel(),
            'open_url' => route('health-records.death.show', [
                'householdNo' => $request->household_no,
                'memberId' => $request->member_id,
            ]),
        ];
    }
}
