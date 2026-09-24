<?php

namespace App\Support;

use App\Models\DeathRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeathRecordService
{
    /**
     * @param  array<string, mixed>  $household
     * @param  array<string, mixed>  $member
     * @param  array{cause_of_death: string, date_of_death: string, registry_no: string, certificate_no: string}  $payload
     */
    public function submit(
        array $household,
        array $member,
        array $payload,
        UploadedFile $certificate
    ): DeathRequest {
        $householdNo = DemoCatalog::normalizeHouseholdNo((string) ($household['householdNo'] ?? ''));
        $memberId = DemoCatalog::normalizeMemberId((string) ($member['id'] ?? ''));

        if (DeathRequest::approvedForMember($householdNo, $memberId) !== null) {
            throw ValidationException::withMessages([
                'cause_of_death' => 'This resident already has an approved death record.',
            ]);
        }

        if (DeathRequest::pendingForMember($householdNo, $memberId) !== null) {
            throw ValidationException::withMessages([
                'cause_of_death' => 'A death record for this resident is already pending Admin verification.',
            ]);
        }

        $actor = $this->actor();

        return DB::transaction(function () use ($household, $member, $payload, $certificate, $householdNo, $memberId, $actor): DeathRequest {
            $request = DeathRequest::query()->create([
                'household_no' => $householdNo,
                'member_id' => $memberId,
                'resident_name' => (string) ($member['name'] ?? 'Resident'),
                'resident_sex' => (string) ($member['sex'] ?? ''),
                'resident_age' => is_numeric($member['age'] ?? null) ? (int) $member['age'] : null,
                'zone' => (string) ($household['zone'] ?? $household['purok'] ?? ''),
                'household_display_no' => (string) ($household['displayNo'] ?? $householdNo),
                'address' => (string) ($household['address'] ?? ''),
                'cause_of_death' => $payload['cause_of_death'],
                'date_of_death' => $payload['date_of_death'],
                'registry_no' => $payload['registry_no'],
                'certificate_no' => $payload['certificate_no'],
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

            return $request->fresh() ?? $request;
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
            $locked = DeathRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending death requests can be approved.',
                ]);
            }

            if (DeathRequest::approvedForMember($locked->household_no, $locked->member_id) !== null) {
                throw ValidationException::withMessages([
                    'status' => 'This resident already has an approved death record.',
                ]);
            }

            $locked->fill([
                'status' => DeathRequest::STATUS_APPROVED,
                'reviewed_by_name' => $actor['name'],
                'reviewed_by_role' => $actor['role'],
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);
            $locked->save();

            ResidentVitalStatus::markDeceased($locked);

            return $locked->fresh() ?? $locked;
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
            $locked = DeathRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending death requests can be rejected.',
                ]);
            }

            $locked->fill([
                'status' => DeathRequest::STATUS_REJECTED,
                'reviewed_by_name' => $actor['name'],
                'reviewed_by_role' => $actor['role'],
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);
            $locked->save();

            return $locked->fresh() ?? $locked;
        });
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
