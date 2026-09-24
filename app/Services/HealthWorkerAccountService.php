<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkerAppointment;
use App\Support\StaffAccountStatus;
use App\Support\StaffProfilePhotoStorage;
use App\Support\StaffRole;
use App\Support\UserManagementErdMode;
use App\Support\WorkerAssignedZones;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Admin Health Worker account persistence against users + worker_appointments.
 *
 * Account (auth/admin) and profile (personal/address/employment details) are
 * separate concerns. Slim Create Account persists only values actually collected
 * on the create form plus a role-only current appointment — never demo/fixture
 * demographics and never invented employment placeholders.
 *
 * Appointment history: role / barangay / date-appointed changes close the current
 * appointment and create a new current row via User::assignCurrentAppointment().
 * Zone-set changes update the current appointment in place. Profile-only edits
 * and end-date-only finalization do not invent history rows.
 */
final class HealthWorkerAccountService
{
    /**
     * Persist a new Health Worker authentication account from the slim create form.
     *
     * @param  array<string, mixed>  $data  Validated StoreHealthWorkerRequest payload
     */
    public function createFromSlimForm(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $status = StaffAccountStatus::normalize($data['status'] ?? null);
            if ($status === null) {
                throw ValidationException::withMessages([
                    'status' => 'Invalid account status.',
                ]);
            }

            $role = StaffRole::normalize($data['role'] ?? null);
            if ($role === null) {
                throw ValidationException::withMessages([
                    'role' => 'Invalid staff role.',
                ]);
            }

            $user = new User;
            $user->fill([
                'first_name' => trim((string) $data['first_name']),
                'middle_name' => filled($data['middle_name'] ?? null)
                    ? trim((string) $data['middle_name'])
                    : null,
                'last_name' => trim((string) $data['last_name']),
                'email' => strtolower(trim((string) $data['email'])),
                'mobile_number' => trim((string) $data['mobile']),
                'username' => filled($data['username'] ?? null)
                    ? trim((string) $data['username'])
                    : null,
                'password' => $data['password'],
                'status' => $status,
                'must_change_password' => true,
                'created_by' => $this->actingAdminId(),
            ]);

            // Incomplete profile — persist only Create Account fields. Never invent
            // demographics, address, employment placement, or photo.
            $user->suffix = null;
            $user->sex = null;
            $user->date_of_birth = null;
            $user->civil_status = null;
            $user->nationality = null;
            $user->house_no = null;
            $user->street = null;
            $user->purok_zone = null;
            $user->barangay = null;
            $user->municipality_city = null;
            $user->province = null;
            $user->zip_code = null;
            $user->photo_path = null;

            if (! UserManagementErdMode::isActive()) {
                $user->username = null;
            }

            $appointmentData = [
                'role' => UserManagementErdMode::appointmentRoleForStorage($role),
                'assigned_barangay' => null,
                'assigned_zone' => null,
                'date_appointed' => null,
                'end_of_appointment' => null,
            ];

            if (UserManagementErdMode::appointmentsHaveIsCurrent()) {
                $appointmentData['is_current'] = true;
            }

            $this->throwIfCreateSchemaConflicts(array_merge(
                $this->unfilledRequiredCreateColumns(
                    $user->getTable(),
                    $user->getAttributes(),
                    [
                        $user->getKeyName(),
                        'created_at',
                        'updated_at',
                        'remember_token',
                        'email_verified_at',
                        'name',
                        'photo_path',
                        'deleted_at',
                    ],
                ),
                $this->unfilledRequiredCreateColumns(
                    'worker_appointments',
                    $appointmentData,
                    ['appointment_id', 'id', 'user_id', 'created_at', 'updated_at'],
                ),
            ));

            $user->save();

            $user->appointments()->create($appointmentData);

            return $user->fresh(['currentAppointment']);
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validated UpdateHealthWorkerRequest payload
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $status = StaffAccountStatus::normalize($data['hw_status'] ?? null);
            if ($status === null) {
                throw ValidationException::withMessages([
                    'hw_status' => 'Invalid account status.',
                ]);
            }

            $role = StaffRole::normalize($data['hw_role'] ?? null);
            if ($role === null) {
                throw ValidationException::withMessages([
                    'hw_role' => 'Invalid staff role.',
                ]);
            }

            $this->assertMayLoseActiveAdmin($user, $status, $role);

            $profile = [
                'first_name' => $data['hw_first_name'],
                'middle_name' => $data['hw_middle_name'] ?? null,
                'last_name' => $data['hw_last_name'],
                'suffix' => $data['hw_suffix'] ?? null,
                'sex' => $data['sex'] ?? null,
                'date_of_birth' => $data['hw_dob'] ?? null,
                'civil_status' => $data['hw_civil_status'] ?? null,
                'nationality' => $data['hw_nationality'] ?? null,
                'mobile_number' => $data['hw_mobile'] ?? null,
                'email' => $data['hw_email'],
                'house_no' => $data['hw_house_no'] ?? null,
                'street' => $data['hw_street'] ?? null,
                'purok_zone' => $data['hw_purok_zone'] ?? null,
                'barangay' => $data['hw_barangay'] ?? null,
                'municipality_city' => $data['hw_municipality'] ?? null,
                'province' => $data['hw_province'] ?? null,
                'zip_code' => $data['hw_zip'] ?? null,
                'username' => $data['hw_username'],
                'status' => $status,
            ];

            $password = $data['hw_password'] ?? null;
            if (is_string($password) && $password !== '') {
                $profile['password'] = $password;
                $profile['must_change_password'] = true;
            }

            $user->fill($profile);

            $previousPhotoPath = $user->photo_path;
            $storedPhotoPath = $this->applyProfilePhoto($user, $data);

            try {
                $user->save();

                $zones = WorkerAssignedZones::normalize($data['hw_assigned_zone'] ?? []);
                $employment = [
                    'role' => $role,
                    'assigned_barangay' => (string) $data['hw_assigned_barangay'],
                    'assigned_zone' => WorkerAssignedZones::primary($zones) ?? '',
                    'assigned_zones' => $zones,
                    'date_appointed' => $data['hw_date_appointed'],
                    'end_of_appointment' => $data['hw_end_appointment'] ?? null,
                ];

                $this->syncAppointment($user, $employment);
            } catch (\Throwable $exception) {
                if (is_string($storedPhotoPath) && $storedPhotoPath !== '') {
                    StaffProfilePhotoStorage::deleteManaged($storedPhotoPath);
                }

                throw $exception;
            }

            if ($storedPhotoPath !== null && $previousPhotoPath !== $storedPhotoPath) {
                StaffProfilePhotoStorage::deleteManaged($previousPhotoPath);
            } elseif ($storedPhotoPath === null && $user->photo_path === null && $previousPhotoPath !== null) {
                StaffProfilePhotoStorage::deleteManaged($previousPhotoPath);
            }

            return $user->fresh(['currentAppointment']);
        });
    }

    /**
     * Deactivate TARGET account login access (status → Suspended/Inactive).
     * Never hard-deletes rows. Never logs out the ACTOR Admin session.
     */
    public function deactivateAccount(User $target): User
    {
        $actorId = $this->actingAdminId();

        return DB::transaction(function () use ($target, $actorId): User {
            /** @var User $locked */
            $locked = User::query()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $role = StaffRole::normalize($locked->role);
            if ($role === StaffRole::ADMIN) {
                throw ValidationException::withMessages([
                    'hw_status' => 'Administrator accounts cannot be deactivated from this action.',
                ]);
            }

            if ($actorId !== null && (int) $locked->getKey() === $actorId) {
                throw ValidationException::withMessages([
                    'hw_status' => 'You cannot deactivate your own account.',
                ]);
            }

            if (StaffAccountStatus::normalize($locked->status) === StaffAccountStatus::INACTIVE) {
                return $locked->fresh(['currentAppointment']) ?? $locked;
            }

            $locked->deactivate();

            return $locked->fresh(['currentAppointment']) ?? $locked;
        });
    }

    /**
     * Activate TARGET account login access (status → Active).
     * Does not Auth::login the target. Never logs out the ACTOR Admin.
     */
    public function activateAccount(User $target): User
    {
        return DB::transaction(function () use ($target): User {
            /** @var User $locked */
            $locked = User::query()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $role = StaffRole::normalize($locked->role);
            if ($role === StaffRole::ADMIN) {
                throw ValidationException::withMessages([
                    'hw_status' => 'Administrator accounts cannot be activated from this action.',
                ]);
            }

            if (StaffAccountStatus::normalize($locked->status) === StaffAccountStatus::ACTIVE) {
                return $locked->fresh(['currentAppointment']) ?? $locked;
            }

            $locked->activate();

            return $locked->fresh(['currentAppointment']) ?? $locked;
        });
    }

    /**
     * Soft-delete login/access. Never hard-deletes the staff row or related history.
     * Distinct from deactivateAccount(): sets deleted_at and closes the current appointment.
     */
    public function deleteAccount(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            if (! UserManagementErdMode::staffHasDeletedAt()) {
                throw ValidationException::withMessages([
                    'hw_status' => 'This account could not be deleted because staff deletion is not available on the current schema.',
                ]);
            }

            /** @var User $locked */
            $locked = User::queryWithDeleted()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $role = StaffRole::normalize($locked->role);
            if ($role === StaffRole::ADMIN) {
                throw ValidationException::withMessages([
                    'hw_status' => 'Administrator accounts cannot be deleted from this action.',
                ]);
            }

            $actorId = $this->actingAdminId();
            if ($actorId !== null && (int) $locked->getKey() === $actorId) {
                throw ValidationException::withMessages([
                    'hw_status' => 'You cannot delete your own account.',
                ]);
            }

            $this->assertMayLoseActiveAdmin(
                $locked,
                StaffAccountStatus::INACTIVE,
                $role ?? StaffRole::BHW,
            );

            if ($locked->isDeleted()) {
                return $locked;
            }

            $this->closeOpenAppointments($locked);

            $locked->forceFill([
                'deleted_at' => now(),
            ])->save();

            $locked->refresh();
            $locked->unsetRelation('currentAppointment');
            $locked->unsetRelation('appointments');

            return $locked;
        });
    }

    private function closeOpenAppointments(User $user): void
    {
        $open = UserManagementErdMode::appointmentsHaveIsCurrent()
            ? $user->appointments()->where('is_current', true)->get()
            : $user->appointments()->whereNull('end_of_appointment')->get();

        foreach ($open as $appointment) {
            $appointment->markEnded();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyProfilePhoto(User $user, array $data): ?string
    {
        $file = $data['hw_photo'] ?? null;
        $remove = filter_var($data['hw_remove_photo'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($file instanceof UploadedFile) {
            $path = StaffProfilePhotoStorage::store($file);
            $user->photo_path = $path;

            return $path;
        }

        if ($remove) {
            $user->photo_path = null;
        }

        return null;
    }

    /**
     * Close/create appointment history when a completed assignment identity changes;
     * first completion of a slim-create stub updates that row in place;
     * zone-set changes update the current appointment in place;
     * otherwise update end date in place when only finalization changes.
     *
     * @param  array{
     *     role: string,
     *     assigned_barangay: string,
     *     assigned_zone: string,
     *     assigned_zones: list<string>,
     *     date_appointed: string,
     *     end_of_appointment?: string|null
     * }  $employment
     */
    private function syncAppointment(User $user, array $employment): WorkerAppointment
    {
        /** @var WorkerAppointment|null $current */
        $current = $user->resolveCurrentAppointment();

        // Slim Create leaves an open role-only stub. First Edit that fills placement
        // must complete that same row — not close it and insert history.
        if ($this->isIncompleteOpenAppointmentStub($current)) {
            return $this->fillCurrentAppointmentInPlace($current, $employment);
        }

        if ($this->appointmentRepresentsNewAssignment($current, $employment)) {
            return $user->assignCurrentAppointment([
                'role' => $employment['role'],
                'assigned_barangay' => $employment['assigned_barangay'],
                'assigned_zone' => $employment['assigned_zone'],
                'assigned_zones' => $employment['assigned_zones'],
                'date_appointed' => $employment['date_appointed'],
                'end_of_appointment' => $employment['end_of_appointment'] ?: null,
            ]);
        }

        // Profile-only / zone-set path with existing appointment: update in place.
        if ($current !== null) {
            $newEnd = $employment['end_of_appointment'] ?: null;
            $oldEnd = $current->end_of_appointment?->format('Y-m-d');
            $newEndNormalized = is_string($newEnd) && $newEnd !== '' ? $newEnd : null;

            if ($oldEnd !== $newEndNormalized) {
                $payload = [
                    'end_of_appointment' => $newEndNormalized,
                ];

                if (UserManagementErdMode::appointmentsHaveIsCurrent()) {
                    $payload['is_current'] = true;
                }

                $current->forceFill($payload)->save();
            }

            $current->syncAssignedZones($employment['assigned_zones']);

            return $current->fresh(['assignedZones']) ?? $current;
        }

        // Legacy / pre-appointment transition: first current row from Edit wizard fields.
        return $user->assignCurrentAppointment([
            'role' => $employment['role'],
            'assigned_barangay' => $employment['assigned_barangay'],
            'assigned_zone' => $employment['assigned_zone'],
            'assigned_zones' => $employment['assigned_zones'],
            'date_appointed' => $employment['date_appointed'],
            'end_of_appointment' => $employment['end_of_appointment'] ?: null,
        ]);
    }

    /**
     * Open appointment with role only — placement/dates never collected (slim Create).
     */
    private function isIncompleteOpenAppointmentStub(?WorkerAppointment $current): bool
    {
        if ($current === null || $current->end_of_appointment !== null) {
            return false;
        }

        $barangay = trim((string) ($current->assigned_barangay ?? ''));
        $zone = trim((string) ($current->assigned_zone ?? ''));
        $appointed = $current->date_appointed?->format('Y-m-d') ?? '';

        return $barangay === '' && $zone === '' && $appointed === '';
    }

    /**
     * @param  array{
     *     role: string,
     *     assigned_barangay: string,
     *     assigned_zone: string,
     *     assigned_zones: list<string>,
     *     date_appointed: string,
     *     end_of_appointment?: string|null
     * }  $employment
     */
    private function fillCurrentAppointmentInPlace(WorkerAppointment $current, array $employment): WorkerAppointment
    {
        $role = StaffRole::normalize($employment['role']);
        if ($role === null) {
            throw ValidationException::withMessages([
                'hw_role' => 'Invalid staff role.',
            ]);
        }

        $end = $employment['end_of_appointment'] ?? null;
        $payload = [
            'role' => UserManagementErdMode::appointmentRoleForStorage($role),
            'assigned_barangay' => $employment['assigned_barangay'],
            'assigned_zone' => $employment['assigned_zone'] !== '' ? $employment['assigned_zone'] : null,
            'date_appointed' => $employment['date_appointed'],
            'end_of_appointment' => is_string($end) && $end !== '' ? $end : null,
        ];

        if (UserManagementErdMode::appointmentsHaveIsCurrent()) {
            $payload['is_current'] = true;
        }

        $current->forceFill($payload)->save();
        $current->syncAssignedZones($employment['assigned_zones']);

        return $current->fresh(['assignedZones']) ?? $current;
    }

    /**
     * @param  array{
     *     role: string,
     *     assigned_barangay: string,
     *     assigned_zone: string,
     *     assigned_zones?: list<string>,
     *     date_appointed: string,
     *     end_of_appointment?: string|null
     * }  $employment
     */
    private function appointmentRepresentsNewAssignment(?WorkerAppointment $current, array $employment): bool
    {
        if ($current === null) {
            return true;
        }

        $newRole = StaffRole::normalize($employment['role']);
        $oldRole = StaffRole::normalize($current->role);
        if ($newRole !== $oldRole) {
            return true;
        }

        $oldBarangay = (string) ($current->assigned_barangay ?? '');
        $oldAppointed = $current->date_appointed?->format('Y-m-d') ?? '';

        if ($oldBarangay !== (string) $employment['assigned_barangay']) {
            return true;
        }

        if ($oldAppointed !== (string) $employment['date_appointed']) {
            return true;
        }

        return false;
    }

    /**
     * Server-side last-active-admin invariant (self-edit and peer-edit).
     */
    public function assertMayLoseActiveAdmin(User $user, string $newStatus, string $newRole): void
    {
        if (! $this->isActiveAdmin($user)) {
            return;
        }

        $remainsActiveAdmin = $newStatus === StaffAccountStatus::ACTIVE
            && StaffRole::normalize($newRole) === StaffRole::ADMIN;

        if ($remainsActiveAdmin) {
            return;
        }

        if ($this->countOtherActiveAdmins($user->id) > 0) {
            return;
        }

        $messages = [];
        if ($newStatus !== StaffAccountStatus::ACTIVE) {
            $messages['hw_status'] = 'Cannot deactivate the last active Admin account.';
        }
        if (StaffRole::normalize($newRole) !== StaffRole::ADMIN) {
            $messages['hw_role'] = 'Cannot demote the last active Admin account.';
        }
        if ($messages === []) {
            $messages['hw_role'] = 'Cannot remove the last active Admin account.';
        }

        throw ValidationException::withMessages($messages);
    }

    public function isActiveAdmin(User $user): bool
    {
        if ($user->isDeleted()) {
            return false;
        }

        if (StaffAccountStatus::normalize($user->status) !== StaffAccountStatus::ACTIVE) {
            return false;
        }

        return StaffRole::normalize($user->role) === StaffRole::ADMIN;
    }

    public function countOtherActiveAdmins(int $exceptUserId): int
    {
        return User::query()
            ->whereKeyNot($exceptUserId)
            ->where('status', StaffAccountStatus::ACTIVE)
            ->whereHas('currentAppointment', static function ($query): void {
                $query->whereIn('role', UserManagementErdMode::appointmentRoleMatchValues(StaffRole::ADMIN));
            })
            ->count();
    }

    public function actingAdminId(): ?int
    {
        $admin = Auth::user();

        return $admin instanceof User ? (int) $admin->getKey() : null;
    }

    /**
     * Stop and report instead of inventing values when an authoritative column
     * is NOT NULL and Create Account did not collect a value.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $ignoreColumns
     */
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $ignoreColumns
     * @return list<string>
     */
    private function unfilledRequiredCreateColumns(
        string $table,
        array $attributes,
        array $ignoreColumns = [],
    ): array {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $conflicts = [];

        foreach (Schema::getColumns($table) as $column) {
            $name = (string) ($column['name'] ?? '');
            if ($name === '' || in_array($name, $ignoreColumns, true)) {
                continue;
            }

            if ($this->schemaColumnIsNullable($column)) {
                continue;
            }

            if (array_key_exists('default', $column) && $column['default'] !== null) {
                continue;
            }

            if (($column['auto_increment'] ?? false) === true) {
                continue;
            }

            $value = $attributes[$name] ?? null;
            if ($value === null || $value === '') {
                $conflicts[] = $table.'.'.$name;
            }
        }

        return $conflicts;
    }

    /**
     * @param  list<string>  $conflicts
     */
    private function throwIfCreateSchemaConflicts(array $conflicts): void
    {
        if ($conflicts === []) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => 'This account cannot be created because the following required database columns have no Create Account value and cannot be left empty: '.implode(', ', $conflicts).'. Schema was not changed and placeholder profile values were not written.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function schemaColumnIsNullable(array $column): bool
    {
        $nullable = $column['nullable'] ?? false;

        return $nullable === true
            || $nullable === 1
            || $nullable === '1'
            || $nullable === 'YES';
    }
}
