<?php

namespace App\Http\Requests\Chatbot;

use App\Models\ResidentAccount;
use App\Support\HouseholdRecordRequestOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHouseholdRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('residentAccount') instanceof ResidentAccount;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'householdNo' => ['required', 'string', 'max:50'],
            'relationship' => ['required', 'string', Rule::in(HouseholdRecordRequestOptions::relationships())],
            'firstName' => ['required', 'string', 'max:100'],
            'middleName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'mobileNumber' => ['required', 'string', 'regex:/^09\d{9}$/'],
            'emailAddress' => ['required', 'email', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mobileNumber.regex' => 'Please enter a valid mobile number.',
        ];
    }
}
