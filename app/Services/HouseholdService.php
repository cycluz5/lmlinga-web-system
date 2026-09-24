<?php

namespace App\Services;

use App\Models\Household;
use App\Models\Resident;
use App\Support\HouseholdNumber;
use App\Support\HouseholdProfilingPresenter;
use App\Support\HouseholdProfilingWriteGuard;
use App\Support\HouseholdShellWriteAdapter;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Household Profiling list/read helpers and shell create/update (DB-17 Phase 2A).
 *
 * The index list and summary cards both count persisted Household/Resident
 * records only. DemoCatalog is not merged into the operational list.
 * New household_no values are staff-entered 3-digit strings (not allocated).
 */
final class HouseholdService
{

    /**
     * @return list<array{id: string, householdNo: string, displayNo: string, houseHead: string, zone: string, street: string, members: int, male: int, female: int, source: 'db'}>
     */
    public function profilingListRows(): array
    {
        $rowsByNo = [];

        if ($this->householdsTableReady()) {
            $dbHouseholds = $this->profilingHouseholdQuery()
                ->with(['residents' => fn ($q) => Resident::eagerLoadForProfiling($q)])
                ->orderBy('household_no')
                ->get();

            foreach ($dbHouseholds as $household) {
                $row = HouseholdProfilingPresenter::listRowFromModel($household);
                $rowsByNo[$row['householdNo']] = $row;
            }
        }

        ksort($rowsByNo, SORT_NATURAL);

        return array_values($rowsByNo);
    }

    /**
     * DB-backed household rows for PDF export with optional list filters.
     *
     * @return list<array{householdNo: string, displayNo: string, houseHead: string, zone: string, street: string, members: int}>
     */
    public function profilingExportRows(?string $search = null, ?string $zone = null, ?string $street = null): array
    {
        $rows = $this->profilingListRows();
        $query = strtolower(trim((string) $search));
        $zoneFilter = trim((string) $zone);
        $streetFilter = trim((string) $street);

        return array_values(array_filter(
            $rows,
            static function (array $row) use ($query, $zoneFilter, $streetFilter): bool {
                if (($row['source'] ?? 'db') !== 'db') {
                    return false;
                }

                $matchesSearch = $query === ''
                    || str_contains(strtolower((string) ($row['houseHead'] ?? '')), $query);
                $matchesZone = $zoneFilter === '' || $zoneFilter === 'all'
                    || (string) ($row['zone'] ?? '') === $zoneFilter;
                $matchesStreet = $streetFilter === '' || $streetFilter === 'all'
                    || (string) ($row['street'] ?? '') === $streetFilter;

                return $matchesSearch && $matchesZone && $matchesStreet;
            }
        ));
    }

    /**
     * Flat per-member personal-information rows for the PDF export — one
     * row per resident (not per household), same filters as
     * profilingExportRows(). Sourced from HouseholdProfilingPresenter's
     * full member presentation (memberList), not the summary list rows.
     *
     * @return list<array<string, string>>
     */
    public function profilingMemberExportRows(?string $search = null, ?string $zone = null, ?string $street = null): array
    {
        if (! $this->householdsTableReady() || ! $this->residentsTableReady()) {
            return [];
        }

        $query = strtolower(trim((string) $search));
        $zoneFilter = trim((string) $zone);
        $streetFilter = trim((string) $street);

        $households = $this->profilingHouseholdQuery()
            ->with(['residents' => fn ($q) => Resident::eagerLoadForProfiling($q)])
            ->orderBy('household_no')
            ->get();

        $rows = [];
        foreach ($households as $household) {
            $presentation = HouseholdProfilingPresenter::fromModel($household);

            $matchesSearch = $query === ''
                || str_contains(strtolower((string) ($presentation['houseHead'] ?? '')), $query);
            $matchesZone = $zoneFilter === '' || $zoneFilter === 'all'
                || (string) ($presentation['zone'] ?? '') === $zoneFilter;
            $matchesStreet = $streetFilter === '' || $streetFilter === 'all'
                || (string) ($presentation['street'] ?? '') === $streetFilter;

            if (! $matchesSearch || ! $matchesZone || ! $matchesStreet) {
                continue;
            }

            $householdNo = (string) ($presentation['householdNo'] ?? '');
            $houseHead = (string) ($presentation['houseHead'] ?? '');
            $rowZone = (string) ($presentation['zone'] ?? '');
            $rowStreet = (string) ($presentation['street'] ?? '');

            foreach (($presentation['memberList'] ?? []) as $member) {
                if (! is_array($member)) {
                    continue;
                }

                $rows[] = [
                    'household_no' => $householdNo,
                    'house_head' => $houseHead,
                    'zone' => $rowZone,
                    'street' => $rowStreet,
                    'member_name' => self::formatMemberName($member),
                    'relationship' => (string) ($member['relationship'] ?? ''),
                    'age' => $member['age'] !== null ? (string) $member['age'] : '',
                    'sex' => (string) ($member['sex'] ?? ''),
                    'civil_status' => (string) ($member['relationship_status'] ?? ''),
                    'birthday' => self::formatMemberBirthday((string) ($member['birthday'] ?? '')),
                    'occupation' => (string) ($member['occupation'] ?? ''),
                    'monthly_income' => (string) ($member['monthly_income'] ?? ''),
                    'religion' => (string) ($member['religion'] ?? ''),
                    'education' => (string) ($member['education'] ?? ''),
                    'philhealth' => (string) ($member['philhealth'] ?? ''),
                    'fp_user' => (string) ($member['fp_user'] ?? ''),
                    'disability' => self::joinListField($member['disability'] ?? null),
                    'medical_history' => self::joinListField($member['medical_history'] ?? null),
                ];
            }
        }

        return $rows;
    }

