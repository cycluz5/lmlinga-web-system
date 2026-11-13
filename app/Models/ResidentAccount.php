<?php

namespace App\Models;

use App\Support\UserManagementErdMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

class ResidentAccount extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\ResidentAccountFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'resident_id',
        'first_name',
        'middle_name',
        'last_name',
        'zone',
        'zone_purok',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function getKeyName(): string
    {
        return UserManagementErdMode::residentAccountKeyName();
    }

    public function getIdAttribute(): mixed
    {
        $key = $this->getKeyName();

        return $this->attributes[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
        ];
    }

    /** False until the email_verified_at migration has run (pre-migration databases keep working). */
    public static function supportsEmailVerification(): bool
    {
        return Schema::hasColumn((new static)->getTable(), 'email_verified_at');
    }

    /** Set only by the email OTP flow (never mass assignable). */
    public function hasVerifiedEmail(): bool
    {
        return ($this->attributes['email_verified_at'] ?? null) !== null;
    }

    public function getZoneAttribute(mixed $value): ?string
    {
        if ($value !== null && $value !== '') {
            return (string) $value;
        }

        if (Schema::hasColumn($this->getTable(), 'zone_purok')) {
            return (string) ($this->attributes['zone_purok'] ?? '');
        }

        return null;
    }

    public function setZoneAttribute(mixed $value): void
    {
        $zone = trim((string) $value);

        if (Schema::hasColumn($this->getTable(), 'zone')) {
            $this->attributes['zone'] = $zone;
        }

        if (Schema::hasColumn($this->getTable(), 'zone_purok')) {
            $this->attributes['zone_purok'] = $zone;
        }
    }

    public function getResidentIdAttribute(): mixed
    {
        if (! Schema::hasColumn($this->getTable(), 'resident_id')) {
            return null;
        }

        return $this->attributes['resident_id'] ?? null;
    }

    /**
     * Optional link to an official household-profiling resident (legacy / when column exists).
     *
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        $residentKey = (new Resident)->getKeyName();

        return $this->belongsTo(Resident::class, 'resident_id', $residentKey);
    }
}
