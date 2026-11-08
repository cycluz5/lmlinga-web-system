<?php

namespace App\Http\Requests;

use App\Support\DemoHouseholdWaterSupply;
use App\Support\HouseholdNumber;
use App\Support\HouseholdZoneResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Plot New Household — create household shell + household-head resident + coordinates.
 *
 * household_no is staff-entered (exactly 3 digits). household_id / house_head are not persistence authority.
 * house_head, if present, is ignored; first_name / middle_name / last_name are stored separately.
 */
class StoreSpotMappingHouseholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $zone = $this->input('zone');
        if (is_numeric($zone)) {
            $zone = 'Zone '.(int) $zone;
        } elseif (is_string($zone)) {
            $zone = trim($zone);
            $number = HouseholdZoneResolver::zoneNumberFromLabel($zone)
                ?? HouseholdZoneResolver::zoneNumberFromStoredValue($zone);
            if ($number !== null) {
                $zone = 'Zone '.$number;
            }
        }

        $dateRegistered = trim((string) $this->input('date_registered', ''));
        if ($dateRegistered === '') {
            $dateRegistered = now()->toDateString();
        }

        $payload = [
            'first_name' => trim((string) $this->input('first_name', '')),
            'middle_name' => trim((string) $this->input('middle_name', '')) ?: null,
            'last_name' => trim((string) $this->input('last_name', '')),
            'birthday' => trim((string) $this->input('birthday', '')),
            'sex' => trim((string) $this->input('sex', '')),
            'civil_status' => trim((string) $this->input('civil_status', '')),
            'zone' => $zone,
            'date_registered' => $dateRegistered,
            'client_marker_id' => trim((string) $this->input('client_marker_id', $this->input('plot_id', ''))),
            'consent' => filter_var($this->input('consent'), FILTER_VALIDATE_BOOLEAN) ? '1' : $this->input('consent'),
            'household_no' => HouseholdNumber::normalizeSubmitted($this->input('household_no')),
        ];

        $canonicalType = DemoHouseholdWaterSupply::normalizeCanonicalHouseholdType(
            $this->input('household_type')
        );
        if ($canonicalType !== null) {
            $payload['household_type'] = $canonicalType;
        }

        $this->merge($payload);

        $this->request->remove('id');
        $this->request->remove('household_id');
        $this->request->remove('member_no');
        $this->request->remove('resident_id');
        $this->request->remove('house_head');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birthday' => ['required', 'date', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sex' => ['required', 'string', Rule::in(['Male', 'Female'])],
            'civil_status' => ['required', 'string', Rule::in(['Single', 'Married', 'Widowed', 'Separated', 'Live-In'])],
            'zone' => ['required', 'string', Rule::in(HouseholdZoneResolver::DISPLAY_ZONES)],
            'household_type' => ['required', Rule::in([
                DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
                DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NON_NHTS,
            ])],
            'date_registered' => ['required', 'date', 'date_format:Y-m-d', 'before_or_equal:today'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'consent' => ['required', 'accepted'],
            'client_marker_id' => ['nullable', 'string', 'max:128'],
            'household_no' => HouseholdNumber::createRules(),
            'id' => ['prohibited'],
            'household_id' => ['prohibited'],
            'member_no' => ['prohibited'],
            'street' => ['prohibited'],
            'address' => ['prohibited'],
            'accomplished_by' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function householdAttributes(): array
    {
        $validated = $this->validated();

        return [
            'zone' => (string) $validated['zone'],
            'date_registered' => (string) $validated['date_registered'],
            'latitude' => $validated['lat'],
            'longitude' => $validated['lng'],
            'household_type' => (string) $validated['household_type'],
            'household_no' => (string) $validated['household_no'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function headAttributes(): array
    {
        $validated = $this->validated();

        return [
            'first_name' => (string) $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => (string) $validated['last_name'],
            'birthday' => (string) $validated['birthday'],
            'sex' => (string) $validated['sex'],
            'civil_status' => (string) $validated['civil_status'],
            'relation' => 'Head',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'First name is required.',
            'last_name.required' => 'Last name is required.',
            'birthday.required' => 'Birthday is required.',
            'birthday.before_or_equal' => 'Birthday must not be in the future.',
            'sex.required' => 'Sex is required.',
            'sex.in' => 'Sex must be Male or Female.',
            'civil_status.required' => 'Civil status is required.',
            'civil_status.in' => 'Civil status must be Single, Married, Widowed, Separated, or Live-In.',
            'household_type.required' => 'Household type is required.',
            'zone.required' => 'Zone is required.',
            'lat.required' => 'Plot coordinates are required.',
            'lng.required' => 'Plot coordinates are required.',
            'consent.accepted' => 'Consent from the head of household is required before plotting.',
        ] + HouseholdNumber::validationMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('household_no')) {
                return;
            }

            $value = HouseholdNumber::normalizeSubmitted($this->input('household_no'));
            if (preg_match(HouseholdNumber::DIGITS_PATTERN, $value) !== 1) {
                return;
            }

            if (HouseholdNumber::isOccupied($value)) {
                $validator->errors()->add('household_no', HouseholdNumber::DUPLICATE_MESSAGE);
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            throw new HttpResponseException(response()->json([
                'message' => $validator->errors()->first() ?: 'Unable to register this household.',
                'errors' => $validator->errors(),
            ], 422));
        }

        parent::failedValidation($validator);
    }
}
