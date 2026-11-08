{{--
    Health Records — Risk Assessment barangay-wide summary.

    Data: App\Support\HealthRecordsRiskAssessment — persisted risk assessment
    records only. Filters are client-side.
--}}
@extends('layouts.dashboard')

@section('title', 'Risk Assessment - LMLinga')

@section('content')
    @php
        $rows = $rows ?? [];
        $zones = $zones ?? [];
        $years = $years ?? [];
        $months = $months ?? \App\Support\HealthRecordsRiskAssessment::monthOptions();
        $summary = $summary ?? [
            'this_year' => 0,
            'this_month' => 0,
            'this_year_label' => now()->format('Y'),
            'this_month_label' => now()->format('F Y'),
        ];
        $totalUnfiltered = $totalUnfiltered ?? count($rows);
        $hasRecords = $totalUnfiltered > 0;
        $currentYear = (string) ($summary['this_year_label'] ?? now()->format('Y'));
        $currentMonth = now()->format('m');
    @endphp

    <div
        class="lml-hr-risk"
        data-lml-hr-risk
        data-ra-data-mode="persisted"
        data-total="{{ $totalUnfiltered }}"
        data-has-records="{{ $hasRecords ? '1' : '0' }}"
        data-current-year="{{ $currentYear }}"
        data-current-month="{{ $currentMonth }}"
        data-export-url="{{ route('health-records.risk-assessment.report-builder') }}"
    >
        <div class="lml-hr-risk__panel">
            <h2 class="visually-hidden" id="lml-hr-risk-heading">Risk Assessment</h2>
            <p class="visually-hidden" id="lml-hr-risk-desc">
                Risk assessment records for monitoring and tracking health risks.
            </p>

            <div class="lml-hr-risk__action-row" data-hr-ra-action-row>
                <div class="lml-hr-risk__actions" role="group" aria-label="Risk Assessment actions">
                    <button
                        type="button"
                        class="lml-hr-risk__export-btn lml-focus-ring"
                        data-hr-ra-export
                        aria-label="Export Risk Assessment data"
                    >
                        <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                        <span>Export Data</span>
                    </button>
                </div>
            </div>

            <div
                class="lml-hr-risk__toast"
                data-hr-ra-toast
                role="status"
                aria-live="polite"
                hidden
            ></div>

            <div
                class="lml-hr-risk__stats"
                role="group"
                aria-label="Risk assessment barangay summary"
            >
                <article class="lml-hr-risk__card lml-hr-risk__card--year">
                    <div class="lml-hr-risk__card-body">
                        <p class="lml-hr-risk__card-label">
                            Total Assessed This Year
                            <span class="lml-hr-risk__card-sublabel" data-ra-year-label>
                                ({{ $summary['this_year_label'] }})
                            </span>
                        </p>
                        <p class="lml-hr-risk__card-value" data-ra-stat="this-year">{{ $summary['this_year'] }}</p>
                    </div>
                </article>

                <article class="lml-hr-risk__card lml-hr-risk__card--month">
                    <div class="lml-hr-risk__card-body">
                        <p class="lml-hr-risk__card-label">
                            Total Assessed This Month
                            <span class="lml-hr-risk__card-sublabel" data-ra-month-label>
                                ({{ $summary['this_month_label'] }})
                            </span>
                        </p>
                        <p class="lml-hr-risk__card-value" data-ra-stat="this-month">{{ $summary['this_month'] }}</p>
                    </div>
                </article>
            </div>

            <div
                class="lml-hr-risk__filters"
                role="toolbar"
                aria-label="Risk assessment search and filters"
            >
                <div class="lml-hr-risk__search">
                    <label class="visually-hidden" for="lml-hr-ra-search">Search Name</label>
                    <i class="bi bi-search lml-hr-risk__search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="lml-hr-ra-search"
                        class="lml-hr-risk__search-input lml-focus-ring"
                        data-hr-ra-search
                        placeholder="Search Name"
                        autocomplete="off"
                    >
                </div>

                <div class="lml-hr-risk__select-wrap">
                    <label class="visually-hidden" for="lml-hr-ra-zone">Filter by zone</label>
                    <select
                        id="lml-hr-ra-zone"
                        class="lml-hr-risk__select lml-focus-ring"
                        data-hr-ra-zone
                    >
                        <option value="all">All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}">{{ $zone }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-risk__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-risk__select-wrap">
                    <label class="visually-hidden" for="lml-hr-ra-year">Filter by year</label>
                    <select
                        id="lml-hr-ra-year"
                        class="lml-hr-risk__select lml-focus-ring"
                        data-hr-ra-year
                    >
                        <option value="all">All Years</option>
                        @foreach ($years as $year)
                            <option value="{{ $year }}">{{ $year }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-risk__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-risk__select-wrap">
                    <label class="visually-hidden" for="lml-hr-ra-month">Filter by month</label>
                    <select
                        id="lml-hr-ra-month"
                        class="lml-hr-risk__select lml-focus-ring"
                        data-hr-ra-month
                    >
                        <option value="all">Month</option>
                        @foreach ($months as $monthOption)
                            <option value="{{ $monthOption['value'] }}">{{ $monthOption['label'] }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-risk__select-icon" aria-hidden="true"></i>
                </div>
            </div>

            <p class="lml-hr-risk__results visually-hidden" data-hr-ra-results aria-live="polite">
                Showing {{ $totalUnfiltered }} of {{ $totalUnfiltered }} assessed clients
            </p>

            <div class="lml-hr-risk__table-card">
                <div
                    class="lml-hr-risk__table-scroll"
                    tabindex="0"
                    aria-labelledby="lml-hr-risk-heading"
                    aria-describedby="lml-hr-risk-desc"
                    @if (! $hasRecords) hidden @endif
                >
                    <table class="lml-hr-risk__table">
                        <caption class="visually-hidden">
                            Risk assessment records by name, age, birthday, date assessed, BMI, and BP.
                        </caption>
                        <colgroup>
                            <col class="lml-hr-risk__col lml-hr-risk__col--name">
                            <col class="lml-hr-risk__col lml-hr-risk__col--age">
                            <col class="lml-hr-risk__col lml-hr-risk__col--birthday">
                            <col class="lml-hr-risk__col lml-hr-risk__col--date">
                            <col class="lml-hr-risk__col lml-hr-risk__col--bmi">
                            <col class="lml-hr-risk__col lml-hr-risk__col--bp">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Age</th>
                                <th scope="col">Birthday</th>
                                <th scope="col">Date Assessed</th>
                                <th scope="col">BMI</th>
                                <th scope="col">BP</th>
                            </tr>
                        </thead>
                        <tbody data-hr-ra-tbody>
                            @foreach ($rows as $row)
                                @php
                                    $viewUrl = (string) ($row['view_url'] ?? '');
                                @endphp
                                <tr
                                    data-hr-ra-row
                                    data-name="{{ strtolower($row['full_name']) }}"
                                    data-zone="{{ $row['zone'] }}"
                                    data-year="{{ $row['year'] }}"
                                    data-month="{{ $row['month'] ?? '' }}"
                                    data-row-key="{{ $row['key'] }}"
                                    data-member-id="{{ $row['member_id'] }}"
                                    data-birthday="{{ $row['birthday_iso'] ?? '' }}"
                                >
                                    <th scope="row" class="lml-hr-risk__cell lml-hr-risk__cell--name">
                                        @if ($viewUrl !== '')
                                            <a
                                                href="{{ $viewUrl }}"
                                                class="lml-hr-risk__name-link lml-focus-ring"
                                                aria-label="Open risk assessment for {{ $row['full_name'] }}"
                                            >
                                                {{ $row['full_name'] }}
                                            </a>
                                        @else
                                            {{ $row['full_name'] }}
                                        @endif
                                    </th>
                                    <td class="lml-hr-risk__cell">{{ $row['age'] }}</td>
                                    <td class="lml-hr-risk__cell">{{ $row['birthday'] }}</td>
                                    <td class="lml-hr-risk__cell">{{ $row['date_assessed'] }}</td>
                                    <td class="lml-hr-risk__cell lml-hr-risk__cell--status">
                                        {{ $row['bmi_status'] }}
                                    </td>
                                    <td class="lml-hr-risk__cell lml-hr-risk__cell--status">
                                        {{ $row['bp_status'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div
                    class="lml-hr-risk__empty"
                    data-hr-ra-empty
                    role="status"
                    @if ($hasRecords) hidden @endif
                >
                    <div class="lml-hr-risk__empty-icon" aria-hidden="true">
                        <i class="bi bi-{{ $hasRecords ? 'search' : 'inbox' }}"></i>
                    </div>
                    <p
                        class="lml-hr-risk__empty-title"
                        data-hr-ra-empty-no-records
                        @if ($hasRecords) hidden @endif
                    >
                        No risk assessment records are available.
                    </p>
                    <p
                        class="lml-hr-risk__empty-title"
                        data-hr-ra-empty-filtered
                        hidden
                    >
                        No risk assessment records match the selected filters.
                    </p>
                    <p
                        class="lml-hr-risk__empty-hint"
                        data-hr-ra-empty-hint
                        @if (! $hasRecords) hidden @endif
                    >
                        Try adjusting search, zone, year, or month.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
