<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use PDOException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Distinguishes schema absence from database unavailability.
 *
 * Schema::hasTable() === false → table genuinely missing (legacy/demo fallback may apply).
 * Query/connection failures must never be treated as "table missing".
 */
class DatabaseSchemaGuard
{
    public function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (QueryException $e) {
            throw new ServiceUnavailableHttpException(
                null,
                'Database is temporarily unavailable.',
                $e
            );
        } catch (PDOException $e) {
            throw new ServiceUnavailableHttpException(
                null,
                'Database is temporarily unavailable.',
                $e
            );
        }
    }

    public function columnExists(string $table, string $column): bool
    {
        if (! $this->tableExists($table)) {
            return false;
        }

        try {
            return Schema::hasColumn($table, $column);
        } catch (QueryException $e) {
            throw new ServiceUnavailableHttpException(
                null,
                'Database is temporarily unavailable.',
                $e
            );
        } catch (PDOException $e) {
            throw new ServiceUnavailableHttpException(
                null,
                'Database is temporarily unavailable.',
                $e
            );
        }
    }

    /**
     * @return Builder<Household>
     */
    public function householdQuery(): Builder
    {
        return $this->withoutSoftDeletesWhenUnsupported(Household::query(), 'households');
    }

    /**
     * @return Builder<Resident>
     */
    public function residentQuery(): Builder
    {
        return $this->withoutSoftDeletesWhenUnsupported(Resident::query(), 'residents');
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function withoutSoftDeletesWhenUnsupported(Builder $query, string $table): Builder
    {
        if (! $this->columnExists($table, 'deleted_at')) {
            return $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query;
    }

    public function residentFpUserColumn(): ?string
    {
        if ($this->columnExists('residents', 'is_fp_user')) {
            return 'is_fp_user';
        }

        if ($this->columnExists('residents', 'fp_user')) {
            return 'fp_user';
        }

        return null;
    }

    /**
     * @param  Builder<Resident>  $query
     */
    public function applyResidentFpCurrentUserScope(Builder $query): bool
    {
        $column = $this->residentFpUserColumn();

        if ($column === null) {
            return false;
        }

        if ($column === 'is_fp_user') {
            $query->where($column, true);

            return true;
        }

        $query->where($column, 'Yes');

        return true;
    }

    public function residentBirthdayColumn(): ?string
    {
        if ($this->columnExists('residents', 'birthday')) {
            return 'birthday';
        }

        if ($this->columnExists('residents', 'birthdate')) {
            return 'birthdate';
        }

        return null;
    }

    public function householdStreetColumn(): ?string
    {
        if ($this->columnExists('households', 'street')) {
            return 'street';
        }

        if ($this->columnExists('households', 'address')) {
            return 'address';
        }

        return null;
    }

    public function householdTypeUsesEnvironmentalProfile(): bool
    {
        return $this->tableExists('household_environmental_profiles')
            && $this->columnExists('household_environmental_profiles', 'household_type');
    }

    public function householdTypeUsesHouseholdColumn(): bool
    {
        return $this->columnExists('households', 'household_type');
    }
}
