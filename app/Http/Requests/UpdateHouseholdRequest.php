<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateHouseholdRequest extends FormRequest
{
    use ValidatesHouseholdShell;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareHouseholdShellForValidation();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->householdShellRules();
    }

    /**
     * Batch 5: the edited household's current coordinates, so an unchanged location is not re-checked.
     *
     * @return array{0: float|null, 1: float|null}
     */
    protected function storedHouseholdCoordinates(): array
    {
        $householdNo = $this->route('householdNo');
        if (! is_string($householdNo) || $householdNo === '') {
            return [null, null];
        }

        $household = \App\Models\Household::query()->where('household_no', $householdNo)->first();
        if ($household === null || ! is_numeric($household->latitude) || ! is_numeric($household->longitude)) {
            return [null, null];
        }

        return [(float) $household->latitude, (float) $household->longitude];
    }

    /**
     * Coordinates are a pair on update: neither, both valid, or both null/blank.
     * Omitted != clear (R02-B).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasLatitude = $this->exists('latitude');
            $hasLongitude = $this->exists('longitude');

            if ($hasLatitude === $hasLongitude) {
                if (! $hasLatitude) {
                    return;
                }

                $latitudeEmpty = $this->coordinateValueIsEmpty($this->input('latitude'));
                $longitudeEmpty = $this->coordinateValueIsEmpty($this->input('longitude'));

                if ($latitudeEmpty === $longitudeEmpty) {
                    return;
                }

                $message = 'Latitude and longitude must both be set or both be cleared.';
                $validator->errors()->add('latitude', $message);
                $validator->errors()->add('longitude', $message);

                return;
            }

            $message = 'Latitude and longitude must be provided together.';
            if ($hasLatitude) {
                $validator->errors()->add('longitude', $message);
            } else {
                $validator->errors()->add('latitude', $message);
            }
        });
    }

    private function coordinateValueIsEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
