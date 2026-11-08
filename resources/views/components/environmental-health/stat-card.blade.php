{{--
    Environmental Health summary statistic card.
    Layouts: compact (water levels), sanitation (With/Without Toilet).
--}}
@props([
    'label',
    'value' => 0,
    'hint' => null,
    'icon' => 'bi-bar-chart',
    'variant' => 'default',
    'layout' => 'compact',
    'statKey' => null,
    'toiletVariant' => null, // with | without — when set, renders toilet-check/cross icon
])

@php
    $variantClass = match ($variant) {
        'good' => 'lml-eh-dashboard__stat-card--good',
        'alert' => 'lml-eh-dashboard__stat-card--alert',
        default => '',
    };
    $layoutClass = match ($layout) {
        'sanitation' => 'lml-eh-dashboard__stat-card--sanitation',
        default => 'lml-eh-dashboard__stat-card--compact',
    };
@endphp

<article {{ $attributes->class(['lml-eh-dashboard__stat-card', $variantClass, $layoutClass]) }} role="listitem">
    <span class="lml-eh-dashboard__stat-icon" aria-hidden="true">
        @if ($toiletVariant)
            <x-environmental-health.toilet-icon :variant="$toiletVariant" size="lg" />
        @else
            <i class="bi {{ $icon }}"></i>
        @endif
    </span>
    <div class="lml-eh-dashboard__stat-body">
        <p class="lml-eh-dashboard__stat-label">{{ $label }}</p>
        <p @class(['lml-eh-dashboard__stat-value']) @if ($statKey) data-stat="{{ $statKey }}" @endif>{{ $value }}</p>
        @if ($hint)
            <p class="lml-eh-dashboard__stat-hint">{{ $hint }}</p>
        @endif
    </div>
</article>
