<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Persist Health Records → Family Planning → Non-Residents against the
 * authoritative ERD: residents (identity) + family_planning (visits) +
 * fp_commodities_given (commodities).
 *
 * family_planning.resident_id is NOT NULL, and residents.household_id is NOT NULL,
 * so walk-in clients are attached to a sentinel household (NR-FP) with 0,0
 * coordinates (excluded from plotted Spot Mapping markers).
 */
final class NonResidentFamilyPlanningService
{
    public const RELATION = 'Non-Resident';

    public const SENTINEL_HOUSEHOLD_NO = 'NR-FP';

    public const CLIENT_KEY_PREFIX = 'nr-';

    public static function isSentinelHouseholdNo(mixed $householdNo): bool
    {
        return strcasecmp(trim((string) $householdNo), self::SENTINEL_HOUSEHOLD_NO) === 0;
    }

    /**
     * @var list<string>
     */
    public const COMMODITY_ENUM = [
        'Pills',
        'Pills-Combined',
        'Condoms',
        'DMPA',
        'IUD',
        'Implant',
    ];

    public static function persistenceReady(): bool
    {
        return FamilyPlanningErdMode::isActive()
            && Schema::hasTable('residents')
            && Schema::hasColumn('residents', 'resident_id')
            && Schema::hasColumn('residents', 'relation_to_household_head')
            && Schema::hasColumn('residents', 'birthday')
            && Schema::hasColumn('residents', 'sex')
            && Schema::hasColumn('residents', 'civil_status')
            && Schema::hasTable('households');
    }

    public static function commoditiesTableReady(): bool
    {
        return Schema::hasTable('fp_commodities_given')
            && Schema::hasColumn('fp_commodities_given', 'fp_id')
            && Schema::hasColumn('fp_commodities_given', 'commodity_name')
            && Schema::hasColumn('fp_commodities_given', 'quantity');
    }

