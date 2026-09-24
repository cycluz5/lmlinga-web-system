{{--
    Environmental Health Dashboard — Figma-aligned compact monitoring layout.
    Aggregates Household Amenities. Presentation-only refinement.
--}}
@extends('layouts.dashboard')

@section('title', 'Environmental Health - LMLinga')

@php
    use App\Support\EnvironmentalHealthDashboard;

    $stats = $statistics ?? [
        'water_supply' => ['level_i' => 0, 'level_ii' => 0, 'level_iii' => 0, 'others' => 0],
        'sanitation' => ['sanitary' => 0, 'unsanitary' => 0, 'not_yet_determined' => 0],
        'toilet_presence' => ['with_toilet' => 0, 'without_toilet' => 0, 'unknown' => 0],
        'overview' => [
            'total_households' => 0,
            'completed_amenities' => 0,
            'pending_assessment' => 0,
            'validated_water_sources' => 0,
            'good_solid_waste' => 0,
        ],
    ];
    $toiletPresence = $stats['toilet_presence'] ?? ['with_toilet' => 0, 'without_toilet' => 0, 'unknown' => 0];
    $filterValues = $filters ?? EnvironmentalHealthDashboard::normalizeFilters([]);
    $reportBuilderUrl = $reportBuilderUrl ?? route('environmental-health.report-builder');
@endphp

