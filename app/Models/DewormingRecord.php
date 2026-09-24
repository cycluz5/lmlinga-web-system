<?php

namespace App\Models;

use App\Support\DewormingErdMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DewormingRecord extends Model
{
    /** @use HasFactory<\Database\Factories\DewormingRecordFactory> */
    use HasFactory;

    public function getKeyName(): string
    {
        return DewormingErdMode::primaryKey();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'resident_id',
        'year',
        'round',
        'se_status',
        'date_given',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'date_given' => 'date',
        ];
    }

    public function getRoundAttribute(): ?int
    {
        $column = DewormingErdMode::roundColumn();
        $value = $this->attributes[$column] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function setRoundAttribute(mixed $value): void
    {
        $this->attributes[DewormingErdMode::roundColumn()] = $value;
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', (new Resident)->getKeyName());
    }
}
