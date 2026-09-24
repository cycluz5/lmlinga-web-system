<?php

namespace App\Http\Requests;

use App\Models\ChildImmunization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreChildImmunizationRequest extends FormRequest
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
            'vaccine_types.*' => ['string', Rule::in(ChildImmunization::SELECTABLE_TYPE_KEYS)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'resident_id' => ['prohibited'],
            'child_immunization_id' => ['prohibited'],
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

            foreach ($vaccines as $vaccineType => $doses) {
                $vaccineKey = strtolower(trim((string) $vaccineType));

                if (! in_array($vaccineKey, ChildImmunization::VACCINE_TYPES, true)) {
                    $validator->errors()->add("vaccines.{$vaccineType}", 'Vaccine type is invalid.');

                    continue;
                }

                if (! is_array($doses)) {
                    $validator->errors()->add("vaccines.{$vaccineKey}", 'Vaccine doses must be an array.');

                    continue;
                }

                $maxIndex = ChildImmunization::DOSE_SLOT_COUNTS[$vaccineKey] - 1;

                foreach ($doses as $index => $dateValue) {
                    if (! is_numeric($index) || (int) $index != $index) {
                        $validator->errors()->add("vaccines.{$vaccineKey}.{$index}", 'Dose index is invalid.');

                        continue;
                    }

                    $doseIndex = (int) $index;
                    if ($doseIndex < 0 || $doseIndex > $maxIndex) {
                        $validator->errors()->add("vaccines.{$vaccineKey}.{$index}", 'Dose index is out of range.');

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
                        $validator->errors()->add("vaccines.{$vaccineKey}.{$index}", 'Vaccine date must be a valid date.');
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
            'remarks.max' => 'Remarks are too long.',
            'resident_id.prohibited' => 'Resident identity cannot be supplied in the request.',
            'child_immunization_id.prohibited' => 'Immunization identity cannot be supplied in the request.',
        ];
    }
}
