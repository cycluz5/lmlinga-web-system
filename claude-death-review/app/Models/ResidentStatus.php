<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentStatus extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DECEASED = 'deceased';

    protected $fillable = [
        'household_no',
        'member_id',
        'status',
        'death_request_id',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
        ];
    }

    public function isDeceased(): bool
    {
        return $this->status === self::STATUS_DECEASED;
    }

    /**
     * @return BelongsTo<DeathRequest, $this>
     */
    public function deathRequest(): BelongsTo
    {
        return $this->belongsTo(DeathRequest::class);
    }

    public static function forMember(string $householdNo, string $memberId): ?self
    {
        return self::query()
            ->where('household_no', $householdNo)
            ->where('member_id', $memberId)
            ->first();
    }
}
