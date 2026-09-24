<?php

namespace App\Models;

use App\Support\ChildImmunizationErdMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChildImmunization extends Model
{
    /** @use HasFactory<\Database\Factories\ChildImmunizationFactory> */
    use HasFactory;

    /**
     * Frozen UI vaccine keys that carry date inputs (excludes FIC/CIC checkboxes).
     *
     * @var list<string>
     */
    public const VACCINE_TYPES = [
        'bcg',
        'hepa-b',
        'dpt-hib-hepb',
        'opv',
        'ipv',
        'pcv',
        'mmr',
    ];

    /**
     * Manual Vaccines Type checkbox keys, including FIC/CIC (not dose vaccines).
     *
     * @var list<string>
     */
    public const SELECTABLE_TYPE_KEYS = [
        'bcg',
        'hepa-b',
        'dpt-hib-hepb',
        'opv',
        'ipv',
        'pcv',
        'mmr',
        'fic',
        'cic',
    ];

    /**
     * 0-based dose slot counts matching the frozen Blade vaccine cards.
     *
     * @var array<string, int>
     */
    public const DOSE_SLOT_COUNTS = [
        'bcg' => 2,
        'hepa-b' => 2,
        'dpt-hib-hepb' => 3,
        'opv' => 3,
        'ipv' => 2,
        'pcv' => 3,
        'mmr' => 2,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'resident_id',
        'selected_vaccine_types',
        'remarks',
    ];

    public function getTable(): string
    {
        return ChildImmunizationErdMode::headerTable();
    }

    public function getKeyName(): string
    {
        return ChildImmunizationErdMode::headerPrimaryKey();
    }

    /**
     * @return list<string>
     */
    public function getFillable(): array
    {
        if (ChildImmunizationErdMode::isActive()) {
            return ['resident_id'];
        }

        return $this->fillable;
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        if (ChildImmunizationErdMode::isActive()) {
            return [];
        }

        return [
            'selected_vaccine_types' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', (new Resident)->getKeyName());
    }

    /**
     * @return HasMany<ImmunizationDose, $this>
     */
    public function doses(): HasMany
    {
        return $this->hasMany(ImmunizationDose::class, 'child_immunization_id', $this->getKeyName());
    }
}
