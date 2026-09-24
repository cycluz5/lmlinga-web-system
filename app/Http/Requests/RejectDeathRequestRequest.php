<?php

namespace App\Http\Requests;

use App\Support\UiRole;
use Illuminate\Foundation\Http\FormRequest;

class RejectDeathRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return UiRole::isAdmin();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'A rejection reason is required.',
        ];
    }
}
