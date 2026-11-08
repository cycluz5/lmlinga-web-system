<?php

namespace App\Http\Requests\Concerns;

use App\Support\DemoRiskAssessment;
use App\Support\RiskAssessmentErdMode;
use Illuminate\Validation\Rule;

trait ValidatesRiskAssessmentFields
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function riskAssessmentErdUnsupportedFieldRules(): array
    {
        return [
            'visual_no_screening' => ['prohibited'],
            'visual_blurred' => ['prohibited'],
            'visual_blurred_note' => ['prohibited'],
            'bmi' => ['prohibited'],
            'bp_status' => ['prohibited'],
            'bmi_status' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function riskAssessmentIdentityProhibitedRules(): array
    {
        return [
            'resident_id' => ['prohibited'],
            'assessment_no' => ['prohibited'],
            'assessment_id' => ['prohibited'],
            'assessmentId' => ['prohibited'],
            'user_id' => ['prohibited'],
            'risk_assessment_id' => ['prohibited'],
            'timbang_id' => ['prohibited'],
            'bmi_status' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function riskAssessmentHistoryGroupRules(string $group): array
    {
        $allowed = RiskAssessmentErdMode::isActive()
            ? RiskAssessmentErdMode::erdAllowedUiKeysForGroup($group)
            : DemoRiskAssessment::allowedKeysForGroup($group);

        return [
            $group => ['nullable', 'array'],
            $group.'.*' => ['string', Rule::in($allowed)],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function riskAssessmentLifestyleFieldRules(): array
    {
        return [
            'tobacco' => ['nullable', 'string', Rule::in(array_merge([''], DemoRiskAssessment::allowedKeysForGroup('tobacco')))],
            'alcohol' => ['nullable', 'string', Rule::in(array_merge([''], DemoRiskAssessment::allowedKeysForGroup('alcohol')))],
            'physical_activity' => ['nullable', 'string', Rule::in(array_merge([''], DemoRiskAssessment::allowedKeysForGroup('physical_activity')))],
            'dietary' => ['nullable', 'string', Rule::in(array_merge([''], DemoRiskAssessment::allowedKeysForGroup('dietary')))],
            'dietary.*' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function riskAssessmentPhysicalFieldRules(): array
    {
        $rules = [
            'height_cm' => ['nullable', 'string', 'max:32'],
            'weight_kg' => ['nullable', 'string', 'max:32'],
            'waist_cm' => ['nullable', 'string', 'max:32'],
            'systolic' => ['nullable', 'string', 'max:32'],
            'diastolic' => ['nullable', 'string', 'max:32'],
        ];

        if (RiskAssessmentErdMode::isActive()) {
            return array_merge($rules, [
                'bmi' => ['prohibited'],
                'bp_status' => ['prohibited'],
                'bmi_status' => ['prohibited'],
                'visual_no_screening' => ['prohibited'],
                'visual_blurred' => ['prohibited'],
                'visual_blurred_note' => ['prohibited'],
            ]);
        }

        return array_merge($rules, [
            'bmi' => ['nullable', 'string', 'max:32'],
            'bp_status' => ['nullable', 'string', 'max:64'],
            'bmi_status' => ['prohibited'],
            'visual_no_screening' => ['sometimes', 'boolean'],
            'visual_blurred' => ['sometimes', 'boolean'],
            'visual_blurred_note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    protected function prepareRiskAssessmentDietaryForValidation(): void
    {
        $this->merge([
            'dietary' => $this->lifestyleScalarInput($this->input('dietary', '')),
        ]);
    }

    /**
     * Keep arrays intact so old multi-select payloads fail the string/in rules
     * instead of being silently converted.
     */
    protected function lifestyleScalarInput(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        return trim((string) ($value ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    protected function riskAssessmentDietaryFromValidated(array $validated): string
    {
        return (string) ($validated['dietary'] ?? '');
    }
}
