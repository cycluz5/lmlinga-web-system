<?php

namespace App\Support;

use App\Models\Resident;
use Illuminate\Support\Facades\Schema;

/**
 * Schema-aware resident persistence.
 *
 * Maps UI/request field names onto columns that actually exist:
 * - relation → residents.relation (legacy) or residents.relation_to_household_head (ERD)
 * - names persist as first_name / middle_name / last_name
 * - optional demographics only when those columns exist and the request provided them
 *
 * Never emits nonexistent columns (member_no, relation, philhealth, …) into Eloquent payloads.
 * Does not invent health or demographic values.
 */
final class ResidentShellWriteAdapter
{
    public static function isSupported(): bool
    {
        $table = (new Resident)->getTable();

        if (! Schema::hasTable($table)) {
            return false;
        }

        $guard = app(DatabaseSchemaGuard::class);

        if (! $guard->columnExists($table, 'household_id')
            || ! $guard->columnExists($table, 'first_name')
            || ! $guard->columnExists($table, 'last_name')
            || self::relationColumn() === null
        ) {
            return false;
        }

        // Authoritative ERD residents require birthday/sex/civil_status.
        // Legacy schemas use relation + relationship_status instead; do not fail those closed.
        if (Schema::hasColumn($table, 'relation_to_household_head') && ! Schema::hasColumn($table, 'relation')) {
            return $guard->columnExists($table, 'birthday')
                && $guard->columnExists($table, 'sex')
                && $guard->columnExists($table, 'civil_status');
        }

        return true;
    }

