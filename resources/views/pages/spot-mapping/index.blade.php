{{--
    Spot Mapping — DB18-C: MySQL-backed markers/stats; coordinates on households.
    Environmental Health handoff remains temporarily compatible (DB18-D cleanup).
--}}
@extends('layouts.dashboard')

@section('title', 'Spot Mapping - LMLinga')

@section('content')
    <div
        class="lml-spot-map"
        data-lml-spot-map
        data-plot-url="{{ route('spot-mapping.plot') }}"
        data-plot-handoff-url="{{ route('spot-mapping.plot-handoff') }}"
        data-plot-new-url="{{ route('spot-mapping.plot-new') }}"
        data-pending-candidates='@json($pendingCandidates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE)'
        data-markers='@json($markers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE)'
        data-boundary-state="{{ $boundaryState ?? 'missing' }}"
        {{-- Offline view: when this page is served from the offline cache, markers are as of this time. --}}
        data-rendered-at="{{ now()->toIso8601String() }}"
        @if (! empty($boundaryGeoJson))
            data-boundary='@json($boundaryGeoJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE)'
        @endif
        @if (! empty($demoLocation['active']))
            data-demo-location='@json($demoLocation['active'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE)'
        @endif
    >
        {{-- SR-10 Demo Location Mode: shown only to the Admin whose session enabled it (gated deployment). --}}
        @if (! empty($demoLocation['active']))
            <div class="lml-spot-map__demo-banner" role="status" data-spot-map-demo-banner>
                <i class="bi bi-broadcast-pin" aria-hidden="true"></i>
                <p class="lml-spot-map__demo-banner-text">
                    <strong>DEMO / SIMULATED LOCATION</strong> — "{{ $demoLocation['active']['label'] }}" is used instead of this device's real position.
                    @unless ($demoLocation['active']['verified'] ?? false)
                        <span data-spot-map-demo-unverified>This demo point is UNVERIFIED (not independently confirmed).</span>
                    @endunless
                    Simulated coordinates are not saved. Ends {{ \Illuminate\Support\Carbon::createFromTimestamp($demoLocation['active']['expires_at'])->timezone(config('app.timezone'))->format('g:i A') }} or when you turn it off or log out.
                </p>
                <form method="POST" action="{{ route('spot-mapping.demo-location.disable') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-light lml-focus-ring fw-medium" data-spot-map-demo-disable>Turn off Demo Mode</button>
                </form>
            </div>
        @endif

        @if (! empty($demoLocation['manage']) && empty($demoLocation['active']))
            <section class="lml-spot-map__demo-panel" aria-labelledby="spot-map-demo-heading" data-spot-map-demo-panel>
                <h2 id="spot-map-demo-heading" class="lml-spot-map__demo-panel-title">Demo Location Mode (Admin, this session only)</h2>
                @if ($demoLocation['available'])
                    <form method="POST" action="{{ route('spot-mapping.demo-location.enable') }}" class="lml-spot-map__demo-form">
                        @csrf
                        <label for="spot-map-demo-point" class="visually-hidden">Verified demo point</label>
                        <select id="spot-map-demo-point" name="point" class="form-select form-select-sm lml-form-control" required>
                            @foreach ($demoLocation['points'] as $point)
                                <option value="{{ $point['key'] }}">{{ $point['label'] }}{{ ($point['verified'] ?? false) ? '' : ' — UNVERIFIED' }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary lml-focus-ring fw-medium">Turn on Demo Mode</button>
                    </form>
                    <p class="lml-spot-map__demo-panel-note">Uses a configured point inside the indicative barangay boundary instead of this device's GPS, for up to 2 hours. Nothing is saved while it is on.</p>
                @else
                    <p class="lml-spot-map__demo-panel-note" data-spot-map-demo-unavailable>Unavailable: {{ $demoLocation['reason'] }}</p>
                @endif
            </section>
        @endif
        {{-- SR-11 / SM-01: barangay boundary status (fail closed; nothing invented when data is missing). --}}
        @if (($boundaryState ?? 'missing') === 'valid')
            {{-- SR-10 Phase B: the boundary in use is INDICATIVE (NAMRIA/PSA COD-AB); it is not used to accept or refuse real plots. --}}
            <p class="lml-spot-map__boundary-note" data-spot-map-boundary-indicative>
                Barangay outline: {{ $boundaryGeoJson['properties']['name'] ?? 'Barangay La Medalla' }}. Indicative only (NAMRIA/PSA data, CC BY-IGO) — not a legal boundary and not used to accept or refuse household plots.
            </p>
        @endif

        @if (($boundaryState ?? 'missing') !== 'valid')
            <div class="lml-spot-map__server-alert lml-spot-map__server-alert--warning" role="status" data-spot-map-boundary-warning>
                <p class="lml-spot-map__server-alert-text">
                    @if (($boundaryState ?? 'missing') === 'missing')
                        Barangay boundary not available: the official Barangay La Medalla boundary has not been added yet, so the map cannot show the barangay limits.
                    @else
                        Barangay boundary not shown: the boundary file is invalid. {{ $boundaryReason ?? '' }}
                    @endif
                    No location is treated as inside the barangay boundary until valid official boundary data is added.
                </p>
            </div>
        @endif

        @if ($errors->any())
            <div class="lml-spot-map__server-alert" role="alert">
                <p class="lml-spot-map__server-alert-text">{{ $errors->first() }}</p>
            </div>
        @endif

        @if (session('status'))
            <div class="lml-spot-map__server-alert lml-spot-map__server-alert--success" role="status">
                <p class="lml-spot-map__server-alert-text">{{ session('status') }}</p>
            </div>
        @endif

        {{-- Plotting is online-only: spot-mapping.js shows this toast (same style as User Management)
             when Plot is pressed while offline. --}}
        <p
            class="lml-spot-map__toast lml-spot-map__toast--error"
            role="alert"
            aria-live="assertive"
            data-spot-map-offline-notice
            hidden
        ></p>

        <div class="lml-spot-map__toolbar">
            <div class="lml-spot-map__stats" role="group" aria-label="Household mapping summary">
                <article class="lml-spot-map__card">
                    <p class="lml-spot-map__card-label">Total HH</p>
                    <p class="lml-spot-map__card-value" data-stat="total">{{ (int) $stats['total'] }}</p>
                </article>
                <article class="lml-spot-map__card">
                    <p class="lml-spot-map__card-label">Plotted</p>
                    <p class="lml-spot-map__card-value" data-stat="plotted">{{ (int) $stats['plotted'] }}</p>
                </article>
                <article class="lml-spot-map__card">
                    <p class="lml-spot-map__card-label">Pending</p>
                    <p class="lml-spot-map__card-value" data-stat="pending">{{ (int) $stats['pending'] }}</p>
                </article>
            </div>

            <div class="lml-spot-map__toolbar-actions">
                <button
                    type="button"
                    class="lml-spot-map__plot-btn lml-focus-ring"
                    data-spot-map-plot
                    aria-pressed="false"
                    aria-busy="false"
                >
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    <span>Plot New Household</span>
                </button>
                <button
                    type="button"
                    class="lml-spot-map__plot-btn lml-spot-map__plot-btn--existing lml-focus-ring"
                    data-spot-map-plot-existing
                    disabled
                    aria-disabled="true"
                >
                    <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                    <span>Plot Selected Household</span>
                </button>
            </div>

            <div class="lml-spot-map__export" data-spot-map-export-menu>
                <button
                    type="button"
                    class="lml-spot-map__export-btn lml-focus-ring"
                    data-spot-map-export-toggle
                    aria-haspopup="menu"
                    aria-expanded="false"
                    aria-controls="lml-spot-map-export-menu"
                    id="lml-spot-map-export-trigger"
                >
                    <i class="bi bi-download" aria-hidden="true"></i>
                    <span>Save Map</span>
                </button>

                <div
                    id="lml-spot-map-export-menu"
                    class="lml-spot-map__export-menu"
                    data-spot-map-export-menu-panel
                    role="region"
                    aria-labelledby="lml-spot-map-export-trigger"
                    hidden
                >
                    <div class="lml-spot-map__export-section">
                        <p class="lml-spot-map__export-heading" id="lml-spot-map-export-overall-heading">Overall Map</p>
                        <div class="lml-spot-map__export-actions-row" role="group" aria-labelledby="lml-spot-map-export-overall-heading">
                            <button
                                type="button"
                                class="lml-spot-map__export-action lml-focus-ring"
                                data-spot-map-export
                                data-scope="overall"
                                data-format="png"
                            >
                                <i class="bi bi-image" aria-hidden="true"></i>
                                <span>Save as Image</span>
                            </button>
                            <button
                                type="button"
                                class="lml-spot-map__export-action lml-focus-ring"
                                data-spot-map-export
                                data-scope="overall"
                                data-format="pdf"
                            >
                                <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                                <span>Save as PDF</span>
                            </button>
                        </div>
                    </div>

                    <div class="lml-spot-map__export-section">
                        <p class="lml-spot-map__export-heading" id="lml-spot-map-export-zone-heading">Per Zone</p>
                        <div class="lml-spot-map__export-zone-field">
                            <label class="lml-spot-map__export-zone-label" for="lml-spot-map-export-zone-select">Zone</label>
                            <select
                                id="lml-spot-map-export-zone-select"
                                class="lml-spot-map__export-zone-select lml-focus-ring"
                                data-spot-map-export-zone-select
                                aria-labelledby="lml-spot-map-export-zone-heading lml-spot-map-export-zone-select-label"
                            >
                                <option value="" selected disabled>Select zone</option>
                                @foreach ([1, 2, 3, 4, 5] as $zone)
                                    <option value="{{ $zone }}">Zone {{ $zone }}</option>
                                @endforeach
                            </select>
                            <span id="lml-spot-map-export-zone-select-label" class="visually-hidden">Choose a barangay zone for export</span>
                        </div>
                        <div class="lml-spot-map__export-actions-row" role="group" aria-labelledby="lml-spot-map-export-zone-heading">
                            <button
                                type="button"
                                class="lml-spot-map__export-action lml-focus-ring"
                                data-spot-map-export-zone-image
                            >
                                <i class="bi bi-image" aria-hidden="true"></i>
                                <span>Save as Image</span>
                            </button>
                            <button
                                type="button"
                                class="lml-spot-map__export-action lml-focus-ring"
                                data-spot-map-export-zone-pdf
                            >
                                <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                                <span>Save as PDF</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <p
            class="lml-spot-map__export-status"
            data-spot-map-export-status
            role="status"
            aria-live="polite"
            hidden
        ></p>

        <section
            class="lml-spot-map__pending"
            data-spot-map-pending
            aria-labelledby="lml-spot-map-pending-heading"
            hidden
        >
            <div class="lml-spot-map__pending-header">
                <p class="lml-spot-map__pending-heading" id="lml-spot-map-pending-heading">
                    Pending Households
                </p>
                <p class="lml-spot-map__pending-hint">
                    Choose an unplotted household from the list, then use Plot Selected Household.
                </p>
            </div>
            <ul
                class="lml-spot-map__pending-list list-unstyled mb-0"
                data-spot-map-pending-list
                role="listbox"
                aria-labelledby="lml-spot-map-pending-heading"
            ></ul>
        </section>

        {{-- Offline view notice (spot-mapping.js fills and shows it when offline or map imagery fails). --}}
        <p class="lml-spot-map__offline-notice" role="status" aria-live="polite" data-spot-map-offline-view-notice hidden></p>
        {{-- Phase 2B: saved offline map progress/problems (hidden once the map is saved). --}}
        <p class="lml-spot-map__offline-map-status" role="status" aria-live="polite" data-spot-map-offline-map-status hidden></p>

        <div class="lml-spot-map__workspace">
            <div class="lml-spot-map__map-wrap">
                <div
                    id="lml-spot-map-canvas"
                    class="lml-spot-map__map"
                    role="region"
                    aria-label="Barangay La Medalla household spot map"
                    tabindex="0"
                ></div>

                <div
                    class="lml-spot-map__overlay"
                    data-spot-map-overlay
                    data-mode="info"
                    hidden
                    role="status"
                    aria-live="polite"
                >
                    <i class="bi bi-geo-alt-fill" data-spot-map-overlay-icon aria-hidden="true"></i>
                    <span data-spot-map-overlay-text>Getting your current location…</span>
                </div>

                <div class="lml-spot-map__legend" aria-label="Map legend">
                    <p class="lml-spot-map__legend-title">Map Legend</p>

                    <div class="lml-spot-map__legend-section">
                        <p class="lml-spot-map__legend-heading">Household Status</p>
                        <ul class="lml-spot-map__legend-list list-unstyled mb-0">
                            <li class="lml-spot-map__legend-item">
                                <span class="lml-spot-map__legend-swatch lml-spot-map__legend-swatch--plotted" aria-hidden="true"></span>
                                <span>Completed / Plotted</span>
                            </li>
                            <li class="lml-spot-map__legend-item">
                                <span class="lml-spot-map__legend-swatch lml-spot-map__legend-swatch--pending" aria-hidden="true"></span>
                                <span>Not yet saved (new pin)</span>
                            </li>
                            <li class="lml-spot-map__legend-item">
                                <span class="lml-spot-map__legend-swatch lml-spot-map__legend-swatch--manual" aria-hidden="true"></span>
                                <span>Placed by hand (unverified)</span>
                            </li>
                            <li class="lml-spot-map__legend-item">
                                <span class="lml-spot-map__legend-swatch lml-spot-map__legend-swatch--demo" aria-hidden="true"></span>
                                <span>Simulated (demo, never saved)</span>
                            </li>
                            <li class="lml-spot-map__legend-item">
                                <span class="lml-spot-map__legend-swatch lml-spot-map__legend-swatch--check" aria-hidden="true"></span>
                                <span>Location to check (outside the indicative boundary)</span>
                            </li>
                        </ul>
                        <p class="lml-spot-map__legend-asof">The number inside a marker is its zone. Zoom in to see household numbers; tap a marker for details.</p>
                        <p class="lml-spot-map__legend-asof">Pending households have no location yet. They are listed under Pending Households.</p>
                        <p class="lml-spot-map__legend-asof" data-spot-map-legend-asof>Households as of {{ now()->timezone(config('app.timezone'))->format('M j, Y g:i A') }}</p>
                    </div>

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

            <aside
                class="lml-spot-map__panel"
                data-spot-map-panel
                hidden
                aria-labelledby="lml-spot-map-panel-title"
            >
                <div class="lml-spot-map__panel-header">
                    <h2 id="lml-spot-map-panel-title" class="lml-spot-map__panel-title">Household Details</h2>
                    <button
                        type="button"
                        class="lml-spot-map__panel-close lml-focus-ring"
                        data-spot-map-close
                        aria-label="Close household details panel"
                    >
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                </div>

                <p class="lml-spot-map__panel-note" data-spot-map-note>
                    Capture a location, then enter the new household and household-head names to register and plot.
                </p>

                <div class="lml-spot-map__group-selector" data-spot-map-group-selector hidden>
                    <p class="lml-spot-map__group-heading" data-spot-map-group-heading>
                        Multiple households at this location
                    </p>
                    <ul class="lml-spot-map__group-list" data-spot-map-group-list></ul>
                </div>

                <div data-spot-map-details-body>
                {{-- Phase 3A: location check (filled by spot-mapping.js; display only, never blocks saving on its own). --}}
                <div class="lml-spot-map__location-check" data-spot-map-location-check hidden>
                    <p class="lml-spot-map__location-kind" data-spot-map-location-kind></p>
                    <ul class="lml-spot-map__location-lines" data-spot-map-location-lines></ul>
                    <button type="button" class="lml-spot-map__btn lml-spot-map__btn--secondary lml-spot-map__regps lml-focus-ring" data-spot-map-regps hidden>
                        <i class="bi bi-crosshair" aria-hidden="true"></i>
                        <span>Try GPS again</span>
                    </button>
                    <label class="lml-spot-map__boundary-confirm" for="lml-spot-map-boundary-confirm-input" data-spot-map-boundary-confirm hidden>
                        <input
                            id="lml-spot-map-boundary-confirm-input"
                            type="checkbox"
                            class="lml-spot-map__consent-input lml-focus-ring"
                            data-spot-map-boundary-confirm-input
                            aria-describedby="lml-spot-map-boundary-confirm-error"
                        >
                        <span>I checked: this household is in La Medalla (the boundary is approximate).</span>
                    </label>
                    <p
                        id="lml-spot-map-boundary-confirm-error"
                        class="lml-spot-map__field-error"
                        data-error-for="boundaryConfirm"
                        role="alert"
                        hidden
                    ></p>
                </div>

                <dl class="lml-spot-map__fields">
                    <div class="lml-spot-map__field">
                        <dt>
                            <label id="lml-spot-map-household-number-label" for="lml-spot-map-household-no">
                                Household No. <span class="lml-spot-map__req" aria-hidden="true">*</span>
                            </label>
                        </dt>
                        <dd>
                            <span data-household-number-text hidden>—</span>
                            <input
                                type="text"
                                id="lml-spot-map-household-no"
                                name="household_no"
                                class="lml-spot-map__zone-select lml-spot-map__control lml-focus-ring"
                                data-spot-map-household-no
                                inputmode="numeric"
                                maxlength="3"
                                pattern="[0-9]{3}"
                                autocomplete="off"
                                aria-labelledby="lml-spot-map-household-number-label"
                                aria-describedby="lml-spot-map-household-no-error"
                                required
                                aria-required="true"
                            >
                            <p
                                id="lml-spot-map-household-no-error"
                                class="lml-spot-map__field-error"
                                data-error-for="householdNo"
                                role="alert"
                                hidden
                            ></p>
                        </dd>
                    </div>
                    <div class="lml-spot-map__field">
                        <dt>
                            <label id="lml-spot-map-hh-type-label" for="lml-spot-map-hh-type">
                                Household Type <span class="lml-spot-map__req" aria-hidden="true">*</span>
                            </label>
                        </dt>
                        <dd data-field="householdTypeDisplay">
                            <span data-hh-type-text hidden>—</span>
                            <select
                                id="lml-spot-map-hh-type"
                                class="lml-spot-map__zone-select lml-spot-map__control lml-focus-ring"
                                data-spot-map-hh-type-select
                                aria-labelledby="lml-spot-map-hh-type-label"
                                required
                                aria-required="true"
                                aria-describedby="lml-spot-map-hh-type-error"
                            >
                                <option value="" selected disabled>Select household type</option>
                                <option value="NHTS">NHTS</option>
                                <option value="Non-NHTS">Non-NHTS</option>
                            </select>
                            <p
                                id="lml-spot-map-hh-type-error"
                                class="lml-spot-map__field-error"
                                data-error-for="householdType"
                                role="alert"
                                hidden
                            >                            </p>
                        </dd>
                    </div>
                    <p class="lml-spot-map__section-heading" id="lml-spot-map-head-heading">Household Head</p>
                    <div class="lml-spot-map__field">
                        <dt>
                            <label for="lml-spot-map-head-first">
                                First Name <span class="lml-spot-map__req" aria-hidden="true">*</span>
                            </label>
                        </dt>
                        <dd>
                            <input
                                id="lml-spot-map-head-first"
                                type="text"
                                name="first_name"
                                class="lml-spot-map__control lml-focus-ring"
                                data-spot-map-head-first
                                maxlength="100"
                                autocomplete="given-name"
                                required
                                aria-required="true"
                                aria-describedby="lml-spot-map-head-first-error"
                            >
                            <p
                                id="lml-spot-map-head-first-error"
                                class="lml-spot-map__field-error"
                                data-error-for="firstName"
                                role="alert"
                                hidden
                            ></p>
                        </dd>
                    </div>
                    <div class="lml-spot-map__field">
                        <dt>
                            <label for="lml-spot-map-head-middle">
                                Middle Name
                            </label>
                        </dt>
                        <dd>
                            <input
                                id="lml-spot-map-head-middle"
                                type="text"
                                name="middle_name"
                                class="lml-spot-map__control lml-focus-ring"
                                data-spot-map-head-middle
                                maxlength="100"
                                autocomplete="additional-name"
                            >
                        </dd>
                    </div>
                    <div class="lml-spot-map__field">
                        <dt>
                            <label for="lml-spot-map-head-last">
                                Last Name <span class="lml-spot-map__req" aria-hidden="true">*</span>
                            </label>
                        </dt>
                        <dd>
                            <input
                                id="lml-spot-map-head-last"
                                type="text"
                                name="last_name"
                                class="lml-spot-map__control lml-focus-ring"
                                data-spot-map-head-last
                                maxlength="100"
                                autocomplete="family-name"
                                required
                                aria-required="true"
                                aria-describedby="lml-spot-map-head-last-error"
                            >
                            <p
                                id="lml-spot-map-head-last-error"
                                class="lml-spot-map__field-error"
                                data-error-for="lastName"
                                role="alert"
                                hidden
                            ></p>
                        </dd>
                    </div>
                    <div class="lml-spot-map__field" data-spot-map-head-birthday-wrap>
                        <dt>
                            <label for="lml-spot-map-head-birthday">
                                Birthday <span class="lml-spot-map__req" aria-hidden="true">*</span>
                            </label>
                        </dt>
                        <dd>
                            <input
                                id="lml-spot-map-head-birthday"
                                type="date"
                                name="birthday"
                                class="lml-spot-map__control lml-focus-ring"
                                data-spot-map-head-birthday
                                max="{{ now()->toDateString() }}"
                                required
                                aria-required="true"
                                aria-describedby="lml-spot-map-head-birthday-error"
                            >
                            <p
                                id="lml-spot-map-head-birthday-error"
                                class="lml-spot-map__field-error"
                                data-error-for="birthday"
                                role="alert"
                                hidden
                            ></p>
                        </dd>
                    </div>
                    <div class="lml-spot-map__field" data-spot-map-head-sex-wrap>
                        <dt>
                            <label for="lml-spot-map-head-sex">
                                Sex <span class="lml-spot-map__req" aria-hidden="true">*</span>
                            </label>
                        </dt>
                        <dd>
                            <select
                                id="lml-spot-map-head-sex"
                                name="sex"
                                class="lml-spot-map__zone-select lml-spot-map__control lml-focus-ring"
                                data-spot-map-head-sex
                                required
                                aria-required="true"
                                aria-describedby="lml-spot-map-head-sex-error"
                            >
                                <option value="" selected disabled>Select sex</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                            <p
                                id="lml-spot-map-head-sex-error"
                                class="lml-spot-map__field-error"
                                data-error-for="sex"
                                role="alert"
                                hidden
                            ></p>
                        </dd>
                    </div>
                    <div class="lml-spot-map__field" data-spot-map-head-civil-status-wrap>
                        <dt>
                            <label for="lml-spot-map-head-civil-status">
                                Civil Status <span class="lml-spot-map__req" aria-hidden="true">*</span>
                            </label>
                        </dt>
                        <dd>
                            <select
                                id="lml-spot-map-head-civil-status"
                                name="civil_status"
                                class="lml-spot-map__zone-select lml-spot-map__control lml-focus-ring"
                                data-spot-map-head-civil-status
                                required
                                aria-required="true"
                                aria-describedby="lml-spot-map-head-civil-status-error"
                            >
                                <option value="" selected disabled>Select civil status</option>
                                <option value="Single">Single</option>
                                <option value="Married">Married</option>
                                <option value="Widowed">Widowed</option>
                                <option value="Separated">Separated</option>
                                <option value="Live-In">Live-In</option>
                            </select>
                            <p
                                id="lml-spot-map-head-civil-status-error"
                                class="lml-spot-map__field-error"
                                data-error-for="civilStatus"
                                role="alert"
                                hidden
                            ></p>
                        </dd>
                    </div>
                    <div class="lml-spot-map__field" data-spot-map-date-wrap>
                        <dt>
                            <label for="lml-spot-map-date-registered">
                                Date Registered <span class="lml-spot-map__req" aria-hidden="true">*</span>
                            </label>
                        </dt>
                        <dd>
                            <input
                                id="lml-spot-map-date-registered"
                                type="date"
                                name="date_registered"
                                class="lml-spot-map__control lml-focus-ring"
                                data-spot-map-date-registered
                                value="{{ now()->toDateString() }}"
                                max="{{ now()->toDateString() }}"
                                required
                                aria-required="true"
                            >
                        </dd>
                    </div>
                    <div class="lml-spot-map__field">
                        <dt>
                            <label id="lml-spot-map-zone-label" for="lml-spot-map-zone">
                                Zone <span class="lml-spot-map__req" aria-hidden="true">*</span>
                            </label>
                        </dt>
                        <dd data-field="zoneDisplay">
                            <span class="lml-spot-map__zone-badge" data-zone-badge hidden>
                                <span class="lml-spot-map__zone-dot" data-zone-dot aria-hidden="true"></span>
                                <span data-zone-text>—</span>
                            </span>
                            <select
                                id="lml-spot-map-zone"
                                class="lml-spot-map__zone-select lml-spot-map__control lml-focus-ring"
                                data-spot-map-zone-select
                                aria-labelledby="lml-spot-map-zone-label"
                                required
                                aria-required="true"
                                aria-describedby="lml-spot-map-zone-error"
                            >
                                <option value="" selected disabled>Select zone</option>
                                <option value="1">Zone 1</option>
                                <option value="2">Zone 2</option>
                                <option value="3">Zone 3</option>
                                <option value="4">Zone 4</option>
                                <option value="5">Zone 5</option>
                            </select>
                            <p
                                id="lml-spot-map-zone-error"
                                class="lml-spot-map__field-error"
                                data-error-for="zone"
                                role="alert"
                                hidden
                            ></p>
                        </dd>
                    </div>
                    <div class="lml-spot-map__field">
                        <dt>Members</dt>
                        <dd data-field="members">—</dd>
                    </div>
                    <div class="lml-spot-map__field">
                        <dt>Status</dt>
                        <dd data-field="statusLabel">—</dd>
                    </div>
                    <div class="lml-spot-map__field">
                        <dt>Latitude</dt>
                        <dd data-field="lat">—</dd>
                    </div>
                    <div class="lml-spot-map__field">
                        <dt>Longitude</dt>
                        <dd data-field="lng">—</dd>
                    </div>
                </dl>

                <div class="lml-spot-map__consent-block" data-spot-map-consent>
                    <p class="lml-spot-map__consent-heading" id="lml-spot-map-consent-label">
                        Consent <span class="lml-spot-map__req" aria-hidden="true">*</span>
                    </p>
                    <label class="lml-spot-map__consent" for="lml-spot-map-consent-input">
                        <input
                            id="lml-spot-map-consent-input"
                            type="checkbox"
                            class="lml-spot-map__consent-input lml-focus-ring"
                            data-spot-map-consent-input
                            required
                            aria-required="true"
                            aria-describedby="lml-spot-map-consent-error"
                        >
                        <span class="lml-spot-map__consent-text">
                            Head of Household agreed to collect household coordinates for household profiling purposes.
                        </span>
                    </label>
                    <p
                        id="lml-spot-map-consent-error"
                        class="lml-spot-map__field-error"
                        data-error-for="consent"
                        role="alert"
                        hidden
                    ></p>
                </div>
                </div>

                <div class="lml-spot-map__panel-actions">
                    <a
                        href="{{ url('/household-profiling') }}"
                        class="lml-spot-map__view-hh lml-focus-ring"
                        data-spot-map-view-hh
                        hidden
                    >
                        <i class="bi bi-eye-fill" aria-hidden="true"></i>
                        <span>View Household</span>
                    </a>
                    <button
                        type="button"
                        class="lml-spot-map__btn lml-spot-map__btn--secondary lml-focus-ring"
                        data-spot-map-cancel
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="lml-spot-map__btn lml-spot-map__btn--primary lml-focus-ring"
                        data-spot-map-confirm
                    >
                        Plot
                    </button>
                </div>
            </aside>
        </div>
    </div>
@endsection
