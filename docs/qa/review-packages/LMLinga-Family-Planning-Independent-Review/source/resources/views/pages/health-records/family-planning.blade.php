{{--
    Health Records — Family Planning barangay-wide summary (Figma-aligned).

    Data: App\Support\HealthRecordsFamilyPlanning — UI-phase fixture only.
    Not mapped from Household Profiling DemoFamilyPlanning (separate module).
    Filters are client-side. Add / Export use UI-phase toasts.
--}}
@extends('layouts.dashboard')

@section('title', 'Family Planning - LMLinga')

@section('content')
    @php
        $rows = $rows ?? [];
        $zones = $zones ?? [];
        $years = $years ?? [];
        $summary = $summary ?? ['total' => 0, 'due' => 0, 'missed' => 0];
        $totalUnfiltered = $totalUnfiltered ?? count($rows);
        $pageDescription = 'Record and management of family planning details for monitoring and tracking reproductive health services.';
    @endphp

    <div
        class="lml-hr-fp"
        data-lml-hr-fp
        data-fp-data-mode="figma-preview"
        data-total="{{ $totalUnfiltered }}"
    >
        <div class="lml-hr-fp__panel">
            <header class="lml-hr-fp__top">
                <div class="lml-hr-fp__title-block">
                    <div class="lml-hr-fp__title-row">
                        <h2 class="lml-hr-fp__title" id="lml-hr-fp-heading">Family Planning</h2>
                        <span
                            class="lml-hr-fp__badge"
                            role="status"
                            aria-label="Non-residents client indicator"
                        >
                            Non - Residents Client
                        </span>
                    </div>
                    <p class="lml-hr-fp__description" id="lml-hr-fp-desc">
                        {{ $pageDescription }}
                    </p>
                </div>

                <div class="lml-hr-fp__actions" role="group" aria-label="Family Planning actions">
                    <button
                        type="button"
                        class="lml-hr-fp__add-btn lml-focus-ring"
                        data-hr-fp-add
                        aria-label="Add family planning record"
                    >
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        <span>Add</span>
                    </button>
                    <button
                        type="button"
                        class="lml-hr-fp__export-btn lml-focus-ring"
                        data-hr-fp-export
                        aria-label="Export Family Planning data"
                    >
                        <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                        <span>Export Data</span>
                    </button>
                </div>
            </header>

            <div
                class="lml-hr-fp__toast"
                data-hr-fp-toast
                role="status"
                aria-live="polite"
                hidden
            ></div>

            <div
                class="lml-hr-fp__stats"
                role="group"
                aria-label="Family planning barangay summary"
            >
                <article class="lml-hr-fp__card lml-hr-fp__card--total">
                    <div class="lml-hr-fp__card-body">
                        <p class="lml-hr-fp__card-label">Total FP Patients</p>
                        <p class="lml-hr-fp__card-value" data-fp-stat="total">{{ $summary['total'] }}</p>
                    </div>
                </article>

                <article class="lml-hr-fp__card lml-hr-fp__card--due">
                    <div class="lml-hr-fp__card-body">
                        <p class="lml-hr-fp__card-label">Due for Follow-ups</p>
                        <p class="lml-hr-fp__card-value lml-hr-fp__card-value--badge" data-fp-stat="due">
                            <span class="lml-hr-fp__count-oval">{{ $summary['due'] }}</span>
                        </p>
                    </div>
                </article>

                <article class="lml-hr-fp__card lml-hr-fp__card--missed">
                    <div class="lml-hr-fp__card-body">
                        <p class="lml-hr-fp__card-label">Missed for Follow-ups</p>
                        <p class="lml-hr-fp__card-value lml-hr-fp__card-value--badge" data-fp-stat="missed">
                            <span class="lml-hr-fp__count-oval">{{ $summary['missed'] }}</span>
                        </p>
                    </div>
                </article>
            </div>

            <div
                class="lml-hr-fp__filters"
                role="toolbar"
                aria-label="Family planning search and filters"
            >
                <div class="lml-hr-fp__search">
                    <label class="visually-hidden" for="lml-hr-fp-search">Search Name</label>
                    <i class="bi bi-search lml-hr-fp__search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="lml-hr-fp-search"
                        class="lml-hr-fp__search-input lml-focus-ring"
                        data-hr-fp-search
                        placeholder="Search Name"
                        autocomplete="off"
                    >
                </div>

                <div class="lml-hr-fp__select-wrap">
                    <label class="visually-hidden" for="lml-hr-fp-zone">Filter by zone</label>
                    <select
                        id="lml-hr-fp-zone"
                        class="lml-hr-fp__select lml-focus-ring"
                        data-hr-fp-zone
                    >
                        <option value="all">All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}">{{ $zone }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-fp__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-fp__select-wrap">
                    <label class="visually-hidden" for="lml-hr-fp-year">Filter by year</label>
                    <select
                        id="lml-hr-fp-year"
                        class="lml-hr-fp__select lml-focus-ring"
                        data-hr-fp-year
                    >
                        <option value="all">All Years</option>
                        @foreach ($years as $year)
                            <option value="{{ $year }}">{{ $year }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-fp__select-icon" aria-hidden="true"></i>
                </div>
            </div>

            <p class="lml-hr-fp__results visually-hidden" data-hr-fp-results aria-live="polite">
                Showing {{ $totalUnfiltered }} of {{ $totalUnfiltered }} family planning patients
            </p>

            <div class="lml-hr-fp__table-card">
                <div
                    class="lml-hr-fp__table-scroll"
                    tabindex="0"
                    aria-labelledby="lml-hr-fp-heading"
                    aria-describedby="lml-hr-fp-desc"
                >
                    <table class="lml-hr-fp__table">
                        <caption class="visually-hidden">
                            Barangay-wide family planning summary by full name,
                            age, method, start date, last visit, and next schedule.
                            Figures and rows are UI-phase preview/demo values.
                        </caption>
                        <colgroup>
                            <col class="lml-hr-fp__col lml-hr-fp__col--name">
                            <col class="lml-hr-fp__col lml-hr-fp__col--age">
                            <col class="lml-hr-fp__col lml-hr-fp__col--method">
                            <col class="lml-hr-fp__col lml-hr-fp__col--start">
                            <col class="lml-hr-fp__col lml-hr-fp__col--last">
                            <col class="lml-hr-fp__col lml-hr-fp__col--next">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Full Name</th>
                                <th scope="col">Age</th>
                                <th scope="col">Method</th>
                                <th scope="col">Start Date</th>
                                <th scope="col">Last Visit</th>
                                <th scope="col">Next Sched</th>
                            </tr>
                        </thead>
                        <tbody data-hr-fp-tbody>
                            @foreach ($rows as $row)
                                <tr
                                    data-hr-fp-row
                                    data-name="{{ strtolower($row['full_name']) }}"
                                    data-zone="{{ $row['zone'] }}"
                                    data-year="{{ $row['year'] }}"
                                    data-row-key="{{ $row['key'] }}"
                                >
                                    <th scope="row" class="lml-hr-fp__cell lml-hr-fp__cell--name">
                                        {{ $row['full_name'] }}
                                    </th>
                                    <td class="lml-hr-fp__cell lml-hr-fp__cell--age">
                                        {{ $row['age'] }}
                                    </td>
                                    <td class="lml-hr-fp__cell lml-hr-fp__cell--method">
                                        {{ $row['method'] }}
                                    </td>
                                    <td class="lml-hr-fp__cell lml-hr-fp__cell--date">
                                        {{ $row['start_date'] }}
                                    </td>
                                    <td class="lml-hr-fp__cell lml-hr-fp__cell--date">
                                        {{ $row['last_visit'] }}
                                    </td>
                                    <td class="lml-hr-fp__cell lml-hr-fp__cell--date">
                                        {{ $row['next_sched'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div
                    class="lml-hr-fp__empty"
                    data-hr-fp-empty
                    role="status"
                    hidden
                >
                    <div class="lml-hr-fp__empty-icon" aria-hidden="true">
                        <i class="bi bi-search"></i>
                    </div>
                    <p class="lml-hr-fp__empty-title">
                        No family planning records match the selected filters.
                    </p>
                    <p class="lml-hr-fp__empty-hint">Try adjusting search, zone, or year.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
