{{--
    Dashboard home — Figma composition (UI development).
    Counts come from App\Support\DashboardUiData (temporary fixtures).
    UI DEVELOPMENT FIXTURE — replace with backend/database aggregate counts during integration.
--}}
@extends('layouts.dashboard')

@section('title', 'Dashboard - LMLinga')

@php
    use App\Support\DashboardUiData;

    $primaryCards = DashboardUiData::primaryCards();
    $householdRows = DashboardUiData::householdSnapshot();
    $statusIndicators = DashboardUiData::healthIndicators();
@endphp

@section('content')
    <div class="lml-dash-home">
        <p class="lml-dash-home__fixture-note">
            Displayed totals are temporary UI demo values, not database records.
        </p>

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
                            role="region"
                            aria-label="Interactive map of Barangay La Medalla, Iriga City. Pan and zoom to explore. Household plotting tools remain on Spot Mapping."
                            tabindex="0"
                        ></div>
                        <p class="lml-dash-map__caption">
                            Interactive map preview of La Medalla, Iriga City.
                        </p>
                    </div>
                </section>

                <section
                    class="lml-dash-panel lml-dash-panel--table"
                    data-dash-panel="household"
                    aria-labelledby="lml-dash-hh-heading"
                >
                    <h2 id="lml-dash-hh-heading" class="visually-hidden">Household snapshot</h2>
                    <div class="lml-dash-table-wrap">
                        <table class="lml-dash-table">
                            <caption class="visually-hidden">Sample household records for dashboard layout review</caption>
                            <thead>
                                <tr>
                                    <th scope="col">HH No.</th>
                                    <th scope="col">HH Head</th>
                                    <th scope="col">Zone</th>
                                    <th scope="col">Street</th>
                                    <th scope="col">No. of Members</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($householdRows as $row)
                                    <tr>
                                        <td>{{ $row['hhNo'] }}</td>
                                        <td>{{ $row['hhHead'] }}</td>
                                        <td>{{ $row['zone'] }}</td>
                                        <td>{{ $row['street'] }}</td>
                                        <td>{{ $row['members'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
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
                                <span class="lml-dash-indicator__value">{{ number_format($item['value']) }}</span>
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
