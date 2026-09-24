<?php

namespace App\Support;

use App\Models\Resident;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persist Health Summary adult immunization against adult_immunization.
 */
final class AdultImmunizationService
{
    /**
     * One dose-date field per vaccine type, submitted together — mirrors
     * Child Immunization's single combined save. Blank dates are left
     * untouched (never clears an already-recorded dose); a filled date
     * inserts a new row or updates the existing one for that vaccine type.
     *
     * @param  array<string, string|null>  $dates  vaccine_type => date_given
     * @return list<array<string, mixed>>
     */
    public function syncForResident(Resident $resident, array $dates): array
    {
        if (! AdultImmunizationErdMode::isActive()) {
            throw ValidationException::withMessages([
                'dates' => AdultImmunizationErdMode::TABLE_MISSING_MESSAGE,
            ]);
        }

        if (! AdultImmunizationEligibility::allows($resident->sex, $resident->birthday)) {
            abort(403, AdultImmunizationEligibility::INELIGIBLE_MESSAGE);
        }

        $pk = AdultImmunizationErdMode::primaryKey();
        $now = now();

        foreach (AdultImmunizationErdMode::vaccineTypes() as $vaccineType) {
            $dateGiven = trim((string) ($dates[$vaccineType] ?? ''));
            if ($dateGiven === '') {
                continue;
            }

            $existing = DB::table('adult_immunization')
                ->where('resident_id', $resident->getKey())
                ->where('vaccine_type', $vaccineType)
                ->first();

            if ($existing !== null) {
                DB::table('adult_immunization')
                    ->where($pk, $existing->{$pk})
                    ->update([
                        'date_given' => $dateGiven,
                        'updated_at' => $now,
                    ]);

                continue;
            }

            try {
                DB::table('adult_immunization')->insert([
                    'resident_id' => $resident->getKey(),
                    'vaccine_type' => $vaccineType,
                    'date_given' => $dateGiven,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException $e) {
                if (! $this->isDuplicateViolation($e)) {
                    throw $e;
                }
            }
        }

        return $this->historyForResident($resident);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyForResident(Resident $resident): array
    {
        if (! AdultImmunizationErdMode::isActive()) {
            return [];
        }

        $pk = AdultImmunizationErdMode::primaryKey();

        return DB::table('adult_immunization')
            ->where('resident_id', $resident->getKey())
            ->orderByDesc('date_given')
            ->orderByDesc($pk)
            ->get()
            ->map(fn (object $row): array => $this->toPresentation($row))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toPresentation(?object $row): array
    {
        if ($row === null) {
            return [];
        }

        $pk = AdultImmunizationErdMode::primaryKey();
        $id = (int) ($row->{$pk} ?? 0);
        $type = (string) ($row->vaccine_type ?? '');
        $date = (string) ($row->date_given ?? '');

        return [
            'id' => $id,
            'vaccine_type' => $type,
            'vaccine_label' => AdultImmunizationErdMode::vaccineLabel($type),
            'date_given' => $date,
            'date_given_label' => DisplayDate::format($date) ?: $date,
        ];
    }

    private function isDuplicateViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $message = strtolower($e->getMessage());

        return $code === '23000'
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }
}
