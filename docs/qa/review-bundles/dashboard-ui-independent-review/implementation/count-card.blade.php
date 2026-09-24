{{--
    Dashboard total-record card (UI-phase).
    variant: primary (top band) | compact (secondary module counts)
    Usage: <x-lml.dashboard.count-card :card="$card" variant="compact" />
--}}
@props([
    'card' => [],
    'variant' => 'primary',
])

@php
    $key = (string) ($card['key'] ?? 'count');
    $label = (string) ($card['label'] ?? 'Records');
    $value = (int) ($card['value'] ?? 0);
    $formatted = number_format($value);
    $isCompact = $variant === 'compact';
@endphp

<article
    class="lml-dash-count{{ $isCompact ? ' lml-dash-count--compact' : '' }}"
    data-dash-count="{{ $key }}"
>
    <h3 class="lml-dash-count__label">{{ $label }}</h3>
    <p class="lml-dash-count__value">{{ $formatted }}</p>
</article>
