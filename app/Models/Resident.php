<?php

namespace App\Models;

use App\Support\ResidentMemberIdentity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;

class Resident extends Model
{
    /** @use HasFactory<\Database\Factories\ResidentFactory> */
    use HasFactory;

    private static ?string $resolvedKeyName = null;

    public static function resetResolvedKeyName(): void
    {
        static::$resolvedKeyName = null;
    }

    public function getKeyName(): string
    {
        if (static::$resolvedKeyName === null) {
            static::$resolvedKeyName = Schema::hasColumn($this->getTable(), 'resident_id')
                ? 'resident_id'
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
        'household_id',
        'member_no',
        'last_name',
        'first_name',
        'middle_name',
        'relation',
        'relation_to_household_head',
        'birthday',
        'sex',
        'relationship_status',
        'civil_status',
        'occupation',
        'occupation_id',
        'occupation_other',
        'monthly_income',
        'religion',
        'religion_id',
        'religion_other',
        'education',
        'educational_attainment',
        'fp_user',
        'is_fp_user',
        'philhealth',
        'philhealth_number',
        'disability',
        'disability_others',
        'medical_history',
        'medical_others',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'disability' => 'array',
            'medical_history' => 'array',
            'is_fp_user' => 'boolean',
        ];
    }

    public function getMemberNoAttribute(mixed $value): ?string
    {
        if (ResidentMemberIdentity::hasMemberNoColumn()) {
            return $value;
        }

        if (! isset($this->attributes[(new self)->getKeyName()])) {
            return null;
        }

        return ResidentMemberIdentity::memberIdFor($this);
    }

