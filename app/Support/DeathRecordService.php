<?php

namespace App\Support;

use App\Models\DeathRequest;
use App\Models\Resident;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeathRecordService
{
    /**
     * Persist a pending death request for a DB-backed resident.
     *
     * @param  array<string, mixed>  $household  Presentation shape (zone/address/displayNo)
     * @param  array<string, mixed>  $member  Presentation shape (name/sex/age)
     * @param  array{cause_of_death: string, date_of_death: string, registry_no: string}  $payload
     */
    public function submit(
        array $household,
        array $member,
        array $payload,
        UploadedFile $certificate,
        Resident $resident
    ): DeathRequest {
        $householdNo = DemoCatalog::normalizeHouseholdNo((string) ($household['householdNo'] ?? ''));
        $memberId = DemoCatalog::normalizeMemberId((string) ($member['id'] ?? ResidentMemberIdentity::memberIdFor($resident)));

        if ($householdNo === '' || $memberId === '') {
            throw ValidationException::withMessages([
                'cause_of_death' => 'Resident identity could not be resolved.',
            ]);
        }

        if ((int) $resident->id <= 0) {
            throw ValidationException::withMessages([
                'cause_of_death' => 'A persisted resident is required to submit a death record.',
            ]);
        }

        $registryNo = trim((string) ($payload['registry_no'] ?? ''));
        unset($payload['certificate_no']);
        if ($registryNo === '') {
            throw ValidationException::withMessages([
                'registry_no' => 'Registry number is required.',
            ]);
        }

        if (DeathRecordsErdMode::isActive()) {
            return $this->submitErd($payload, $certificate, $resident, $registryNo);
        }

        if (DeathRequest::approvedForResident($resident->id) !== null) {
            throw ValidationException::withMessages([
                'cause_of_death' => 'This resident already has an approved death record.',
            ]);
        }

        if (DeathRequest::pendingForResident($resident->id) !== null) {
            throw ValidationException::withMessages([
                'cause_of_death' => 'A death record for this resident is already pending Admin verification.',
            ]);
        }

        $actor = $this->actor();

        try {
            return DB::transaction(function () use ($household, $member, $payload, $certificate, $householdNo, $memberId, $actor, $registryNo, $resident): DeathRequest {
                $lockedPending = DeathRequest::query()
                    ->where('resident_id', $resident->id)
                    ->pending()
                    ->lockForUpdate()
                    ->first();
                if ($lockedPending !== null) {
                    throw ValidationException::withMessages([
                        'cause_of_death' => 'A death record for this resident is already pending Admin verification.',
                    ]);
                }

                $lockedApproved = DeathRequest::query()
                    ->where('resident_id', $resident->id)
                    ->approved()
                    ->lockForUpdate()
                    ->first();
                if ($lockedApproved !== null) {
                    throw ValidationException::withMessages([
                        'cause_of_death' => 'This resident already has an approved death record.',
                    ]);
                }

                $request = DeathRequest::query()->create([
                    'household_no' => $householdNo,
                    'member_id' => $memberId,
                    'resident_id' => $resident->id,
                    'resident_name' => (string) ($member['name'] ?? 'Resident'),
                    'resident_sex' => (string) ($member['sex'] ?? ''),
                    'resident_age' => is_numeric($member['age'] ?? null) ? (int) $member['age'] : null,
                    'zone' => (string) ($household['zone'] ?? $household['purok'] ?? ''),
                    'household_display_no' => (string) ($household['displayNo'] ?? $householdNo),
                    'address' => (string) ($household['address'] ?? ''),
                    'cause_of_death' => $payload['cause_of_death'],
                    'date_of_death' => $payload['date_of_death'],
                    'registry_no' => $registryNo,
                    'certificate_no' => $registryNo,
                    'certificate_disk' => DeathCertificateStorage::DISK,
                    'certificate_path' => 'pending',
                    'certificate_original_name' => '',
                    'certificate_mime' => '',
                    'certificate_size' => 0,
                    'certificate_extension' => '',
                    'status' => DeathRequest::STATUS_PENDING,
                    'submitted_by_name' => $actor['name'],
                    'submitted_by_role' => $actor['role'],
                    'submitted_at' => now(),
                    'reviewed_by_name' => null,
                    'reviewed_by_role' => null,
                    'reviewed_at' => null,
                    'rejection_reason' => null,
                ]);

                try {
                    $stored = DeathCertificateStorage::store($certificate, $request);
                    $request->fill($stored);
                    $request->save();
                } catch (\Throwable $e) {
                    DeathCertificateStorage::deleteStored($request);
                    throw $e;
                }

                return $request->fresh(['resident.household']) ?? $request;
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateActiveDeathRequest($e)) {
                throw ValidationException::withMessages([
                    'cause_of_death' => 'A death record for this resident is already pending Admin verification.',
                ]);
            }

            throw $e;
        }
    }

    /**
     * @param  array{cause_of_death: string, date_of_death: string, registry_no: string}  $payload
     */
    private function submitErd(
        array $payload,
        UploadedFile $certificate,
        Resident $resident,
        string $registryNo
    ): DeathRequest {
        if ($registryNo === '') {
            throw ValidationException::withMessages([
                'registry_no' => 'Registry number is required.',
            ]);
        }

        try {
            return $this->submitErdTransaction($payload, $certificate, $resident, $registryNo);
        } catch (AtRestEncryptionException) {
            throw ValidationException::withMessages([
                'cause_of_death' => 'Unable to securely save the death record. Please contact the system administrator.',
            ]);
        }
    }

    /**
     * @param  array{cause_of_death: string, date_of_death: string, registry_no: string}  $payload
     */
    private function submitErdTransaction(
        array $payload,
        UploadedFile $certificate,
        Resident $resident,
        string $registryNo
    ): DeathRequest {
        return DB::transaction(function () use ($payload, $certificate, $resident, $registryNo): DeathRequest {
            $existing = DeathRequest::query()
                ->where('resident_id', $resident->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->isApproved()) {
                throw ValidationException::withMessages([
                    'cause_of_death' => 'This resident already has an approved death record.',
                ]);
            }

            if ($existing !== null && $existing->isPending()) {
                throw ValidationException::withMessages([
                    'cause_of_death' => 'A death record for this resident is already pending Admin verification.',
                ]);
            }

            $submittedBy = DeathRecordsStaffResolver::resolveUserIdOrFail();

            if ($existing === null) {
                $request = DeathRequest::query()->create([
                    'resident_id' => $resident->id,
                    'cause_of_death' => $payload['cause_of_death'],
                    'date_of_death' => $payload['date_of_death'],
                    'death_certificate_no' => $registryNo,
                    'death_certificate_file_path' => 'pending',
                    'verification_status' => DeathRecordsErdMode::pendingVerificationStatus(),
                    'submitted_by' => $submittedBy,
                    'rejection_reason' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                ]);
            } else {
                DeathCertificateStorage::deleteStored($existing);
                $existing->fill([
                    'cause_of_death' => $payload['cause_of_death'],
                    'date_of_death' => $payload['date_of_death'],
                    'death_certificate_no' => $registryNo,
                    'death_certificate_file_path' => 'pending',
                    'verification_status' => DeathRecordsErdMode::pendingVerificationStatus(),
                    'submitted_by' => $submittedBy,
                    'rejection_reason' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                ]);
                $existing->save();
                $request = $existing;
            }

            try {
                $stored = DeathCertificateStorage::store($certificate, $request);
                $request->fill($stored);
                $request->save();
            } catch (\Throwable $e) {
                DeathCertificateStorage::deleteStored($request);
                throw $e;
            }

            return $request->fresh(['resident.household']) ?? $request;
        });
    }

    public function approve(DeathRequest $request): DeathRequest
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Only pending death requests can be approved.',
            ]);
        }

        $actor = $this->actor();

        return DB::transaction(function () use ($request, $actor): DeathRequest {
            $key = $request->getKeyName();
            $locked = DeathRequest::query()->lockForUpdate()->where($key, $request->getKey())->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending death requests can be approved.',
                ]);
            }

            $residentId = (int) ($locked->resident_id ?? 0);
            if ($residentId > 0 && DeathRequest::approvedForResident($residentId) !== null) {
                throw ValidationException::withMessages([
                    'status' => 'This resident already has an approved death record.',
                ]);
            }

            if (DeathRecordsErdMode::isActive()) {
                $locked->fill([
                    'status' => DeathRequest::STATUS_APPROVED,
                    'verified_by' => DeathRecordsStaffResolver::resolveUserIdOrFail('status'),
                    'verified_at' => now(),
                    'rejection_reason' => null,
                ]);
            } else {
                $locked->fill([
                    'status' => DeathRequest::STATUS_APPROVED,
                    'reviewed_by_name' => $actor['name'],
                    'reviewed_by_role' => $actor['role'],
                    'reviewed_at' => now(),
                    'rejection_reason' => null,
                ]);
            }

            $locked->save();

            ResidentVitalStatus::markDeceased($locked);

            return $locked->fresh(['resident.household']) ?? $locked;
        });
    }

    public function reject(DeathRequest $request, string $reason): DeathRequest
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Only pending death requests can be rejected.',
            ]);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => 'A rejection reason is required.',
            ]);
        }

        $actor = $this->actor();

        return DB::transaction(function () use ($request, $reason, $actor): DeathRequest {
            $key = $request->getKeyName();
            $locked = DeathRequest::query()->lockForUpdate()->where($key, $request->getKey())->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending death requests can be rejected.',
                ]);
            }

            if (DeathRecordsErdMode::isActive()) {
                $locked->fill([
                    'status' => DeathRequest::STATUS_REJECTED,
                    'verified_by' => DeathRecordsStaffResolver::resolveUserIdOrFail('status'),
                    'verified_at' => now(),
                    'rejection_reason' => $reason,
                ]);
            } else {
                $locked->fill([
                    'status' => DeathRequest::STATUS_REJECTED,
                    'reviewed_by_name' => $actor['name'],
                    'reviewed_by_role' => $actor['role'],
                    'reviewed_at' => now(),
                    'rejection_reason' => $reason,
                ]);
            }

            $locked->save();

            return $locked->fresh(['resident.household']) ?? $locked;
        });
    }

    private function isDuplicateActiveDeathRequest(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'death_requests_one_pending_resident')
            || str_contains($message, 'death_requests_one_approved_resident')
            || str_contains($message, 'pending_resident_key')
            || str_contains($message, 'approved_resident_key')
            || str_contains($message, 'death_requests_one_pending')
            || str_contains($message, 'death_requests_one_approved')
            || str_contains($message, 'uq_death_resident');
    }

    /**
     * @return array{name: string, role: string}
     */
    private function actor(): array
    {
        $role = UiRole::current() ?? UiRole::LEAST_PRIVILEGED;

        return [
            'name' => UiRole::displayName($role),
            'role' => $role,
        ];
    }
}
