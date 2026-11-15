<?php

namespace App\Http\Requests;

use App\Models\Household;
use App\Rules\WithinBarangayBoundary;
use App\Support\DemoCatalog;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DB18-C — persist coordinates for an existing active household.
 * household_no is a lookup key only; client surrogate ids are ignored.
 */
class UpdateSpotMappingCoordinatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merged = [
            'household_no' => DemoCatalog::normalizeHouseholdNo(
                trim((string) $this->input('household_no', ''))
            ),
            'consent' => filter_var($this->input('consent'), FILTER_VALIDATE_BOOLEAN)
                ? '1'
                : $this->input('consent'),
        ];

        if ($this->exists('confirm_replot')) {
            $merged['confirm_replot'] = filter_var(
                $this->input('confirm_replot'),
                FILTER_VALIDATE_BOOLEAN
            );
        }

        $this->merge($merged);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        [$storedLat, $storedLng] = $this->storedCoordinates();

        return [
            'household_no' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            // Batch 5: a new or changed location must be inside the La Medalla boundary; re-saving the
            // household's current location unchanged is still allowed.
            'lat' => ['required', 'numeric', 'between:-90,90', new WithinBarangayBoundary('lng', $storedLat, $storedLng)],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            // Workflow confirmation only — never persisted.
            'consent' => ['required', 'accepted'],
            // Future intentional replot only; missing/false blocks replacing plotted coords.
            'confirm_replot' => ['sometimes', 'boolean'],
            // Client surrogate ids are ignored; household_no is the only lookup key.
            'id' => ['prohibited'],
            'household_id' => ['prohibited'],
        ];
    }

    /**
     * The household's currently stored coordinates (lookup only; never trusted for identity).
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function storedCoordinates(): array
    {
        $householdNo = (string) $this->input('household_no', '');
        if ($householdNo === '') {
            return [null, null];
        }

        $household = Household::query()->where('household_no', $householdNo)->first();
        if ($household === null || ! is_numeric($household->latitude) || ! is_numeric($household->longitude)) {
            return [null, null];
        }

        return [(float) $household->latitude, (float) $household->longitude];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'household_no.required' => 'Household number is required.',
            'lat.required' => 'Plot coordinates are required.',
            'lng.required' => 'Plot coordinates are required.',
            'lat.between' => 'Latitude must be between -90 and 90.',
            'lng.between' => 'Longitude must be between -180 and 180.',
            'consent.accepted' => 'Consent from the head of household is required before plotting.',
        ];
    }
}
