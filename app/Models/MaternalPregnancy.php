<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaternalPregnancy extends Model
{
    /** @use HasFactory<\Database\Factories\MaternalPregnancyFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_TRANSFERRED_OUT = 'transferred_out';

    /**
     * Clinical / section fields only.
     * Ownership (resident_id, pregnancy_no) and lifecycle (pregnancy_number,
     * status, registered_at) are server-assigned outside mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lmp',
        'gravida',
        'parity',
        'edd',
        'weight',
        'height',
        'bmi',
        'blood_pressure',
        'prenatal',
        'immunizations',
        'supplementations',
        'laboratory',
        'delivery',
        'postnatal',
        'trans_out',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registered_at' => 'date',
            'lmp' => 'date',
            'edd' => 'date',
            'gravida' => 'integer',
            'parity' => 'integer',
            'weight' => 'decimal:2',
            'height' => 'decimal:2',
            'bmi' => 'decimal:1',
            'prenatal' => 'array',
            'immunizations' => 'array',
            'supplementations' => 'array',
            'laboratory' => 'array',
            'delivery' => 'array',
            'postnatal' => 'array',
            'trans_out' => 'array',
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
