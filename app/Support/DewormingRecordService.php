<?php

namespace App\Support;

use App\Models\DewormingRecord;
use App\Models\Resident;
use App\Support\DewormingErdMode;
use App\Support\DisplayDate;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * DB-06 Phase 3 — Deworming read/write for persisted residents.
 */
final class DewormingRecordService
{
    public const DUPLICATE_MESSAGE = 'A Deworming record for this year and round already exists.';
    /**
     * Create a deworming administration record. resident_id is always derived server-side.
     *
     * @param  array{
     *     year?: int|string|null,
     *     round?: int|string|null,
     *     se_status?: string|null,
     *     date_given?: string|null,
     *     remarks?: string|null
     * }  $payload
     */
    public function createForResident(Resident $resident, array $payload): DewormingRecord
    {
        if ((int) $resident->id <= 0) {
            abort(404, 'Resident was not found.');
        }

        $year = (int) ($payload['year'] ?? 0);
        $round = (int) ($payload['round'] ?? 0);
        $seStatus = trim((string) ($payload['se_status'] ?? ''));
        $dateGiven = trim((string) ($payload['date_given'] ?? ''));
        $remarks = $this->nullableString($payload['remarks'] ?? null);

        $this->rejectDuplicateRecord($resident, $year, $round);

        try {
            $attributes = [
                'resident_id' => $resident->id,
                'year' => $year,
                DewormingErdMode::roundColumn() => $round,
                'date_given' => $dateGiven,
                'remarks' => $remarks ?? HealthRecordsDeworming::REMARKS_NONE,
            ];

            if (DewormingErdMode::hasSeStatusColumn()) {
                $attributes['se_status'] = $seStatus;
            }

            return DewormingRecord::query()->create($attributes);
        } catch (QueryException $e) {
            if ($this->isDuplicateRecordViolation($e)) {
                $this->throwDuplicateValidation();
            }

            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recordsForResident(Resident $resident): array
    {
        return DewormingRecord::query()
            ->where('resident_id', $resident->id)
            ->orderByDesc('year')
            ->orderByDesc(DewormingErdMode::roundColumn())
            ->get()
            ->map(fn (DewormingRecord $record): array => self::toPresentation($record))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function toPresentation(DewormingRecord $record): array
    {
        $dateGiven = $record->date_given;

        return [
            'id' => (string) $record->id,
            'year' => (string) $record->year,
            'round' => (string) $record->round,
            'se_status' => (string) $record->se_status,
            'date_given' => $dateGiven instanceof Carbon
                ? $dateGiven->toDateString()
                : (string) $dateGiven,
            'date_given_label' => $dateGiven instanceof Carbon
                ? (DisplayDate::format($dateGiven->toDateString()) ?: $dateGiven->toDateString())
                : self::formatDisplayDate((string) $dateGiven),
            'remarks' => filled($record->remarks)
                ? (string) $record->remarks
                : HealthRecordsDeworming::REMARKS_NONE,
        ];
    }

    private function rejectDuplicateRecord(Resident $resident, int $year, int $round): void
    {
        if ($this->recordExists($resident, $year, $round)) {
            $this->throwDuplicateValidation();
        }
    }

    private function recordExists(Resident $resident, int $year, int $round): bool
    {
        return DewormingRecord::query()
            ->where('resident_id', $resident->id)
            ->where('year', $year)
            ->where(DewormingErdMode::roundColumn(), $round)
            ->exists();
    }

    /**
     * @throws ValidationException
     */
    private function throwDuplicateValidation(): never
    {
        throw ValidationException::withMessages([
            'round' => self::DUPLICATE_MESSAGE,
        ]);
    }

    private function isDuplicateRecordViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return (str_contains($message, 'UNIQUE constraint failed') && str_contains($message, 'deworming_records'))
            || str_contains($message, 'Duplicate entry')
            || (string) $e->getCode() === '23000';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function formatDisplayDate(string $isoDate): string
    {
        $isoDate = trim($isoDate);
        if ($isoDate === '') {
            return '';
        }

        try {
            $formatted = DisplayDate::format($isoDate);

            return $formatted !== '' ? $formatted : $isoDate;
        } catch (\Throwable) {
            return $isoDate;
        }
    }
}
