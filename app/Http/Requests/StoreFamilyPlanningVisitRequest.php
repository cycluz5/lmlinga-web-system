<?php

namespace App\Http\Requests;

use App\Support\FamilyPlanningEligibility;
use App\Support\HealthMemberIdentity;
use App\Http\Requests\Concerns\ValidatesFamilyPlanningVisitFields;
use Illuminate\Foundation\Http\FormRequest;

class StoreFamilyPlanningVisitRequest extends FormRequest
{
    use ValidatesFamilyPlanningVisitFields;

    public function authorize(): bool
    {
        $householdNo = (string) $this->route('householdNo');
        $memberId = (string) $this->route('memberId');
        $ctx = app(HealthMemberIdentity::class)->resolve($householdNo, $memberId);

        return FamilyPlanningEligibility::allowsMemberContext($ctx);
    }

    protected function prepareForValidation(): void
    {
        $this->prepareFamilyPlanningVisitFieldsForValidation();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->familyPlanningVisitFieldRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->familyPlanningVisitFieldMessages();
    }

    /**
     * @return array<string, mixed>
     */
    public function visitPayload(): array
    {
        return $this->familyPlanningVisitPayloadFromValidated($this->validated());
    }
}
