{{--
    Dashboard total-record card.
    variant: primary (top band) | compact (secondary module counts)
    Usage: <x-lml.dashboard.count-card :card="$card" variant="compact" />
--}}
@props([
    'card' => [],
    'variant' => 'primary',
])

@php
    use App\Support\DashboardStatistics;
    use App\Support\DashboardUiData;

    $key = (string) ($card['key'] ?? 'count');
    $label = (string) ($card['label'] ?? 'Records');
    $icon = (string) ($card['icon'] ?? '');
    $raw = $card['value'] ?? 0;
    $formatted = DashboardStatistics::isUnavailable($raw)
        ? DashboardStatistics::UNAVAILABLE
        : DashboardUiData::formatMetricValue(is_numeric($raw) ? (int) $raw : 0);
    $isCompact = $variant === 'compact';
@endphp

<article
    class="lml-dash-count{{ $isCompact ? ' lml-dash-count--compact' : '' }}"
    data-dash-count="{{ $key }}"
>
    @if (! $isCompact && $icon !== '')
        <span class="lml-dash-count__icon" aria-hidden="true">
            <i class="bi {{ $icon }}"></i>
        </span>
    @endif
    <p class="lml-dash-count__value">{{ $formatted }}</p>
    <h3 class="lml-dash-count__label">{{ $label }}</h3>
</article>
