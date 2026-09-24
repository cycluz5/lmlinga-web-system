<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationTimbangMeasurement extends Model
{
    /** @use HasFactory<\Database\Factories\OperationTimbangMeasurementFactory> */
    use HasFactory;

    protected $table = 'operation_timbang_measurements';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'resident_id',
        'weighed_at',
        'weight_kg',
        'height_cm',
        'muac_cm',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weighed_at' => 'date',
            'weight_kg' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'muac_cm' => 'decimal:2',
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
