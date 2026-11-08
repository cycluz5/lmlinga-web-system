<?php

namespace App\Http\Requests;

use App\Support\HealthMemberIdentity;
use App\Support\MaternalCareEligibility;
use Illuminate\Foundation\Http\FormRequest;

class StoreMaternalPregnancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $householdNo = (string) $this->route('householdNo');
        $memberId = (string) $this->route('memberId');
        $ctx = app(HealthMemberIdentity::class)->resolve($householdNo, $memberId);

        if (! MaternalCareEligibility::allowsMemberContext($ctx)) {
            return false;
        }

        return MaternalCareEligibility::allowsWorkflowMemberContext($ctx);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'lmp' => ['nullable', 'date', 'before_or_equal:today'],
            'gravida' => ['nullable', 'integer', 'min:0'],
            'parity' => ['nullable', 'integer', 'min:0'],
            'edd' => ['nullable', 'date'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            // Accepted for backward-compatible request shape only. Persistence derives BMI.
            'bmi' => ['nullable', 'numeric', 'min:0'],
            'blood_pressure' => ['nullable', 'string', 'max:32'],
            'resident_id' => ['prohibited'],
            'household_id' => ['prohibited'],
            'householdNo' => ['prohibited'],
            'member_id' => ['prohibited'],
            'memberId' => ['prohibited'],
            'pregnancy_no' => ['prohibited'],
            'pregnancy_number' => ['prohibited'],
            'pregnancy_id' => ['prohibited'],
            'maternal_care_id' => ['prohibited'],
            'maternal_id' => ['prohibited'],
            'prenatal_id' => ['prohibited'],
            'prenatal_visit_id' => ['prohibited'],
            'delivery_id' => ['prohibited'],
            'delivery_outcome_id' => ['prohibited'],
            'timbang_id' => ['prohibited'],
            'status' => ['prohibited'],
            'registered_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lmp.before_or_equal' => 'Last Menstrual Period (LMP) cannot be a future date.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pregnancyPayload(): array
    {
        $validated = $this->validated();

        return [
            'lmp' => $validated['lmp'] ?? '',
            'gravida' => $validated['gravida'] ?? '',
            'parity' => $validated['parity'] ?? '',
            'edd' => $validated['edd'] ?? '',
            'weight' => $validated['weight'] ?? '',
            'height' => $validated['height'] ?? '',
            'blood_pressure' => $validated['blood_pressure'] ?? '',
        ];
    }
}
