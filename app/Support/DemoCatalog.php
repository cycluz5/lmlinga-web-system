<?php

namespace App\Support;

/**
 * Shared demo catalog loaders for UI-preview routes (no persistence).
 */
final class DemoCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function healthWorkers(): array
    {
        return HealthWorkerUiCatalog::all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findHealthWorker(string $id): ?array
    {
        return HealthWorkerUiCatalog::find($id);
    }

    /**
     * Resident chatbot accounts for User Management → Residents.
     * Distinct from householdRequests() / findHouseholdRequest().
     *
     * @return list<array<string, mixed>>
     */
    public static function residentAccounts(): array
    {
        return DemoResidentAccounts::all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findResidentAccount(string $id): ?array
    {
        return DemoResidentAccounts::find($id);
    }

    /**
     * Household record access requests (demo catalog).
     *
     * @return list<array<string, mixed>>
     */
    public static function householdRequests(): array
    {
        /** @var list<array<string, mixed>> $catalog */
        $catalog = require resource_path('demo/residents.php');

        return array_map(
            static fn (array $request): array => HouseholdRequestValidator::enrich($request),
            $catalog
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findHouseholdRequest(string $id): ?array
    {
        return collect(self::householdRequests())->firstWhere('id', $id);
    }

    /**
     * @deprecated Use householdRequests() — retained for transitional callers.
     *
     * @return list<array<string, mixed>>
     */
    public static function residents(): array
    {
        return self::householdRequests();
    }

    /**
     * @deprecated Use findHouseholdRequest() — retained for transitional callers.
     *
     * @return array<string, mixed>|null
     */
    public static function findResident(string $id): ?array
    {
        return self::findHouseholdRequest($id);
    }

    /**
     * Ensure demo household helpers exist without requiring a DemoCatalog read.
     *
     * Helpers (lml_demo_member_display / lml_demo_find_member) are defined in
     * resources/demo/households.php. DB-first household views never load that
     * catalog, so member-view and other blades would fatally call an undefined
     * function unless helpers are ensured here first.
     */
    public static function ensureHouseholdHelpers(): void
    {
        if (function_exists('lml_demo_member_display') && function_exists('lml_demo_find_member')) {
            return;
        }

        require resource_path('demo/households.php');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function households(): array
    {
        /** @var array<string, array<string, mixed>> $catalog */
        $catalog = require resource_path('demo/households.php');

        return $catalog;
    }

    public static function normalizeHouseholdNo(string $householdNo): string
    {
        return strtoupper(trim($householdNo));
    }

    public static function normalizeMemberId(string $memberId): string
    {
        return strtoupper(trim($memberId));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findHousehold(string $householdNo): ?array
    {
        $key = self::normalizeHouseholdNo($householdNo);
        $catalog = self::households();

        return $catalog[$key] ?? null;
    }
}
