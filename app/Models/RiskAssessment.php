<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAssessment extends Model
{
    /** @use HasFactory<\Database\Factories\RiskAssessmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conducted_at',
        'red_flags',
        'past_medical',
        'family_history',
        'dietary',
        'tobacco',
        'alcohol',
        'physical_activity',
        'height_cm',
        'weight_kg',
        'bmi',
        'waist_cm',
        'systolic',
        'diastolic',
        'bp_status',
        'bp_reading',
        'bmi_label',
        'visual_no_screening',
        'visual_blurred',
        'visual_blurred_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conducted_at' => 'date',
            'red_flags' => 'array',
            'past_medical' => 'array',
            'family_history' => 'array',
            'dietary' => 'json',
            'height_cm' => 'decimal:2',
            'weight_kg' => 'decimal:2',
            'bmi' => 'decimal:2',
            'waist_cm' => 'decimal:2',
            'systolic' => 'integer',
            'diastolic' => 'integer',
            'visual_no_screening' => 'boolean',
            'visual_blurred' => 'boolean',
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
