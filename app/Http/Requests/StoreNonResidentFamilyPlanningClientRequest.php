<?php

namespace App\Http\Requests;

use App\Support\HealthRecordsNonResidentFamilyPlanning;
use App\Support\NonResidentFamilyPlanningService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNonResidentFamilyPlanningClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => trim((string) $this->input('first_name', '')),
            'middle_name' => trim((string) $this->input('middle_name', '')),
            'last_name' => trim((string) $this->input('last_name', '')),
            'remarks' => trim((string) $this->input('remarks', '')),
            'address_zone' => trim((string) $this->input('address_zone', '')),
            'commodities' => $this->normalizeCommodityInput($this->input('commodities', [])),
        ]);
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
            'birthday' => ['required', 'date', 'before_or_equal:today'],
            'sex' => ['required', 'string', Rule::in(HealthRecordsNonResidentFamilyPlanning::sexOptions())],
            'civil_status' => ['required', 'string', Rule::in(HealthRecordsNonResidentFamilyPlanning::civilStatusOptions())],
            'address_zone' => ['nullable', 'string', 'max:255'],
            'visited_at' => ['required', 'date'],
            'method' => ['nullable', 'string', Rule::in(array_merge([''], HealthRecordsNonResidentFamilyPlanning::methodOptions()))],
            'remarks' => ['nullable', 'string'],
            'commodities' => ['nullable', 'array'],
            'commodities.*.name' => ['nullable', 'string', Rule::in(array_merge([''], NonResidentFamilyPlanningService::COMMODITY_ENUM))],
            'commodities.*.quantity' => ['nullable', 'integer', 'min:1'],
            'resident_id' => ['prohibited'],
            'household_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }

    /**
     * @return list<array{name: string, quantity: int|string|null}>
     */
    private function normalizeCommodityInput(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = NonResidentFamilyPlanningService::mapCommodityName((string) ($item['name'] ?? '')) ?? trim((string) ($item['name'] ?? ''));
            $qtyRaw = $item['quantity'] ?? null;
            $qty = is_string($qtyRaw) ? trim($qtyRaw) : $qtyRaw;

            if ($name === '' && ($qty === null || $qty === '')) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'quantity' => $qty === '' || $qty === null ? null : $qty,
            ];
        }

        return $rows;
    }
}
