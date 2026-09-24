<?php

namespace App\Models;

use App\Support\ChildImmunizationErdMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImmunizationDose extends Model
{
    /** @use HasFactory<\Database\Factories\ImmunizationDoseFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'child_immunization_id',
        'vaccine_type',
        'dose_index',
        'date_given',
    ];

    public function getKeyName(): string
    {
        return ChildImmunizationErdMode::dosePrimaryKey();
    }

    /**
     * @return list<string>
     */
    public function getFillable(): array
    {
        if (ChildImmunizationErdMode::doseSequenceColumn() === 'dose_number') {
            return [
                'child_immunization_id',
                'vaccine_type',
                'dose_number',
                'date_given',
            ];
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

    public function getVaccineTypeAttribute(mixed $value): string
    {
        $stored = trim((string) $value);

        if (! ChildImmunizationErdMode::usesErdVaccineTypeLabels()) {
            return $stored;
        }

        return ChildImmunizationErdMode::uiKeyFromStoredVaccineType($stored);
    }

    public function setVaccineTypeAttribute(mixed $value): void
    {
        $raw = trim((string) $value);

        $this->attributes['vaccine_type'] = ChildImmunizationErdMode::usesErdVaccineTypeLabels()
            ? ChildImmunizationErdMode::storedVaccineTypeFromUiKey($raw)
            : $raw;
    }

    public function getDoseIndexAttribute(mixed $value): ?int
    {
        if (ChildImmunizationErdMode::doseSequenceColumn() !== 'dose_number') {
            return $value === null ? null : (int) $value;
        }

        $doseNumber = $this->attributes['dose_number'] ?? null;

        return $doseNumber === null
            ? null
            : ChildImmunizationErdMode::indexFromDoseNumber((int) $doseNumber);
    }

    public function setDoseIndexAttribute(mixed $value): void
    {
        $index = (int) $value;

        if (ChildImmunizationErdMode::doseSequenceColumn() === 'dose_number') {
            $this->attributes['dose_number'] = ChildImmunizationErdMode::doseNumberFromIndex($index);

            return;
        }

        $this->attributes['dose_index'] = $index;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        $casts = [
            'date_given' => 'date',
        ];

        if (ChildImmunizationErdMode::doseSequenceColumn() === 'dose_number') {
            $casts['dose_number'] = 'integer';
        } else {
            $casts['dose_index'] = 'integer';
        }

        return $casts;
    }

    /**
     * @return BelongsTo<ChildImmunization, $this>
     */
    public function childImmunization(): BelongsTo
    {
        return $this->belongsTo(
            ChildImmunization::class,
            'child_immunization_id',
            (new ChildImmunization)->getKeyName()
        );
    }
}
