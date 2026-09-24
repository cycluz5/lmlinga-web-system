<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChildBirthHistory extends Model
{
    /** @use HasFactory<\Database\Factories\ChildBirthHistoryFactory> */
    use HasFactory;
    /**
     * @var list<string>
     */
    protected $fillable = [
        'resident_id',
        'birth_weight_kg',
        'birth_length_cm',
        'status',
        'pcab',
        'breastfeeding_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_weight_kg' => 'decimal:2',
            'birth_length_cm' => 'decimal:2',
            'breastfeeding_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', (new Resident)->getKeyName());
    }
}
