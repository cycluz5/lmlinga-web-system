<?php

namespace App\Support;

/**
 * Demo Family Planning visit catalog helpers.
 *
 * Fixture catalog for non-persisted demo members (UI/regression fallback).
 * DB-13: persisted residents use FamilyPlanningVisitService — not this catalog.
 * Distinct from demographic member field fp_user.
 */
final class DemoFamilyPlanning
{
    /**
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    public static function catalog(): array
    {
        /** @var array<string, array<string, list<array<string, mixed>>>> $catalog */
        $catalog = require resource_path('demo/family-planning.php');

        return $catalog;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forMember(string $householdNo, string $memberId): array
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);
        $catalog = self::catalog();
        $rows = $catalog[$hh][$mb] ?? [];

        $normalized = array_map(
            static fn (array $row): array => self::normalizeVisit($row),
            $rows
        );

        usort(
            $normalized,
            static function (array $a, array $b): int {
                return strcmp((string) ($b['visited_at'] ?? ''), (string) ($a['visited_at'] ?? ''));
            }
        );

        return array_values($normalized);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $householdNo, string $memberId, string $visitId): ?array
    {
        $id = strtoupper(trim($visitId));

        foreach (self::forMember($householdNo, $memberId) as $row) {
            if (strtoupper((string) ($row['id'] ?? '')) === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function filterByDate(array $rows, ?string $date, ?string $from = null, ?string $to = null): array
    {
        $filter = is_string($date) ? trim($date) : '';
        if ($filter === '' || $filter === 'all') {
            return $rows;
        }

        $today = \Carbon\Carbon::today();

        if ($filter === 'this_month') {
            return self::filterByInclusiveVisitRange(
                $rows,
                $today->copy()->startOfMonth()->toDateString(),
                $today->copy()->endOfMonth()->toDateString()
            );
        }

        if ($filter === 'last_3_months') {
            return self::filterByInclusiveVisitRange(
                $rows,
                $today->copy()->subMonthsNoOverflow(3)->toDateString(),
                $today->toDateString()
            );
        }

        if ($filter === 'this_year') {
            return self::filterByInclusiveVisitRange(
                $rows,
                $today->copy()->startOfYear()->toDateString(),
                $today->copy()->endOfYear()->toDateString()
            );
        }

        if ($filter === 'custom') {
            $fromDate = is_string($from) ? trim($from) : '';
            $toDate = is_string($to) ? trim($to) : '';

            if ($fromDate === '' || $toDate === '' || $fromDate > $toDate) {
                return $rows;
            }

            return self::filterByInclusiveVisitRange($rows, $fromDate, $toDate);
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{total_visits: int, last_visit: string|null, last_visit_label: string}
     */
    public static function summaryStats(array $rows): array
    {
        $total = count($rows);
        if ($total === 0) {
            return [
                'total_visits' => 0,
                'last_visit' => null,
                'last_visit_label' => '—',
            ];
        }

        $latest = (string) ($rows[0]['visited_at'] ?? '');

        return [
            'total_visits' => $total,
            'last_visit' => $latest !== '' ? $latest : null,
            'last_visit_label' => $latest !== '' ? self::formatVisitDate($latest) : '—',
        ];
    }

    /**
     * @param  list<array{name?: string, quantity?: int|string}>  $commodities
     */
    public static function commoditiesLabel(array $commodities): string
    {
        $names = [];
        foreach ($commodities as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names === [] ? '—' : implode(', ', $names);
    }

    public static function totalQuantity(array $commodities): int
    {
        $sum = 0;
        foreach ($commodities as $item) {
            $sum += (int) ($item['quantity'] ?? 0);
        }

        return $sum;
    }

    public static function formatVisitDate(string $isoDate): string
    {
        try {
            $formatted = DisplayDate::format($isoDate);

            return $formatted !== '' ? $formatted : $isoDate;
        } catch (\Throwable) {
            return $isoDate;
        }
    }

    /**
     * Demo UI commodity options for Add/Edit selects (display labels only).
     *
     * @return list<string>
     */
    public static function commodityOptions(): array
    {
        return [
            'Pills',
            'Pills - Combined',
            'Condoms',
            'DMPA',
            'IUD',
            'Implant',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function normalizeVisit(array $row): array
    {
        $commodities = is_array($row['commodities'] ?? null) ? $row['commodities'] : [];
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
            'id' => strtoupper(trim((string) ($row['id'] ?? ''))),
            'visited_at' => (string) ($row['visited_at'] ?? ''),
            'remarks' => (string) ($row['remarks'] ?? ''),
            'commodities' => $normalizedCommodities,
            'commodities_label' => self::commoditiesLabel($normalizedCommodities),
            'total_quantity' => self::totalQuantity($normalizedCommodities),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function filterByInclusiveVisitRange(array $rows, string $from, string $to): array
    {
        return array_values(array_filter(
            $rows,
            static function (array $row) use ($from, $to): bool {
                $visited = (string) ($row['visited_at'] ?? '');

                return $visited !== '' && $visited >= $from && $visited <= $to;
            }
        ));
    }
}
