<?php

namespace App\Http\Requests;

use App\Support\DemoHouseholdWaterSupply;
use App\Services\SpotMappingHandoffService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreHouseholdWaterSupplyRequest extends FormRequest
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

        $specify = $this->input('specify_water_source');
        if (is_string($specify)) {
            $specify = trim($specify);
        }

        // Strip any browser-supplied computed status / ownership fields — server derives them.
        $this->request->remove('basic_safe_water_status');
        $this->request->remove('water_status');
        $this->request->remove('safe_water_status');
        $this->request->remove('household_type');
        $this->request->remove('household_id');
        $this->request->remove('id');
        $this->request->remove('completed_step');

        $this->merge([
            'household_no' => $householdNo,
            'water_supply_status' => strtolower(trim((string) $this->input('water_supply_status', ''))),
            'specify_water_source' => $specify === '' ? null : $specify,
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $levels = implode(',', DemoHouseholdWaterSupply::waterSupplyLevels());

        return [
            'household_no' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'water_supply_status' => ['required', 'in:'.$levels],
            'specify_water_source' => [
                'nullable',
                'string',
                'max:255',
                'required_if:water_supply_status,'.DemoHouseholdWaterSupply::WATER_LEVEL_OTHERS,
            ],
            'water_source_location' => ['required', 'in:yes,no'],
            'water_availability' => ['required', 'in:yes,no'],
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
            'water_supply_status.required' => 'Please select a water supply level.',
            'specify_water_source.required_if' => 'Please specify the water source.',
            'water_source_location.required' => 'Please select water source location.',
            'water_availability.required' => 'Please indicate water availability.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $householdNo = (string) $this->input('household_no', '');

            if (! DemoHouseholdWaterSupply::isRecognized($householdNo)) {
                $validator->errors()->add(
                    'household_no',
                    SpotMappingHandoffService::INVALID_MESSAGE
                );
            }
        });
    }
}
