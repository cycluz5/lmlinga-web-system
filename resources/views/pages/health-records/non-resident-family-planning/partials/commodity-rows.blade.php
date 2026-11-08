@php
    $commodityOptions = $commodityOptions ?? [];
    $commodities = $commodities ?? [['name' => '', 'quantity' => '']];
    $idPrefix = $idPrefix ?? 'lml-hr-fp-nr';
@endphp

<div class="lml-hr-fp-nr__commodity-list" data-hr-fp-nr-commodity-list>
    @foreach ($commodities as $index => $commodity)
        <div class="lml-hr-fp-nr__commodity-row" data-hr-fp-nr-commodity-row>
            <div class="lml-hr-fp-nr__field">
                <label for="{{ $idPrefix }}-commodity-{{ $index }}">Commodity</label>
                <select
                    id="{{ $idPrefix }}-commodity-{{ $index }}"
                    name="commodities[{{ $index }}][name]"
                    class="lml-hr-fp-nr__input lml-focus-ring"
                    data-hr-fp-nr-commodity-name
                >
                    <option value="">Select commodity</option>
                    @foreach ($commodityOptions as $option)
                        <option
                            value="{{ $option }}"
                            @selected(($commodity['name'] ?? '') === $option)
                        >
                            {{ $option }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="lml-hr-fp-nr__field lml-hr-fp-nr__field--qty">
                <label for="{{ $idPrefix }}-qty-{{ $index }}">Quantity</label>
                <input
                    id="{{ $idPrefix }}-qty-{{ $index }}"
                    type="number"
                    name="commodities[{{ $index }}][quantity]"
                    class="lml-hr-fp-nr__input lml-focus-ring"
                    min="0"
                    step="1"
                    value="{{ $commodity['quantity'] !== '' && $commodity['quantity'] !== null ? $commodity['quantity'] : '' }}"
                    data-hr-fp-nr-commodity-qty
                    inputmode="numeric"
                >
            </div>
            <button
                type="button"
                class="lml-hr-fp-nr__commodity-remove lml-focus-ring"
                data-hr-fp-nr-commodity-remove
                aria-label="Remove commodity row"
                @if ($index === 0 && count($commodities) === 1) hidden @endif
            >
                <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
        </div>
    @endforeach
</div>

<button
    type="button"
    class="lml-hr-fp-nr__add-commodity lml-focus-ring"
    data-hr-fp-nr-commodity-add
>
    <i class="bi bi-plus-lg" aria-hidden="true"></i>
    <span>Add Another Commodity</span>
</button>

<template data-hr-fp-nr-commodity-template>
    <div class="lml-hr-fp-nr__commodity-row" data-hr-fp-nr-commodity-row>
        <div class="lml-hr-fp-nr__field">
            <label>Commodity</label>
            <select
                name="commodities[__INDEX__][name]"
                class="lml-hr-fp-nr__input lml-focus-ring"
                data-hr-fp-nr-commodity-name
            >
                <option value="">Select commodity</option>
                @foreach ($commodityOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <div class="lml-hr-fp-nr__field lml-hr-fp-nr__field--qty">
            <label>Quantity</label>
            <input
                type="number"
                name="commodities[__INDEX__][quantity]"
                class="lml-hr-fp-nr__input lml-focus-ring"
                min="0"
                step="1"
                value=""
                data-hr-fp-nr-commodity-qty
                inputmode="numeric"
            >
        </div>
        <button
            type="button"
            class="lml-hr-fp-nr__commodity-remove lml-focus-ring"
            data-hr-fp-nr-commodity-remove
            aria-label="Remove commodity row"
        >
            <i class="bi bi-trash" aria-hidden="true"></i>
        </button>
    </div>
</template>
