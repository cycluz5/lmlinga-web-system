<?php

namespace App\Http\Requests;

use App\Support\DemoMaternalCare;
use App\Support\HealthMemberIdentity;
use App\Support\MaternalCareEligibility;
use App\Support\MaternalPregnancyService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaternalCareSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $householdNo = (string) $this->route('householdNo');
        $memberId = (string) $this->route('memberId');
        $section = strtolower(trim((string) $this->route('section')));

        if (! in_array($section, [
            'prenatal',
            'immunizations',
            'supplementations',
            'laboratory',
            'delivery',
            'postnatal',
            'trans-out',
        ], true)) {
            return false;
        }

        $ctx = app(HealthMemberIdentity::class)->resolve($householdNo, $memberId);
        if ($ctx['member'] === null) {
            return false;
        }

        if (! MaternalCareEligibility::allowsMemberContext($ctx)) {
            return false;
        }

        if (! MaternalCareEligibility::allowsWorkflowMemberContext($ctx)) {
            return false;
        }

        if ($ctx['source'] === 'db' && $ctx['resident'] !== null) {
            return app(MaternalPregnancyService::class)
                ->hasWritableSectionForResident($ctx['resident'], $section);
        }

        // Demo-only members keep session write path (no DB mutation).
        return $ctx['source'] === 'demo';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $section = strtolower(trim((string) $this->route('section')));

        $ownership = [
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
            'postpartum_vitamin_a_id' => ['prohibited'],
            'timbang_id' => ['prohibited'],
            'status' => ['prohibited'],
            'registered_at' => ['prohibited'],
        ];

        return array_merge($ownership, match ($section) {
            'prenatal' => $this->prenatalRules(),
            'immunizations' => $this->immunizationRules(),
            'supplementations' => $this->supplementationRules(),
            'laboratory' => $this->laboratoryRules(),
            'delivery' => $this->deliveryRules(),
            'postnatal' => $this->postnatalRules(),
            'trans-out' => $this->transOutRules(),
            default => [],
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'visits.*.date.before_or_equal' => 'Visit date cannot be a future date.',
            'td1.before_or_equal' => 'Immunization date cannot be a future date.',
            'td2.before_or_equal' => 'Immunization date cannot be a future date.',
            'td3.before_or_equal' => 'Immunization date cannot be a future date.',
            'td4.before_or_equal' => 'Immunization date cannot be a future date.',
            'td5.before_or_equal' => 'Immunization date cannot be a future date.',
            'deworming_date.before_or_equal' => 'Deworming date cannot be a future date.',
            'ifa.*.date.before_or_equal' => 'Supplementation date cannot be a future date.',
            'mms.*.date.before_or_equal' => 'Supplementation date cannot be a future date.',
            'calcium.*.date.before_or_equal' => 'Supplementation date cannot be a future date.',
            'rusf.*.date.before_or_equal' => 'RUSF date cannot be a future date.',
            'hepatitis_b.date.before_or_equal' => 'Screening date cannot be a future date.',
            'cbc.date.before_or_equal' => 'Screening date cannot be a future date.',
            'gdm.date.before_or_equal' => 'Screening date cannot be a future date.',
            'urinalysis.date.before_or_equal' => 'Screening date cannot be a future date.',
            'ultrasound.date.before_or_equal' => 'Screening date cannot be a future date.',
            'syphilis.date.before_or_equal' => 'Screening date cannot be a future date.',
            'hiv.date.before_or_equal' => 'Screening date cannot be a future date.',
            'date_terminated.before_or_equal' => 'Date terminated cannot be a future date.',
            'datetime.before_or_equal' => 'Date and time of delivery cannot be in the future.',
            'vitamin_a.date.before_or_equal' => 'Vitamin A date cannot be a future date.',
            'contacts.*.before_or_equal' => 'Contact date cannot be a future date.',
            'supplementation.*.date.before_or_equal' => 'Supplementation date cannot be a future date.',
            'date_transferred_out.before_or_equal' => 'Date transferred out cannot be a future date.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sectionPayload(): array
    {
        return $this->validated();
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function prenatalRules(): array
    {
        $rules = [
            'visits' => ['nullable', 'array'],
        ];

        foreach (DemoMaternalCare::prenatalSchedule() as $trimester) {
            foreach ($trimester['visits'] as $visit) {
                $key = $visit['key'];
                $rules["visits.{$key}"] = ['nullable', 'array'];
                $rules["visits.{$key}.date"] = ['nullable', 'date', 'before_or_equal:today'];
                $rules["visits.{$key}.height"] = ['nullable', 'numeric', 'min:0'];
                $rules["visits.{$key}.weight"] = ['nullable', 'numeric', 'min:0'];
                // BMI is accepted for backward-compatible request shape only.
                // Persistence overwrites it from that visit's height + weight.
                $rules["visits.{$key}.bmi"] = ['nullable', 'numeric', 'min:0'];
                $rules["visits.{$key}.bp"] = ['nullable', 'string', 'max:32'];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function immunizationRules(): array
    {
        return [
            'td1' => ['nullable', 'date', 'before_or_equal:today'],
            'td2' => ['nullable', 'date', 'before_or_equal:today'],
            'td3' => ['nullable', 'date', 'before_or_equal:today'],
            'td4' => ['nullable', 'date', 'before_or_equal:today'],
            'td5' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function supplementationRules(): array
    {
        $rules = [
            'deworming_date' => ['nullable', 'date', 'before_or_equal:today'],
            'ifa' => ['nullable', 'array'],
            'mms' => ['nullable', 'array'],
            'calcium' => ['nullable', 'array'],
            'rusf' => ['nullable', 'array'],
            'rusf.*' => ['nullable', 'array'],
            'rusf.*.date' => ['nullable', 'date', 'before_or_equal:today'],
            'rusf.*.id' => ['nullable', 'integer', 'min:1'],
        ];

        foreach (['ifa', 'mms', 'calcium'] as $group) {
            $visits = DemoMaternalCare::supplementationSchedule()[$group]['visits'] ?? [];
            foreach ($visits as $visit) {
                $key = $visit['key'];
                $rules["{$group}.{$key}"] = ['nullable', 'array'];
                $rules["{$group}.{$key}.date"] = ['nullable', 'date', 'before_or_equal:today'];
                $rules["{$group}.{$key}.tablets"] = ['nullable', 'integer', 'min:0'];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function laboratoryRules(): array
    {
        $rules = [
            'hepatitis_b' => ['nullable', 'array'],
            'hepatitis_b.date' => ['nullable', 'date', 'before_or_equal:today'],
            'hepatitis_b.result' => ['nullable', 'string', Rule::in(array_merge([''], DemoMaternalCare::HEPATITIS_B_RESULTS))],
            'cbc' => ['nullable', 'array'],
            'cbc.date' => ['nullable', 'date', 'before_or_equal:today'],
            'cbc.result' => ['nullable', 'string', Rule::in(array_merge([''], DemoMaternalCare::CBC_RESULTS))],
            'gdm' => ['nullable', 'array'],
            'gdm.date' => ['nullable', 'date', 'before_or_equal:today'],
            'gdm.result' => ['nullable', 'string', Rule::in(array_merge([''], DemoMaternalCare::GDM_RESULTS))],
            'urinalysis' => ['nullable', 'array'],
            'urinalysis.date' => ['nullable', 'date', 'before_or_equal:today'],
            'ultrasound' => ['nullable', 'array'],
            'ultrasound.date' => ['nullable', 'date', 'before_or_equal:today'],
            'syphilis' => ['nullable', 'array'],
            'syphilis.date' => ['nullable', 'date', 'before_or_equal:today'],
            'syphilis.result' => ['nullable', 'string', Rule::in(array_merge([''], DemoMaternalCare::SYPHILIS_RESULTS))],
            'hiv' => ['nullable', 'array'],
            'hiv.date' => ['nullable', 'date', 'before_or_equal:today'],
            'hiv.result' => ['nullable', 'string', Rule::in(array_merge([''], DemoMaternalCare::HIV_RESULTS))],
            'cvc' => ['nullable', 'array'],
            'cvc.value' => ['nullable', 'numeric'],
            'gestational' => ['nullable', 'array'],
            'gestational.value' => ['nullable', 'numeric'],
        ];

        foreach ([
            'hepatitis_b' => 'hep_b_screening_id',
            'cbc' => 'cbc_screening_id',
            'gdm' => 'gdm_screening_id',
            'urinalysis' => 'urinalysis_screening_id',
            'ultrasound' => 'ultrasound_screening_id',
            'syphilis' => 'syphilis_screening_id',
            'hiv' => 'hiv_screening_id',
            'cvc' => 'cvc_screening_id',
            'gestational' => 'gestational_screening_id',
        ] as $key => $pk) {
            $rules[$pk] = ['prohibited'];
            $rules["{$key}.id"] = ['prohibited'];
            $rules["{$key}.{$pk}"] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function deliveryRules(): array
    {
        return [
            'outcome' => ['nullable', 'string', Rule::in(array_merge([''], array_keys(DemoMaternalCare::OUTCOMES)))],
            'newborn_sex' => ['nullable', 'string', Rule::in(array_merge([''], array_keys(DemoMaternalCare::NEWBORN_SEXES)))],
            'plurality' => ['nullable', 'string', Rule::in(array_merge([''], array_keys(DemoMaternalCare::PLURALITIES)))],
            'plurality_number' => ['nullable', 'integer'],
            'delivery_type' => ['nullable', 'string', Rule::in(array_merge([''], array_keys(DemoMaternalCare::DELIVERY_TYPES)))],
            'birth_weight' => ['nullable', 'numeric', 'min:0'],
            'delivery_status' => ['nullable', 'string', 'max:120'],
            'datetime' => ['nullable', 'date', 'before_or_equal:now'],
            'date_terminated' => ['nullable', 'date', 'before_or_equal:today'],
            'birth_attendant' => ['nullable', 'string', Rule::in(array_merge([''], array_keys(DemoMaternalCare::BIRTH_ATTENDANTS)))],
            'birth_attendant_other' => ['nullable', 'string', 'max:120'],
            'place' => ['nullable', 'string', Rule::in(array_merge([''], array_keys(DemoMaternalCare::PLACES_OF_DELIVERY)))],
            'facility_name' => ['nullable', 'string', 'max:160'],
            'bemonc_cemonc' => ['nullable', 'string', Rule::in(['', 'Yes', 'No'])],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function postnatalRules(): array
    {
        $rules = [
            'contacts' => ['nullable', 'array'],
            'supplementation' => ['nullable', 'array'],
            'vitamin_a' => ['nullable', 'array'],
            'vitamin_a.date' => ['nullable', 'date', 'before_or_equal:today'],
            'vitamin_a.id' => ['prohibited'],
            'vitamin_a.postpartum_vitamin_a_id' => ['prohibited'],
            'vitamin_a.maternal_care_id' => ['prohibited'],
        ];

        foreach (DemoMaternalCare::postnatalContacts() as $contact) {
            $rules['contacts.'.$contact['key']] = ['nullable', 'date', 'before_or_equal:today'];
        }

        foreach (DemoMaternalCare::postpartumSupplementationVisits() as $visit) {
            $key = $visit['key'];
            $rules["supplementation.{$key}"] = ['nullable', 'array'];
            $rules["supplementation.{$key}.date"] = ['nullable', 'date', 'before_or_equal:today'];
            $rules["supplementation.{$key}.tablets"] = ['nullable', 'integer', 'min:0'];
        }

        return $rules;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function transOutRules(): array
    {
        return [
            'to_facility' => ['nullable', 'string', 'max:160'],
            'occurred_at_stage' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:255'],
            'date_transferred_out' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Delivery UI posts free-text "status"; keep it out of the prohibited server status field.
        if (strtolower(trim((string) $this->route('section'))) === 'delivery'
            && $this->exists('status')
        ) {
            $this->merge([
                'delivery_status' => $this->input('status'),
            ]);
            $this->request->remove('status');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function sectionPayloadForMerge(): array
    {
        $payload = $this->validated();
        if (array_key_exists('delivery_status', $payload)) {
            $payload['status'] = $payload['delivery_status'];
            unset($payload['delivery_status']);
        }

        // Strip prohibited keys that may appear in validated() as absent.
        unset(
            $payload['resident_id'],
            $payload['household_id'],
            $payload['householdNo'],
            $payload['member_id'],
            $payload['memberId'],
            $payload['pregnancy_no'],
            $payload['pregnancy_number'],
            $payload['pregnancy_id'],
            $payload['maternal_care_id'],
            $payload['maternal_id'],
            $payload['prenatal_id'],
            $payload['prenatal_visit_id'],
            $payload['delivery_id'],
            $payload['delivery_outcome_id'],
            $payload['postpartum_vitamin_a_id'],
            $payload['timbang_id'],
            $payload['registered_at'],
        );

        return $payload;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $section = strtolower(trim((string) $this->route('section')));
            if ($section === 'supplementations') {
                $this->rejectDuplicateRusfDates($validator);

                return;
            }

            if ($section !== 'delivery') {
                return;
            }

            $plurality = trim((string) $this->input('plurality'));
            $pluralityNumber = trim((string) $this->input('plurality_number'));

            if ($plurality === 'Multiple' && $pluralityNumber === '') {
                $validator->errors()->add(
                    'plurality_number',
                    'Plurality number is required for a multiple birth.'
                );
            }
        });
    }

    private function rejectDuplicateRusfDates(Validator $validator): void
    {
        $items = $this->input('rusf');
        if (! is_array($items)) {
            return;
        }

        $seen = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $raw = trim((string) ($item['date'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $normalized = substr($raw, 0, 10);
            if (isset($seen[$normalized])) {
                $validator->errors()->add(
                    'rusf.'.$index.'.date',
                    'This RUSF date is already included.'
                );
                continue;
            }
            $seen[$normalized] = true;
        }
    }
}
