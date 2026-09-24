<?php

namespace App\Models;

use App\Casts\AtRestEncrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalHistory extends Model
{
    protected $table = 'medical_history';

    protected $primaryKey = 'medical_history_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'resident_id',
        'no_medical_history',
        'diabetes_mellitus',
        'heart_disease',
        'hypertension',
        'kidney_disease',
        'tuberculosis',
        'other_medical_history',
    ];

    /**
     * AES-256-GCM at rest (see AtRestColumns).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'no_medical_history' => AtRestEncrypted::class,
            'diabetes_mellitus' => AtRestEncrypted::class,
            'heart_disease' => AtRestEncrypted::class,
            'hypertension' => AtRestEncrypted::class,
            'kidney_disease' => AtRestEncrypted::class,
            'tuberculosis' => AtRestEncrypted::class,
            'other_medical_history' => AtRestEncrypted::class,
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
