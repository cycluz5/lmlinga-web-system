<?php

namespace App\Http\Requests;

use App\Support\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;

class ResetStaffPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge([
                'email' => strtolower(trim($email)),
            ]);
        }

        // Identity is the broker email + token pair. Ignore forged account ids.
        $this->request->remove('id');
        $this->request->remove('user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => StrongPassword::laravelRules(true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'This password reset link is invalid or has expired.',
            'email.required' => 'Your email is required.',
            'email.email' => 'Enter a valid email address.',
            'password.required' => 'New Password is required.',
            'password.confirmed' => 'Password and Confirm New Password must match.',
        ];
    }
}
