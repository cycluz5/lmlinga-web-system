<?php

namespace App\Http\Requests;

use App\Support\ChildBirthHistoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChildBirthHistoryRequest extends FormRequest
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
            'birth_weight' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'birth_length' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'pcab' => [
                'nullable',
                'string',
                Rule::in([
                    ChildBirthHistoryService::PCAB_AT_LEAST_2_DOSES,
                    ChildBirthHistoryService::PCAB_TT3_TD3_TO_TT5_TD5,
                ]),
            ],
            'breastfeeding_date' => ['nullable', 'date', 'date_format:Y-m-d', 'before_or_equal:today'],
            // resident_id must never be accepted from the client.
            'resident_id' => ['prohibited'],
            'household_id' => ['prohibited'],
            'child_nutrition_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'birth_weight.numeric' => 'Birth weight must be a number.',
            'birth_weight.min' => 'Birth weight cannot be negative.',
            'birth_length.numeric' => 'Birth length must be a number.',
            'birth_length.min' => 'Birth length cannot be negative.',
            'pcab.in' => 'CPAB selection is invalid.',
            'breastfeeding_date.date_format' => 'Initiated feeding date must be a valid date.',
            'breastfeeding_date.before_or_equal' => 'Initiated feeding date cannot be a future date.',
            'resident_id.prohibited' => 'Resident identity cannot be supplied in the request.',
            'household_id.prohibited' => 'Household identity cannot be supplied in the request.',
            'child_nutrition_id.prohibited' => 'Child nutrition identity cannot be supplied in the request.',
        ];
    }
}
