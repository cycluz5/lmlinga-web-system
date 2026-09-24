<?php

namespace App\Http\Requests\Admin;

use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\StrongPassword;
use App\Support\UserManagementErdMode;
use App\Support\WorkerAssignedZones;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

class UpdateHealthWorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('hw_end_appointment') === '') {
            $this->merge(['hw_end_appointment' => null]);
        }

        if ($this->input('hw_suffix') === '') {
            $this->merge(['hw_suffix' => null]);
        }

        if (! $this->exists('hw_remove_photo')) {
            $this->merge(['hw_remove_photo' => false]);
        }

        $zones = $this->input('hw_assigned_zone');
        if (is_string($zones) || is_int($zones) || is_float($zones)) {
            $this->merge([
                'hw_assigned_zone' => trim((string) $zones) === '' ? [] : [(string) $zones],
            ]);
        } elseif (is_array($zones)) {
            $this->merge([
                'hw_assigned_zone' => array_values($zones),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'sex' => ['required', 'string', Rule::in(['Male', 'Female'])],
            'hw_first_name' => ['required', 'string', 'max:100'],
            'hw_last_name' => ['required', 'string', 'max:100'],
            'hw_middle_name' => ['required', 'string', 'max:100'],
            'hw_suffix' => ['nullable', 'string', 'max:20'],
            'hw_dob' => ['required', 'date', 'before:today'],
            'hw_civil_status' => ['required', 'string', Rule::in(['Single', 'Married', 'Widowed', 'Separated', 'Annulled'])],
            'hw_nationality' => ['required', 'string', Rule::in(['Filipino', 'Other'])],
            'hw_mobile' => ['required', 'string', 'max:20'],
            'hw_email' => [
                'required',
                'email',
                'max:255',
                $this->uniqueStaffIdentifier('email', $userId),
            ],
            'hw_house_no' => ['required', 'string', 'max:20'],
            'hw_street' => ['required', 'string', 'max:150'],
            'hw_purok_zone' => ['required', 'string', Rule::in(['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5'])],
            'hw_barangay' => ['required', 'string', 'max:100'],
            'hw_municipality' => ['required', 'string', 'max:100'],
            'hw_province' => ['required', 'string', 'max:100'],
            'hw_zip' => ['required', 'string', 'max:10'],
            'hw_role' => ['required', 'string', Rule::in(['BHW', 'BNS', 'BSPO', 'Admin', ...StaffRole::ALL])],
            'hw_assigned_barangay' => ['required', 'string', 'max:100'],
            'hw_assigned_zone' => ['required', 'array', 'min:1'],
            'hw_assigned_zone.*' => ['required', 'string', 'distinct', Rule::in(WorkerAssignedZones::ALLOWED)],
            'hw_date_appointed' => ['required', 'date', 'before_or_equal:today'],
            'hw_end_appointment' => ['nullable', 'date'],
            'hw_username' => [
                'required',
                'string',
                'max:100',
                $this->uniqueStaffIdentifier('username', $userId),
            ],
            'hw_status' => ['required', 'string', Rule::in(StaffAccountStatus::ALL)],
            'hw_password' => ['nullable', 'string', 'confirmed'],
            'hw_password_confirmation' => ['nullable', 'string'],
            'hw_photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png', 'max:2048'],
            'hw_remove_photo' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('hw_password')) {
                $password = (string) $this->input('hw_password');
                if (! StrongPassword::meetsRequirements($password)) {
                    $validator->errors()->add('hw_password', StrongPassword::MESSAGE);
                }
            }

            $role = StaffRole::normalize($this->input('hw_role'));
            if ($role === null && $this->filled('hw_role')) {
                $validator->errors()->add('hw_role', 'Invalid staff role.');
            }

            $appointed = $this->input('hw_date_appointed');
            $ended = $this->input('hw_end_appointment');
            if (is_string($appointed) && is_string($ended) && $appointed !== '' && $ended !== '') {
                if (strcmp($ended, $appointed) < 0) {
                    $validator->errors()->add(
                        'hw_end_appointment',
                        'End of appointment must be on or after the date appointed.'
                    );
                }
            }
        });
    }

    private function uniqueStaffIdentifier(string $column, mixed $userId): Unique
    {
        $ignoreId = ctype_digit((string) $userId) ? (int) $userId : null;

        return Rule::unique(UserManagementErdMode::staffTableName(), $column)
            ->ignore($ignoreId, UserManagementErdMode::staffKeyName());
    }
}
