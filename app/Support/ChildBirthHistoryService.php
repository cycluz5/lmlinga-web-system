<?php

namespace App\Support;

use App\Models\ChildBirthHistory;
use App\Models\Resident;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Birth History read/write for persisted residents.
 *
 * Authoritative ERD storage is child_nutrition (one row per resident):
 * length_at_birth_cm, weight_at_birth_kg, initiated_breastfeeding_date.
 * Legacy sqlite may also have child_nutritions newborn_* columns and/or
 * child_birth_histories (PCAB / stored status only exist on that legacy table).
 */
final class ChildBirthHistoryService
{
    public const PCAB_AT_LEAST_2_DOSES = 'at_least_2_doses_1_month_prior';

    public const PCAB_TT3_TD3_TO_TT5_TD5 = 'tt3_td3_to_tt5_td5_prior';

    /**
     * True when any compatible Birth History store exists.
     * Live ERD uses child_nutrition; it does not include child_birth_histories.
     */
    public static function persistenceAvailable(): bool
    {
        return self::nutritionPersistenceAvailable()
            || self::legacyBirthHistoryTableAvailable();
    }

    public static function nutritionPersistenceAvailable(): bool
    {
        $guard = app(DatabaseSchemaGuard::class);

        return $guard->tableExists('child_nutrition')
            || $guard->tableExists('child_nutritions');
    }

    public static function legacyBirthHistoryTableAvailable(): bool
    {
        return app(DatabaseSchemaGuard::class)->tableExists((new ChildBirthHistory)->getTable());
    }

    /**
     * @return array<string, string>
     */
    public static function pcabLabels(): array
    {
        return [
            self::PCAB_AT_LEAST_2_DOSES => 'At least 2 doses received at least 1 month prior to delivery',
            self::PCAB_TT3_TD3_TO_TT5_TD5 => 'TT3/TD3 – TT5/TD5 given to the mother anytime prior to delivery',
        ];
    }

    /**
     * Upsert birth history for a persisted resident. resident_id is always derived server-side.
     *
     * @param  array{
     *     birth_weight?: string|float|null,
     *     birth_length?: string|float|null,
     *     pcab?: string|null,
     *     breastfeeding_date?: string|null
     * }  $payload
     */
    public function saveForResident(Resident $resident, array $payload): void
    {
        HouseholdProfilingWriteGuard::rejectBirthHistoryWrite();

        if ((int) $resident->getKey() <= 0) {
            abort(404, 'Resident was not found.');
        }

        $weight = $this->nullableDecimal($payload['birth_weight'] ?? null);
        $length = $this->nullableDecimal($payload['birth_length'] ?? null);
        $pcab = $this->nullableString($payload['pcab'] ?? null);
        $bfDate = $this->nullableString($payload['breastfeeding_date'] ?? null);
        $bfDate = $bfDate !== null && $bfDate !== '' ? $bfDate : null;

        if (self::nutritionPersistenceAvailable()) {
            $this->upsertNutritionBirthFields($resident, $weight, $length, $bfDate);
        }

        if (self::legacyBirthHistoryTableAvailable()) {
            ChildBirthHistory::query()->updateOrCreate(
                ['resident_id' => $resident->getKey()],
                [
                    'birth_weight_kg' => $weight,
                    'birth_length_cm' => $length,
                    'status' => self::deriveStatus($weight),
                    'pcab' => $pcab,
                    'breastfeeding_date' => $bfDate,
                ]
            );
        }
    }

    /**
     * @return array{weight: string, length: string, status: string, pcab: string, breastfeeding_date: string, breastfeeding_date_display: string}|null
     */
    public static function presentationForResident(Resident $resident): ?array
    {
        if (! self::persistenceAvailable()) {
            return null;
        }

        $nutrition = self::nutritionBirthRow($resident);
        $legacy = null;
        if (self::legacyBirthHistoryTableAvailable()) {
            $resident->loadMissing('childBirthHistory');
            $legacy = $resident->childBirthHistory;
        }

        if ($nutrition === null && $legacy === null) {
            return null;
        }

        $nutrition = $nutrition ?? ['weight' => '', 'length' => '', 'breastfeeding_date' => ''];

        $weight = self::firstFilled(
            $nutrition['weight'],
            self::formatDecimal($legacy?->birth_weight_kg)
        );
        $length = self::firstFilled(
            $nutrition['length'],
            self::formatDecimal($legacy?->birth_length_cm)
        );
        $bfDate = self::firstFilled(
            $nutrition['breastfeeding_date'],
            self::formatIsoDate($legacy?->breastfeeding_date)
        );
        $pcab = $legacy !== null ? (string) ($legacy->pcab ?? '') : '';
        $storedStatus = trim((string) ($legacy?->status ?? ''));

        return [
            'weight' => $weight,
            'length' => $length,
            'status' => $storedStatus !== ''
                ? $storedStatus
                : (string) (self::deriveStatus($weight !== '' ? $weight : null) ?? ''),
            'pcab' => self::pcabDisplayLabel($pcab !== '' ? $pcab : null),
            'breastfeeding_date' => $bfDate,
            'breastfeeding_date_display' => self::formatDisplayDate($bfDate),
        ];
    }