    public function getRelationAttribute(mixed $value): ?string
    {
        if ($value !== null && $value !== '') {
            return (string) $value;
        }

        if (Schema::hasColumn($this->getTable(), 'relation_to_household_head')) {
            return (string) ($this->attributes['relation_to_household_head'] ?? '');
        }

        return $value !== null ? (string) $value : null;
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'household_id', (new Household)->getKeyName());
    }

    public static function occupationLookupAvailable(): bool
    {
        return Schema::hasTable('occupation')
            && Schema::hasColumn((new self)->getTable(), 'occupation_id');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Resident>|\Illuminate\Database\Eloquent\Relations\HasMany<Resident, Household>  $query
     */
    public static function eagerLoadForProfiling($query): void
    {
        $query->orderBy((new self)->getKeyName());

        if (self::occupationLookupAvailable()) {
            $query->with('occupationLookup');
        }

        if (self::religionLookupAvailable()) {
            $query->with('religionLookup');
        }

        if (Schema::hasTable('disability_type')) {
            $query->with('disabilityType');
        }

        if (Schema::hasTable('medical_history')) {
            $query->with('medicalHistoryEntry');
        }
    }

    /**
     * Authoritative ERD occupation lookup. Do not confuse with legacy residents.occupation.
     *
     * @return BelongsTo<Occupation, $this>
     */
    public function occupationLookup(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id', 'occupation_id');
    }

    public static function religionLookupAvailable(): bool
    {
        return Schema::hasTable('religion')
            && Schema::hasColumn((new self)->getTable(), 'religion_id');
    }

    /**
     * Authoritative ERD religion lookup. Do not confuse with legacy residents.religion.
     *
     * @return BelongsTo<Religion, $this>
     */
    public function religionLookup(): BelongsTo
    {
        return $this->belongsTo(Religion::class, 'religion_id', 'religion_id');
    }

    /**
     * @return HasOne<DisabilityType, $this>
     */
    public function disabilityType(): HasOne
    {
        return $this->hasOne(DisabilityType::class, 'resident_id', $this->getKeyName());
    }

    /**
     * @return HasOne<MedicalHistory, $this>
     */
    public function medicalHistoryEntry(): HasOne
    {
        return $this->hasOne(MedicalHistory::class, 'resident_id', $this->getKeyName());
    }

    /**
     * Household-head identification from authoritative resident fields only.
     * Never invents a head from display names or member order.
     * `is_household_head = 1` is a positive match; 0/null still falls through to relation fields.
     */
    public function isHouseholdHead(): bool
    {
        $attributes = $this->getAttributes();

        if (array_key_exists('is_household_head', $attributes) && (int) $attributes['is_household_head'] === 1) {
            return true;
        }

        $relation = strtolower(preg_replace(
            '/\s+/',
            ' ',
            trim($this->rawHouseholdHeadRelation($attributes))
        ) ?? '');

        return in_array($relation, [
            'head',
            'household head',
            'head of household',
            'hh head',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rawHouseholdHeadRelation(array $attributes): string
    {
        foreach (['relation', 'relation_to_household_head'] as $column) {
            if (! array_key_exists($column, $attributes)) {
                continue;
            }

            $value = trim((string) $attributes[$column]);
            if ($value !== '') {
                return $value;
            }
        }

        return trim((string) ($this->relation ?? ''));
    }

    /**
     * @return HasMany<DeathRequest, $this>
     */
    public function deathRequests(): HasMany
    {
        return $this->hasMany(DeathRequest::class);
    }

    /**
     * @return HasMany<ResidentStatus, $this>
     */
    public function residentStatuses(): HasMany
    {
        return $this->hasMany(ResidentStatus::class);
    }

    /**
     * @return HasOne<ChildBirthHistory, $this>
     */
    public function childBirthHistory(): HasOne
    {
        return $this->hasOne(ChildBirthHistory::class, 'resident_id', $this->getKeyName());
    }

    /**
     * @return HasMany<DewormingRecord, $this>
     */
    public function dewormingRecords(): HasMany
    {
        return $this->hasMany(DewormingRecord::class, 'resident_id', $this->getKeyName());
    }

    /**
     * DB-08 Phase 1 — one Child Immunization header per resident.
     *
     * @return HasOne<ChildImmunization, $this>
     */
    public function childImmunization(): HasOne
    {
        return $this->hasOne(ChildImmunization::class, 'resident_id', $this->getKeyName());
    }

    /**
     * DB-09 Phase 1 — one School-Based Immunization header per resident.
     *
     * @return HasOne<SchoolImmunization, $this>
     */
    public function schoolImmunization(): HasOne
    {
        return $this->hasOne(SchoolImmunization::class, 'resident_id', $this->getKeyName());
    }

    /**
     * DB-10 Phase 2 — one Child Nutrition worksheet per resident.
     *
     * @return HasOne<ChildNutrition, $this>
     */
    public function childNutrition(): HasOne
    {
        return $this->hasOne(ChildNutrition::class, 'resident_id', $this->getKeyName());
    }

    /**
     * DB-12 Phase 2 — Risk Assessment history rows per resident.
     *
     * @return HasMany<RiskAssessment, $this>
     */
    public function riskAssessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class, 'resident_id', $this->getKeyName());
    }

    /**
     * DB-13 Phase 2 — Family Planning visit history rows per resident.
     *
     * @return HasMany<FamilyPlanningVisit, $this>
     */
    public function familyPlanningVisits(): HasMany
    {
        return $this->hasMany(FamilyPlanningVisit::class, 'resident_id', $this->getKeyName());
    }

    /**
     * DB-14 Phase 2 — Maternal Care pregnancy history rows per resident.
     *
     * @return HasMany<MaternalPregnancy, $this>
     */
    public function maternalPregnancies(): HasMany
    {
        return $this->hasMany(MaternalPregnancy::class, 'resident_id', $this->getKeyName());
    }

    /**
     * DB-16 — Operation Timbang longitudinal weigh-in measurements per resident.
     *
     * @return HasMany<OperationTimbangMeasurement, $this>
     */
    public function operationTimbangMeasurements(): HasMany
    {
        return $this->hasMany(OperationTimbangMeasurement::class, 'resident_id', $this->getKeyName());
    }

    /**
     * FR-12 — paper ERD timbang_records measurement events.
     *
     * @return HasMany<TimbangRecord, $this>
     */
    public function timbangRecords(): HasMany
    {
        return $this->hasMany(TimbangRecord::class, 'resident_id', $this->getKeyName());
    }
}
