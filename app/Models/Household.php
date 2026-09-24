<?php

namespace App\Models;

use App\Support\NonResidentFamilyPlanningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;

class Household extends Model
{
    /** @use HasFactory<\Database\Factories\HouseholdFactory> */
    use HasFactory;

    private static ?string $resolvedKeyName = null;

    public static function resetResolvedKeyName(): void
    {
        static::$resolvedKeyName = null;
    }

    public function getKeyName(): string
    {
        if (static::$resolvedKeyName === null) {
            static::$resolvedKeyName = Schema::hasColumn($this->getTable(), 'household_id')
                ? 'household_id'
                : 'id';
        }

        return static::$resolvedKeyName;
    }

    public function getIdAttribute(): mixed
    {
        $keyName = $this->getKeyName();

        if ($keyName === 'id') {
            return $this->attributes['id'] ?? null;
        }

        return $this->attributes[$keyName] ?? null;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'household_no',
        'zone',
        'purok',
        'street',
        'date_registered',
        'address',
        'latitude',
        'longitude',
        'accomplished_by',
        'household_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_registered' => 'date',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
        ];
    }

    /**
     * Exclude the synthetic Non-Resident Family Planning sentinel household.
     *
     * @param  Builder<Household>  $query
     * @return Builder<Household>
     */
    public function scopeExcludingNonResidentSentinel(Builder $query): Builder
    {
        if (! Schema::hasColumn($this->getTable(), 'household_no')) {
            return $query;
        }

        return $query->where(
            $this->getTable().'.household_no',
            '!=',
            NonResidentFamilyPlanningService::SENTINEL_HOUSEHOLD_NO
        );
    }

    /**
     * Primary key of the NR-FP sentinel household, or null when it is absent.
     */
    public static function nonResidentSentinelKey(): mixed
    {
        $model = new static;
        if (! Schema::hasColumn($model->getTable(), 'household_no')) {
            return null;
        }

        return static::query()
            ->where('household_no', NonResidentFamilyPlanningService::SENTINEL_HOUSEHOLD_NO)
            ->value($model->getKeyName());
    }

    /**
     * @return HasMany<Resident, $this>
     */
    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class, 'household_id', $this->getKeyName());
    }

    /**
     * DB-17 Phase 2B — one current environmental/amenities profile per household.
     *
     * @return HasOne<HouseholdEnvironmentalProfile, $this>
     */
    public function environmentalProfile(): HasOne
    {
        return $this->hasOne(HouseholdEnvironmentalProfile::class, 'household_id', $this->getKeyName());
    }
}
