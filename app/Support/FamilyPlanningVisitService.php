<?php

namespace App\Support;

use App\Models\FamilyPlanningVisit;
use App\Models\Resident;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB-13 Phase 2 — Family Planning visit persistence for Household Profiling members.
 */
final class FamilyPlanningVisitService
{
    public const COMMODITIES_NOT_STORED_MESSAGE = 'Commodity details are not stored in the current database configuration.';

    /**
     * @param  array<string, mixed>  $payload
     * @return FamilyPlanningVisit|array<string, mixed>
     */
    public function createForResident(Resident $resident, array $payload): FamilyPlanningVisit|array
    {
        HouseholdProfilingWriteGuard::rejectFamilyPlanningWrite();

        if (FamilyPlanningErdMode::isActive()) {
            return $this->createForResidentErd($resident, $payload);
        }

        $attributes = $this->normalizePayload($payload);

        return DB::transaction(function () use ($resident, $attributes): FamilyPlanningVisit {
            /** @var FamilyPlanningVisit $visit */
            $visit = $resident->familyPlanningVisits()->make($attributes);
            // Temporary unique value (≤16 chars) until primary key is known.
            $visit->visit_no = 'T'.strtoupper(bin2hex(random_bytes(7)));
            $visit->save();

            $visit->visit_no = sprintf('FP-%03d', $visit->id);
            $visit->save();

            return $visit->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateForResident(Resident $resident, string $visitNo, array $payload): void
    {
        HouseholdProfilingWriteGuard::rejectFamilyPlanningWrite();

        if (FamilyPlanningErdMode::isActive()) {
            $this->updateForResidentErd($resident, $visitNo, $payload);

            return;
        }

        $visit = $this->findForResident($resident, $visitNo);
        if ($visit === null) {
            abort(404);
        }

        $visit->fill($this->normalizePayload($payload));
        $visit->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createForResidentErd(Resident $resident, array $payload): array
    {
        $attributes = $this->normalizeErdPayload($payload);
        $commodities = $this->normalizeErdCommodities(
            is_array($payload['commodities'] ?? null) ? $payload['commodities'] : []
        );

        $fpId = (int) DB::transaction(function () use ($resident, $attributes, $commodities): int {
            $id = (int) DB::table('family_planning')->insertGetId([
                'resident_id' => $resident->getKey(),
                'visitation_date' => $attributes['visited_at'],
                'remarks' => $attributes['remarks'],
                'created_at' => now(),
                'updated_at' => now(),
            ], FamilyPlanningErdMode::primaryKey());

            $this->replaceErdCommodities($id, $commodities);

            return $id;
        });

        $presentation = $this->findPresentationFromErd($resident, sprintf('FP-%03d', $fpId));
        if ($presentation === null) {
            abort(500, 'Family planning record could not be loaded after save.');
        }

        return $presentation;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function updateForResidentErd(Resident $resident, string $visitNo, array $payload): void
    {
        $fpId = $this->parseFamilyPlanningId($visitNo);
        if ($fpId === null) {
            abort(404);
        }

        $attributes = $this->normalizeErdPayload($payload);
        $pk = FamilyPlanningErdMode::primaryKey();

        $commodities = $this->normalizeErdCommodities(
            is_array($payload['commodities'] ?? null) ? $payload['commodities'] : []
        );

        $updated = DB::transaction(function () use ($pk, $fpId, $resident, $attributes, $commodities): int {
            $count = (int) DB::table('family_planning')
                ->where($pk, $fpId)
                ->where('resident_id', $resident->getKey())
                ->update([
                    'visitation_date' => $attributes['visited_at'],
                    'remarks' => $attributes['remarks'],
                    'updated_at' => now(),
                ]);

            if ($count > 0) {
                $this->replaceErdCommodities($fpId, $commodities);
            }

            return $count;
        });

        if ($updated === 0) {
            abort(404);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{visited_at: string, remarks: string|null}
     */
    private function normalizeErdPayload(array $payload): array
    {
        $remarks = trim((string) ($payload['remarks'] ?? ''));

        return [
            'visited_at' => (string) $payload['visited_at'],
            'remarks' => $remarks === '' ? null : $remarks,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function payloadHasCommodities(array $payload): bool
    {
        $commodities = is_array($payload['commodities'] ?? null) ? $payload['commodities'] : [];

        foreach ($commodities as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (trim((string) ($row['name'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyRowsForResident(Resident $resident): array
    {
        if (Schema::hasTable('family_planning_visits')) {
            return $resident->familyPlanningVisits()
                ->orderByDesc('visited_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (FamilyPlanningVisit $row): array => $this->toPresentation($row))
                ->all();
        }

        if (Schema::hasTable('family_planning')) {
            return $this->historyRowsFromErd($resident);
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyRowsFromErd(Resident $resident): array
    {
        $pk = Schema::hasColumn('family_planning', 'fp_id') ? 'fp_id' : 'family_planning_id';

        $rows = DB::table('family_planning')
            ->where('resident_id', $resident->getKey())
            ->orderByDesc('visitation_date')
            ->orderByDesc($pk)
            ->get();

        $commoditiesByFp = $this->commoditiesByFamilyPlanningIds(
            $rows->map(static fn (object $row): int => (int) ($row->{$pk} ?? 0))->all()
        );

        return $rows->map(function (object $row) use ($pk, $commoditiesByFp): array {
            $id = (int) ($row->{$pk} ?? 0);
            $commodities = $commoditiesByFp[$id] ?? [];

            return [
                'id' => sprintf('FP-%03d', $id),
                'visited_at' => (string) ($row->visitation_date ?? ''),
                'remarks' => (string) ($row->remarks ?? ''),
                'commodities' => $commodities,
                'commodities_label' => $commodities === []
                    ? HealthRecordsClinicalListing::EMPTY
                    : DemoFamilyPlanning::commoditiesLabel($commodities),
                'total_quantity' => DemoFamilyPlanning::totalQuantity($commodities),
            ];
        })->all();
    }

    public function findForResident(Resident $resident, string $visitNo): ?FamilyPlanningVisit
    {
        if (! Schema::hasTable('family_planning_visits')) {
            return null;
        }

        $id = strtoupper(trim($visitNo));

        return $resident->familyPlanningVisits()
            ->where('visit_no', $id)
            ->first();
    }

    /**
     * Detail/show contract for Blade. Reads authoritative ERD when legacy visits absent.
     *
     * @return array<string, mixed>|null
     */
    public function findPresentationForResident(Resident $resident, string $visitNo): ?array
    {
        if (Schema::hasTable('family_planning_visits')) {
            $model = $this->findForResident($resident, $visitNo);

            return $model !== null ? $this->toPresentation($model) : null;
        }

        if (Schema::hasTable('family_planning')) {
            return $this->findPresentationFromErd($resident, $visitNo);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPresentationFromErd(Resident $resident, string $visitNo): ?array
    {
        $fpId = $this->parseFamilyPlanningId($visitNo);
        if ($fpId === null) {
            return null;
        }

        $pk = Schema::hasColumn('family_planning', 'fp_id') ? 'fp_id' : 'family_planning_id';

        $row = DB::table('family_planning')
            ->where($pk, $fpId)
            ->where('resident_id', $resident->getKey())
            ->first();

        if ($row === null) {
            return null;
        }

        $id = (int) ($row->{$pk} ?? 0);
        $commodities = $this->commoditiesByFamilyPlanningIds([$id])[$id] ?? [];

        return [
            'id' => sprintf('FP-%03d', $id),
            'visited_at' => (string) ($row->visitation_date ?? ''),
            'remarks' => (string) ($row->remarks ?? ''),
            'commodities' => $commodities,
            'commodities_label' => DemoFamilyPlanning::commoditiesLabel($commodities),
            'total_quantity' => DemoFamilyPlanning::totalQuantity($commodities),
        ];
    }

    /**
     * @param  list<int>  $fpIds
     * @return array<int, list<array{name: string, quantity: int}>>
     */
    private function commoditiesByFamilyPlanningIds(array $fpIds): array
    {
        $fpIds = array_values(array_filter($fpIds, static fn (int $id): bool => $id > 0));
        if ($fpIds === [] || ! FamilyPlanningErdMode::commoditiesTableReady()) {
            return [];
        }

        $orderColumn = Schema::hasColumn('fp_commodities_given', 'commodity_given_id')
            ? 'commodity_given_id'
            : (Schema::hasColumn('fp_commodities_given', 'id') ? 'id' : 'fp_id');

        $rows = DB::table('fp_commodities_given')
            ->whereIn('fp_id', $fpIds)
            ->orderBy($orderColumn)
            ->get(['fp_id', 'commodity_name', 'quantity']);

        $grouped = [];
        foreach ($rows as $row) {
            $fpId = (int) ($row->fp_id ?? 0);
            $name = $this->commodityDisplayName((string) ($row->commodity_name ?? ''));
            if ($fpId <= 0 || $name === '') {
                continue;
            }
            $grouped[$fpId][] = [
                'name' => $name,
                'quantity' => (int) ($row->quantity ?? 0),
            ];
        }

        return $grouped;
    }

    /**
     * @param  list<array{name: string, quantity: int}>  $commodities
     */
    private function replaceErdCommodities(int $fpId, array $commodities): void
    {
        if ($fpId <= 0 || ! FamilyPlanningErdMode::commoditiesTableReady()) {
            return;
        }

        DB::table('fp_commodities_given')->where('fp_id', $fpId)->delete();
        if ($commodities === []) {
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
     * @param  list<mixed>  $rows
     * @return list<array{name: string, quantity: int}>
     */
    private function normalizeErdCommodities(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = NonResidentFamilyPlanningService::mapCommodityName((string) ($row['name'] ?? ''));
            if ($mapped === null) {
                continue;
            }
            $normalized[] = [
                'name' => $mapped,
                'quantity' => (int) ($row['quantity'] ?? 0),
            ];
        }

        return $normalized;
    }

    private function commodityDisplayName(string $stored): string
    {
        return match (trim($stored)) {
            'Pills-Combined' => 'Pills - Combined',
            default => trim($stored),
        };
    }

    private function parseFamilyPlanningId(string $visitNo): ?int
    {
        $token = strtoupper(trim($visitNo));
        if (preg_match('/^FP-(\d+)$/i', $token, $matches) !== 1) {
            return null;
        }

        $id = (int) $matches[1];

        return $id > 0 ? $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPresentation(FamilyPlanningVisit $visit): array
    {
        $commodities = is_array($visit->commodities) ? $visit->commodities : [];
        $normalizedCommodities = [];

        foreach ($commodities as $item) {
            if (! is_array($item)) {
                continue;
            }
            $normalizedCommodities[] = [
                'name' => trim((string) ($item['name'] ?? '')),
                'quantity' => (int) ($item['quantity'] ?? 0),
            ];
        }

        return [
            'id' => (string) $visit->visit_no,
            'visited_at' => $visit->visited_at?->toDateString() ?? '',
            'remarks' => (string) ($visit->remarks ?? ''),
            'commodities' => $normalizedCommodities,
            'commodities_label' => DemoFamilyPlanning::commoditiesLabel($normalizedCommodities),
            'total_quantity' => DemoFamilyPlanning::totalQuantity($normalizedCommodities),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $remarks = trim((string) ($payload['remarks'] ?? ''));
        $commodities = $this->normalizeCommodities(
            is_array($payload['commodities'] ?? null) ? $payload['commodities'] : []
        );

        return [
            'visited_at' => (string) $payload['visited_at'],
            'remarks' => $remarks === '' ? null : $remarks,
            'commodities' => $commodities === [] ? null : $commodities,
        ];
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array{name: string, quantity: int}>
     */
    private function normalizeCommodities(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $normalized[] = [
                'name' => $name,
                'quantity' => (int) ($row['quantity'] ?? 0),
            ];
        }

        return $normalized;
    }
}