    /**
     * "Last Name, First Name Middle Name" — same convention Environmental
     * Health exports use for household heads.
     *
     * @param  array<string, mixed>  $member
     */
    private static function formatMemberName(array $member): string
    {
        $first = trim((string) ($member['first_name'] ?? ''));
        $middle = trim((string) ($member['middle_name'] ?? ''));
        $last = trim((string) ($member['last_name'] ?? ''));

        $given = trim(implode(' ', array_filter([$first, $middle], static fn (string $p): bool => $p !== '')));

        if ($last === '') {
            return $given !== '' ? $given : (string) ($member['name'] ?? '');
        }

        return $given === '' ? $last : $last.', '.$given;
    }

    private static function formatMemberBirthday(string $isoDate): string
    {
        if ($isoDate === '') {
            return '';
        }

        try {
            return \Illuminate\Support\Carbon::parse($isoDate)->format('m/d/Y');
        } catch (\Throwable) {
            return $isoDate;
        }
    }

    private static function joinListField(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        return trim((string) $value);
    }

    /**
     * Registered (database) totals for summary cards.
     * DemoCatalog fallback rows are intentionally excluded.
     *
     * @return array{households: int, respondents: int, male: int, female: int}
     */
    public function profilingSummary(): array
    {
        if (! $this->householdsTableReady() || ! $this->residentsTableReady()) {
            return [
                'households' => 0,
                'respondents' => 0,
                'male' => 0,
                'female' => 0,
            ];
        }

        return [
            'households' => $this->profilingHouseholdQuery()->count(),
            'respondents' => $this->profilingResidentQuery()->count(),
            'male' => $this->profilingResidentQuery()->where('sex', 'Male')->count(),
            'female' => $this->profilingResidentQuery()->where('sex', 'Female')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(array $validated): Household
    {
        HouseholdProfilingWriteGuard::rejectHouseholdShellWrite();

        $payload = $this->normalizePayload($validated);
        $householdNo = $validated['household_no'] ?? null;

        if (! is_string($householdNo) || preg_match(HouseholdNumber::DIGITS_PATTERN, $householdNo) !== 1) {
            throw ValidationException::withMessages([
                'household_no' => HouseholdNumber::FORMAT_MESSAGE,
            ]);
        }

        if (HouseholdNumber::isOccupied($householdNo)) {
            throw ValidationException::withMessages([
                'household_no' => HouseholdNumber::DUPLICATE_MESSAGE,
            ]);
        }

        $payload = $this->applyUnplottedCoordinateSentinelForCreate($payload);

        return DB::transaction(function () use ($payload, $householdNo): Household {
            try {
                return Household::query()->create([
                    ...$payload,
                    'household_no' => $householdNo,
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueConstraintViolation($e)) {
                    throw ValidationException::withMessages([
                        'household_no' => HouseholdNumber::DUPLICATE_MESSAGE,
                    ]);
                }

                throw $e;
            }
        });
    }

    /**
     * Create household shell and initial Head in one transaction.
     * Used when a combined create workflow is invoked; rolls back both on failure.
     *
     * @param  array<string, mixed>  $householdValidated
     * @param  array<string, mixed>  $headValidated
     * @return array{household: Household, resident: Resident}
     */
    public function createWithHead(array $householdValidated, array $headValidated, ResidentService $residents): array
    {
        return DB::transaction(function () use ($householdValidated, $headValidated, $residents): array {
            $household = $this->create($householdValidated);
            $headPayload = array_merge($headValidated, ['relation' => 'Head']);
            $resident = $residents->create($household, $headPayload);

            return [
                'household' => $household->fresh(['residents']) ?? $household,
                'resident' => $resident,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(Household $household, array $validated): Household
    {
        HouseholdProfilingWriteGuard::rejectHouseholdShellWrite();

        // Inspect key presence before normalizePayload defaults omitted coords to null (R02-B).
        $hasLatitude = array_key_exists('latitude', $validated);
        $hasLongitude = array_key_exists('longitude', $validated);

        if ($hasLatitude !== $hasLongitude) {
            throw ValidationException::withMessages([
                'latitude' => 'Latitude and longitude must be provided together.',
                'longitude' => 'Latitude and longitude must be provided together.',
            ]);
        }

        if ($hasLatitude && $hasLongitude) {
            $latitudeEmpty = $validated['latitude'] === null || $validated['latitude'] === '';
            $longitudeEmpty = $validated['longitude'] === null || $validated['longitude'] === '';

            if ($latitudeEmpty !== $longitudeEmpty) {
                throw ValidationException::withMessages([
                    'latitude' => 'Latitude and longitude must both be set or both be cleared.',
                    'longitude' => 'Latitude and longitude must both be set or both be cleared.',
                ]);
            }
        }

        $payload = $this->normalizePayload($validated);

        if (! $hasLatitude && ! $hasLongitude) {
            unset($payload['latitude'], $payload['longitude']);
        }

        return DB::transaction(function () use ($household, $payload): Household {
            $locked = Household::query()->whereKey($household->id)->lockForUpdate()->firstOrFail();

            unset($payload['household_no'], $payload['id'], $payload['household_id']);

            $locked->fill($payload);
            $locked->save();

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Whether household archival delete is supported by the current schema.
     * Authoritative ERD (lmlinga_erd_reference) has no deleted_at column.
     * A deleted_at column alone is not enough: without SoftDeletes the model
     * would hard-delete and violate RESTRICT FKs on residents.
     */
    public function householdDeletionSupported(): bool
    {
        if (! $this->householdsTableReady()) {
            return false;
        }

        try {
            return Schema::hasColumn('households', 'deleted_at')
                && in_array(SoftDeletes::class, class_uses_recursive(Household::class), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Soft-delete an active household shell only when deleted_at exists (R02-A).
     * Does not cascade to residents or dependent health/environmental rows.
     * Never force-deletes. Unsupported on authoritative ERD schemas.
     */
    public function softDelete(Household $household): void
    {
        if (! $this->householdDeletionSupported()) {
            throw ValidationException::withMessages([
                'household' => 'Household deletion is not supported for the current database schema.',
            ]);
        }

        $household->delete();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizePayload(array $validated): array
    {
        return HouseholdShellWriteAdapter::persistableAttributes($validated);
    }

    /**
     * ERD households.latitude/longitude are NOT NULL. Blank create coords become
     * the existing Spot Mapping unplotted sentinel (0,0) — not SQL null.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyUnplottedCoordinateSentinelForCreate(array $payload): array
    {
        if (! Schema::hasColumn('households', 'latitude') || ! Schema::hasColumn('households', 'longitude')) {
            return $payload;
        }

        $latitude = $payload['latitude'] ?? null;
        $longitude = $payload['longitude'] ?? null;
        $latitudeEmpty = $latitude === null || $latitude === '';
        $longitudeEmpty = $longitude === null || $longitude === '';

        if ($latitudeEmpty && $longitudeEmpty) {
            $payload['latitude'] = 0;
            $payload['longitude'] = 0;
        }

        return $payload;
    }

    /**
     * True only for real unique-key collisions (e.g. MySQL 1062 / SQLite UNIQUE).
     * Must not treat generic SQLSTATE 23000 or incidental "household_no" SQL text
     * (such as NOT NULL latitude failures) as duplicates.
     */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if ($driverCode === 1062) {
            return true;
        }

        $message = $e->getMessage();

        return str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint failed');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Household>
     */
    private function profilingHouseholdQuery()
    {
        return Household::query()->excludingNonResidentSentinel();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Resident>
     */
    private function profilingResidentQuery()
    {
        $query = Resident::query();
        $sentinelId = Household::nonResidentSentinelKey();
        if ($sentinelId !== null) {
            $query->where('household_id', '!=', $sentinelId);
        }

        return $query;
    }

    private function householdsTableReady(): bool
    {
        try {
            return Schema::hasTable('households');
        } catch (\Throwable) {
            return false;
        }
    }

    private function residentsTableReady(): bool
    {
        try {
            return Schema::hasTable('residents');
        } catch (\Throwable) {
            return false;
        }
    }
}
