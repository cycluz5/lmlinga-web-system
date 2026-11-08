<?php

namespace App\Http\Requests;

use App\Models\ChildNutrition;
use App\Support\HealthMemberIdentity;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreChildNutritionRequest extends FormRequest
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
        $dobRule = $this->dobAfterOrEqualRule();

        $rules = [
            'newborn' => ['nullable', 'array'],
            'newborn.length' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'newborn.weight' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'newborn.breastfeeding_date' => array_values(array_filter([
                'nullable',
                'date',
                'date_format:Y-m-d',
                'before_or_equal:today',
                $dobRule,
            ])),

            'iron' => ['nullable', 'array'],
            'iron.1st' => $this->optionalDateRules($dobRule),
            'iron.2nd' => $this->optionalDateRules($dobRule),
            'iron.3rd' => $this->optionalDateRules($dobRule),

            'vitamin_a' => ['nullable', 'array'],
            'vitamin_a.va-6-11' => $this->optionalDateRules($dobRule),
            'vitamin_a.va-12-59-1' => $this->optionalDateRules($dobRule),
            'vitamin_a.va-12-59-2' => $this->optionalDateRules($dobRule),

            'mnp' => ['nullable', 'array'],
            'mnp.mnp-6-11' => $this->optionalDateRules($dobRule),
            'mnp.mnp-12-23' => $this->optionalDateRules($dobRule),

            'lns_sq' => ['nullable', 'array'],
            'lns_sq.lns-6-11' => $this->optionalDateRules($dobRule),
            'lns_sq.lns-12-23' => $this->optionalDateRules($dobRule),

            'mam' => ['nullable', 'array'],
            'sam' => ['nullable', 'array'],

            'resident_id' => ['prohibited'],
            'child_nutrition_id' => ['prohibited'],
            'household_id' => ['prohibited'],
        ];

        foreach (ChildNutrition::SFP_PROGRAMS as $program) {
            foreach (ChildNutrition::SFP_OUTCOMES as $outcome) {
                $rules["{$program}.{$outcome}"] = ['nullable', 'array'];
                $rules["{$program}.{$outcome}.date"] = $this->optionalDateRules($dobRule);
                $rules["{$program}_{$outcome}"] = [
                    'nullable',
                    'string',
                    Rule::in(ChildNutrition::SFP_ACTIONS),
                ];
            }
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['newborn', 'iron', 'vitamin_a', 'mnp', 'lns_sq', 'mam', 'sam'] as $group) {
                $value = $this->input($group);
                if ($value !== null && ! is_array($value)) {
                    $validator->errors()->add($group, 'The '.$group.' field must be an array.');
                }
            }

            foreach (ChildNutrition::SFP_PROGRAMS as $program) {
                $programGroup = $this->input($program);
                if (! is_array($programGroup)) {
                    continue;
                }

                foreach ($programGroup as $outcomeKey => $row) {
                    if (! in_array((string) $outcomeKey, ChildNutrition::SFP_OUTCOMES, true)) {
                        $validator->errors()->add(
                            "{$program}.{$outcomeKey}",
                            'Supplementary feeding outcome is invalid.'
                        );

                        continue;
                    }

                    if ($row !== null && ! is_array($row)) {
                        $validator->errors()->add(
                            "{$program}.{$outcomeKey}",
                            'Supplementary feeding outcome must be an array.'
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
        $dobMessage = 'The date must be on or after the child\'s date of birth.';

        return [
            'resident_id.prohibited' => 'Resident identity cannot be supplied in the request.',
            'child_nutrition_id.prohibited' => 'Child nutrition identity cannot be supplied in the request.',
            'household_id.prohibited' => 'Household identity cannot be supplied in the request.',
            'newborn.length.numeric' => 'Length at Birth must be a number.',
            'newborn.weight.numeric' => 'Weight at Birth must be a number.',
            'newborn.length.min' => 'Length at Birth must be zero or greater.',
            'newborn.weight.min' => 'Weight at Birth must be zero or greater.',
            'newborn.breastfeeding_date.date_format' => 'Initiated feeding date must be a valid date.',
            'newborn.breastfeeding_date.before_or_equal' => 'Initiated feeding date cannot be a future date.',
            'newborn.breastfeeding_date.after_or_equal' => $dobMessage,
            'iron.*.after_or_equal' => $dobMessage,
            'vitamin_a.*.after_or_equal' => $dobMessage,
            'mnp.*.after_or_equal' => $dobMessage,
            'lns_sq.*.after_or_equal' => $dobMessage,
            'mam.*.date.after_or_equal' => $dobMessage,
            'sam.*.date.after_or_equal' => $dobMessage,
        ];
    }

    /**
     * @return list<string>
     */
    private function optionalDateRules(?string $dobRule): array
    {
        return array_values(array_filter([
            'nullable',
            'date_format:Y-m-d',
            $dobRule,
        ]));
    }

    private function dobAfterOrEqualRule(): ?string
    {
        $birthday = $this->resolvedResidentBirthday();

        return $birthday !== null ? 'after_or_equal:'.$birthday : null;
    }

    private function resolvedResidentBirthday(): ?string
    {
        $householdNo = trim((string) $this->route('householdNo', ''));
        $memberId = trim((string) $this->route('memberId', ''));
        if ($householdNo === '' || $memberId === '') {
            return null;
        }

        $ctx = app(HealthMemberIdentity::class)->resolve($householdNo, $memberId);
        $resident = $ctx['resident'] ?? null;
        if ($resident === null) {
            return null;
        }

        $birthday = $resident->birthday ?? null;
        if ($birthday instanceof Carbon) {
            return $birthday->format('Y-m-d');
        }

        $raw = trim((string) $birthday);
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
