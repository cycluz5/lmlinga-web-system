<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTimbangRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only raw measurements are user input. Weight-for-age, height-for-age,
     * MUAC status, BMI, and overall status are always server-computed by
     * NutritionAssessmentService — never accepted from the client.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'measurement_date' => ['required', 'date', 'date_format:Y-m-d', 'before_or_equal:today'],
            'weight_kg' => ['nullable', 'numeric', 'gt:0', 'max:999.99'],
            'height_cm' => ['nullable', 'numeric', 'gt:0', 'max:999.99'],
            'muac_cm' => ['nullable', 'numeric', 'gt:0', 'max:99.9'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'bmi' => ['prohibited'],
            'bmi_value' => ['prohibited'],
            'bmi_status' => ['prohibited'],
            'weight_for_age' => ['prohibited'],
            'height_for_age' => ['prohibited'],
            'weight_for_height' => ['prohibited'],
            'muac_status' => ['prohibited'],
            'overall_nutritional_status' => ['prohibited'],
            'resident_id' => ['prohibited'],
            'timbang_id' => ['prohibited'],
            'household_id' => ['prohibited'],
            'unregistered_child_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filledMeasurement('weight_kg')
                || $this->filledMeasurement('height_cm')
                || $this->filledMeasurement('muac_cm')) {
                return;
            }

            $validator->errors()->add(
                'weight_kg',
                'Enter at least one of weight, height, or MUAC.'
            );
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'measurement_date.required' => 'Measurement date is required.',
            'measurement_date.before_or_equal' => 'Measurement date cannot be a future date.',
            'bmi.prohibited' => 'BMI is calculated automatically and cannot be supplied in the request.',
            'bmi_value.prohibited' => 'BMI is calculated automatically and cannot be supplied in the request.',
            'bmi_status.prohibited' => 'BMI status is calculated automatically and cannot be supplied in the request.',
            'weight_for_age.prohibited' => 'Weight-for-age is calculated automatically and cannot be supplied in the request.',
            'height_for_age.prohibited' => 'Height-for-age is calculated automatically and cannot be supplied in the request.',
            'weight_for_height.prohibited' => 'Weight-for-height is no longer a manual field.',
            'muac_status.prohibited' => 'MUAC status is calculated automatically and cannot be supplied in the request.',
            'overall_nutritional_status.prohibited' => 'Overall nutritional status is calculated automatically and cannot be supplied in the request.',
            'resident_id.prohibited' => 'Resident identity cannot be supplied in the request.',
            'timbang_id.prohibited' => 'Timbang identity cannot be supplied in the request.',
            'household_id.prohibited' => 'Household identity cannot be supplied in the request.',
            'unregistered_child_id.prohibited' => 'Unregistered child identity cannot be supplied in the request.',
        ];
    }

    private function filledMeasurement(string $key): bool
    {
        $value = $this->input($key);

        return $value !== null && trim((string) $value) !== '';
    }
}
