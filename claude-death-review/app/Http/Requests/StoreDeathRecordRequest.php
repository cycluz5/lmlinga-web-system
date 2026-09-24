<?php

namespace App\Http\Requests;

use App\Support\DeathCertificateStorage;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeathRecordRequest extends FormRequest
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
            'cause_of_death' => ['required', 'string', 'max:500'],
            'date_of_death' => ['required', 'date', 'date_format:Y-m-d', 'before_or_equal:today'],
            'registry_no' => ['required', 'string', 'max:100'],
            'certificate_no' => ['required', 'string', 'max:100'],
            'death_certificate' => [
                'required',
                'file',
                'max:'.DeathCertificateStorage::MAX_KILOBYTES,
                'mimes:png,jpg,jpeg,pdf',
                'mimetypes:image/png,image/jpeg,application/pdf',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cause_of_death.required' => 'Cause of death is required.',
            'date_of_death.required' => 'Date of death is required.',
            'date_of_death.date_format' => 'Date of death must be a valid date.',
            'date_of_death.before_or_equal' => 'Date of death cannot be in the future.',
            'registry_no.required' => 'Registry number is required.',
            'certificate_no.required' => 'Death certificate number is required.',
            'death_certificate.required' => 'Death certificate file is required.',
            'death_certificate.file' => 'Death certificate file is required.',
            'death_certificate.mimes' => 'Death certificate must be a PNG, JPG, or PDF file.',
            'death_certificate.mimetypes' => 'Death certificate must be a PNG, JPG, or PDF file.',
            'death_certificate.max' => 'Death certificate must be 5 MB or smaller.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cause_of_death' => 'cause of death',
            'date_of_death' => 'date of death',
            'registry_no' => 'registry number',
            'certificate_no' => 'death certificate number',
            'death_certificate' => 'death certificate file',
        ];
    }
}
