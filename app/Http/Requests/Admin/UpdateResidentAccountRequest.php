<?php

namespace App\Http\Requests\Admin;

use App\Support\DemoResidentAccounts;
use App\Support\ResidentAccountUiCatalog;
use App\Support\UserManagementErdMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateResidentAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $accountId = ResidentAccountUiCatalog::parsePublicId((string) $this->route('id'));
        $keyColumn = UserManagementErdMode::residentAccountKeyName();

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'zone' => ['required', 'string', 'in:'.implode(',', DemoResidentAccounts::ALLOWED_ZONES)],
            'email' => [
                'required',
                'email',
                'max:150',
                Rule::unique('resident_accounts', 'email')->ignore($accountId, $keyColumn),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function safeAccountAttributes(): array
    {
        $validated = $this->validated();

        return [
            'first_name' => trim((string) $validated['first_name']),
            'middle_name' => filled($validated['middle_name'] ?? null)
                ? trim((string) $validated['middle_name'])
                : '',
            'last_name' => trim((string) $validated['last_name']),
            'zone' => trim((string) $validated['zone']),
            'email' => strtolower(trim((string) $validated['email'])),
        ];
    }
}
