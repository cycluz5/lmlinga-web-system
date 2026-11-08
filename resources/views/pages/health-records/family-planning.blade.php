{{--
    Health Records — Family Planning barangay-wide summary (Figma-aligned).

    Data: App\Support\HealthRecordsFamilyPlanning — persisted family planning
    records only. Filters are client-side. Export Data lands on the Report
    Builder's choices page (zone/year/month) before generating the PDF.
--}}
@extends('layouts.dashboard')

@section('title', 'Family Planning - LMLinga')

@section('content')
    @php
        $rows = $rows ?? [];
        $zones = $zones ?? [];
        $years = $years ?? [];
        $summary = $summary ?? ['total' => 0, 'commodities' => []];
        $totalUnfiltered = $totalUnfiltered ?? count($rows);
        $hasRecords = $totalUnfiltered > 0;
        $commodities = $summary['commodities'] ?? [];
    @endphp

    <div
        class="lml-hr-fp"
        data-lml-hr-fp
        data-fp-data-mode="persisted"
        data-total="{{ $totalUnfiltered }}"
        data-has-records="{{ $hasRecords ? '1' : '0' }}"
        data-export-url="{{ route('health-records.family-planning.report-builder') }}"
    >
        <div class="lml-hr-fp__panel">
            <h2 class="visually-hidden" id="lml-hr-fp-heading">Family Planning</h2>
            <p class="visually-hidden" id="lml-hr-fp-desc">
                Family planning records for monitoring and tracking reproductive health services.
            </p>

            <div class="lml-hr-fp__action-row" data-hr-fp-action-row>
                <div class="lml-hr-fp__actions" role="group" aria-label="Family Planning actions">
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
            </div>

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

                <article class="lml-hr-fp__card lml-hr-fp__card--commodities">
                    <div class="lml-hr-fp__card-body">
                        <p class="lml-hr-fp__card-label">Commodities Type Users</p>
                        <div
                            class="lml-hr-fp__commodities"
                            data-fp-stat="commodities"
                            aria-live="polite"
                        >
                            @if ($commodities === [])
                                <p class="lml-hr-fp__commodities-empty">No commodities recorded</p>
                            @else
                                <ul class="lml-hr-fp__commodities-list">
                                    @foreach ($commodities as $item)
                                        <li class="lml-hr-fp__commodities-item">
                                            <span class="lml-hr-fp__commodities-name">{{ $item['name'] }}</span>
                                            <span class="lml-hr-fp__commodities-count">{{ $item['count'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
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
                    @if (! $hasRecords) hidden @endif
                >
                    <table class="lml-hr-fp__table">
                        <caption class="visually-hidden">
                            Barangay-wide family planning summary by full name,
                            age, method, start date, last visit, and next schedule.
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
                                @php
                                    $viewUrl = (string) ($row['view_url'] ?? '');
                                    $method = (string) ($row['method'] ?? '');
                                @endphp
                                <tr
                                    data-hr-fp-row
                                    data-name="{{ strtolower($row['full_name']) }}"
                                    data-zone="{{ $row['zone'] }}"
                                    data-year="{{ $row['year'] }}"
                                    data-method="{{ $method }}"
                                    data-row-key="{{ $row['key'] }}"
                                >
                                    <th scope="row" class="lml-hr-fp__cell lml-hr-fp__cell--name">
                                        @if ($viewUrl !== '')
                                            <a
                                                href="{{ $viewUrl }}"
                                                class="lml-hr-fp__name-link lml-focus-ring"
                                                aria-label="Open family planning for {{ $row['full_name'] }}"
                                            >
                                                {{ $row['full_name'] }}
                                            </a>
                                        @else
                                            {{ $row['full_name'] }}
                                        @endif
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
                    @if ($hasRecords) hidden @endif
                >
                    <div class="lml-hr-fp__empty-icon" aria-hidden="true">
                        <i class="bi bi-{{ $hasRecords ? 'search' : 'inbox' }}"></i>
                    </div>
                    <p
                        class="lml-hr-fp__empty-title"
                        data-hr-fp-empty-no-records
                        @if ($hasRecords) hidden @endif
                    >
                        No family planning records are available.
                    </p>
                    <p
                        class="lml-hr-fp__empty-title"
                        data-hr-fp-empty-filtered
                        hidden
                    >
                        No family planning records match the selected filters.
                    </p>
                    <p
                        class="lml-hr-fp__empty-hint"
                        data-hr-fp-empty-hint
                        @if (! $hasRecords) hidden @endif
                    >
                        Try adjusting search, zone, or year.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
