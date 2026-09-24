<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChildNutritionSfpOutcome extends Model
{
    /** @use HasFactory<\Database\Factories\ChildNutritionSfpOutcomeFactory> */
    use HasFactory;

    protected $table = 'child_nutrition_sfp_outcomes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'child_nutrition_id',
        'program',
        'outcome',
        'outcome_date',
        'action_yes_no',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<ChildNutrition, $this>
     */
    public function childNutrition(): BelongsTo
    {
        return $this->belongsTo(ChildNutrition::class);
    }
}
