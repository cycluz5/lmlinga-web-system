<?php

namespace App\Http\Requests\Concerns;

use App\Support\DemoFamilyPlanning;
use App\Support\FamilyPlanningErdMode;
use Illuminate\Validation\Rule;

trait ValidatesFamilyPlanningVisitFields
{
    protected function prepareFamilyPlanningVisitFieldsForValidation(): void
    {
        $merge = [
            'remarks' => trim((string) $this->input('remarks', '')),
        ];

        if (FamilyPlanningErdMode::commoditiesSupported(true)) {
            $merge['commodities'] = $this->normalizeCommodityInput($this->input('commodities', []));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function familyPlanningVisitFieldRules(): array
    {
        $rules = [
            'visited_at' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string'],
            'resident_id' => ['prohibited'],
            'household_id' => ['prohibited'],
            'member_id' => ['prohibited'],
            'visit_no' => ['prohibited'],
            'visit_id' => ['prohibited'],
            'visitId' => ['prohibited'],
        ];

        if (! FamilyPlanningErdMode::commoditiesSupported(true)) {
            $rules['commodities'] = ['prohibited'];

            return $rules;
        }

        $rules['commodities'] = ['nullable', 'array'];
        $rules['commodities.*.name'] = [
            'nullable',
            'string',
            Rule::in(array_merge([''], DemoFamilyPlanning::commodityOptions())),
        ];
        $rules['commodities.*.quantity'] = ['nullable', 'integer', 'min:0'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function familyPlanningVisitFieldMessages(): array
    {
        return [
            'visited_at.before_or_equal' => 'Visit date cannot be a future date.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function familyPlanningVisitPayloadFromValidated(array $validated): array
    {
        return [
            'visited_at' => $validated['visited_at'],
            'remarks' => $validated['remarks'] ?? '',
            'commodities' => FamilyPlanningErdMode::commoditiesSupported(true)
                ? (is_array($validated['commodities'] ?? null) ? $validated['commodities'] : [])
                : [],
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
            $name = trim((string) ($item['name'] ?? ''));
            $qtyRaw = $item['quantity'] ?? null;
            $qty = is_string($qtyRaw) ? trim($qtyRaw) : $qtyRaw;

            if ($name === '' && ($qty === null || $qty === '')) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'quantity' => $qty === '' || $qty === null ? 0 : $qty,
            ];
        }

        return $rows;
    }
}
