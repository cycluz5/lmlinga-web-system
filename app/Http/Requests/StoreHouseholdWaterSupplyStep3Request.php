<?php

namespace App\Http\Requests;

use App\Support\DemoHouseholdWaterSupply;
use App\Services\SpotMappingHandoffService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreHouseholdWaterSupplyStep3Request extends FormRequest
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

        $toiletType = strtolower(trim((string) $this->input('toilet_type', '')));
        $withoutToilet = DemoHouseholdWaterSupply::isWithoutToilet($toiletType);

        $openDefecation = $this->blankToNull($this->input('open_defecation_practiced'));
        $sharedToilet = $this->blankToNull($this->input('shared_toilet'));
        $sewageDisposal = $this->blankToNull($this->input('sewage_disposal_method'));

        if ($withoutToilet) {
            $sharedToilet = 'no';
            $sewageDisposal = null;
        }

        // Strip any browser-supplied computed statuses / ownership — server derives them.
        $this->request->remove('toilet_status');
        $this->request->remove('management_status');
        $this->request->remove('facility_status');
        $this->request->remove('safely_managed');
        $this->request->remove('household_id');
        $this->request->remove('id');
        $this->request->remove('completed_step');
        $this->request->remove('basic_safe_water_status');
        $this->request->remove('solid_waste_status');
        $this->request->remove('household_environmental_profile_id');

        $this->merge([
            'household_no' => $householdNo,
            'toilet_type' => $toiletType === '' ? null : $toiletType,
            'open_defecation_practiced' => $openDefecation,
            'shared_toilet' => $sharedToilet,
            'sewage_disposal_method' => $sewageDisposal,
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $toiletType = strtolower(trim((string) $this->input('toilet_type', '')));
        $withoutToilet = DemoHouseholdWaterSupply::isWithoutToilet($toiletType);

        return [
            'household_no' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'toilet_type' => [
                'required',
                'string',
                Rule::in(DemoHouseholdWaterSupply::toiletTypes()),
            ],
            'open_defecation_practiced' => ['required', 'in:yes,no'],
            'shared_toilet' => $withoutToilet
                ? ['required', 'in:no']
                : ['required', 'in:yes,no'],
            'sewage_disposal_method' => [
                'nullable',
                'string',
                Rule::in(DemoHouseholdWaterSupply::sewageDisposalMethods()),
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
            'toilet_type.required' => 'Please select the type of toilet.',
            'toilet_type.in' => 'Please select a valid type of toilet.',
            'open_defecation_practiced.required' => 'Please indicate whether open defecation is practiced.',
            'open_defecation_practiced.in' => 'Please indicate whether open defecation is practiced.',
            'shared_toilet.required' => 'Please indicate whether the toilet facility is shared.',
            'shared_toilet.in' => 'Please indicate whether the toilet facility is shared.',
            'sewage_disposal_method.required' => 'Please select the excreta or sewage disposal method.',
            'sewage_disposal_method.in' => 'Please select the excreta or sewage disposal method.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $householdNo = (string) $this->input('household_no', '');

            if (! DemoHouseholdWaterSupply::hasCompletedStep2($householdNo)
                || ! DemoHouseholdWaterSupply::isRecognized($householdNo)) {
                $validator->errors()->add(
                    'household_no',
                    SpotMappingHandoffService::INVALID_MESSAGE
                );
            }
        });
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtolower(trim((string) $value));

        return $trimmed === '' ? null : $trimmed;
    }
}
