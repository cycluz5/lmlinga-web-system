<?php

namespace App\Support;

use App\Models\ResidentAccount;
use Illuminate\Support\Facades\Schema;

/**
 * Adapts resident_accounts into the frozen Admin User Management Residents UI shape.
 *
 * Production authority: MySQL resident_accounts only (no demo/session merge).
 * Public presentation IDs: ra-{account_id|id}.
 */
final class ResidentAccountUiCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        if (! Schema::hasTable('resident_accounts')) {
            return [];
        }

        $query = ResidentAccount::query()->orderBy(UserManagementErdMode::residentAccountKeyName());

        if (UserManagementErdMode::residentAccountsHaveResidentLink()) {
            $query->with(['resident.household']);
        }

        return $query
            ->get()
            ->map(static fn (ResidentAccount $account): array => self::present($account))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $publicId): ?array
    {
        $account = self::findAccount($publicId);

        return $account !== null ? self::present($account) : null;
    }

    public static function findAccount(string $publicId): ?ResidentAccount
    {
        $id = self::parsePublicId($publicId);
        if ($id === null || ! Schema::hasTable('resident_accounts')) {
            return null;
        }

        $query = ResidentAccount::query();

        if (UserManagementErdMode::residentAccountsHaveResidentLink()) {
            $query->with(['resident.household']);
        }

        return $query->find($id);
    }

    public static function parsePublicId(string $publicId): ?int
    {
        $publicId = trim($publicId);
        if (preg_match('/^ra-(\d+)$/', $publicId, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    public static function publicId(int|string $id): string
    {
        return 'ra-'.(string) $id;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(ResidentAccount $account): array
    {
        $first = trim((string) $account->first_name);
        $middle = trim((string) $account->middle_name);
        $last = trim((string) $account->last_name);
        $name = trim(implode(' ', array_filter([$first, $middle, $last], static fn (string $p): bool => $p !== '')));

        return [
            'id' => self::publicId($account->getKey()),
            'account_id' => $account->getKey(),
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'name' => $name !== '' ? $name : (string) $account->email,
            'email' => (string) $account->email,
            'zone' => self::resolveZone($account),
            'resident_id' => $account->resident_id,
            'source' => 'database',
        ];
    }

    public static function resolveZone(ResidentAccount $account): string
    {
        if (UserManagementErdMode::residentAccountsHaveResidentLink()) {
            $resident = $account->relationLoaded('resident')
                ? $account->resident
                : $account->resident()->with('household')->first();

            if ($resident !== null) {
                $household = $resident->relationLoaded('household')
                    ? $resident->household
                    : $resident->household()->first();

                $householdZone = trim((string) ($household?->zone ?? $household?->purok ?? ''));
                if ($householdZone !== '') {
                    return $householdZone;
                }
            }
        }

        return trim((string) ($account->zone ?? ''));
    }
}
