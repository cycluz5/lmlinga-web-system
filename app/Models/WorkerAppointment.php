<?php

namespace App\Models;

use App\Models\Scopes\StaffNotDeletedScope;
use App\Support\StaffRole;
use App\Support\UserManagementErdMode;
use App\Support\WorkerAssignedZones;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class WorkerAppointment extends Model
{
    /** @use HasFactory<\Database\Factories\WorkerAppointmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'role',
        'assigned_barangay',
        'assigned_zone',
        'date_appointed',
        'end_of_appointment',
        'is_current',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_appointed' => 'date',
            'end_of_appointment' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function getKeyName(): string
    {
        return UserManagementErdMode::appointmentKeyName();
    }

    protected static function booted(): void
    {
        static::saving(function (WorkerAppointment $appointment): void {
            $role = StaffRole::normalize($appointment->role);
            if ($role === null) {
                throw ValidationException::withMessages([
                    'role' => 'Invalid staff role.',
                ]);
            }

            $appointment->role = UserManagementErdMode::appointmentRoleForStorage($role);

            if (! UserManagementErdMode::appointmentsHaveIsCurrent() || ! $appointment->is_current) {
                return;
            }

            static::query()
                ->where('user_id', $appointment->user_id)
                ->when($appointment->exists, fn ($q) => $q->whereKeyNot($appointment->getKey()))
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'end_of_appointment' => $appointment->end_of_appointment
                        ?? now()->toDateString(),
                ]);
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', (new User)->getKeyName())
            ->withoutGlobalScope(StaffNotDeletedScope::class);
    }

    /**
     * @return HasMany<WorkerAppointmentZone, $this>
     */
    public function assignedZones(): HasMany
    {
        return $this->hasMany(WorkerAppointmentZone::class, 'appointment_id', $this->getKeyName())
            ->orderBy('worker_appointment_zone_id');
    }

    /**
     * Selected zones for this appointment period, preserving first-selected order.
     *
     * @return list<string>
     */
    public function assignedZoneLabels(): array
    {
        if (Schema::hasTable('worker_appointment_zones')) {
            $this->loadMissing('assignedZones');
            $fromPivot = WorkerAssignedZones::normalize(
                $this->assignedZones->pluck('assigned_zone')->all()
            );
            if ($fromPivot !== []) {
                return $fromPivot;
            }
        }

        $scalar = trim((string) ($this->assigned_zone ?? ''));

        return $scalar !== '' ? [$scalar] : [];
    }

    /**
     * Replace this appointment's zone set in place and keep assigned_zone as the first zone.
     *
     * @param  list<string>|string|null  $zones
     */
    public function syncAssignedZones(array|string|null $zones): void
    {
        $normalized = WorkerAssignedZones::normalize($zones);
        $primary = WorkerAssignedZones::primary($normalized);

        $this->forceFill([
            'assigned_zone' => $primary,
        ])->save();

        if (! Schema::hasTable('worker_appointment_zones')) {
            return;
        }

        $this->assignedZones()->delete();
        foreach ($normalized as $zone) {
            $this->assignedZones()->create([
                'assigned_zone' => $zone,
            ]);
        }

        $this->unsetRelation('assignedZones');
        $this->load('assignedZones');
    }

    public function markEnded(\DateTimeInterface|string|null $endedOn = null): void
    {
        $payload = [
            'end_of_appointment' => $endedOn ?? now()->toDateString(),
        ];

        if (UserManagementErdMode::appointmentsHaveIsCurrent()) {
            $payload['is_current'] = false;
        }

        $this->forceFill($payload)->save();
    }
}
