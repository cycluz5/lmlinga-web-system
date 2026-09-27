<?php

namespace App\Models;

use App\Support\DeathRecordsErdMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentStatus extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DECEASED = 'deceased';

    protected $fillable = [
        'household_no',
        'member_id',
        'resident_id',
        'status',
        'death_request_id',
        'death_record_id',
        'recorded_at',
    ];

    /**
     * ERD mode: resident_status_id PK, FKs to residents and death_records.
     */
    public function getKeyName(): string
    {
        return DeathRecordsErdMode::isActive() ? 'resident_status_id' : 'id';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'resident_id' => 'integer',
        ];
    }

    public function isDeceased(): bool
    {
        return strcasecmp((string) $this->status, self::STATUS_DECEASED) === 0;
    }

    /**
     * @return BelongsTo<DeathRequest, $this>
     */
    public function deathRequest(): BelongsTo
    {
        return $this->belongsTo(
            DeathRequest::class,
            DeathRecordsErdMode::isActive() ? 'death_record_id' : 'death_request_id'
        );
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public static function forMember(string $householdNo, string $memberId): ?self
    {
        return self::query()
            ->where('household_no', $householdNo)
            ->where('member_id', $memberId)
            ->first();
    }
}
