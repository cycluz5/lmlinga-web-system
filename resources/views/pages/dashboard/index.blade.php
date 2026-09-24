{{--
    Dashboard home — Figma composition.
    Counts come from App\Support\DashboardUiData backed by DashboardStatistics (MySQL).
    Map markers reuse SpotMappingService::mappedMarkers() (same plotted households as Spot Mapping).
--}}
@extends('layouts.dashboard')

@section('title', 'Dashboard - LMLinga')

@php
    use App\Support\DashboardUiData;

    $primaryCards = DashboardUiData::primaryCards();
    $zoneCards = DashboardUiData::zoneSummaryCards();
    $statusIndicators = DashboardUiData::healthIndicators();
    $markers = $markers ?? [];
@endphp

@section('content')
    <div class="lml-dash-home">
        <section class="lml-dash-home__primary" aria-labelledby="lml-dash-primary-heading">
            <h2 id="lml-dash-primary-heading" class="lml-dash-home__heading lml-dash-home__heading--sr">Overview</h2>
            <div class="lml-dash-home__grid lml-dash-home__grid--primary">
                @foreach ($primaryCards as $card)
                    <x-lml.dashboard.count-card :card="$card" />
                @endforeach
            </div>
        </section>

        <div class="lml-dash-home__workspace">
            <div class="lml-dash-home__workspace-main">
                <section
                    class="lml-dash-panel lml-dash-panel--map"
                    data-dash-panel="map"
                    aria-labelledby="lml-dash-map-heading"
                >
                    <header class="lml-dash-panel__header">
                        <h2 id="lml-dash-map-heading" class="lml-dash-panel__title">La Medalla, Iriga City</h2>
                    </header>
                    <div class="lml-dash-map">
                        <div
                            id="lml-dash-map-canvas"
                            class="lml-dash-map__canvas"
                            data-lml-dash-map
                            data-markers='@json($markers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE)'
                            role="region"
                            aria-label="Interactive map of Barangay La Medalla, Iriga City showing plotted households. Pan and zoom to explore. Household plotting tools remain on Spot Mapping."
                            tabindex="0"
                        ></div>
                        {{-- Reuses Spot Mapping legend/swatch classes and --lml-spot-zone-* palette. Zones only; no plot/pending chrome. --}}
                        <div
                            class="lml-spot-map__legend lml-dash-map__legend"
                            data-dash-map-legend
                            aria-label="Map legend"
                        >
                            <p class="lml-spot-map__legend-title">Map Legend</p>
                            <div class="lml-spot-map__legend-section">
                                <p class="lml-spot-map__legend-heading">Barangay Zones</p>
                                <ul class="lml-spot-map__legend-list list-unstyled mb-0">
                                    <li class="lml-spot-map__legend-item">
                                        <span class="lml-spot-map__legend-swatch lml-spot-map__legend-swatch--zone-1" aria-hidden="true"></span>
                                        <span>Zone 1</span>
                                    </li>
                                    <li class="lml-spot-map__legend-item">
                                        <span class="lml-spot-map__legend-swatch lml-spot-map__legend-swatch--zone-2" aria-hidden="true"></span>
                                        <span>Zone 2</span>
                                    </li>
                                    <li class="lml-spot-map__legend-item">
                                        <span class="lml-spot-map__legend-swatch lml-spot-map__legend-swatch--zone-3" aria-hidden="true"></span>
                                        <span>Zone 3</span>
                                    </li>
                                    <li class="lml-spot-map__legend-item">
                                        <span class="lml-spot-map__legend-swatch lml-spot-map__legend-swatch--zone-4" aria-hidden="true"></span>
                                        <span>Zone 4</span>
                                    </li>
                                    <li class="lml-spot-map__legend-item">
                                        <span class="lml-spot-map__legend-swatch lml-spot-map__legend-swatch--zone-5" aria-hidden="true"></span>
                                        <span>Zone 5</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    class="lml-dash-panel lml-dash-panel--zones"
                    data-dash-panel="zones"
                    aria-labelledby="lml-dash-zones-heading"
                >
                    <header class="lml-dash-panel__header lml-dash-panel__header--zones">
                        <h2 id="lml-dash-zones-heading" class="lml-dash-panel__title">
                            Zone-Based Household and Population Map
                        </h2>
                        <p class="lml-dash-panel__subtitle">Visual representation of residential data by zone</p>
                    </header>
                    <div class="lml-dash-home__grid lml-dash-home__grid--zones">
                        @foreach ($zoneCards as $zoneCard)
                            <x-lml.dashboard.zone-summary-card :card="$zoneCard" />
                        @endforeach
                    </div>
                </section>
            </div>

            <aside class="lml-dash-home__workspace-side" aria-labelledby="lml-dash-indicators-heading">
                <section class="lml-dash-panel lml-dash-panel--indicators">
                    <header class="lml-dash-panel__header lml-dash-panel__header--indicators">
                        <h2 id="lml-dash-indicators-heading" class="lml-dash-panel__title">
                            <i class="bi bi-heart-pulse-fill" aria-hidden="true"></i>
                            Health Indicators
                        </h2>
                        <p class="lml-dash-panel__subtitle">Monitoring health status within the community</p>
                    </header>
                    <ul class="lml-dash-indicators list-unstyled mb-0">
                        @foreach ($statusIndicators as $item)
                            <li
                                class="lml-dash-indicator lml-dash-indicator--{{ $item['tone'] }}"
                                data-dash-indicator="{{ $item['key'] }}"
                            >
                                <span class="lml-dash-indicator__value">{{ \App\Support\DashboardUiData::formatMetricValue($item['value']) }}</span>
                                <span class="lml-dash-indicator__label">{{ $item['label'] }}</span>
                                <span class="lml-dash-indicator__icon" aria-hidden="true">
                                    <x-lml.dashboard.indicator-pictogram :name="$item['icon']" />
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </aside>
        </div>
    </div>
@endsection