@section('content')
    <div
        class="lml-eh-dashboard"
        data-lml-eh-dashboard
        data-total="{{ $totalUnfiltered }}"
    >
        <header class="lml-eh-dashboard__header">
            <h2 class="visually-hidden" id="lml-eh-dashboard-heading">Environmental Health</h2>
            <a class="lml-eh-dashboard__export-btn lml-focus-ring" href="{{ $reportBuilderUrl }}">
                <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                <span>Export Data</span>
            </a>
        </header>

        {{-- Keep overview counters for JS / calculation bindings without a visible accordion --}}
        <div class="visually-hidden" aria-hidden="true">
            <span data-stat="overview-total">{{ $stats['overview']['total_households'] }}</span>
            <span data-stat="overview-completed">{{ $stats['overview']['completed_amenities'] }}</span>
            <span data-stat="overview-pending">{{ $stats['overview']['pending_assessment'] }}</span>
            <span data-stat="overview-validated">{{ $stats['overview']['validated_water_sources'] }}</span>
            <span data-stat="overview-waste">{{ $stats['overview']['good_solid_waste'] }}</span>
            <span data-stat="sanitation-sanitary">{{ $stats['sanitation']['sanitary'] }}</span>
            <span data-stat="sanitation-unsanitary">{{ $stats['sanitation']['unsanitary'] }}</span>
            <span data-stat="sanitation-pending">{{ $stats['sanitation']['not_yet_determined'] }}</span>
        </div>

        <div class="lml-eh-dashboard__summary-grid">
            <section class="lml-eh-dashboard__box" aria-labelledby="lml-eh-water-title">
                <h3 id="lml-eh-water-title" class="lml-eh-dashboard__group-title">
                    <i class="bi bi-droplet" aria-hidden="true"></i>
                    <span>Water Supply Status</span>
                </h3>
                <div class="lml-eh-dashboard__stat-row" role="list">
                    <x-environmental-health.stat-card
                        label="Level I"
                        :value="$stats['water_supply']['level_i']"
                        icon="bi-droplet"
                        layout="compact"
                        stat-key="water-level_i"
                    />
                    <x-environmental-health.stat-card
                        label="Level II"
                        :value="$stats['water_supply']['level_ii']"
                        icon="bi-moisture"
                        layout="compact"
                        stat-key="water-level_ii"
                    />
                    <x-environmental-health.stat-card
                        label="Level III"
                        :value="$stats['water_supply']['level_iii']"
                        icon="bi-house-door-fill"
                        layout="compact"
                        stat-key="water-level_iii"
                    />
                    <x-environmental-health.stat-card
                        label="Others"
                        :value="$stats['water_supply']['others']"
                        icon="bi-house"
                        layout="compact"
                        stat-key="water-others"
                    />
                </div>
            </section>

            <section class="lml-eh-dashboard__box" aria-labelledby="lml-eh-sanitation-title">
                <h3 id="lml-eh-sanitation-title" class="lml-eh-dashboard__group-title">
                    <i class="bi bi-shield" aria-hidden="true"></i>
                    <span>Sanitation Services</span>
                </h3>
                <div class="lml-eh-dashboard__stat-row lml-eh-dashboard__stat-row--sanitation" role="list">
                    <x-environmental-health.stat-card
                        label="With Toilet"
                        :value="$toiletPresence['with_toilet']"
                        variant="good"
                        layout="sanitation"
                        toilet-variant="with"
                        stat-key="sanitation-with"
                    />
                    <x-environmental-health.stat-card
                        label="Without Toilet"
                        :value="$toiletPresence['without_toilet']"
                        variant="alert"
                        layout="sanitation"
                        toilet-variant="without"
                        stat-key="sanitation-without"
                    />
                </div>
            </section>
        </div>

        <form
            class="lml-eh-dashboard__filters"
            method="get"
            action="{{ route('environmental-health.index') }}"
            role="search"
            aria-label="Environmental health search and filters"
            data-eh-filters
        >
            <div class="lml-eh-dashboard__filter-field lml-eh-dashboard__filter-field--search">
                <label class="lml-eh-dashboard__filter-label" for="lml-eh-hh-no">Household Number</label>
                <div class="lml-eh-dashboard__search">
                    <i class="bi bi-search lml-eh-dashboard__search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="lml-eh-hh-no"
                        name="household_no"
                        class="lml-eh-dashboard__search-input lml-focus-ring"
                        value="{{ $filterValues['household_no'] }}"
                        placeholder="Search household number"
                        autocomplete="off"
                        data-eh-filter="household_no"
                    >
                </div>
            </div>

            <div class="lml-eh-dashboard__filter-field">
                <label class="lml-eh-dashboard__filter-label" for="lml-eh-zone">Zone</label>
                <div class="lml-eh-dashboard__select-wrap">
                    <select id="lml-eh-zone" name="zone" class="lml-eh-dashboard__select lml-focus-ring" data-eh-filter="zone">
                        <option value="all" @selected($filterValues['zone'] === 'all')>All</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}" @selected($filterValues['zone'] === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-eh-dashboard__select-icon" aria-hidden="true"></i>
                </div>
            </div>

            <div class="lml-eh-dashboard__filter-field">
                <label class="lml-eh-dashboard__filter-label" for="lml-eh-street">Street</label>
                <div class="lml-eh-dashboard__select-wrap">
                    <select id="lml-eh-street" name="street" class="lml-eh-dashboard__select lml-focus-ring" data-eh-filter="street">
                        <option value="all" @selected($filterValues['street'] === 'all')>All</option>
                        @foreach ($streets as $street)
                            <option value="{{ $street }}" @selected($filterValues['street'] === $street)>{{ $street }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-eh-dashboard__select-icon" aria-hidden="true"></i>
                </div>
            </div>

            <noscript>
                <button type="submit" class="lml-eh-dashboard__apply-btn lml-focus-ring">Apply Filters</button>
            </noscript>
        </form>

        <p class="lml-eh-dashboard__results" data-eh-results aria-live="polite">
            Showing {{ $filteredCount }} of {{ $totalUnfiltered }} household amenities records
        </p>

        <div class="lml-eh-dashboard__table-card">
            <div class="lml-eh-dashboard__table-scroll" tabindex="0" role="region" aria-label="Environmental health household records">
                <table class="lml-eh-dashboard__table">
                    <caption class="visually-hidden">
                        Household amenities environmental health overview. Use View or Edit to open an individual record.
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">HH No.</th>
                            <th scope="col">HH Head</th>
                            <th scope="col">Water Supply Level</th>
                            <th scope="col">Sanitation Services</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody data-eh-tbody>
                        @forelse ($rows as $row)
                            @php
                                $presence = $row['toilet_presence'] ?? 'unknown';
                                $actionMode = $row['action_mode'] ?? 'add';
                                $hasPresence = $presence === 'with_toilet' || $presence === 'without_toilet';
                            @endphp
                            <tr
                                data-eh-row
                                data-household-no="{{ $row['household_no'] }}"
                                data-house-head="{{ $row['house_head'] }}"
                                data-zone="{{ $row['zone'] }}"
                                data-street="{{ $row['street'] }}"
                                data-water-supply="{{ $row['water_supply_status'] }}"
                                data-toilet-status="{{ $row['toilet_status'] !== '' ? $row['toilet_status'] : 'not_yet_determined' }}"
                                data-toilet-presence="{{ $presence }}"
                                data-sanitation="{{ $presence }}"
                                data-record-status="{{ $row['record_status'] }}"
                                data-solid-waste="{{ $row['solid_waste_status'] }}"
                            >
                                <td data-label="HH No.">
                                    <span class="lml-eh-dashboard__hh-no">{{ $row['household_no'] }}</span>
                                </td>
                                <td data-label="HH Head">{{ $row['house_head'] }}</td>
                                <td data-label="Water Supply Level">
                                    <span class="lml-eh-dashboard__level" role="status">
                                        {{ $row['water_supply_short'] }}
                                    </span>
                                </td>
                                <td data-label="Sanitation Services">
                                    @if ($hasPresence)
                                        <span
                                            class="lml-eh-dashboard__toilet-status lml-eh-dashboard__toilet-status--{{ $presence === 'with_toilet' ? 'with' : 'without' }}"
                                            role="status"
                                        >
                                            <x-environmental-health.toilet-icon
                                                :variant="$presence === 'with_toilet' ? 'with' : 'without'"
                                                size="sm"
                                            />
                                            <span>{{ $row['toilet_presence_label'] }}</span>
                                        </span>
                                    @else
                                        <span class="lml-eh-dashboard__dash" role="status">—</span>
                                    @endif
                                </td>
                                <td data-label="Actions">
                                    <div class="lml-eh-dashboard__actions" role="group" aria-label="Actions for {{ $row['household_no'] }}">
                                        <a
                                            href="{{ $row['view_url'] }}"
                                            class="lml-eh-dashboard__action-btn lml-eh-dashboard__action-btn--view lml-focus-ring"
                                            aria-label="View amenities for {{ $row['household_no'] }}"
                                        >
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                            <span>View</span>
                                        </a>
                                        @if ($actionMode === 'edit')
                                            <a
                                                href="{{ $row['edit_url'] }}"
                                                class="lml-eh-dashboard__action-btn lml-eh-dashboard__action-btn--edit lml-focus-ring"
                                                aria-label="Edit amenities for {{ $row['household_no'] }}"
                                            >
                                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                                <span>Edit</span>
                                            </a>
                                        @else
                                            <a
                                                href="{{ $row['edit_url'] }}"
                                                class="lml-eh-dashboard__action-btn lml-eh-dashboard__action-btn--add lml-focus-ring"
                                                aria-label="Add amenities for {{ $row['household_no'] }}"
                                            >
                                                <i class="bi bi-plus-square" aria-hidden="true"></i>
                                                <span>Add</span>
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr data-eh-empty-row>
                                <td colspan="5">
                                    <div class="lml-eh-dashboard__empty">
                                        <span class="lml-eh-dashboard__empty-icon" aria-hidden="true">
                                            <i class="bi bi-search"></i>
                                        </span>
                                        <p class="lml-eh-dashboard__empty-title">No household amenities records match your filters.</p>
                                        <p class="lml-eh-dashboard__empty-hint">Try clearing search or selecting All for each filter.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="lml-eh-dashboard__empty" data-eh-empty hidden>
                <span class="lml-eh-dashboard__empty-icon" aria-hidden="true">
                    <i class="bi bi-search"></i>
                </span>
                <p class="lml-eh-dashboard__empty-title">No household amenities records match your filters.</p>
                <p class="lml-eh-dashboard__empty-hint">Try clearing search or selecting All for each filter.</p>
            </div>
        </div>
    </div>
@endsection
