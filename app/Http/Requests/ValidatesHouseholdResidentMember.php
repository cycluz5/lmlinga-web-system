<?php

namespace App\Http\Requests;

use App\Support\ResidentMemberFieldMap;
use Illuminate\Validation\Rule;

trait ValidatesHouseholdResidentMember
{
    /**
     * @return list<string>
     */
    public static function relations(): array
    {
        return [
            'Head',
            'Spouse',
            'Son',
            'Daughter',
            'Parent',
            'Sibling',
            'Grandchild',
            'Other Relative',
            'Non-Relative',
        ];
    }

    /**
     * @return list<string>
     */
    public static function occupations(): array
    {
        return [
            'None / N/A',
            'Farmer',
            'Fisherfolk',
            'Vendor',
            'Teacher',
            'Nurse',
            'Driver',
            'Construction Worker',
            'Government Employee',
            'Private Employee',
            'Self-employed',
            'Student',
            'Homemaker',
            'Unemployed',
            'Other',
        ];
    }

    /**
     * @return list<string>
     */
    public static function monthlyIncomes(): array
    {
        return [
            'None / N/A',
            'Below 5,000',
            '5,000 – 9,999',
            '10,000 – 19,999',
            '20,000 – 29,999',
            '30,000 – 49,999',
            '50,000 and above',
        ];
    }

    /**
     * @return list<string>
     */
    public static function religions(): array
    {
        return [
            'Roman Catholic',
            'Iglesia ni Cristo',
            'Protestant',
            'Islam',
            'Born Again',
            'Other',
            'None',
        ];
    }

    /**
     * @return list<string>
     */
    public static function educations(): array
    {
        return [
            'No Formal Education',
            'Elementary Level',
            'Elementary Graduate',
            'High School Level',
            'High School Graduate',
            'Vocational',
            'College Level',
            'College Graduate',
            'Post-Graduate',
            'Not Applicable',
        ];
    }

    /**
     * @return list<string>
     */
    public static function disabilityOptions(): array
    {
        return [
            'none',
            'Intellectual Disability (ID)',
            'Mental Disability (MD)',
            'Physical Disability (PD)',
            'others',
        ];
    }

    /**
     * @return list<string>
     */
    public static function medicalHistoryOptions(): array
    {
        return [
            'none',
            'Diabetes Mellitus',
            'Heart Disease',
            'Hypertension',
            'Kidney Disease',
            'Tuberculosis',
            'others',
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function memberRules(): array
    {
        return [
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'relation' => ['required', 'string', Rule::in(self::relations())],
            'birthday' => ['required', 'date', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sex' => ['required', 'string', Rule::in(['Male', 'Female'])],
            'relationship_status' => ['required', 'string', Rule::in(['Single', 'Married', 'Widowed', 'Separated', 'Live-in'])],
            'occupation' => ['required', 'string', Rule::in(self::occupations())],
            'occupation_other' => ['nullable', 'string', 'max:255'],
            'monthly_income' => ['required', 'string', Rule::in(self::monthlyIncomes())],
            'religion' => ['required', 'string', Rule::in(self::religions())],
            'religion_other' => ['nullable', 'string', 'max:255'],
            'education' => ['required', 'string', Rule::in(self::educations())],
            'fp_user' => ['required', 'string', Rule::in(['Yes', 'No', 'N/A'])],
            'philhealth' => ['nullable', 'regex:/^\d{12}$/'],
            'disability' => ['required', 'array', 'min:1'],
            'disability.*' => ['string', Rule::in(self::disabilityOptions())],
            'disability_others' => ['nullable', 'string', 'max:255'],
            'medical_history' => ['required', 'array', 'min:1'],
            'medical_history.*' => ['string', Rule::in(self::medicalHistoryOptions())],
            'medical_others' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareMemberForValidation(): void
    {
        $philhealth = $this->input('philhealth');
        if (is_string($philhealth)) {
            $trimmed = preg_replace('/\s+/', '', trim($philhealth)) ?? '';
            $this->merge([
                'philhealth' => $trimmed === '' ? null : $trimmed,
            ]);
        }

        foreach (['middle_name', 'disability_others', 'medical_others', 'occupation_other', 'religion_other'] as $field) {
            $value = $this->input($field);
            if (is_string($value) && trim($value) === '') {
                $this->merge([$field => null]);
            }
        }

        // Strip identity / forged fields — never writable from the browser.
        $this->request->remove('id');
        $this->request->remove('household_id');
        $this->request->remove('household_no');
        $this->request->remove('member_no');
        $this->request->remove('resident_id');
        $this->request->remove('age');
        $this->request->remove('householdHead');
        $this->request->remove('is_household_head');
    }

    /**
     * @param  \Illuminate\Validation\Validator  $validator
     */
    protected function withMemberValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $disability = $this->input('disability', []);
            $medical = $this->input('medical_history', []);

            if (is_array($disability)
                && in_array('none', $disability, true)
                && count(array_diff($disability, ['none'])) > 0
            ) {
                $validator->errors()->add(
                    'disability',
                    'None cannot be combined with other disability options.'
                );
            }

            if (is_array($medical)
                && in_array('none', $medical, true)
                && count(array_diff($medical, ['none'])) > 0
            ) {
                $validator->errors()->add(
                    'medical_history',
                    'None cannot be combined with other medical history options.'
                );
            }

            if (ResidentMemberFieldMap::isOtherChoice((string) $this->input('occupation'))
                && blank($this->input('occupation_other'))
            ) {
                $validator->errors()->add('occupation_other', 'Please specify the occupation.');
            }

            if (ResidentMemberFieldMap::isOtherChoice((string) $this->input('religion'))
                && blank($this->input('religion_other'))
            ) {
                $validator->errors()->add('religion_other', 'Please specify the religion.');
            }

            if (is_array($disability)
                && in_array('others', $disability, true)
                && blank($this->input('disability_others'))
            ) {
                $validator->errors()->add('disability_others', 'Please specify the disability type.');
            }

            if (is_array($medical)
                && in_array('others', $medical, true)
                && blank($this->input('medical_others'))
            ) {
                $validator->errors()->add('medical_others', 'Please specify the medical history condition.');
            }
        });
    }
}