    /**
     * Prefer legacy relation when present; otherwise authoritative ERD column.
     */
    public static function relationColumn(): ?string
    {
        $table = (new Resident)->getTable();

        if (Schema::hasColumn($table, 'relation')) {
            return 'relation';
        }

        if (Schema::hasColumn($table, 'relation_to_household_head')) {
            return 'relation_to_household_head';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function relationValueFromPayload(array $payload): string
    {
        $column = self::relationColumn();
        if ($column !== null && array_key_exists($column, $payload)) {
            return trim((string) $payload[$column]);
        }

        return trim((string) ($payload['relation'] ?? $payload['relation_to_household_head'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function persistableAttributes(array $validated): array
    {
        $guard = app(DatabaseSchemaGuard::class);
        $table = (new Resident)->getTable();
        $payload = [];

        if ($guard->columnExists($table, 'first_name') && array_key_exists('first_name', $validated)) {
            $payload['first_name'] = trim((string) $validated['first_name']);
        }

        if ($guard->columnExists($table, 'last_name') && array_key_exists('last_name', $validated)) {
            $payload['last_name'] = trim((string) $validated['last_name']);
        }

        if ($guard->columnExists($table, 'middle_name') && self::hasAny($validated, ['middle_name'])) {
            $middle = trim((string) ($validated['middle_name'] ?? ''));
            $payload['middle_name'] = $middle === '' ? null : $middle;
        }

        $relationColumn = self::relationColumn();
        if ($relationColumn !== null && self::hasAny($validated, ['relation', 'relation_to_household_head'])) {
            $payload[$relationColumn] = trim((string) ($validated['relation'] ?? $validated['relation_to_household_head'] ?? ''));
        }

        if ($guard->columnExists($table, 'birthday') && array_key_exists('birthday', $validated)) {
            $birthday = $validated['birthday'];
            $payload['birthday'] = $birthday === null || $birthday === '' ? null : (string) $birthday;
        }

        if ($guard->columnExists($table, 'sex') && array_key_exists('sex', $validated)) {
            $payload['sex'] = trim((string) $validated['sex']);
        }

        if ($guard->columnExists($table, 'monthly_income') && array_key_exists('monthly_income', $validated)) {
            $payload['monthly_income'] = ResidentMemberFieldMap::monthlyIncomeForStorage(
                trim((string) $validated['monthly_income'])
            );
        }

        self::mapCivilStatus($guard, $table, $validated, $payload);
        self::mapOccupation($guard, $table, $validated, $payload);
        self::mapReligion($guard, $table, $validated, $payload);
        self::mapEducation($guard, $table, $validated, $payload);
        self::mapFpUser($guard, $table, $validated, $payload);

        $philhealthValue = null;
        $hasPhilhealth = false;
        if (array_key_exists('philhealth', $validated) || array_key_exists('philhealth_number', $validated)) {
            $hasPhilhealth = true;
            $raw = $validated['philhealth'] ?? $validated['philhealth_number'] ?? null;
            $trimmed = preg_replace('/\s+/', '', trim((string) $raw)) ?? '';
            $philhealthValue = $trimmed === '' ? null : $trimmed;
        }

        foreach (['disability', 'disability_others', 'medical_history', 'medical_others'] as $column) {
            if (! $guard->columnExists($table, $column) || ! array_key_exists($column, $validated)) {
                continue;
            }

            $payload[$column] = $validated[$column];
        }

        if ($hasPhilhealth) {
            if ($guard->columnExists($table, 'philhealth')) {
                $payload['philhealth'] = $philhealthValue;
            } elseif ($guard->columnExists($table, 'philhealth_number')) {
                $payload['philhealth_number'] = $philhealthValue;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $payload
     */
    private static function mapCivilStatus(DatabaseSchemaGuard $guard, string $table, array $validated, array &$payload): void
    {
        if (! self::hasAny($validated, ['relationship_status', 'civil_status'])) {
            return;
        }

        $value = trim((string) ($validated['relationship_status'] ?? $validated['civil_status'] ?? ''));

        if ($guard->columnExists($table, 'relationship_status')) {
            $payload['relationship_status'] = $value;

            return;
        }

        if ($guard->columnExists($table, 'civil_status')) {
            $payload['civil_status'] = ResidentMemberFieldMap::civilStatusForStorage($value);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $payload
     */
    private static function mapOccupation(DatabaseSchemaGuard $guard, string $table, array $validated, array &$payload): void
    {
        if (! array_key_exists('occupation', $validated) && ! array_key_exists('occupation_id', $validated) && ! array_key_exists('occupation_other', $validated)) {
            return;
        }

        $name = trim((string) ($validated['occupation'] ?? ''));
        $isOther = ResidentMemberFieldMap::isOtherChoice($name);
        $custom = $isOther ? trim((string) ($validated['occupation_other'] ?? '')) : '';

        if ($guard->columnExists($table, 'occupation') && array_key_exists('occupation', $validated)) {
            $payload['occupation'] = $isOther && $custom !== '' ? $custom : $name;
        }

        $resolvedId = null;
        $hasResolvedId = false;
        if ($guard->columnExists($table, 'occupation_id')) {
            if (array_key_exists('occupation_id', $validated) && $validated['occupation_id'] !== null && $validated['occupation_id'] !== '') {
                $resolvedId = (int) $validated['occupation_id'];
                $hasResolvedId = true;
            } elseif (array_key_exists('occupation', $validated)) {
                $resolvedId = $name !== '' ? ResidentMemberFieldMap::lookupOccupationId($name) : null;
                $hasResolvedId = true;
            }

            if ($hasResolvedId) {
                $payload['occupation_id'] = $resolvedId;
            }
        }

        if ($guard->columnExists($table, 'occupation_other') && (
            array_key_exists('occupation', $validated) || array_key_exists('occupation_other', $validated)
        )) {
            if ($isOther) {
                $payload['occupation_other'] = $custom !== '' ? $custom : null;
            } elseif (! $guard->columnExists($table, 'occupation') && $hasResolvedId && $resolvedId === null && $name !== '') {
                $payload['occupation_other'] = $name;
            } else {
                $payload['occupation_other'] = null;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $payload
     */
    private static function mapReligion(DatabaseSchemaGuard $guard, string $table, array $validated, array &$payload): void
    {
        if (! array_key_exists('religion', $validated) && ! array_key_exists('religion_id', $validated) && ! array_key_exists('religion_other', $validated)) {
            return;
        }

        $name = trim((string) ($validated['religion'] ?? ''));
        $isOther = ResidentMemberFieldMap::isOtherChoice($name);
        $custom = $isOther ? trim((string) ($validated['religion_other'] ?? '')) : '';

        if ($guard->columnExists($table, 'religion') && array_key_exists('religion', $validated)) {
            $payload['religion'] = $isOther && $custom !== '' ? $custom : $name;
        }

        $resolvedId = null;
        $hasResolvedId = false;
        if ($guard->columnExists($table, 'religion_id')) {
            if (array_key_exists('religion_id', $validated) && $validated['religion_id'] !== null && $validated['religion_id'] !== '') {
                $resolvedId = (int) $validated['religion_id'];
                $hasResolvedId = true;
            } elseif (array_key_exists('religion', $validated)) {
                $resolvedId = $name !== '' ? ResidentMemberFieldMap::lookupReligionId($name) : null;
                $hasResolvedId = true;
            }

            if ($hasResolvedId) {
                $payload['religion_id'] = $resolvedId;
            }
        }

        if ($guard->columnExists($table, 'religion_other') && (
            array_key_exists('religion', $validated) || array_key_exists('religion_other', $validated)
        )) {
            if ($isOther) {
                $payload['religion_other'] = $custom !== '' ? $custom : null;
            } elseif (! $guard->columnExists($table, 'religion') && $hasResolvedId && $resolvedId === null && $name !== '') {
                $payload['religion_other'] = $name;
            } else {
                $payload['religion_other'] = null;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $payload
     */
    private static function mapEducation(DatabaseSchemaGuard $guard, string $table, array $validated, array &$payload): void
    {
        if (! self::hasAny($validated, ['education', 'educational_attainment'])) {
            return;
        }

        $value = trim((string) ($validated['education'] ?? $validated['educational_attainment'] ?? ''));

        if ($guard->columnExists($table, 'education')) {
            $payload['education'] = $value;

            return;
        }

        if ($guard->columnExists($table, 'educational_attainment')) {
            $payload['educational_attainment'] = ResidentMemberFieldMap::educationalAttainmentForStorage($value);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $payload
     */
    private static function mapFpUser(DatabaseSchemaGuard $guard, string $table, array $validated, array &$payload): void
    {
        if (! self::hasAny($validated, ['fp_user', 'is_fp_user'])) {
            return;
        }

        if ($guard->columnExists($table, 'fp_user') && array_key_exists('fp_user', $validated)) {
            $payload['fp_user'] = (string) $validated['fp_user'];

            return;
        }

        if (! $guard->columnExists($table, 'is_fp_user')) {
            return;
        }

        if (array_key_exists('is_fp_user', $validated)) {
            $payload['is_fp_user'] = (bool) $validated['is_fp_user'];

            return;
        }

        $payload['is_fp_user'] = strcasecmp(trim((string) ($validated['fp_user'] ?? '')), 'Yes') === 0;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $keys
     */
    private static function hasAny(array $validated, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $validated)) {
                return true;
            }
        }

        return false;
    }
}
