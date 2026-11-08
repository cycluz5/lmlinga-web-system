<?php

namespace App\Http\Requests;

use App\Support\NonResidentFamilyPlanningService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNonResidentFamilyPlanningVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'remarks' => trim((string) $this->input('remarks', '')),
            'commodities' => $this->normalizeCommodityInput($this->input('commodities', [])),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'visited_at' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
            'commodities' => ['nullable', 'array'],
            'commodities.*.name' => ['nullable', 'string', Rule::in(array_merge([''], NonResidentFamilyPlanningService::COMMODITY_ENUM))],
            'commodities.*.quantity' => ['nullable', 'integer', 'min:1'],
            'resident_id' => ['prohibited'],
            'household_id' => ['prohibited'],
            'fp_id' => ['prohibited'],
            'visit_id' => ['prohibited'],
            'visitId' => ['prohibited'],
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
