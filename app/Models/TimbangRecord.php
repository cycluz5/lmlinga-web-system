<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimbangRecord extends Model
{
    /** @use HasFactory<\Database\Factories\TimbangRecordFactory> */
    use HasFactory;

    protected $table = 'timbang_records';

    /**
     * Weight-for-Age classification, per the National Nutrition Council
     * (DOH) Child Growth Standards reference
     * (resources/growth-references/nnc_growth_standards.json). Widened from
     * the original 3-value paper-ERD ENUM to add 'Overweight', which that
     * reference legitimately produces — do not invent further labels
     * beyond what the reference supports.
     *
     * @var list<string>
     */
    public const WEIGHT_FOR_AGE = [
        'Severely Underweight',
        'Underweight',
        'Normal',
        'Overweight',
    ];

    /**
     * Height-for-Age classification, per the same NNC reference. Widened
     * from the original 3-value paper-ERD ENUM to add 'Tall'.
     *
     * @var list<string>
     */
    public const HEIGHT_FOR_AGE = [
        'Severely Stunted',
        'Stunted',
        'Normal',
        'Tall',
    ];

    /**
     * Weight-for-Length/Height (0-59 completed months). WHO SD-position
     * reference (resources/growth-references/who_weight_for_length_height_zscore.csv).
     * Unlike WEIGHT_FOR_AGE/HEIGHT_FOR_AGE, weight_for_height was never a
     * paper-ERD ENUM (plain varchar(50)), so these are simply the WHO
     * standard's own 5 labels — no widening needed.
     *
     * @var list<string>
     */
    public const WEIGHT_FOR_HEIGHT = [
        'Severely Wasted',
        'Wasted',
        'Normal',
        'Possible Risk of Overweight',
        'Overweight',
    ];

    /**
     * MUAC (6-59 completed months only). Absolute-cm thresholds from
     * resources/growth-references/who_status_thresholds.csv. 'N/A' means the
     * resident's age at measurement was outside the applicable band.
     *
     * @var list<string>
     */
    public const MUAC_STATUS = [
        'Severe Acute Malnutrition (SAM)',
        'Moderate Acute Malnutrition (MAM)',
        'Normal',
        'N/A',
    ];

    /**
     * Adult (19y+) BMI classification. Shared with HealthRecordsRiskAssessment
     * via RiskAssessmentClinicalValues::classifyAdultBmi() — one standard only.
     *
     * @var list<string>
     */
    public const ADULT_BMI_STATUS = [
        'Underweight',
        'Normal',
        'Overweight',
        'Obese',
    ];

    /**
     * Sentinel bmi_status text used when a numeric BMI exists but no
     * approved BMI-for-Age (5-19y) threshold reference is available to
     * classify it. Never invented thresholds. (Height-for-Age no longer
     * needs this — see HEIGHT_FOR_AGE — now that the NNC reference covers it.)
     */
    public const REFERENCE_UNAVAILABLE = 'Reference data required';

    /**
     * Centralized severity labels for overall_nutritional_status. Worst
     * applicable indicator wins; indicators with no concrete result never
     * contribute. See NutritionAssessmentService::determineOverallStatus().
     *
     * @var list<string>
     */
    public const OVERALL_STATUS = [
        'Normal',
        'At Risk',
        'Malnourished',
        'Severely Malnourished',
    ];

    public function getKeyName(): string
    {
        return 'timbang_id';
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'resident_id',
        'measurement_date',
        'weight_kg',
        'height_cm',
        'muac_cm',
        'muac_status',
        'weight_for_age',
        'height_for_age',
        'weight_for_height',
        'bmi_value',
        'bmi_status',
        'overall_nutritional_status',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'measurement_date' => 'date',
            'weight_kg' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'muac_cm' => 'decimal:1',
            'bmi_value' => 'decimal:1',
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
