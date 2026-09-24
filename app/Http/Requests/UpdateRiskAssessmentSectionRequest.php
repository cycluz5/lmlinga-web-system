<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesRiskAssessmentFields;
use App\Support\DemoRiskAssessment;
use App\Support\HealthMemberIdentity;
use App\Support\RiskAssessmentErdMode;
use App\Support\RiskAssessmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRiskAssessmentSectionRequest extends FormRequest
{
    use ValidatesRiskAssessmentFields;

    public function authorize(): bool
    {
        $section = DemoRiskAssessment::normalizeSection((string) $this->route('section', ''));
        if ($section === null) {
            return false;
        }

        if (RiskAssessmentErdMode::isActive() && ! RiskAssessmentErdMode::sectionSupported($section)) {
            return false;
        }

        $hh = (string) $this->route('householdNo', '');
        $mb = (string) $this->route('memberId', '');
        $id = strtoupper(trim((string) $this->route('assessmentId', '')));

        $ctx = app(HealthMemberIdentity::class)->resolve($hh, $mb);
        if ($ctx['member'] === null) {
            return false;
        }

        if ($ctx['source'] !== 'db' || $ctx['resident'] === null) {
            return false;
        }

        return app(RiskAssessmentService::class)
            ->findPresentationForResident($ctx['resident'], $id) !== null;
    }

    protected function prepareForValidation(): void
    {
        $section = DemoRiskAssessment::normalizeSection((string) $this->route('section', ''));

        $merge = [];

        if (in_array($section, [
            DemoRiskAssessment::SECTION_RED_FLAGS,
            DemoRiskAssessment::SECTION_PAST_MEDICAL,
            DemoRiskAssessment::SECTION_FAMILY_HISTORY,
        ], true)) {
            $group = match ($section) {
                DemoRiskAssessment::SECTION_RED_FLAGS => 'red_flags',
                DemoRiskAssessment::SECTION_PAST_MEDICAL => 'past_medical',
                default => 'family_history',
            };
            $raw = $this->input($group, []);
            $list = is_array($raw) ? $raw : [];
            $merge[$group] = DemoRiskAssessment::applyNoneExclusive(
                array_map(static fn (mixed $v): string => trim((string) $v), $list)
            );
        }

        if ($section === DemoRiskAssessment::SECTION_LIFESTYLE) {
            $merge['tobacco'] = $this->lifestyleScalarInput($this->input('tobacco', ''));
            $merge['alcohol'] = $this->lifestyleScalarInput($this->input('alcohol', ''));
            $merge['physical_activity'] = $this->lifestyleScalarInput($this->input('physical_activity', ''));
            $merge['dietary'] = $this->lifestyleScalarInput($this->input('dietary', ''));
        }

        if ($section === DemoRiskAssessment::SECTION_PHYSICAL) {
            if (! RiskAssessmentErdMode::isActive()) {
                $merge['visual_no_screening'] = $this->boolean('visual_no_screening');
                $merge['visual_blurred'] = $this->boolean('visual_blurred');
            }

            foreach ([
                'height_cm', 'weight_kg', 'bmi', 'waist_cm',
                'systolic', 'diastolic', 'bp_status', 'visual_blurred_note',
            ] as $field) {
                if (RiskAssessmentErdMode::isActive() && in_array($field, ['bmi', 'bp_status', 'visual_blurred_note'], true)) {
                    continue;
                }
                if (RiskAssessmentErdMode::isActive() && in_array($field, ['visual_no_screening', 'visual_blurred'], true)) {
                    continue;
                }
                $merge[$field] = trim((string) $this->input($field, ''));
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $section = DemoRiskAssessment::normalizeSection((string) $this->route('section', ''));

        if (RiskAssessmentErdMode::isActive() && $section !== null && ! RiskAssessmentErdMode::sectionSupported($section)) {
            return [
                'section' => ['prohibited'],
            ];
        }

        $base = match ($section) {
            DemoRiskAssessment::SECTION_RED_FLAGS => $this->riskAssessmentHistoryGroupRules('red_flags'),
            DemoRiskAssessment::SECTION_PAST_MEDICAL => $this->riskAssessmentHistoryGroupRules('past_medical'),
            DemoRiskAssessment::SECTION_FAMILY_HISTORY => $this->riskAssessmentHistoryGroupRules('family_history'),
            DemoRiskAssessment::SECTION_LIFESTYLE => $this->riskAssessmentLifestyleFieldRules(),
            DemoRiskAssessment::SECTION_PHYSICAL => $this->riskAssessmentPhysicalFieldRules(),
            default => [],
        };

        return array_merge($base, $this->riskAssessmentIdentityProhibitedRules());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $section = DemoRiskAssessment::normalizeSection((string) $this->route('section', ''));
            if ($section === null) {
                $validator->errors()->add('section', 'Unknown risk assessment section.');
            }

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
    public function sectionPayload(): array
    {
        $section = DemoRiskAssessment::normalizeSection((string) $this->route('section', ''));
        $validated = $this->validated();

        return match ($section) {
            DemoRiskAssessment::SECTION_RED_FLAGS => [
                'red_flags' => $validated['red_flags'] ?? [],
            ],
            DemoRiskAssessment::SECTION_PAST_MEDICAL => [
                'past_medical' => $validated['past_medical'] ?? [],
            ],
            DemoRiskAssessment::SECTION_FAMILY_HISTORY => [
                'family_history' => $validated['family_history'] ?? [],
            ],
            DemoRiskAssessment::SECTION_LIFESTYLE => [
                'tobacco' => $validated['tobacco'] ?? '',
                'alcohol' => $validated['alcohol'] ?? '',
                'dietary' => $this->riskAssessmentDietaryFromValidated($validated),
                'physical_activity' => $validated['physical_activity'] ?? '',
            ],
            DemoRiskAssessment::SECTION_PHYSICAL => RiskAssessmentErdMode::isActive()
                ? [
                    'height_cm' => $validated['height_cm'] ?? '',
                    'weight_kg' => $validated['weight_kg'] ?? '',
                    'waist_cm' => $validated['waist_cm'] ?? '',
                    'systolic' => $validated['systolic'] ?? '',
                    'diastolic' => $validated['diastolic'] ?? '',
                ]
                : [
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
                ],
            default => [],
        };
    }
}
