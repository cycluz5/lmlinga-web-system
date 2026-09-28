<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementAgePreset extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'announcement_age_preset_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'announcement_id',
        'preset',
    ];

    /**
     * @return BelongsTo<Announcement, $this>
     */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class, 'announcement_id');
    }
}
