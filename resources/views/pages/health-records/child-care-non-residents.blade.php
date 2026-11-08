{{--
    Health Records → Child Care → Non-Residents listing (Figma).
    UI-phase fixture; filters are client-side. Classification is full-name lookup.
--}}
@extends('layouts.dashboard')

@section('title', 'Child Care | Non-Residents - LMLinga')

@section('content')
    @php
        $rows = $rows ?? [];
        $barangays = $barangays ?? [];
        $years = $years ?? [];
        $totalUnfiltered = $totalUnfiltered ?? count($rows);
        $createUrl = route('health-records.child-care.non-residents.create');
        $childCareUrl = route('health-records.child-care.index');
    @endphp

    <div
        class="lml-hr-cc-nr"
        data-lml-hr-cc-nr
        data-lml-hr-cc-nr-mode="listing"
        data-total="{{ $totalUnfiltered }}"
    >
        <div class="lml-hr-cc-nr__panel">
            <h2 class="visually-hidden" id="lml-hr-cc-nr-heading">Non-resident child care records</h2>

            <header class="lml-hr-cc-nr__top lml-hr-cc-nr__top--actions-only">
                <a
                    href="{{ $childCareUrl }}"
                    class="lml-hr-cc-nr__listing-back lml-focus-ring"
                    data-hr-cc-nr-back
                >
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back</span>
                </a>
                <div class="lml-hr-cc-nr__actions">
                    <a
                        href="{{ $createUrl }}"
                        class="lml-hr-cc-nr__add-btn lml-focus-ring"
                        data-hr-cc-nr-add
                    >
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        <span>Add</span>
                    </a>
                </div>
            </header>

            <div
                class="lml-hr-cc-nr__filters"
                role="toolbar"
                aria-label="Non-resident child search and filters"
            >
                <div class="lml-hr-cc-nr__search">
                    <label class="visually-hidden" for="lml-hr-cc-nr-search">Search Name</label>
                    <i class="bi bi-search lml-hr-cc-nr__search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="lml-hr-cc-nr-search"
                        class="lml-hr-cc-nr__search-input lml-focus-ring"
                        data-hr-cc-nr-search
                        placeholder="Search Name"
                        autocomplete="off"
                    >
                </div>

                <div class="lml-hr-cc-nr__select-wrap">
                    <label class="visually-hidden" for="lml-hr-cc-nr-barangay">Filter by barangay</label>
                    <select
                        id="lml-hr-cc-nr-barangay"
                        class="lml-hr-cc-nr__select lml-focus-ring"
                        data-hr-cc-nr-barangay
                    >
                        <option value="all">Barangay</option>
                        @foreach ($barangays as $barangay)
                            <option value="{{ $barangay }}">{{ $barangay }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-cc-nr__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-cc-nr__select-wrap">
                    <label class="visually-hidden" for="lml-hr-cc-nr-year">Filter by year</label>
                    <select
                        id="lml-hr-cc-nr-year"
                        class="lml-hr-cc-nr__select lml-focus-ring"
                        data-hr-cc-nr-year
                    >
                        <option value="all">Year</option>
                        @foreach ($years as $year)
                            <option value="{{ $year }}">{{ $year }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-cc-nr__select-icon" aria-hidden="true"></i>
                </div>
            </div>

            <p class="lml-hr-cc-nr__results visually-hidden" data-hr-cc-nr-results aria-live="polite">
                Showing {{ $totalUnfiltered }} of {{ $totalUnfiltered }} non-resident children
            </p>

            <div class="lml-hr-cc-nr__table-card">
                <div
                    class="lml-hr-cc-nr__table-scroll"
                    tabindex="0"
                    aria-labelledby="lml-hr-cc-nr-heading"
                >
                    <table class="lml-hr-cc-nr__table">
                        <caption class="visually-hidden">
                            Non-resident child care records by full name, age, and health status.
                            UI-phase preview data only.
                        </caption>
                        <colgroup>
                            <col class="lml-hr-cc-nr__col lml-hr-cc-nr__col--name">
                            <col class="lml-hr-cc-nr__col lml-hr-cc-nr__col--age">
                            <col class="lml-hr-cc-nr__col lml-hr-cc-nr__col--health">
                            <col class="lml-hr-cc-nr__col lml-hr-cc-nr__col--action">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Full Name</th>
                                <th scope="col">Age</th>
                                <th scope="col">Health Status</th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody data-hr-cc-nr-tbody>
                            @forelse ($rows as $row)
                                <tr
                                    data-hr-cc-nr-row
                                    data-name="{{ \App\Support\HealthRecordsNonResidentChildCare::normalizeFullName($row['full_name']) }}"
                                    data-barangay="{{ $row['barangay'] }}"
                                    data-year="{{ $row['year'] }}"
                                >
                                    <th scope="row" class="lml-hr-cc-nr__cell lml-hr-cc-nr__cell--name">
                                        {{ $row['full_name'] }}
                                    </th>
                                    <td class="lml-hr-cc-nr__cell lml-hr-cc-nr__cell--age">{{ $row['age_label'] }}</td>
                                    <td class="lml-hr-cc-nr__cell lml-hr-cc-nr__cell--health">{{ $row['health_status'] }}</td>
                                    <td class="lml-hr-cc-nr__cell lml-hr-cc-nr__cell--action">
                                        <a
                                            href="{{ $row['view_url'] }}"
                                            class="lml-hr-cc-nr__view-btn lml-focus-ring"
                                            aria-label="View non-resident child record for {{ $row['full_name'] }}"
                                        >
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                            <span>View</span>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr data-hr-cc-nr-empty-row>
                                    <td colspan="4">No non-resident child records are available.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div
                    class="lml-hr-cc-nr__empty"
                    data-hr-cc-nr-empty
                    role="status"
                    hidden
                >
                    <div class="lml-hr-cc-nr__empty-icon" aria-hidden="true">
                        <i class="bi bi-search"></i>
                    </div>
                    <p class="lml-hr-cc-nr__empty-title">
                        No non-resident child records match the selected filters.
                    </p>
                    <p class="lml-hr-cc-nr__empty-hint">Try adjusting the search, barangay, or year.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
