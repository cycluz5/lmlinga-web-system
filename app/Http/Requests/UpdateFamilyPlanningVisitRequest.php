<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesFamilyPlanningVisitFields;
use App\Support\FamilyPlanningVisitService;
use App\Support\HealthMemberIdentity;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFamilyPlanningVisitRequest extends FormRequest
{
    use ValidatesFamilyPlanningVisitFields;

    public function authorize(): bool
    {
        $householdNo = (string) $this->route('householdNo');
        $memberId = (string) $this->route('memberId');
        $visitId = (string) $this->route('visitId');

        $ctx = app(HealthMemberIdentity::class)->resolve($householdNo, $memberId);

        if (! \App\Support\FamilyPlanningEligibility::allowsMemberContext($ctx)) {
            return false;
        }

        if ($ctx['source'] === 'db' && $ctx['resident'] !== null) {
            return app(FamilyPlanningVisitService::class)
                ->findPresentationForResident($ctx['resident'], $visitId) !== null;
        }

        // Demo-only members have no write path; deny update authorization.
        return false;
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
