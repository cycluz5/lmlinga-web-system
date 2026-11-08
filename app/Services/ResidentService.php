<?php

namespace App\Services;

use App\Models\Household;
use App\Models\Resident;
use App\Support\HouseholdProfilingWriteGuard;
use App\Support\ResidentHealthConditionSync;
use App\Support\ResidentMemberIdentity;
use App\Support\ResidentShellWriteAdapter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DB-05 Phase 2 — Resident create/update, member_no allocation, Head invariant.
 */
final class ResidentService
{
    private const MAX_MEMBER_NO_ATTEMPTS = 8;

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(Household $household, array $validated): Resident
    {
        HouseholdProfilingWriteGuard::rejectResidentWrite();

        $payload = $this->normalizePayload($validated);

        return DB::transaction(function () use ($household, $payload, $validated): Resident {
            $locked = Household::query()->whereKey($household->id)->lockForUpdate()->firstOrFail();

            if (strcasecmp(ResidentShellWriteAdapter::relationValueFromPayload($payload), 'Head') === 0) {
                $this->assertNoActiveHead((int) $locked->id);
            }

            $attributes = [
                ...$payload,
                'household_id' => $locked->id,
            ];

            if (! ResidentMemberIdentity::hasMemberNoColumn()) {
                $resident = Resident::query()->create($attributes);
                ResidentHealthConditionSync::sync($resident, $validated);

                return $resident;
            }

            $attempt = 0;
            while ($attempt < self::MAX_MEMBER_NO_ATTEMPTS) {
                $attempt++;
                $memberNo = $this->allocateNextMemberNo();

                try {
                    $resident = Resident::query()->create([
                        ...$attributes,
                        'member_no' => $memberNo,
                    ]);
                    ResidentHealthConditionSync::sync($resident, $validated);

                    return $resident;
                } catch (QueryException $e) {
                    if (! $this->isUniqueConstraintViolation($e) || $attempt >= self::MAX_MEMBER_NO_ATTEMPTS) {
                        throw $e;
                    }
                }
            }

            throw ValidationException::withMessages([
                'member_no' => 'Unable to allocate a unique member number. Please try again.',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(Resident $resident, array $validated): Resident
    {
        HouseholdProfilingWriteGuard::rejectResidentWrite();

        $payload = $this->normalizePayload($validated);

        return DB::transaction(function () use ($resident, $payload, $validated): Resident {
            $lockedResident = Resident::query()->whereKey($resident->id)->lockForUpdate()->firstOrFail();
            Household::query()->whereKey($lockedResident->household_id)->lockForUpdate()->firstOrFail();

            $newRelation = ResidentShellWriteAdapter::relationValueFromPayload($payload);
            if ($newRelation === '') {
                $newRelation = (string) $lockedResident->relation;
            }
            $wasHead = strcasecmp((string) $lockedResident->relation, 'Head') === 0;
            $becomesHead = strcasecmp($newRelation, 'Head') === 0;

            if ($becomesHead && ! $wasHead) {
                $this->assertNoActiveHead((int) $lockedResident->household_id, (int) $lockedResident->id);
            }

            unset($payload['household_id'], $payload['member_no'], $payload['id'], $payload['resident_id']);

            $lockedResident->fill($payload);
            $lockedResident->save();

            ResidentHealthConditionSync::sync($lockedResident, $validated);

            return $lockedResident->fresh();
        });
    }

    /**
     * Next MB-{n} from persisted resident member numbers only.
     */
    public function allocateNextMemberNo(): string
    {
        $max = 0;

        $dbNumbers = Resident::query()
            ->pluck('member_no')
            ->all();

        foreach ($dbNumbers as $memberNo) {
            $max = max($max, $this->numericSuffix((string) $memberNo));
        }

        $next = $max + 1;

        return 'MB-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    private function assertNoActiveHead(int $householdId, ?int $exceptResidentId = null): void
    {
        $relationColumn = ResidentShellWriteAdapter::relationColumn() ?? 'relation';
        $keyName = (new Resident)->getKeyName();

        $query = Resident::query()
            ->where('household_id', $householdId)
            ->where($relationColumn, 'Head');

        if ($exceptResidentId !== null) {
            $query->where($keyName, '!=', $exceptResidentId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'relation' => 'This household already has an active Head. Change the existing Head first.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizePayload(array $validated): array
    {
        $prepared = $validated;

        if (array_key_exists('philhealth', $prepared)) {
            $philhealth = preg_replace('/\s+/', '', trim((string) $prepared['philhealth']));
            $prepared['philhealth'] = ($philhealth === null || $philhealth === '') ? null : $philhealth;
        }

        if (array_key_exists('disability', $prepared)) {
            $disability = array_values(array_unique(array_map('strval', $prepared['disability'] ?? [])));
            $prepared['disability'] = $disability;
            $disabilityOthers = in_array('others', $disability, true)
                ? trim((string) ($prepared['disability_others'] ?? ''))
                : null;
            $prepared['disability_others'] = $disabilityOthers === '' ? null : $disabilityOthers;
        }

        if (array_key_exists('medical_history', $prepared)) {
            $medical = array_values(array_unique(array_map('strval', $prepared['medical_history'] ?? [])));
            $prepared['medical_history'] = $medical;
            $medicalOthers = in_array('others', $medical, true)
                ? trim((string) ($prepared['medical_others'] ?? ''))
                : null;
            $prepared['medical_others'] = $medicalOthers === '' ? null : $medicalOthers;
        }

        return ResidentShellWriteAdapter::persistableAttributes($prepared);
    }

    private function numericSuffix(string $memberNo): int
    {
        if (preg_match('/^MB-(\d+)$/i', trim($memberNo), $m) !== 1) {
            return 0;
        }

        return (int) $m[1];
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'member_no')
            || (string) $e->getCode() === '23000';
    }
}
