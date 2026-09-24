<?php

namespace App\Http\Requests;

use App\Support\HealthRecordsDeworming;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDewormingRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'round' => ['required', 'string', Rule::in(HealthRecordsDeworming::roundOptions())],
            'se_status' => ['required', 'string', Rule::in(HealthRecordsDeworming::seStatusOptions())],
            'date_given' => ['required', 'date', 'date_format:Y-m-d', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            // resident_id must never be accepted from the client.
            'resident_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'year.required' => 'Year is required.',
            'year.integer' => 'Year must be a valid number.',
            'round.required' => 'Deworming round is required.',
            'round.in' => 'Deworming round selection is invalid.',
            'se_status.required' => 'SE Status is required.',
            'se_status.in' => 'SE Status selection is invalid.',
            'date_given.required' => 'Date given is required.',
            'date_given.date_format' => 'Date given must be a valid date.',
            'date_given.before_or_equal' => 'Date given cannot be a future date.',
            'resident_id.prohibited' => 'Resident identity cannot be supplied in the request.',
        ];
    }
}
