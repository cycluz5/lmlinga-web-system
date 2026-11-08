<?php

namespace App\Http\Requests;

use App\Models\SchoolImmunization;
use App\Support\SchoolImmunizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSchoolImmunizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'vaccines' => ['nullable', 'array'],
            'vaccine_types' => ['nullable', 'array'],
            'vaccine_types.*' => ['string', Rule::in(SchoolImmunization::SELECTABLE_TYPE_KEYS)],
            'resident_id' => ['prohibited'],
            'school_immunization_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $vaccines = $this->input('vaccines');

            if ($vaccines === null) {
                return;
            }

            if (! is_array($vaccines)) {
                $validator->errors()->add('vaccines', 'Vaccine data must be an array.');

                return;
            }

            foreach ($vaccines as $slotGroupRaw => $doseKeys) {
                $slotGroup = strtolower(trim((string) $slotGroupRaw));

                if (! is_array($doseKeys)) {
                    $validator->errors()->add(
                        "vaccines.{$slotGroupRaw}",
                        'Vaccine dose group must be an array.'
                    );

                    continue;
                }

                foreach ($doseKeys as $slotKeyRaw => $dateValue) {
                    $slotKey = strtolower(trim((string) $slotKeyRaw));

                    if (! SchoolImmunizationService::isAllowedSlot($slotGroup, $slotKey)) {
                        $validator->errors()->add(
                            "vaccines.{$slotGroupRaw}.{$slotKeyRaw}",
                            'School-based immunization dose slot is invalid.'
                        );

                        continue;
                    }

                    if ($dateValue === null || $dateValue === '') {
                        continue;
                    }

                    $date = trim((string) $dateValue);
                    if ($date === '') {
                        continue;
                    }

                    $parsed = date_create_from_format('Y-m-d', $date);
                    if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                        $validator->errors()->add(
                            "vaccines.{$slotGroupRaw}.{$slotKeyRaw}",
                            'Vaccine date must be a valid date.'
                        );
                    }
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vaccine_types.*.in' => 'Vaccine type selection is invalid.',
            'resident_id.prohibited' => 'Resident identity cannot be supplied in the request.',
            'school_immunization_id.prohibited' => 'School immunization identity cannot be supplied in the request.',
        ];
    }
}
