<?php

namespace App\Support;

use App\Models\DisabilityType;
use App\Models\MedicalHistory;
use App\Models\Resident;
use Illuminate\Support\Facades\Schema;

/**
 * Syncs member Health & Welfare checkboxes onto ERD related tables when present.
 */
final class ResidentHealthConditionSync
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public static function sync(Resident $resident, array $validated): void
    {
        self::syncDisability($resident, $validated);
        self::syncMedicalHistory($resident, $validated);
    }

    public static function tableAvailable(string $table): bool
    {
        return Schema::hasTable($table);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private static function syncDisability(Resident $resident, array $validated): void
    {
        if (! Schema::hasTable('disability_type') || ! array_key_exists('disability', $validated)) {
            return;
        }

        $selected = array_values(array_unique(array_map('strval', $validated['disability'] ?? [])));
        $others = in_array('others', $selected, true)
            ? trim((string) ($validated['disability_others'] ?? ''))
            : '';

        $attributes = [
            'resident_id' => $resident->getKey(),
            'no_disability' => in_array('none', $selected, true),
            'intellectual_disability' => in_array('Intellectual Disability (ID)', $selected, true),
            'mental_disability' => in_array('Mental Disability (MD)', $selected, true),
            'physical_disability' => in_array('Physical Disability (PD)', $selected, true),
            'other_disability' => in_array('others', $selected, true),
            'other_disability_specify' => in_array('others', $selected, true) && $others !== '' ? $others : null,
        ];

        DisabilityType::query()->updateOrCreate(
            ['resident_id' => $resident->getKey()],
            $attributes
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private static function syncMedicalHistory(Resident $resident, array $validated): void
    {
        if (! Schema::hasTable('medical_history') || ! array_key_exists('medical_history', $validated)) {
            return;
        }

        $selected = array_values(array_unique(array_map('strval', $validated['medical_history'] ?? [])));
        $others = in_array('others', $selected, true)
            ? trim((string) ($validated['medical_others'] ?? ''))
            : '';

        $attributes = [
            'resident_id' => $resident->getKey(),
            'no_medical_history' => in_array('none', $selected, true),
            'diabetes_mellitus' => in_array('Diabetes Mellitus', $selected, true),
            'heart_disease' => in_array('Heart Disease', $selected, true),
            'hypertension' => in_array('Hypertension', $selected, true),
            'kidney_disease' => in_array('Kidney Disease', $selected, true),
            'tuberculosis' => in_array('Tuberculosis', $selected, true),
            'other_medical_history' => in_array('others', $selected, true) && $others !== '' ? $others : null,
        ];

        MedicalHistory::query()->updateOrCreate(
            ['resident_id' => $resident->getKey()],
            $attributes
        );
    }
}