    /**
     * Form-safe values (ISO date for <input type="date">).
     *
     * @return array{weight: string, length: string, pcab: string, breastfeeding_date: string}
     */
    public static function formValuesForResident(Resident $resident): array
    {
        $empty = [
            'weight' => '',
            'length' => '',
            'pcab' => '',
            'breastfeeding_date' => '',
        ];

        if (! self::persistenceAvailable()) {
            return $empty;
        }

        $nutrition = self::nutritionBirthRow($resident);
        $legacy = null;
        if (self::legacyBirthHistoryTableAvailable()) {
            $resident->loadMissing('childBirthHistory');
            $legacy = $resident->childBirthHistory;
        }

        if ($nutrition === null && $legacy === null) {
            return $empty;
        }

        $nutrition = $nutrition ?? ['weight' => '', 'length' => '', 'breastfeeding_date' => ''];

        return [
            'weight' => self::firstFilled(
                $nutrition['weight'],
                self::formatDecimal($legacy?->birth_weight_kg)
            ),
            'length' => self::firstFilled(
                $nutrition['length'],
                self::formatDecimal($legacy?->birth_length_cm)
            ),
            'pcab' => self::pcabFormValue($legacy),
            'breastfeeding_date' => self::firstFilled(
                $nutrition['breastfeeding_date'],
                self::formatIsoDate($legacy?->breastfeeding_date)
            ),
        ];
    }

    /**
     * Map a DB record to the frozen demoMember birth_history presentation shape.
     *
     * @return array{weight: string, length: string, status: string, pcab: string, breastfeeding_date: string, breastfeeding_date_display: string}
     */
    public static function toPresentation(ChildBirthHistory $record): array
    {
        $weight = $record->birth_weight_kg;
        $length = $record->birth_length_cm;
        $bfDate = self::formatIsoDate($record->breastfeeding_date);

        return [
            'weight' => $weight !== null ? self::formatDecimal($weight) : '',
            'length' => $length !== null ? self::formatDecimal($length) : '',
            'status' => (string) ($record->status ?? ''),
            'pcab' => self::pcabDisplayLabel($record->pcab),
            'breastfeeding_date' => $bfDate,
            'breastfeeding_date_display' => self::formatDisplayDate($bfDate),
        ];
    }

    /**
     * Form-safe pcab value (stored key, not display label).
     */
    public static function pcabFormValue(?ChildBirthHistory $record): string
    {
        if ($record === null || $record->pcab === null || $record->pcab === '') {
            return '';
        }

        return (string) $record->pcab;
    }

    public static function pcabDisplayLabel(?string $pcab): string
    {
        if ($pcab === null || $pcab === '') {
            return '';
        }

        return self::pcabLabels()[$pcab] ?? $pcab;
    }

    public static function deriveStatus(?string $weight): ?string
    {
        if ($weight === null || $weight === '') {
            return null;
        }

        $kg = (float) $weight;
        if ($kg <= 0) {
            return null;
        }

        return $kg < 2.5 ? 'Low Birth Weight' : 'Normal';
    }

    public static function formatDisplayDate(?string $iso): string
    {
        return DisplayDate::format($iso);
    }

    /**
     * @return array{weight: string, length: string, date: string}
     */
    public static function nutritionColumnMap(): array
    {
        if (ChildNutritionErdMode::isActive()) {
            return [
                'weight' => 'weight_at_birth_kg',
                'length' => 'length_at_birth_cm',
                'date' => 'initiated_breastfeeding_date',
            ];
        }

        return [
            'weight' => 'newborn_weight_kg',
            'length' => 'newborn_length_cm',
            'date' => 'newborn_breastfeeding_date',
        ];
    }

    private function upsertNutritionBirthFields(
        Resident $resident,
        ?string $weight,
        ?string $length,
        ?string $bfDate,
    ): void {
        $table = ChildNutritionErdMode::nutritionTable();
        $map = self::nutritionColumnMap();
        $residentKey = $resident->getKey();
        $now = now();

        $values = [
            $map['weight'] => $weight,
            $map['length'] => $length,
            $map['date'] => $bfDate,
            'updated_at' => $now,
        ];

        $existing = DB::table($table)
            ->where('resident_id', $residentKey)
            ->orderBy(ChildNutritionErdMode::nutritionPrimaryKey())
            ->first();

        if ($existing !== null) {
            DB::table($table)
                ->where('resident_id', $residentKey)
                ->where(
                    ChildNutritionErdMode::nutritionPrimaryKey(),
                    $existing->{ChildNutritionErdMode::nutritionPrimaryKey()}
                )
                ->update($values);

            return;
        }

        DB::table($table)->insert(array_merge($values, [
            'resident_id' => $residentKey,
            'created_at' => $now,
        ]));
    }

    /**
     * @return array{weight: string, length: string, breastfeeding_date: string}|null
     */
    private static function nutritionBirthRow(Resident $resident): ?array
    {
        if (! self::nutritionPersistenceAvailable()) {
            return null;
        }

        $table = ChildNutritionErdMode::nutritionTable();
        if (! Schema::hasTable($table)) {
            return null;
        }

        $row = DB::table($table)
            ->where('resident_id', $resident->getKey())
            ->orderBy(ChildNutritionErdMode::nutritionPrimaryKey())
            ->first();

        if ($row === null) {
            return null;
        }

        $map = self::nutritionColumnMap();

        return [
            'weight' => self::formatDecimal($row->{$map['weight']} ?? null),
            'length' => self::formatDecimal($row->{$map['length']} ?? null),
            'breastfeeding_date' => self::formatIsoDate($row->{$map['date']} ?? null),
        ];
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function firstFilled(string $preferred, string $fallback): string
    {
        return $preferred !== '' ? $preferred : $fallback;
    }

    private static function formatDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, 2, '.', '');
    }

    private static function formatIsoDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d');
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }
}
