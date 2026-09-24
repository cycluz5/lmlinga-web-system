<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyPlanningVisit extends Model
{
    /** @use HasFactory<\Database\Factories\FamilyPlanningVisitFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'visited_at',
        'remarks',
        'commodities',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visited_at' => 'date',
            'commodities' => 'array',
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
