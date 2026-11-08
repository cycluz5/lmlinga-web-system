{{--
    Health Records — Death listing of persisted death_requests.
--}}
@extends('layouts.dashboard')

@section('title', 'Death - LMLinga')

@section('content')
    @php
        $records = $records ?? null;
        $filters = $filters ?? ['search' => '', 'zone' => 'all', 'cause' => 'all', 'sex' => 'all', 'year' => 'all', 'month' => 'all'];
        $zones = $zones ?? [];
        $causes = $causes ?? [];
        $years = $years ?? [];
        $months = $months ?? \App\Support\HealthRecordsDeath::monthOptions();
        $summary = $summary ?? ['total' => 0, 'female' => 0, 'male' => 0, 'pending' => 0];
        $reportBuilderQuery = array_filter(
            ['zone' => $filters['zone'] ?? 'all', 'year' => $filters['year'] ?? 'all', 'month' => $filters['month'] ?? 'all'],
            static fn (string $value): bool => $value !== '' && $value !== 'all'
        );
        $totalUnfiltered = $totalUnfiltered ?? 0;
        $filteredTotal = $records?->total() ?? 0;
        $hasDataset = $totalUnfiltered > 0;
        $hasFilteredRows = $filteredTotal > 0;
    @endphp

    <div
        class="lml-hr-death"
        data-lml-hr-death
        data-death-data-mode="persisted"
        data-total="{{ $totalUnfiltered }}"
    >
        <div class="lml-hr-death__panel">
            <header class="lml-hr-death__top">
                <div class="lml-hr-death__title-block">
                    
                </div>

                <div class="lml-hr-death__actions">
                    <a
                        href="{{ route('health-records.death.report-builder', $reportBuilderQuery) }}"
                        class="lml-hr-death__export-btn lml-focus-ring"
                        data-hr-death-export
                        aria-label="Choose report options before exporting Death Records as PDF"
                    >
                        <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                        <span>Export Data</span>
                    </a>
                </div>
            </header>

            <div
                class="lml-hr-death__stats"
                role="group"
                aria-label="Death barangay summary"
            >
                <article class="lml-hr-death__card lml-hr-death__card--total">
                    <div class="lml-hr-death__card-body">
                        <p class="lml-hr-death__card-label">Total Deaths</p>
                        <p class="lml-hr-death__card-value" data-death-stat="total">{{ $summary['total'] }}</p>
                    </div>
                    <span class="lml-hr-death__card-icon" aria-hidden="true">
                        <i class="bi bi-archive"></i>
                    </span>
                </article>

                <article class="lml-hr-death__card lml-hr-death__card--female">
                    <div class="lml-hr-death__card-body">
                        <p class="lml-hr-death__card-label">Female</p>
                        <p class="lml-hr-death__card-value" data-death-stat="female">{{ $summary['female'] }}</p>
                    </div>
                    <span class="lml-hr-death__card-icon" aria-hidden="true">
                        <i class="bi bi-gender-female"></i>
                    </span>
                </article>

                <article class="lml-hr-death__card lml-hr-death__card--male">
                    <div class="lml-hr-death__card-body">
                        <p class="lml-hr-death__card-label">Male</p>
                        <p class="lml-hr-death__card-value" data-death-stat="male">{{ $summary['male'] }}</p>
                    </div>
                    <span class="lml-hr-death__card-icon" aria-hidden="true">
                        <i class="bi bi-gender-male"></i>
                    </span>
                </article>

                <article class="lml-hr-death__card lml-hr-death__card--pending">
                    <div class="lml-hr-death__card-body">
                        <p class="lml-hr-death__card-label">Pending Count</p>
                        <p class="lml-hr-death__card-value" data-death-stat="pending">{{ $summary['pending'] }}</p>
                    </div>
                    <span class="lml-hr-death__card-icon" aria-hidden="true">
                        <i class="bi bi-hourglass-split"></i>
                    </span>
                </article>
            </div>

            <form
                class="lml-hr-death__filters"
                method="GET"
                action="{{ route('health-records.death.index') }}"
                role="search"
                aria-label="Death search and filters"
                data-hr-death-filter-form
            >
                <div class="lml-hr-death__search">
                    <label class="visually-hidden" for="lml-hr-death-search">Search Name</label>
                    <i class="bi bi-search lml-hr-death__search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="lml-hr-death-search"
                        name="search"
                        class="lml-hr-death__search-input lml-focus-ring"
                        data-hr-death-search
                        placeholder="Search Name"
                        value="{{ $filters['search'] }}"
                        autocomplete="off"
                    >
                </div>

                <div class="lml-hr-death__select-wrap">
                    <label class="visually-hidden" for="lml-hr-death-zone">Filter by zone</label>
                    <select
                        id="lml-hr-death-zone"
                        name="zone"
                        class="lml-hr-death__select lml-focus-ring"
                        data-hr-death-zone
                    >
                        <option value="all" @selected($filters['zone'] === 'all')>All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}" @selected($filters['zone'] === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-death__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-death__select-wrap">
                    <label class="visually-hidden" for="lml-hr-death-cause">Filter by cause of death</label>
                    <select
                        id="lml-hr-death-cause"
                        name="cause"
                        class="lml-hr-death__select lml-focus-ring"
                        data-hr-death-cause
                    >
                        <option value="all" @selected($filters['cause'] === 'all')>Cause of Death</option>
                        @foreach ($causes as $cause)
                            <option value="{{ $cause }}" @selected($filters['cause'] === $cause)>{{ $cause }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-death__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-death__select-wrap">
                    <label class="visually-hidden" for="lml-hr-death-sex">Filter by sex</label>
                    <select
                        id="lml-hr-death-sex"
                        name="sex"
                        class="lml-hr-death__select lml-focus-ring"
                        data-hr-death-sex
                    >
                        <option value="all" @selected($filters['sex'] === 'all')>Sex</option>
                        <option value="female" @selected($filters['sex'] === 'female')>Female</option>
                        <option value="male" @selected($filters['sex'] === 'male')>Male</option>
                    </select>
                    <i class="bi bi-chevron-down lml-hr-death__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-death__select-wrap">
                    <label class="visually-hidden" for="lml-hr-death-year">Filter by year</label>
                    <select
                        id="lml-hr-death-year"
                        name="year"
                        class="lml-hr-death__select lml-focus-ring"
                        data-hr-death-year
                    >
                        <option value="all" @selected($filters['year'] === 'all')>Year</option>
                        @foreach ($years as $year)
                            <option value="{{ $year }}" @selected($filters['year'] === $year)>{{ $year }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-death__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-death__select-wrap">
                    <label class="visually-hidden" for="lml-hr-death-month">Filter by month</label>
                    <select
                        id="lml-hr-death-month"
                        name="month"
                        class="lml-hr-death__select lml-focus-ring"
                        data-hr-death-month
                    >
                        <option value="all" @selected($filters['month'] === 'all')>Month</option>
                        @foreach ($months as $monthOption)
                            <option
                                value="{{ $monthOption['value'] }}"
                                @selected($filters['month'] === $monthOption['value'])
                            >
                                {{ $monthOption['label'] }}
                            </option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-death__select-icon" aria-hidden="true"></i>
                </div>
            </form>

            <p class="lml-hr-death__results visually-hidden" data-hr-death-results aria-live="polite">
                @if ($hasFilteredRows)
                    Showing page {{ $records->currentPage() }} of {{ $records->lastPage() }}
                    ({{ $filteredTotal }} of {{ $totalUnfiltered }} death records)
                @else
                    Showing 0 of {{ $totalUnfiltered }} death records
                @endif
            </p>

            <div class="lml-hr-death__table-card">
                <div
                    class="lml-hr-death__table-scroll"
                    tabindex="0"
                    aria-labelledby="lml-hr-death-heading"
                    aria-describedby="lml-hr-death-desc"
                    @if (! $hasFilteredRows) hidden @endif
                >
                    <table class="lml-hr-death__table">
                        <caption class="visually-hidden">
                            Submitted death records by name, age, birthday, sex, cause of death, and status.
                        </caption>
                        <colgroup>
                            <col class="lml-hr-death__col lml-hr-death__col--name">
                            <col class="lml-hr-death__col lml-hr-death__col--age">
                            <col class="lml-hr-death__col lml-hr-death__col--birthday">
                            <col class="lml-hr-death__col lml-hr-death__col--sex">
                            <col class="lml-hr-death__col lml-hr-death__col--cause">
                            <col class="lml-hr-death__col lml-hr-death__col--status">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Age</th>
                                <th scope="col">Birthday</th>
                                <th scope="col">Sex</th>
                                <th scope="col">Cause of Death</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody data-hr-death-tbody>
                            @foreach ($records ?? [] as $row)
                                <tr
                                    class="lml-hr-death__record-row"
                                    data-hr-death-row
                                    data-row-key="{{ $row['key'] }}"
                                >
                                    <th scope="row" class="lml-hr-death__cell lml-hr-death__cell--name">
                                        <a
                                            href="{{ $row['open_url'] }}"
                                            class="lml-hr-death__name-link lml-focus-ring"
                                            aria-label="Open death record for {{ $row['full_name'] }}"
                                        >
                                            {{ $row['full_name'] }}
                                        </a>
                                    </th>
                                    <td class="lml-hr-death__cell lml-hr-death__cell--age">
                                        {{ $row['age'] }}
                                    </td>
                                    <td class="lml-hr-death__cell lml-hr-death__cell--birthday">
                                        {{ $row['birthday'] }}
                                    </td>
                                    <td class="lml-hr-death__cell lml-hr-death__cell--sex">
                                        {{ $row['sex'] }}
                                    </td>
                                    <td class="lml-hr-death__cell lml-hr-death__cell--cause">
                                        {{ $row['cause_of_death'] }}
                                    </td>
                                    <td class="lml-hr-death__cell lml-hr-death__cell--status">
                                        <span
                                            class="lml-hr-death__status lml-hr-death__status--{{ $row['status'] }}"
                                        >
                                            {{ $row['status_label'] }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($records && $records->hasPages())
                    <nav
                        class="lml-hr-death__pagination"
                        aria-label="Death records pagination"
                        data-hr-death-pagination
                    >
                        <p class="lml-hr-death__pagination-summary" role="status" aria-live="polite">
                            Page {{ $records->currentPage() }} of {{ $records->lastPage() }}
                        </p>
                        <div class="lml-hr-death__pagination-controls">
                            @if ($records->onFirstPage())
                                <span
                                    class="lml-hr-death__pagination-btn"
                                    aria-disabled="true"
                                >
                                    Previous
                                </span>
                            @else
                                <a
                                    href="{{ $records->previousPageUrl() }}"
                                    class="lml-hr-death__pagination-btn lml-focus-ring"
                                    rel="prev"
                                >
                                    Previous
                                </a>
                            @endif

                            <div class="lml-hr-death__pagination-pages" aria-label="Death record pages">
                                @foreach ($records->getUrlRange(1, $records->lastPage()) as $page => $url)
                                    @if ($page === $records->currentPage())
                                        <span
                                            class="lml-hr-death__pagination-page lml-hr-death__pagination-page--active"
                                            aria-current="page"
                                        >
                                            {{ $page }}
                                        </span>
                                    @else
                                        <a
                                            href="{{ $url }}"
                                            class="lml-hr-death__pagination-page lml-focus-ring"
                                            aria-label="Go to page {{ $page }}"
                                        >
                                            {{ $page }}
                                        </a>
                                    @endif
                                @endforeach
                            </div>

                            @if ($records->hasMorePages())
                                <a
                                    href="{{ $records->nextPageUrl() }}"
                                    class="lml-hr-death__pagination-btn lml-focus-ring"
                                    rel="next"
                                >
                                    Next
                                </a>
                            @else
                                <span
                                    class="lml-hr-death__pagination-btn"
                                    aria-disabled="true"
                                >
                                    Next
                                </span>
                            @endif
                        </div>
                    </nav>
                @endif

                <div
                    class="lml-hr-death__empty"
                    data-hr-death-empty
                    role="status"
                    @if ($hasFilteredRows) hidden @endif
                >
                    <div class="lml-hr-death__empty-icon" aria-hidden="true">
                        <i class="bi bi-journal-x"></i>
                    </div>
                    <p class="lml-hr-death__empty-title" data-hr-death-empty-title>
                        @if ($hasDataset)
                            No death records match the selected filters.
                        @else
                            No death records have been recorded yet.
                        @endif
                    </p>
                    <p class="lml-hr-death__empty-hint" data-hr-death-empty-hint>
                        @if ($hasDataset)
                            Try adjusting search, zone, cause, sex, year, or month.
                        @else
                            Death records appear here after submission and Admin verification.
                        @endif
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
