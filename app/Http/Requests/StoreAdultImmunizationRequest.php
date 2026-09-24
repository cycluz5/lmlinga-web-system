<?php

namespace App\Http\Requests;

use App\Support\AdultImmunizationEligibility;
use App\Support\AdultImmunizationErdMode;
use App\Support\HealthMemberIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAdultImmunizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $householdNo = (string) $this->route('householdNo');
        $memberId = (string) $this->route('memberId');
        $ctx = app(HealthMemberIdentity::class)->resolve($householdNo, $memberId);

        return AdultImmunizationEligibility::allowsMemberContext($ctx);
    }

    /**
     * One optional date field per known vaccine type, submitted together —
     * mirrors Child Immunization's single combined dose-date save.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'dates' => ['nullable', 'array'],
            'resident_id' => ['prohibited'],
            'household_id' => ['prohibited'],
            'member_id' => ['prohibited'],
        ];

        foreach (AdultImmunizationErdMode::vaccineTypes() as $type) {
            $rules["dates.{$type}"] = ['nullable', 'date', 'date_format:Y-m-d', 'before_or_equal:today'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dates.*.date' => 'Date given must be a valid date.',
            'dates.*.before_or_equal' => 'Date given cannot be a future date.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $dates = $this->input('dates', []);
            if (! is_array($dates)) {
                return;
            }

            $allowed = AdultImmunizationErdMode::vaccineTypes();
            foreach (array_keys($dates) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $validator->errors()->add('dates', 'Vaccine type is invalid.');

                    return;
                }
            }
        });
    }
}
