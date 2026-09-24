<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    /** @use HasFactory<\Database\Factories\AnnouncementFactory> */
    use HasFactory;

    public const TARGET_ALL = 'all';

    public const TARGET_AGE = 'age';

    public const TARGET_ACTIVE_MATERNAL = 'active_maternal';

    public const TARGET_ACTIVE_FP_USER = 'active_fp_user';

    public const ZONE_ALL = 'all';

    public const ZONE_SPECIFIC = 'specific';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'message',
        'event_date',
        'event_time',
        'place',
        'target_group',
        'age_presets',
        'age_min_months',
        'age_max_months',
        'zone_mode',
        'zones',
        'audience_label',
        'estimated_reach',
        'posted_by_user_id',
        'posted_by_name',
        'posted_by_role',
        'posted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'age_presets' => 'array',
            'zones' => 'array',
            'posted_at' => 'datetime',
            'estimated_reach' => 'integer',
        ];
    }
}
