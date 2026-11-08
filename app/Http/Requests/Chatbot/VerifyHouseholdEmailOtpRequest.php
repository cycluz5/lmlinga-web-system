<?php

namespace App\Http\Requests\Chatbot;

use Illuminate\Foundation\Http\FormRequest;

class VerifyHouseholdEmailOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'otp.required' => 'Please enter the 6-digit verification code.',
            'otp.regex' => 'Please enter the complete 6-digit verification code.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $otp = $this->input('otp');

        if (is_string($otp) || is_numeric($otp)) {
            $digits = preg_replace('/\D+/', '', (string) $otp) ?? '';
            $this->merge(['otp' => $digits]);
        }
    }
}
