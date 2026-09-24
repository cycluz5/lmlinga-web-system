{{--
    Dashboard zone household / population card.
    Zone colors reuse Spot Mapping palette via --lml-spot-zone-N on .lml-dash-home.
--}}
@props([
    'card' => [],
])

@php
    use App\Support\DashboardUiData;

    $zone = (string) ($card['zone'] ?? 'Zone');
    $zoneNumber = (int) ($card['zoneNumber'] ?? 0);
    $households = (int) ($card['households'] ?? 0);
    $population = (int) ($card['population'] ?? 0);
    $householdIcon = (string) ($card['householdIcon'] ?? 'bi-house-door-fill');
    $populationIcon = (string) ($card['populationIcon'] ?? 'bi-people-fill');
    $key = (string) ($card['key'] ?? 'zone');
@endphp

<article
    class="lml-dash-zone"
    data-dash-zone="{{ $key }}"
    data-dash-zone-number="{{ $zoneNumber }}"
>
    <header class="lml-dash-zone__header">
        <span class="lml-dash-zone__indicator lml-dash-zone__indicator--{{ $zoneNumber }}" aria-hidden="true"></span>
        <h3 class="lml-dash-zone__title">{{ $zone }}</h3>
    </header>

    <div class="lml-dash-zone__metrics">
        <div class="lml-dash-zone__metric">
            <span class="lml-dash-zone__metric-icon" aria-hidden="true">
                <i class="bi {{ $householdIcon }}"></i>
            </span>
            <div class="lml-dash-zone__metric-body">
                <p class="lml-dash-zone__metric-value">{{ DashboardUiData::formatMetricValue($households) }}</p>
            </div>
        </div>

        <div class="lml-dash-zone__metric">
            <span class="lml-dash-zone__metric-icon" aria-hidden="true">
                <i class="bi {{ $populationIcon }}"></i>
            </span>
            <div class="lml-dash-zone__metric-body">
                <p class="lml-dash-zone__metric-value">{{ DashboardUiData::formatMetricValue($population) }}</p>
            </div>
        </div>
    </div>
</article>