    public static function rejectIfUnavailable(): void
    {
        if (! self::persistenceReady()) {
            throw ValidationException::withMessages([
                'client' => 'Non-resident family planning cannot be saved with the current database configuration.',
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function loadClients(): array
    {
        if (! self::persistenceReady()) {
            return [];
        }

        $residentPk = 'resident_id';
        $fpPk = FamilyPlanningErdMode::primaryKey();
        $householdPk = Schema::hasColumn('households', 'household_id') ? 'household_id' : 'id';
        $zoneColumn = Schema::hasColumn('households', 'purok')
            ? 'purok'
            : (Schema::hasColumn('households', 'zone') ? 'zone' : null);

        $residentSelect = [
            'r.'.$residentPk.' as resident_id',
            'r.first_name',
            'r.middle_name',
            'r.last_name',
            'r.birthday',
            'r.sex',
            'r.civil_status',
        ];
        if ($zoneColumn !== null) {
            $residentSelect[] = 'h.'.$zoneColumn.' as household_zone';
        }

        $residentQuery = DB::table('residents as r')
            ->where('r.relation_to_household_head', self::RELATION);

        if ($zoneColumn !== null) {
            $residentQuery->leftJoin('households as h', 'h.'.$householdPk, '=', 'r.household_id');
        }

        $residentRows = $residentQuery
            ->select($residentSelect)
            ->orderBy('r.last_name')
            ->orderBy('r.first_name')
            ->orderBy('r.'.$residentPk)
            ->get();

        if ($residentRows->isEmpty()) {
            return [];
        }

        $residentIds = $residentRows->pluck('resident_id')->map(static fn ($id): int => (int) $id)->all();

        $visitQuery = DB::table('family_planning')
            ->whereIn('resident_id', $residentIds)
            ->orderBy('visitation_date')
            ->orderBy($fpPk);

        $visitSelect = ['resident_id', $fpPk.' as fp_id', 'visitation_date'];
        if (Schema::hasColumn('family_planning', 'remarks')) {
            $visitSelect[] = 'remarks';
        }
        $visitRows = $visitQuery->get($visitSelect);

        $commoditiesByFp = [];
        if (self::commoditiesTableReady() && $visitRows->isNotEmpty()) {
            $fpIds = $visitRows->pluck('fp_id')->map(static fn ($id): int => (int) $id)->all();
            $commodityRows = DB::table('fp_commodities_given')
                ->whereIn('fp_id', $fpIds)
                ->orderBy('commodity_given_id')
                ->get(['fp_id', 'commodity_name', 'quantity']);

            foreach ($commodityRows as $commodity) {
                $fpId = (int) $commodity->fp_id;
                $commoditiesByFp[$fpId][] = [
                    'name' => (string) $commodity->commodity_name,
                    'quantity' => (int) $commodity->quantity,
                    'given_at' => null,
                ];
            }
        }

        $visitsByResident = [];
        foreach ($visitRows as $visit) {
            $residentId = (int) $visit->resident_id;
            $fpId = (int) $visit->fp_id;
            $visitedAt = (string) ($visit->visitation_date ?? '');
            $commodities = $commoditiesByFp[$fpId] ?? [];
            foreach ($commodities as $index => $item) {
                $commodities[$index]['given_at'] = $visitedAt !== '' ? $visitedAt : null;
            }

            $visitsByResident[$residentId][] = [
                'id' => self::visitKey($fpId),
                'visited_at' => $visitedAt,
                'remarks' => (string) ($visit->remarks ?? ''),
                'commodities' => $commodities,
            ];
        }

        $clients = [];
        foreach ($residentRows as $row) {
            $residentId = (int) $row->resident_id;
            $visits = $visitsByResident[$residentId] ?? [];
            usort(
                $visits,
                static fn (array $a, array $b): int => strcmp((string) ($b['visited_at'] ?? ''), (string) ($a['visited_at'] ?? ''))
            );

            $zone = trim((string) ($row->household_zone ?? ''));
            $start = $visits === [] ? '' : (string) ($visits[array_key_last($visits)]['visited_at'] ?? '');
            $last = $visits === [] ? '' : (string) ($visits[0]['visited_at'] ?? '');
            $method = self::methodFromVisits($visits);

            $clients[] = [
                'key' => self::clientKey($residentId),
                'resident_id' => $residentId,
                'first_name' => (string) ($row->first_name ?? ''),
                'middle_name' => (string) ($row->middle_name ?? ''),
                'last_name' => (string) ($row->last_name ?? ''),
                'birthday' => (string) ($row->birthday ?? ''),
                'sex' => (string) ($row->sex ?? ''),
                'civil_status' => (string) ($row->civil_status ?? ''),
                'address_zone' => $zone,
                'barangay' => $zone,
                'municipality' => '',
                'method' => $method,
                'start_date' => $start !== '' ? HealthRecordsNonResidentFamilyPlanning::formatVisitDateShort($start) : '',
                'last_visit' => $last !== '' ? HealthRecordsNonResidentFamilyPlanning::formatVisitDateShort($last) : '',
                'year' => $last !== '' ? substr($last, 0, 4) : '',
                'visits' => $visits,
            ];
        }

        return $clients;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function createClient(array $validated): array
    {
        self::rejectIfUnavailable();

        return DB::transaction(function () use ($validated): array {
            $householdId = self::ensureSentinelHousehold();
            $now = now();

            $residentPayload = [
                'household_id' => $householdId,
                'first_name' => trim((string) $validated['first_name']),
                'last_name' => trim((string) $validated['last_name']),
                'relation_to_household_head' => self::RELATION,
                'birthday' => (string) $validated['birthday'],
                'sex' => (string) $validated['sex'],
                'civil_status' => (string) $validated['civil_status'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $middle = trim((string) ($validated['middle_name'] ?? ''));
            if (Schema::hasColumn('residents', 'middle_name')) {
                $residentPayload['middle_name'] = $middle === '' ? null : $middle;
            }
            if (Schema::hasColumn('residents', 'is_fp_user')) {
                $residentPayload['is_fp_user'] = 1;
            }

            $residentId = (int) DB::table('residents')->insertGetId($residentPayload, 'resident_id');

            $commodities = self::mergeMethodCommodity(
                is_array($validated['commodities'] ?? null) ? $validated['commodities'] : [],
                (string) ($validated['method'] ?? '')
            );

            self::insertVisit($residentId, (string) $validated['visited_at'], $validated['remarks'] ?? null, $commodities);

            $client = HealthRecordsNonResidentFamilyPlanning::findClient(self::clientKey($residentId));
            if ($client === null) {
                abort(500, 'Non-resident family planning client could not be loaded after save.');
            }

            return $client;
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function createVisit(int $residentId, array $validated): array
    {
        self::rejectIfUnavailable();
        self::assertNonResident($residentId);

        return DB::transaction(function () use ($residentId, $validated): array {
            $commodities = self::normalizeCommodities(
                is_array($validated['commodities'] ?? null) ? $validated['commodities'] : []
            );
            $fpId = self::insertVisit(
                $residentId,
                (string) $validated['visited_at'],
                $validated['remarks'] ?? null,
                $commodities
            );

            $visit = HealthRecordsNonResidentFamilyPlanning::findVisit(self::clientKey($residentId), self::visitKey($fpId));
            if ($visit === null) {
                abort(500, 'Family planning visit could not be loaded after save.');
            }

            return $visit;
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function updateVisit(int $residentId, int $fpId, array $validated): void
    {
        self::rejectIfUnavailable();
        self::assertNonResident($residentId);

        $updated = DB::table('family_planning')
            ->where(FamilyPlanningErdMode::primaryKey(), $fpId)
            ->where('resident_id', $residentId)
            ->update([
                'visitation_date' => (string) $validated['visited_at'],
                'remarks' => self::nullableRemarks($validated['remarks'] ?? null),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            abort(404);
        }

        if (self::commoditiesTableReady()) {
            DB::table('fp_commodities_given')->where('fp_id', $fpId)->delete();
            self::insertCommodities(
                $fpId,
                self::normalizeCommodities(
                    is_array($validated['commodities'] ?? null) ? $validated['commodities'] : []
                )
            );
        }
    }

    public static function deleteClient(int $residentId): void
    {
        self::rejectIfUnavailable();
        self::assertNonResident($residentId);

        DB::transaction(function () use ($residentId): void {
            $fpPk = FamilyPlanningErdMode::primaryKey();
            $fpIds = DB::table('family_planning')
                ->where('resident_id', $residentId)
                ->pluck($fpPk)
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($fpIds !== [] && self::commoditiesTableReady()) {
                DB::table('fp_commodities_given')->whereIn('fp_id', $fpIds)->delete();
            }

            DB::table('family_planning')->where('resident_id', $residentId)->delete();
            DB::table('residents')
                ->where('resident_id', $residentId)
                ->where('relation_to_household_head', self::RELATION)
                ->delete();
        });
    }

    public static function parseClientKey(string $clientKey): ?int
    {
        $key = strtolower(trim($clientKey));
        if (preg_match('/^nr-(\d+)$/', $key, $matches) !== 1) {
            return null;
        }

        $id = (int) $matches[1];

        return $id > 0 ? $id : null;
    }

    public static function parseVisitKey(string $visitId): ?int
    {
        $token = strtoupper(trim($visitId));
        if (preg_match('/^FP-(\d+)$/', $token, $matches) !== 1) {
            return null;
        }

        $id = (int) $matches[1];

        return $id > 0 ? $id : null;
    }

    public static function clientKey(int $residentId): string
    {
        return self::CLIENT_KEY_PREFIX.$residentId;
    }

    public static function visitKey(int $fpId): string
    {
        return sprintf('FP-%d', $fpId);
    }

    /**
     * @return list<string>
     */
    public static function commodityOptions(): array
    {
        return self::COMMODITY_ENUM;
    }

    public static function mapCommodityName(string $raw): ?string
    {
        $name = trim($raw);
        if ($name === '') {
            return null;
        }

        $normalized = match ($name) {
            'Pills - Combined', 'Pills-Combined' => 'Pills-Combined',
            'Condom', 'Condoms' => 'Condoms',
            'Injectable' => 'DMPA',
            default => $name,
        };

        return in_array($normalized, self::COMMODITY_ENUM, true) ? $normalized : null;
    }

    public static function mapMethodToCommodity(string $method): ?string
    {
        return match (trim($method)) {
            'Pills' => 'Pills',
            'Condom' => 'Condoms',
            'Injectable' => 'DMPA',
            'IUD' => 'IUD',
            'Implant' => 'Implant',
            default => self::mapCommodityName($method),
        };
    }

    private static function ensureSentinelHousehold(): int
    {
        $householdPk = Schema::hasColumn('households', 'household_id') ? 'household_id' : 'id';
        $existing = DB::table('households')
            ->where('household_no', self::SENTINEL_HOUSEHOLD_NO)
            ->value($householdPk);

        if ($existing !== null) {
            return (int) $existing;
        }

        $now = now();
        $payload = [
            'household_no' => self::SENTINEL_HOUSEHOLD_NO,
            'date_registered' => $now->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('households', 'purok')) {
            $payload['purok'] = 'Walk-in';
        } elseif (Schema::hasColumn('households', 'zone')) {
            $payload['zone'] = 'Walk-in';
        }

        if (Schema::hasColumn('households', 'latitude')) {
            $payload['latitude'] = 0;
        }
        if (Schema::hasColumn('households', 'longitude')) {
            $payload['longitude'] = 0;
        }

        return (int) DB::table('households')->insertGetId($payload, $householdPk);
    }

    /**
     * @param  list<array{name: string, quantity: int}>  $commodities
     */
    private static function insertVisit(int $residentId, string $visitedAt, mixed $remarks, array $commodities): int
    {
        $now = now();
        $payload = [
            'resident_id' => $residentId,
            'visitation_date' => $visitedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('family_planning', 'remarks')) {
            $payload['remarks'] = self::nullableRemarks($remarks);
        }

        $fpId = (int) DB::table('family_planning')->insertGetId($payload, FamilyPlanningErdMode::primaryKey());
        self::insertCommodities($fpId, $commodities);

        return $fpId;
    }

    /**
     * @param  list<array{name: string, quantity: int}>  $commodities
     */
    private static function insertCommodities(int $fpId, array $commodities): void
    {
        if (! self::commoditiesTableReady() || $commodities === []) {
            return;
        }

        $now = now();
        foreach ($commodities as $item) {
            DB::table('fp_commodities_given')->insert([
                'fp_id' => $fpId,
                'commodity_name' => $item['name'],
                'quantity' => $item['quantity'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param  list<mixed>  $raw
     * @return list<array{name: string, quantity: int}>
     */
    private static function mergeMethodCommodity(array $raw, string $method): array
    {
        $commodities = self::normalizeCommodities($raw);
        $mapped = self::mapMethodToCommodity($method);
        if ($mapped === null) {
            return $commodities;
        }

        foreach ($commodities as $item) {
            if ($item['name'] === $mapped) {
                return $commodities;
            }
        }

        $commodities[] = [
            'name' => $mapped,
            'quantity' => 1,
        ];

        return $commodities;
    }

    /**
     * @param  list<mixed>  $raw
     * @return list<array{name: string, quantity: int}>
     */
    public static function normalizeCommodities(array $raw): array
    {
        $normalized = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $mapped = self::mapCommodityName((string) ($item['name'] ?? ''));
            if ($mapped === null) {
                continue;
            }
            $qty = (int) ($item['quantity'] ?? 0);
            if ($qty < 1) {
                continue;
            }
            $normalized[] = [
                'name' => $mapped,
                'quantity' => $qty,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $visits
     */
    private static function methodFromVisits(array $visits): string
    {
        foreach ($visits as $visit) {
            $commodities = is_array($visit['commodities'] ?? null) ? $visit['commodities'] : [];
            foreach ($commodities as $item) {
                $name = trim((string) ($item['name'] ?? ''));
                if ($name !== '') {
                    return match ($name) {
                        'Condoms' => 'Condom',
                        'DMPA' => 'Injectable',
                        'Pills-Combined' => 'Pills',
                        default => $name,
                    };
                }
            }
        }

        return '';
    }

    private static function nullableRemarks(mixed $remarks): ?string
    {
        $trimmed = trim((string) $remarks);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function assertNonResident(int $residentId): void
    {
        $exists = DB::table('residents')
            ->where('resident_id', $residentId)
            ->where('relation_to_household_head', self::RELATION)
            ->exists();

        if (! $exists) {
            abort(404);
        }
    }
}
