<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolImmunization extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolImmunizationFactory> */
    use HasFactory;

    /**
     * Frozen UI slot groups for date inputs.
     *
     * @var list<string>
     */
    public const SLOT_GROUPS = [
        'grade-1',
        'grade-7',
        'hpv',
    ];

    /**
     * Manual Vaccines Type checkbox keys. Non-null dated slots also force their key on.
     *
     * @var list<string>
     */
    public const SELECTABLE_TYPE_KEYS = [
        'grade1_td',
        'grade1_mr',
        'grade7_td',
        'grade7_mr',
        'hpv_1',
        'hpv_2',
    ];

    /**
     * Fixed dose slot inventory matching the frozen Blade form (six slots).
     *
     * @var list<array{slot_group: string, slot_key: string}>
     */
    public const DOSE_SLOTS = [
        ['slot_group' => 'grade-1', 'slot_key' => 'td'],
        ['slot_group' => 'grade-1', 'slot_key' => 'mr'],
        ['slot_group' => 'grade-7', 'slot_key' => 'td'],
        ['slot_group' => 'grade-7', 'slot_key' => 'mr'],
        ['slot_group' => 'hpv', 'slot_key' => '1'],
        ['slot_group' => 'hpv', 'slot_key' => '2'],
    ];

    /**
     * Dose slot → Vaccines Type checkbox key (DATE EXISTS → CHECKBOX CHECKED).
     *
     * @var array<string, array<string, string>>
     */
    public const SLOT_TYPE_KEYS = [
        'grade-1' => [
            'td' => 'grade1_td',
            'mr' => 'grade1_mr',
        ],
        'grade-7' => [
            'td' => 'grade7_td',
            'mr' => 'grade7_mr',
        ],
        'hpv' => [
            '1' => 'hpv_1',
            '2' => 'hpv_2',
        ],
    ];

    public static function typeKeyForSlot(string $slotGroup, string $slotKey): ?string
    {
        return self::SLOT_TYPE_KEYS[$slotGroup][$slotKey] ?? null;
    }
    /**
     * @var list<string>
     */
    protected $fillable = [
        'resident_id',
        'selected_vaccine_types',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selected_vaccine_types' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', (new Resident)->getKeyName());
    }

    /**
     * @return HasMany<SchoolImmunizationDose, $this>
     */
    public function doses(): HasMany
    {
        return $this->hasMany(SchoolImmunizationDose::class);
    }
}
