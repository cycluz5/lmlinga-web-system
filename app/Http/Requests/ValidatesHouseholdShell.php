<?php

namespace App\Http\Requests;

use App\Support\DatabaseSchemaGuard;
use App\Support\HouseholdNumber;
use App\Support\HouseholdZoneResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

trait ValidatesHouseholdShell
{
    /**
     * @return list<string>
     */
    public static function zones(): array
    {
        return HouseholdZoneResolver::DISPLAY_ZONES;
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function householdShellRules(bool $includeHouseholdNo = false): array
    {
        $guard = app(DatabaseSchemaGuard::class);
        $streetColumnExists = $guard->columnExists('households', 'street');

        $rules = [
            'zone' => ['required', 'string', Rule::in(self::zones())],
            'street' => $streetColumnExists
                ? ['required', 'string', 'max:150']
                : ['nullable', 'string', 'max:150'],
            'date_registered' => ['required', 'date', 'date_format:Y-m-d', 'before_or_equal:today'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accomplished_by' => ['nullable', 'string', 'max:160'],
            'household_type' => ['nullable', 'string', 'max:50'],
            'id' => ['prohibited'],
            'household_id' => ['prohibited'],
        ];

        if ($includeHouseholdNo) {
            $rules['household_no'] = HouseholdNumber::createRules();
        }

        return $rules;
    }

    protected function prepareHouseholdShellForValidation(bool $allowHouseholdNo = false): void
    {
        foreach (['address', 'accomplished_by', 'street'] as $field) {
            $value = $this->input($field);
            if (is_string($value) && trim($value) === '') {
                $this->merge([$field => $field === 'street' ? '' : null]);
            }
        }

        // Only normalize blank → null when the key was actually submitted.
        // Absent keys must stay absent so update can preserve existing coordinates (R02-B).
        foreach (['latitude', 'longitude'] as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);
            if ($value === '' || $value === null) {
                $this->merge([$field => null]);
            }
        }

        // household_id / id are never writable. household_no is create-only.
        $this->request->remove('id');
        $this->request->remove('household_id');

        if ($allowHouseholdNo) {
            $this->merge([
                'household_no' => HouseholdNumber::normalizeSubmitted($this->input('household_no')),
            ]);
        } else {
            $this->request->remove('household_no');
        }
    }

    protected function afterHouseholdNoAvailability(Validator $validator): void
    {
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
    }
}
