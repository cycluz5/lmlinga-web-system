<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementZone extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'announcement_zone_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'announcement_id',
        'zone',
    ];

    /**
     * @return BelongsTo<Announcement, $this>
     */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class, 'announcement_id');
    }
}
