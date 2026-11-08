<?php

namespace App\Models;

use App\Models\Scopes\StaffNotDeletedScope;
use App\Support\StaffAccountStatus;
use App\Support\StaffProfilePhotoStorage;
use App\Support\StaffRole;
use App\Support\UiRole;
use App\Support\UserManagementErdMode;
use App\Support\WorkerAssignedZones;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'photo_path',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'sex',
        'date_of_birth',
        'civil_status',
        'nationality',
        'mobile_number',
        'email',
        'house_no',
        'street',
        'purok_zone',
        'barangay',
        'municipality_city',
        'province',
        'zip_code',
        'username',
        'password',
        'status',
        'must_change_password',
        'created_by',
        'email_verified_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'date_of_birth' => 'date',
            'deleted_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        return UserManagementErdMode::isActive() ? 'user_management' : 'users';
    }

    public function getKeyName(): string
    {
        return UserManagementErdMode::staffKeyName();
    }

    public function getIdAttribute(): mixed
    {
        $key = $this->getKeyName();

        return $this->attributes[$key] ?? null;
    }

    public function getNameAttribute(mixed $value): string
    {
        if ($value !== null && trim((string) $value) !== '') {
            return (string) $value;
        }

        return $this->composeDisplayName();
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new StaffNotDeletedScope);

        static::saving(function (User $user): void {
            if ($user->status !== null) {
                $normalized = StaffAccountStatus::normalize($user->status);
                if ($normalized === null) {
                    throw ValidationException::withMessages([
                        'status' => 'Invalid staff account status.',
                    ]);
                }
                $user->status = UserManagementErdMode::isActive()
                    ? ($normalized === StaffAccountStatus::INACTIVE ? 'Suspended' : StaffAccountStatus::ACTIVE)
                    : $normalized;
            }

            $hasNameColumn = Schema::hasColumn($user->getTable(), 'name');
            if ($hasNameColumn && ($user->isDirty(['first_name', 'middle_name', 'last_name', 'suffix']) || $user->name === null || $user->name === '')) {
                $user->name = $user->composeDisplayName();
            }
        });
    }

    public function composeDisplayName(): string
    {
        $parts = array_filter([
            trim((string) $this->first_name),
            trim((string) $this->middle_name),
            trim((string) $this->last_name),
        ], static fn (string $part): bool => $part !== '');

        $base = implode(' ', $parts);
        $suffix = trim((string) $this->suffix);
        if ($suffix !== '' && strcasecmp($suffix, 'N/A') !== 0) {
            $base = trim($base.' '.$suffix);
        }

        if ($base !== '') {
            return $base;
        }

        return trim((string) $this->name) !== ''
            ? (string) $this->name
            : (string) ($this->email ?? 'Staff User');
    }

    /**
     * Effective shell role from the current worker appointment (authoritative).
     * Exposed as `role` so UiRole::current() keeps working with Auth::user().
     */
    public function getRoleAttribute(): ?string
    {
        return StaffRole::normalize($this->resolveCurrentAppointment()?->role);
    }

    public function resolveCurrentAppointment(): ?WorkerAppointment
    {
        if ($this->relationLoaded('currentAppointment') && $this->currentAppointment !== null) {
            return $this->currentAppointment;
        }

        if (UserManagementErdMode::isActive()) {
            $open = $this->appointments()
                ->whereNull('end_of_appointment')
                ->orderByDesc('date_appointed')
                ->first();

            if ($open !== null) {
                return $open;
            }

            return $this->appointments()->orderByDesc('date_appointed')->first();
        }

        return $this->currentAppointment()->first();
    }

    public function isActive(): bool
    {
        if ($this->isDeleted()) {
            return false;
        }

        return StaffAccountStatus::normalize($this->status) === StaffAccountStatus::ACTIVE;
    }

    public function isDeleted(): bool
    {
        if (! UserManagementErdMode::staffHasDeletedAt()) {
            return false;
        }

        return $this->deleted_at !== null;
    }

    /**
     * Staff lookup including soft-deleted identity rows (audit / lock paths).
     *
     * @return Builder<static>
     */
    public static function queryWithDeleted(): Builder
    {
        return static::query()->withoutGlobalScope(StaffNotDeletedScope::class);
    }

    /**
     * Public URL for a managed staff profile photo, or null for the UI placeholder.
     */
    public function profilePhotoUrl(): ?string
    {
        return StaffProfilePhotoStorage::url($this->photo_path);
    }

    public function deactivate(): void
    {
        $this->forceFill(['status' => StaffAccountStatus::INACTIVE])->save();
    }

    public function activate(): void
    {
        $this->forceFill(['status' => StaffAccountStatus::ACTIVE])->save();
    }

    /**
     * @return HasMany<WorkerAppointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(WorkerAppointment::class, 'user_id', $this->getKeyName());
    }

    /**
     * @return HasOne<WorkerAppointment, $this>
     */
    public function currentAppointment(): HasOne
    {
        $relation = $this->hasOne(WorkerAppointment::class, 'user_id', $this->getKeyName());

        if (UserManagementErdMode::isActive()) {
            return $relation
                ->whereNull('end_of_appointment')
                ->latestOfMany('date_appointed');
        }

        return $relation->where('is_current', true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', $this->getKeyName())
            ->withoutGlobalScope(StaffNotDeletedScope::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by', $this->getKeyName());
    }

    /**
     * Establish or replace the current appointment (ends prior current rows).
     *
     * @param  array{
     *     role: string,
     *     assigned_barangay: string,
     *     assigned_zone?: string|null,
     *     assigned_zones?: list<string>|string|null,
     *     date_appointed: string|\DateTimeInterface,
     *     end_of_appointment?: string|\DateTimeInterface|null
     * }  $attributes
     */
    public function assignCurrentAppointment(array $attributes): WorkerAppointment
    {
        $role = StaffRole::normalize($attributes['role'] ?? null);
        if ($role === null) {
            throw ValidationException::withMessages([
                'role' => 'Invalid staff role.',
            ]);
        }

        return $this->getConnection()->transaction(function () use ($attributes, $role): WorkerAppointment {
            if (UserManagementErdMode::isActive()) {
                $this->appointments()
                    ->whereNull('end_of_appointment')
                    ->update([
                        'end_of_appointment' => now()->toDateString(),
                    ]);
            } elseif (UserManagementErdMode::appointmentsHaveIsCurrent()) {
                $this->appointments()
                    ->where('is_current', true)
                    ->update([
                        'is_current' => false,
                        'end_of_appointment' => now()->toDateString(),
                    ]);
            }

            $zones = WorkerAssignedZones::normalize(
                $attributes['assigned_zones'] ?? ($attributes['assigned_zone'] ?? null)
            );
            $primary = WorkerAssignedZones::primary($zones);

            $payload = [
                'role' => UserManagementErdMode::appointmentRoleForStorage($role),
                'assigned_barangay' => (string) $attributes['assigned_barangay'],
                'assigned_zone' => $primary,
                'date_appointed' => $attributes['date_appointed'],
                'end_of_appointment' => $attributes['end_of_appointment'] ?? null,
            ];

            if (UserManagementErdMode::appointmentsHaveIsCurrent()) {
                $payload['is_current'] = true;
            }

            /** @var WorkerAppointment $appointment */
            $appointment = $this->appointments()->create($payload);
            $appointment->syncAssignedZones($zones);

            $this->unsetRelation('currentAppointment');
            $this->unsetRelation('appointments');

            return $appointment->fresh(['assignedZones']) ?? $appointment;
        });
    }

    public function syncUiRoleSession(): void
    {
        $role = $this->role;
        if ($role !== null) {
            UiRole::set($role);
        }
    }
}
