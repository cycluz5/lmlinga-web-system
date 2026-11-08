{{--
    Household Profiling — Family Planning Visit Records.
    Resident-specific. DB-13: MySQL for persisted residents; demo catalog fallback otherwise.
--}}
@extends('layouts.dashboard')

@section('title', ($demoMember['name'] ?? 'Member') . ' — Family Planning - LMLinga')

@section('content')
    @php
        use App\Support\DemoFamilyPlanning;

        $allowedFilters = ['all', 'this_month', 'last_3_months', 'this_year', 'custom'];
        $rawFilter = is_string(request('date')) ? trim((string) request('date')) : '';
        $filterDate = in_array($rawFilter, $allowedFilters, true) ? $rawFilter : '';
        $filterFrom = is_string(request('from')) ? trim((string) request('from')) : '';
        $filterTo = is_string(request('to')) ? trim((string) request('to')) : '';
        if ($filterFrom !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
            $filterFrom = '';
        }
        if ($filterTo !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
            $filterTo = '';
        }

        $allVisits = $visits ?? [];
        $filteredVisits = DemoFamilyPlanning::filterByDate(
            $allVisits,
            $filterDate !== '' ? $filterDate : null,
            $filterFrom !== '' ? $filterFrom : null,
            $filterTo !== '' ? $filterTo : null
        );
        $showCustomRange = $filterDate === 'custom';
        $stats = DemoFamilyPlanning::summaryStats($allVisits);
        $commoditiesSupported = (bool) ($commoditiesSupported ?? true);

        $createUrl = route('household-profiling.members.family-planning.create', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);
        $historyUrl = route('household-profiling.members.family-planning.index', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);
    @endphp

    <div
        class="lml-fp"
        data-lml-fp
        data-lml-fp-mode="history"
        data-demo="{{ ($canPersist ?? false) ? 'false' : 'true' }}"
        data-household-no="{{ $householdNo }}"
        data-member-id="{{ $memberId }}"
        @if ($demoMember)
            data-member-name="{{ $demoMember['name'] }}"
        @endif
    >
        @if (! $demoHousehold || ! $demoMember)
            <section class="lml-fp__not-found" aria-labelledby="lml-fp-nf-title">
                <h2 id="lml-fp-nf-title" class="lml-fp__not-found-title">
                    Member not found
                </h2>
                <p class="lml-fp__not-found-message">
                    @if (! $demoHousehold)
                        No record found.
                    @else
                        No record found.
                    @endif
                </p>
                <a
                    href="{{ $demoHousehold ? route('household-profiling.view', ['householdNo' => $householdNo]) : route('household-profiling.index') }}"
                    class="lml-fp__not-found-link lml-focus-ring"
                >
                    {{ $demoHousehold ? 'Back to Household' : 'Return to Household List' }}
                </a>
            </section>
        @else
            @include('pages.household-profiling.partials.family-planning-member-card', [
                'demoMember' => $demoMember,
                'householdNo' => $householdNo,
                'memberId' => $memberId,
                'totalVisits' => $stats['total_visits'],
                'lastVisitLabel' => $stats['last_visit_label'],
                'lastVisitIso' => $stats['last_visit'],
            ])

            <section
                class="lml-fp__panel"
                aria-labelledby="lml-fp-history-title"
                data-fp-history
            >
                <header class="lml-fp__panel-head">
                    <div class="lml-fp__panel-titles">
                        <h2 id="lml-fp-history-title" class="lml-fp__panel-title">
                            <i
                                class="bi bi-clipboard2-pulse lml-fp__panel-title-icon"
                                aria-hidden="true"
                            ></i>
                            <span>FAMILY PLANNING VISIT RECORDS</span>
                        </h2>
                        <p class="lml-fp__panel-subtitle">
                            @if ($commoditiesSupported)
                                Monitor family planning visits and commodities provided to clients.
                            @else
                                Monitor family planning visits for this resident.
                            @endif
                        </p>
                    </div>

                    <div class="lml-fp__panel-controls">
                        <form
                            id="lml-fp-date-filter-form"
                            class="lml-fp__date-filter"
                            method="get"
                            action="{{ $historyUrl }}"
                            data-fp-date-filter
                        >
                            <div class="lml-fp__date-control">
                                <i
                                    class="bi bi-funnel lml-fp__date-control-icon"
                                    data-fp-date-icon-funnel
                                    @if ($showCustomRange) hidden @endif
                                    aria-hidden="true"
                                ></i>
                                <i
                                    class="bi bi-calendar3 lml-fp__date-control-icon"
                                    data-fp-date-icon-calendar
                                    @unless ($showCustomRange) hidden @endunless
                                    aria-hidden="true"
                                ></i>
                                <select
                                    id="lml-fp-date"
                                    name="date"
                                    class="lml-fp__date-select lml-focus-ring"
                                    data-fp-date-select
                                    aria-label="Filter family planning visits by visit date"
                                    aria-controls="lml-fp-custom-range"
                                >
                                    <option value="" @selected($filterDate === '')>Date</option>
                                    <option value="all" @selected($filterDate === 'all')>All Dates</option>
                                    <option value="this_month" @selected($filterDate === 'this_month')>This Month</option>
                                    <option value="last_3_months" @selected($filterDate === 'last_3_months')>Last 3 Months</option>
                                    <option value="this_year" @selected($filterDate === 'this_year')>This Year</option>
                                    <option value="custom" @selected($filterDate === 'custom')>Custom range</option>
                                </select>
                                <i
                                    class="bi bi-chevron-down lml-fp__date-control-chevron"
                                    aria-hidden="true"
                                ></i>
                            </div>
                        </form>

                        <a
                            href="{{ $createUrl }}"
                            class="lml-fp__add-btn lml-focus-ring"
                            data-fp-add
                            aria-label="Add family planning visit record for {{ $demoMember['name'] }}"
                        >
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                            <span>ADD</span>
                        </a>
                    </div>
                </header>

                <div
                    id="lml-fp-custom-range"
                    class="lml-fp__custom-range"
                    data-fp-custom-range
                    @unless ($showCustomRange) hidden @endunless
                >
                    <div class="lml-fp__custom-range-fields">
                        <div class="lml-fp__custom-range-field">
                            <label class="lml-fp__custom-range-label" for="lml-fp-date-from">
                                From
                            </label>
                            <input
                                id="lml-fp-date-from"
                                type="date"
                                name="from"
                                form="lml-fp-date-filter-form"
                                value="{{ $filterFrom }}"
                                class="lml-fp__custom-range-input lml-focus-ring"
                                data-fp-date-from
                                @unless ($showCustomRange) disabled @endunless
                                autocomplete="off"
                            >
                        </div>
                        <div class="lml-fp__custom-range-field">
                            <label class="lml-fp__custom-range-label" for="lml-fp-date-to">
                                To
                            </label>
                            <input
                                id="lml-fp-date-to"
                                type="date"
                                name="to"
                                form="lml-fp-date-filter-form"
                                value="{{ $filterTo }}"
                                class="lml-fp__custom-range-input lml-focus-ring"
                                data-fp-date-to
                                @unless ($showCustomRange) disabled @endunless
                                autocomplete="off"
                            >
                        </div>
                    </div>
                </div>

                @if (count($allVisits) === 0)
                    <div class="lml-fp__empty" data-fp-empty role="status">
                        <p class="lml-fp__empty-title">
                            No family planning visits recorded for this resident.
                        </p>
                        <p class="lml-fp__empty-hint">
                            Use ADD when a health worker records a family planning visit.
                        </p>
                    </div>
                @elseif (count($filteredVisits) === 0)
                    <div class="lml-fp__empty" data-fp-empty-filtered role="status">
                        <p class="lml-fp__empty-title">
                            No family planning visits match the selected date.
                        </p>
                        <p class="lml-fp__empty-hint">
                            Select All Dates to see all visits for this resident.
                        </p>
                    </div>
                @else
                    <div class="lml-fp__table-scroll" tabindex="0">
                        <table class="lml-fp__table">
                            <caption class="visually-hidden">
                                Family planning visit records for {{ $demoMember['name'] }}
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">Visit Date</th>
                                    @if ($commoditiesSupported)
                                        <th scope="col">Commodities Given</th>
                                        <th scope="col">Total Quantity</th>
                                    @endif
                                    <th scope="col">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($filteredVisits as $row)
                                    @php
                                        $viewUrl = route('household-profiling.members.family-planning.show', [
                                            'householdNo' => $householdNo,
                                            'memberId' => $memberId,
                                            'visitId' => $row['id'],
                                        ]);
                                    @endphp
                                    <tr
                                        data-fp-row
                                        data-visited-at="{{ $row['visited_at'] }}"
                                    >
                                        <td>
                                            <span class="lml-fp__date-cell">
                                                <i class="bi bi-calendar3" aria-hidden="true"></i>
                                                <time datetime="{{ $row['visited_at'] }}">
                                                    {{ DemoFamilyPlanning::formatVisitDate((string) $row['visited_at']) }}
                                                </time>
                                            </span>
                                        </td>
                                        @if ($commoditiesSupported)
                                            <td>{{ $row['commodities_label'] }}</td>
                                            <td>{{ $row['total_quantity'] }}</td>
                                        @endif
                                        <td>
                                            <a
                                                href="{{ $viewUrl }}"
                                                class="lml-fp__view-link lml-focus-ring"
                                                data-fp-view
                                                aria-label="View family planning visit on {{ DemoFamilyPlanning::formatVisitDate((string) $row['visited_at']) }} for {{ $demoMember['name'] }}"
                                            >
                                                View
                                                <span aria-hidden="true">→</span>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    </div>
@endsection
