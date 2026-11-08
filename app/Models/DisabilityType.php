<?php

namespace App\Models;

use App\Casts\AtRestEncrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisabilityType extends Model
{
    protected $table = 'disability_type';

    protected $primaryKey = 'disability_type_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'resident_id',
        'no_disability',
        'intellectual_disability',
        'mental_disability',
        'physical_disability',
        'other_disability',
        'other_disability_specify',
    ];

    /**
     * AES-256-GCM at rest (see AtRestColumns).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'no_disability' => AtRestEncrypted::class,
            'intellectual_disability' => AtRestEncrypted::class,
            'mental_disability' => AtRestEncrypted::class,
            'physical_disability' => AtRestEncrypted::class,
            'other_disability' => AtRestEncrypted::class,
            'other_disability_specify' => AtRestEncrypted::class,
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
