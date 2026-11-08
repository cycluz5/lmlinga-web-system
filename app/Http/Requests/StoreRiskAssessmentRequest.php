<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesRiskAssessmentFields;
use App\Support\DemoRiskAssessment;
use App\Support\RiskAssessmentErdMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRiskAssessmentRequest extends FormRequest
{
    use ValidatesRiskAssessmentFields;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [
            'tobacco' => $this->lifestyleScalarInput($this->input('tobacco', '')),
            'alcohol' => $this->lifestyleScalarInput($this->input('alcohol', '')),
            'physical_activity' => $this->lifestyleScalarInput($this->input('physical_activity', '')),
            'dietary' => $this->lifestyleScalarInput($this->input('dietary', '')),
        ];

        foreach (['red_flags', 'past_medical', 'family_history'] as $group) {
            $raw = $this->input($group, []);
            $list = is_array($raw) ? $raw : [];
            $merge[$group] = DemoRiskAssessment::applyNoneExclusive(
                array_map(static fn (mixed $v): string => trim((string) $v), $list)
            );
        }

        if (RiskAssessmentErdMode::isActive()) {
            $this->prepareRiskAssessmentDietaryForValidation();
        } else {
            $merge['visual_no_screening'] = $this->boolean('visual_no_screening');
            $merge['visual_blurred'] = $this->boolean('visual_blurred');
            foreach ([
                'height_cm', 'weight_kg', 'bmi', 'waist_cm',
                'systolic', 'diastolic', 'bp_status', 'visual_blurred_note',
            ] as $field) {
                $merge[$field] = trim((string) $this->input($field, ''));
            }
        }

        if (! RiskAssessmentErdMode::isActive()) {
            // Legacy fields already merged above.
        } else {
            foreach ([
                'height_cm', 'weight_kg', 'waist_cm', 'systolic', 'diastolic',
            ] as $field) {
                $merge[$field] = trim((string) $this->input($field, ''));
            }
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $base = array_merge([
            'tobacco' => ['nullable', 'string', Rule::in(array_merge([''], DemoRiskAssessment::allowedKeysForGroup('tobacco')))],
            'alcohol' => ['nullable', 'string', Rule::in(array_merge([''], DemoRiskAssessment::allowedKeysForGroup('alcohol')))],
            'physical_activity' => ['nullable', 'string', Rule::in(array_merge([''], DemoRiskAssessment::allowedKeysForGroup('physical_activity')))],
            'dietary' => ['nullable', 'string', Rule::in(array_merge([''], DemoRiskAssessment::allowedKeysForGroup('dietary')))],
            'dietary.*' => ['prohibited'],
            'height_cm' => ['nullable', 'string', 'max:32'],
            'weight_kg' => ['nullable', 'string', 'max:32'],
            'waist_cm' => ['nullable', 'string', 'max:32'],
            'systolic' => ['nullable', 'string', 'max:32'],
            'diastolic' => ['nullable', 'string', 'max:32'],
        ], $this->riskAssessmentIdentityProhibitedRules(), $this->riskAssessmentHistoryGroupRules('red_flags'), $this->riskAssessmentHistoryGroupRules('past_medical'), $this->riskAssessmentHistoryGroupRules('family_history'));

        if (RiskAssessmentErdMode::isActive()) {
            return array_merge(
                $base,
                $this->riskAssessmentErdUnsupportedFieldRules()
            );
        }

        return array_merge($base, [
            'bmi' => ['nullable', 'string', 'max:32'],
            'bp_status' => ['nullable', 'string', 'max:64'],
            'bmi_status' => ['prohibited'],
            'visual_no_screening' => ['sometimes', 'boolean'],
            'visual_blurred' => ['sometimes', 'boolean'],
            'visual_blurred_note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['red_flags', 'past_medical', 'family_history'] as $group) {
                $selected = $this->input($group);
                if (! is_array($selected)) {
                    continue;
                }
                if (in_array('none', $selected, true) && count($selected) > 1) {
                    $validator->errors()->add(
                        $group,
                        'None cannot be combined with other conditions.'
                    );
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function assessmentPayload(): array
    {
        $validated = $this->validated();

        if (RiskAssessmentErdMode::isActive()) {
            return [
                'red_flags' => $validated['red_flags'] ?? [],
                'past_medical' => $validated['past_medical'] ?? [],
                'family_history' => $validated['family_history'] ?? [],
                'tobacco' => $validated['tobacco'] ?? '',
                'alcohol' => $validated['alcohol'] ?? '',
                'dietary' => $validated['dietary'] ?? '',
                'physical_activity' => $validated['physical_activity'] ?? '',
                'height_cm' => $validated['height_cm'] ?? '',
                'weight_kg' => $validated['weight_kg'] ?? '',
                'waist_cm' => $validated['waist_cm'] ?? '',
                'systolic' => $validated['systolic'] ?? '',
                'diastolic' => $validated['diastolic'] ?? '',
            ];
        }

        return [
            'red_flags' => $validated['red_flags'] ?? [],
            'past_medical' => $validated['past_medical'] ?? [],
            'family_history' => $validated['family_history'] ?? [],
            'tobacco' => $validated['tobacco'] ?? '',
            'alcohol' => $validated['alcohol'] ?? '',
            'dietary' => $validated['dietary'] ?? '',
            'physical_activity' => $validated['physical_activity'] ?? '',
            'height_cm' => $validated['height_cm'] ?? '',
            'weight_kg' => $validated['weight_kg'] ?? '',
            'bmi' => $validated['bmi'] ?? '',
            'waist_cm' => $validated['waist_cm'] ?? '',
            'systolic' => $validated['systolic'] ?? '',
            'diastolic' => $validated['diastolic'] ?? '',
            'bp_status' => $validated['bp_status'] ?? '',
            'visual_no_screening' => (bool) ($validated['visual_no_screening'] ?? false),
            'visual_blurred' => (bool) ($validated['visual_blurred'] ?? false),
            'visual_blurred_note' => $validated['visual_blurred_note'] ?? '',
        ];
    }
}
