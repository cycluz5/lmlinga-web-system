<?php

namespace App\Models;

use App\Support\DemoDeath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DeathRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'household_no',
        'member_id',
        'resident_name',
        'resident_sex',
        'resident_age',
        'zone',
        'household_display_no',
        'address',
        'cause_of_death',
        'date_of_death',
        'registry_no',
        'certificate_no',
        'certificate_disk',
        'certificate_path',
        'certificate_original_name',
        'certificate_mime',
        'certificate_size',
        'certificate_extension',
        'status',
        'submitted_by_name',
        'submitted_by_role',
        'submitted_at',
        'reviewed_by_name',
        'reviewed_by_role',
        'reviewed_at',
        'rejection_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_death' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'certificate_size' => 'integer',
            'resident_age' => 'integer',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending verification',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            default => 'Unknown',
        };
    }

    public function formattedDateOfDeath(): string
    {
        $iso = $this->date_of_death?->format('Y-m-d');

        return DemoDeath::formatDateForDisplay($iso);
    }

    public function residentIdentityMeta(): string
    {
        $parts = [];

        foreach ([$this->resident_sex, $this->resident_age, $this->member_id] as $part) {
            $value = is_scalar($part) ? trim((string) $part) : '';
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode(' · ', $parts);
    }

    public function householdLocationLabel(): string
    {
        $household = trim((string) ($this->household_display_no ?: $this->household_no));
        $zone = trim((string) $this->zone);

        if ($household !== '' && $zone !== '') {
            return 'Household '.$household.' · '.$zone;
        }

        if ($household !== '') {
            return 'Household '.$household;
        }

        return $zone !== '' ? $zone : '—';
    }

    /**
     * @param  Builder<DeathRequest>  $query
     * @return Builder<DeathRequest>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * @param  Builder<DeathRequest>  $query
     * @return Builder<DeathRequest>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public static function latestForMember(string $householdNo, string $memberId): ?self
    {
        return self::query()
            ->where('household_no', $householdNo)
            ->where('member_id', $memberId)
            ->orderByDesc('id')
            ->first();
    }

    public static function pendingForMember(string $householdNo, string $memberId): ?self
    {
        return self::query()
            ->where('household_no', $householdNo)
            ->where('member_id', $memberId)
            ->pending()
            ->first();
    }

    public static function approvedForMember(string $householdNo, string $memberId): ?self
    {
        return self::query()
            ->where('household_no', $householdNo)
            ->where('member_id', $memberId)
            ->approved()
            ->first();
    }
}
