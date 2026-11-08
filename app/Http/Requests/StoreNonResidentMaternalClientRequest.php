<?php

namespace App\Http\Requests;

use App\Support\HealthRecordsNonResidentMaternal;
use Illuminate\Foundation\Http\FormRequest;

class StoreNonResidentMaternalClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Client-controlled classification and derived BMI are never trusted.
        $this->request->remove('sex');
        $this->request->remove('gender');
        $this->request->remove('bmi');
        $this->request->remove('sex_normalized');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $statuses = implode(',', HealthRecordsNonResidentMaternal::statusOptions());

        return [
            'first_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'birthday' => ['required', 'date', 'before:today'],
            'status' => ['required', 'string', 'in:'.$statuses],
            'complete_address' => ['required', 'string', 'max:500'],
            'lmp' => ['required', 'date'],
            'gravida' => ['required', 'integer', 'min:0', 'max:30'],
            'parity' => ['required', 'integer', 'min:0', 'max:30'],
            'edd' => ['nullable', 'date'],
            'weight' => ['required', 'numeric', 'min:1', 'max:400'],
            'height' => ['required', 'numeric', 'min:40', 'max:250'],
            'blood_pressure' => ['required', 'string', 'max:20', 'regex:/^\d{2,3}\s*\/\s*\d{2,3}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lmp' => 'last menstrual period',
            'edd' => 'expected date of delivery',
            'complete_address' => 'complete address',
            'blood_pressure' => 'blood pressure',
        ];
    }
}
