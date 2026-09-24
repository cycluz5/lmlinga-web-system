<?php

namespace App\Models;

use App\Support\AtRestColumns;
use App\Support\AtRestRecord;
use App\Support\DeathRecordsErdMode;
use App\Support\DemoCatalog;
use App\Support\DemoDeath;
use App\Support\HouseholdProfilingPresenter;
use App\Support\ResidentMemberIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

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

    public $timestamps = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'household_no',
        'member_id',
        'resident_id',
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
        'death_certificate_no',
        'death_certificate_file_path',
        'verification_status',
        'submitted_by',
        'verified_by',
        'verified_at',
    ];

    public function getTable(): string
    {
        return DeathRecordsErdMode::isActive() ? 'death_records' : 'death_requests';
    }

    public function getKeyName(): string
    {
        return DeathRecordsErdMode::isActive() ? 'death_record_id' : 'id';
    }

    public function getRouteKeyName(): string
    {
        return $this->getKeyName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_death' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'certificate_size' => 'integer',
            'resident_age' => 'integer',
            'resident_id' => 'integer',
            'submitted_by' => 'integer',
            'verified_by' => 'integer',
        ];
    }

    /**
     * AES-256-GCM for death_records.cause_of_death (see AtRestColumns).
     *
     * @return Attribute<string|null, string|null>
     */
    protected function causeOfDeath(): Attribute
    {
        return $this->atRestAttribute('cause_of_death');
    }

    /**
     * AES-256-GCM for death_records.death_certificate_no (Registry Number).
     *
     * @return Attribute<string|null, string|null>
     */
    protected function deathCertificateNo(): Attribute
    {
        return $this->atRestAttribute('death_certificate_no');
    }

    /**
     * Legacy death_requests columns are not registered and pass through unchanged.
     *
     * @return Attribute<string|null, string|null>
     */
    private function atRestAttribute(string $column): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): mixed => AtRestColumns::isEncrypted($this->getTable(), $column)
                ? AtRestRecord::open($value, $this->getTable(), $column)
                : $value,
            set: fn (mixed $value): mixed => AtRestColumns::isEncrypted($this->getTable(), $column)
                ? AtRestRecord::seal($value, $this->getTable(), $column)
                : $value,
        );
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', (new Resident)->getKeyName());
    }

    public function getAttribute($key): mixed
    {
        if (DeathRecordsErdMode::isActive()) {
            return $this->erdAttribute($key);
        }

        return parent::getAttribute($key);
    }

    public function setAttribute($key, $value): mixed
    {
        if (DeathRecordsErdMode::isActive()) {
            return $this->setErdAttribute($key, $value);
        }

        return parent::setAttribute($key, $value);
    }

    private function erdAttribute(string $key): mixed
    {
        return match ($key) {
            'id', 'death_record_id' => parent::getAttribute('death_record_id'),
            'status' => DeathRecordsErdMode::appStatusFromErd(parent::getAttribute('verification_status')),
            'certificate_no', 'death_certificate_no', 'registry_no' => (string) parent::getAttribute('death_certificate_no'),
            'certificate_path', 'death_certificate_file_path' => (string) parent::getAttribute('death_certificate_file_path'),
            'certificate_disk' => \App\Support\DeathCertificateStorage::DISK,
            'submitted_at' => parent::getAttribute('created_at'),
            'reviewed_at' => parent::getAttribute('verified_at'),
            'household_no' => $this->erdHouseholdNo(),
            'member_id' => $this->erdMemberId(),
            'resident_name' => $this->erdResidentName(),
            'resident_sex' => $this->erdResidentSex(),
            'resident_age' => $this->erdResidentAge(),
            'zone' => $this->erdZone(),
            'household_display_no' => $this->erdHouseholdDisplayNo(),
            'address' => $this->erdAddress(),
            'submitted_by_name' => $this->erdStaffName(parent::getAttribute('submitted_by')),
            'submitted_by_role' => '',
            'reviewed_by_name' => $this->erdStaffName(parent::getAttribute('verified_by')),
            'reviewed_by_role' => '',
            default => parent::getAttribute($key),
        };
    }

    private function setErdAttribute(string $key, mixed $value): mixed
    {
        return match ($key) {
            'status', 'verification_status' => parent::setAttribute(
                'verification_status',
                is_string($value) && ! str_contains($value, ' ')
                    ? DeathRecordsErdMode::erdStatusFromApp($value)
                    : $value
            ),
            'certificate_no', 'death_certificate_no', 'registry_no' => parent::setAttribute('death_certificate_no', $value),
            'certificate_path', 'death_certificate_file_path' => parent::setAttribute('death_certificate_file_path', $value),
            'submitted_at', 'created_at' => parent::setAttribute('created_at', $value),
            'reviewed_at', 'verified_at' => parent::setAttribute('verified_at', $value),
            'submitted_by_name', 'submitted_by_role', 'reviewed_by_name', 'reviewed_by_role' => $this,
            'household_no', 'member_id', 'resident_name', 'resident_sex', 'resident_age', 'zone', 'household_display_no', 'address' => $this,
            'certificate_disk', 'certificate_original_name', 'certificate_mime', 'certificate_size', 'certificate_extension' => $this,
            default => parent::setAttribute($key, $value),
        };
    }

    private function erdHouseholdNo(): string
    {
        $resident = $this->relationLoaded('resident') ? $this->resident : $this->resident()->with('household')->first();
        $householdNo = trim((string) ($resident?->household?->household_no ?? ''));

        return $householdNo !== '' ? DemoCatalog::normalizeHouseholdNo($householdNo) : '';
    }

    private function erdMemberId(): string
    {
        $resident = $this->relationLoaded('resident') ? $this->resident : $this->resident()->first();

        return $resident instanceof Resident ? ResidentMemberIdentity::memberIdFor($resident) : '';
    }

    private function erdResidentName(): string
    {
        $resident = $this->relationLoaded('resident') ? $this->resident : $this->resident()->first();

        return $resident instanceof Resident ? HouseholdProfilingPresenter::fullName($resident) : '';
    }

    private function erdResidentSex(): string
    {
        $resident = $this->relationLoaded('resident') ? $this->resident : $this->resident()->first();

        return $resident instanceof Resident ? (string) $resident->sex : '';
    }

    private function erdResidentAge(): ?int
    {
        $resident = $this->relationLoaded('resident') ? $this->resident : $this->resident()->first();
        if (! $resident instanceof Resident || $resident->birthday === null) {
            return null;
        }

        return $resident->birthday->age;
    }

    private function erdZone(): string
    {
        $household = $this->relationLoaded('resident')
            ? $this->resident?->household
            : $this->resident()->with('household')->first()?->household;

        if ($household === null) {
            return '';
        }

        if (Schema::hasColumn($household->getTable(), 'zone')) {
            return trim((string) ($household->zone ?? ''));
        }

        return trim((string) ($household->getAttributes()['purok'] ?? ''));
    }

    private function erdHouseholdDisplayNo(): string
    {
        $householdNo = $this->erdHouseholdNo();
        if ($householdNo === '') {
            return '';
        }

        return preg_replace('/^HH-/i', 'HH ', $householdNo) ?: $householdNo;
    }

    private function erdAddress(): string
    {
        $household = $this->relationLoaded('resident')
            ? $this->resident?->household
            : $this->resident()->with('household')->first()?->household;

        if ($household === null) {
            return '';
        }

        $address = trim((string) ($household->address ?? ''));

        return $address !== '' ? $address : trim((string) ($household->street ?? ''));
    }

    private function erdStaffName(mixed $userId): string
    {
        if (! is_numeric($userId) || (int) $userId <= 0 || ! Schema::hasTable('user_management')) {
            return '';
        }

        $row = \Illuminate\Support\Facades\DB::table('user_management')
            ->where('user_id', (int) $userId)
            ->first(['first_name', 'last_name']);

        if ($row === null) {
            return '';
        }

        return trim(implode(' ', array_filter([
            trim((string) ($row->first_name ?? '')),
            trim((string) ($row->last_name ?? '')),
        ])));
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

    public function displayRegistryNo(): string
    {
        // Canonical user-facing identifier. ERD has no registry_no column;
        // death_records.death_certificate_no is Registry Number. Do not return
        // empty/"—" when that column has a value, and do not add a migration.
        if (DeathRecordsErdMode::isActive()) {
            return trim((string) $this->death_certificate_no);
        }

        $registry = trim((string) $this->registry_no);
        if ($registry !== '') {
            return $registry;
        }

        return trim((string) $this->certificate_no);
    }

    /**
     * @deprecated Use displayRegistryNo(). Alias kept for legacy callers; not used by Blade.
     */
    public function displayCertificateNo(): string
    {
        return $this->displayRegistryNo();
    }

    public function displayCertificateFileName(): string
    {
        if (DeathRecordsErdMode::isActive()) {
            $path = trim((string) $this->certificate_path);
            if ($path === '' || $path === 'pending') {
                return '';
            }

            return DemoDeath::safeFilename(basename($path));
        }

        return trim((string) $this->certificate_original_name);
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
        if (DeathRecordsErdMode::isActive()) {
            return $query->where('verification_status', DeathRecordsErdMode::pendingVerificationStatus());
        }

        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * @param  Builder<DeathRequest>  $query
     * @return Builder<DeathRequest>
     */
    public function scopeApproved(Builder $query): Builder
    {
        if (DeathRecordsErdMode::isActive()) {
            return $query->where('verification_status', 'Verified');
        }

        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * @param  Builder<DeathRequest>  $query
     * @return Builder<DeathRequest>
     */
    public function scopeRejected(Builder $query): Builder
    {
        if (DeathRecordsErdMode::isActive()) {
            return $query->where('verification_status', 'Rejected');
        }

        return $query->where('status', self::STATUS_REJECTED);
    }

    public static function latestForMember(string $householdNo, string $memberId): ?self
    {
        if (DeathRecordsErdMode::isActive()) {
            $resolved = app(\App\Support\HouseholdMemberResolver::class)->resolveMember($householdNo, $memberId);
            $resident = $resolved['resident'] ?? null;
            if (! $resident instanceof Resident) {
                return null;
            }

            return self::query()
                ->with(['resident.household'])
                ->where('resident_id', $resident->id)
                ->first();
        }

        return self::query()
            ->where('household_no', DemoCatalog::normalizeHouseholdNo($householdNo))
            ->where('member_id', DemoCatalog::normalizeMemberId($memberId))
            ->orderByDesc((new self)->getKeyName())
            ->first();
    }

    public static function pendingForMember(string $householdNo, string $memberId): ?self
    {
        $latest = self::latestForMember($householdNo, $memberId);

        return $latest !== null && $latest->isPending() ? $latest : null;
    }

    public static function approvedForMember(string $householdNo, string $memberId): ?self
    {
        $latest = self::latestForMember($householdNo, $memberId);

        return $latest !== null && $latest->isApproved() ? $latest : null;
    }

    public static function pendingForResident(int $residentId): ?self
    {
        if ($residentId <= 0) {
            return null;
        }

        return self::query()
            ->where('resident_id', $residentId)
            ->pending()
            ->first();
    }

    public static function approvedForResident(int $residentId): ?self
    {
        if ($residentId <= 0) {
            return null;
        }

        return self::query()
            ->where('resident_id', $residentId)
            ->approved()
            ->first();
    }
}
