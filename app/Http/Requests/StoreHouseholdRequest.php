<?php

namespace App\Http\Requests;

use App\Support\HouseholdNumber;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreHouseholdRequest extends FormRequest
{
    use ValidatesHouseholdShell;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareHouseholdShellForValidation(allowHouseholdNo: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->householdShellRules(includeHouseholdNo: true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return HouseholdNumber::validationMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->afterHouseholdNoAvailability($validator);
        });
    }
}
