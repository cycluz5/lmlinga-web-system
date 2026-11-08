<?php

namespace App\Http\Requests;

use App\Models\Household;
use App\Support\DemoCatalog;
use App\Support\DemoHouseholdWaterSupply;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateHouseholdAmenitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $householdNo = DemoCatalog::normalizeHouseholdNo(
            (string) $this->route('householdNo', '')
        );

        $toiletType = strtolower(trim((string) $this->input('toilet_type', '')));
        $withoutToilet = DemoHouseholdWaterSupply::isWithoutToilet($toiletType);
        $submittedPractices = $this->input('solid_waste_practices', []);
        $practices = is_array($submittedPractices) ? $submittedPractices : [$submittedPractices];

        // Strip browser-supplied computed statuses, progression, and forged ownership fields.
        // Amenities does not edit household_type (read-only badge); ignore any browser value.
        $this->request->remove('basic_safe_water_status');
        $this->request->remove('water_status');
        $this->request->remove('safe_water_status');
        $this->request->remove('toilet_status');
        $this->request->remove('management_status');
        $this->request->remove('facility_status');
        $this->request->remove('safely_managed');
        $this->request->remove('solid_waste_status');
        $this->request->remove('complete_sanitation_status');
        $this->request->remove('completed_step');
        $this->request->remove('step');
        $this->request->remove('household_type');
        $this->request->remove('id');
        $this->request->remove('household_id');
        $this->request->remove('household_environmental_profile_id');
        $this->request->remove('environmental_profile_id');
        $this->request->remove('profile_id');
        $this->request->remove('solid_waste_practice_id');
        $this->request->remove('solid_waste_id');

        $this->merge([
            'household_no' => $householdNo,
            'water_supply_status' => strtolower(trim((string) $this->input('water_supply_status', ''))),
            'specify_water_source' => $this->blankToNull($this->input('specify_water_source'), false),
            'water_source_location' => strtolower(trim((string) $this->input('water_source_location', ''))),
            'water_availability' => strtolower(trim((string) $this->input('water_availability', ''))),
            'microbiological_test_date' => $this->blankToNull($this->input('microbiological_test_date'), false),
            'microbiological_result' => $this->blankToNull($this->input('microbiological_result')),
            'physicochemical_test_date' => $this->blankToNull($this->input('physicochemical_test_date'), false),
            'physicochemical_result' => $this->blankToNull($this->input('physicochemical_result')),
            'toilet_type' => $toiletType,
            'open_defecation_practiced' => strtolower(trim((string) $this->input('open_defecation_practiced', ''))),
            'shared_toilet' => $withoutToilet
                ? 'no'
                : strtolower(trim((string) $this->input('shared_toilet', ''))),
            'sewage_disposal_method' => $withoutToilet
                ? null
                : $this->blankToNull($this->input('sewage_disposal_method')),
            'solid_waste_practices' => array_values(array_unique(array_filter(array_map(
                static fn (mixed $value): string => strtolower(trim((string) $value)),
                $practices
            ), static fn (string $value): bool => $value !== ''))),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $withoutToilet = DemoHouseholdWaterSupply::isWithoutToilet((string) $this->input('toilet_type', ''));

        return [
            'household_no' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'water_supply_status' => ['required', Rule::in(DemoHouseholdWaterSupply::waterSupplyLevels())],
            'specify_water_source' => [
                'nullable',
                'string',
                'max:255',
                'required_if:water_supply_status,'.DemoHouseholdWaterSupply::WATER_LEVEL_OTHERS,
            ],
            'water_source_location' => ['required', 'in:yes,no'],
            'water_availability' => ['required', 'in:yes,no'],
            'microbiological_test_date' => ['nullable', 'date', 'date_format:Y-m-d', 'before_or_equal:today'],
            'microbiological_result' => ['nullable', 'in:passed,failed'],
            'physicochemical_test_date' => ['nullable', 'date', 'date_format:Y-m-d', 'before_or_equal:today'],
            'physicochemical_result' => ['nullable', 'in:passed,failed'],
            'toilet_type' => ['required', Rule::in(DemoHouseholdWaterSupply::toiletTypes())],
            'open_defecation_practiced' => ['required', 'in:yes,no'],
            'shared_toilet' => $withoutToilet ? ['required', 'in:no'] : ['required', 'in:yes,no'],
            'sewage_disposal_method' => [
                'nullable',
                Rule::in(DemoHouseholdWaterSupply::sewageDisposalMethods()),
            ],
            'solid_waste_practices' => ['required', 'array', 'min:1', 'max:4'],
            'solid_waste_practices.*' => ['required', Rule::in(DemoHouseholdWaterSupply::solidWastePracticeValues())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $householdNo = (string) $this->input('household_no', '');
            $household = Household::query()->where('household_no', $householdNo)->first();

            if ($household === null) {
                $validator->errors()->add(
                    'household_no',
                    'Amenities can only be saved for registered database households.'
                );

                return;
            }

            $microDate = (string) ($this->input('microbiological_test_date') ?? '');
            $microResult = (string) ($this->input('microbiological_result') ?? '');
            $physicoDate = (string) ($this->input('physicochemical_test_date') ?? '');
            $physicoResult = (string) ($this->input('physicochemical_result') ?? '');

            if ($microDate !== '' && $microResult === '') {
                $validator->errors()->add('microbiological_result', 'Please select the microbiological test result.');
            }
            if ($microDate === '' && $microResult !== '') {
                $validator->errors()->add('microbiological_test_date', 'Please select the microbiological test date.');
            }
            if ($physicoDate !== '' && $physicoResult === '') {
                $validator->errors()->add('physicochemical_result', 'Please select the physico-chemical test result.');
            }
            if ($physicoDate === '' && $physicoResult !== '') {
                $validator->errors()->add('physicochemical_test_date', 'Please select the physico-chemical test date.');
            }
        });
    }

    private function blankToNull(mixed $value, bool $lowercase = true): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        return $lowercase ? strtolower($trimmed) : $trimmed;
    }
}
