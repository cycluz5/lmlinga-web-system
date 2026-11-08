<?php

namespace App\Http\Requests;

use App\Support\DemoHouseholdWaterSupply;
use App\Services\SpotMappingHandoffService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreHouseholdWaterSupplyStep4Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $householdNo = DemoHouseholdWaterSupply::normalizeHouseholdNo(
            (string) $this->input('household_no', '')
        );

        $submitted = $this->input('solid_waste_practices', []);
        $practices = is_array($submitted) ? $submitted : [$submitted];

        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $practices
        ), static fn (string $value): bool => $value !== '')));

        $this->merge([
            'household_no' => $householdNo,
            'solid_waste_practices' => $normalized,
        ]);

        // Strip forged ownership / progression / derived fields.
        $this->request->remove('household_id');
        $this->request->remove('id');
        $this->request->remove('completed_step');
        $this->request->remove('solid_waste_status');
        $this->request->remove('basic_safe_water_status');
        $this->request->remove('toilet_status');
        $this->request->remove('management_status');
        $this->request->remove('household_environmental_profile_id');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'household_no' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'solid_waste_practices' => ['required', 'array', 'min:1', 'max:4'],
            'solid_waste_practices.*' => [
                'required',
                'string',
                Rule::in(DemoHouseholdWaterSupply::solidWastePracticeValues()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'household_no.required' => 'A linked household is required.',
            'household_no.regex' => 'The household number format is invalid.',
            'solid_waste_practices.required' => 'Please select at least one solid waste management practice.',
            'solid_waste_practices.array' => 'Please select at least one solid waste management practice.',
            'solid_waste_practices.min' => 'Please select at least one solid waste management practice.',
            'solid_waste_practices.*.in' => 'One or more selected solid waste management practices are invalid.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $householdNo = (string) $this->input('household_no', '');

            if (! DemoHouseholdWaterSupply::hasCompletedStep3($householdNo)
                || ! DemoHouseholdWaterSupply::isRecognized($householdNo)) {
                $validator->errors()->add(
                    'household_no',
                    SpotMappingHandoffService::INVALID_MESSAGE
                );
            }
        });
    }
}
