<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolImmunizationDose extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolImmunizationDoseFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'school_immunization_id',
        'slot_group',
        'slot_key',
        'date_given',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_given' => 'date',
        ];
    }

    /**
     * @return BelongsTo<SchoolImmunization, $this>
     */
    public function schoolImmunization(): BelongsTo
    {
        return $this->belongsTo(SchoolImmunization::class);
    }
}
