{{--
    Health Records → Family Planning → Non-Residents listing (Figma).
    UI-phase fixture; filters are client-side. Export is a preview toast.
--}}
@extends('layouts.dashboard')

@section('title', 'Family Planning | Non Residents - LMLinga')

@section('content')
    @php
        use App\Support\HealthRecordsNonResidentFamilyPlanning;

        $clients = $clients ?? [];
        $barangays = $barangays ?? [];
        $years = $years ?? [];
        $totalUnfiltered = $totalUnfiltered ?? count($clients);
        $summaryUrl = route('health-records.family-planning.index');
        $createUrl = route('health-records.family-planning.non-residents.create');
    @endphp

    <div
        class="lml-hr-fp-nr"
        data-lml-hr-fp-nr
        data-lml-hr-fp-nr-mode="listing"
        data-total="{{ $totalUnfiltered }}"
    >
        <div class="lml-hr-fp-nr__panel">
            <h2 class="visually-hidden" id="lml-hr-fp-nr-heading">Family Planning | Non Residents</h2>
            <p class="visually-hidden" id="lml-hr-fp-nr-desc">
                List of all non-resident clients who received family planning services in this barangay.
            </p>

            <header class="lml-hr-fp-nr__top lml-hr-fp-nr__top--actions-only" data-hr-fp-nr-listing-header>
                <a
                    href="{{ $summaryUrl }}"
                    class="lml-hr-fp-nr__back-btn lml-focus-ring"
                    data-hr-fp-nr-back
                >
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back</span>
                </a>

                <div
                    class="lml-hr-fp-nr__actions"
                    role="group"
                    aria-label="Non-resident client actions"
                    data-hr-fp-nr-action-group
                >
                    <a
                        href="{{ $createUrl }}"
                        class="lml-hr-fp-nr__add-btn lml-focus-ring"
                        data-hr-fp-nr-add
                    >
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        <span>Add Visit</span>
                    </a>
                    <button
                        type="button"
                        class="lml-hr-fp-nr__export-btn lml-focus-ring"
                        data-hr-fp-nr-export
                        aria-label="Export non-resident family planning data"
                    >
                        <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                        <span>Export Data</span>
                    </button>
                </div>
            </header>

            <div
                class="lml-hr-fp-nr__toast"
                data-hr-fp-nr-toast
                role="status"
                aria-live="polite"
                hidden
            ></div>

            <div
                class="lml-hr-fp-nr__filters"
                role="toolbar"
                aria-label="Non-resident client search and filters"
            >
                <div class="lml-hr-fp-nr__search">
                    <label class="visually-hidden" for="lml-hr-fp-nr-search">Search Name</label>
                    <i class="bi bi-search lml-hr-fp-nr__search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="lml-hr-fp-nr-search"
                        class="lml-hr-fp-nr__search-input lml-focus-ring"
                        data-hr-fp-nr-search
                        placeholder="Search Name"
                        autocomplete="off"
                    >
                </div>

                <div class="lml-hr-fp-nr__select-wrap">
                    <label class="visually-hidden" for="lml-hr-fp-nr-barangay">Filter by barangay</label>
                    <select
                        id="lml-hr-fp-nr-barangay"
                        class="lml-hr-fp-nr__select lml-focus-ring"
                        data-hr-fp-nr-barangay
                    >
                        <option value="all">Barangay</option>
                        @foreach ($barangays as $barangay)
                            <option value="{{ $barangay }}">{{ $barangay }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-fp-nr__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-fp-nr__select-wrap lml-hr-fp-nr__select-wrap--year">
                    <label class="visually-hidden" for="lml-hr-fp-nr-year">Filter by year</label>
                    <select
                        id="lml-hr-fp-nr-year"
                        class="lml-hr-fp-nr__select lml-focus-ring"
                        data-hr-fp-nr-year
                    >
                        <option value="all">Year</option>
                        @foreach ($years as $year)
                            <option value="{{ $year }}">{{ $year }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-fp-nr__select-icon" aria-hidden="true"></i>
                </div>
            </div>

            <div class="lml-hr-fp-nr__table-card">
                <div
                    class="lml-hr-fp-nr__table-scroll"
                    tabindex="0"
                    aria-labelledby="lml-hr-fp-nr-heading"
                    aria-describedby="lml-hr-fp-nr-desc"
                >
                    <table class="lml-hr-fp-nr__table">
                        <caption class="visually-hidden">
                            Non-resident family planning clients by full name, age, method, start date, and last visit.
                            UI-phase preview data only.
                        </caption>
                        <colgroup>
                            <col class="lml-hr-fp-nr__col lml-hr-fp-nr__col--name">
                            <col class="lml-hr-fp-nr__col lml-hr-fp-nr__col--age">
                            <col class="lml-hr-fp-nr__col lml-hr-fp-nr__col--method">
                            <col class="lml-hr-fp-nr__col lml-hr-fp-nr__col--date">
                            <col class="lml-hr-fp-nr__col lml-hr-fp-nr__col--date">
                            <col class="lml-hr-fp-nr__col lml-hr-fp-nr__col--actions">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Full Name</th>
                                <th scope="col">Age</th>
                                <th scope="col">Method</th>
                                <th scope="col">Start Date</th>
                                <th scope="col">Last Visit</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody data-hr-fp-nr-tbody>
                            @foreach ($clients as $client)
                                @php
                                    $showUrl = route('health-records.family-planning.non-residents.show', [
                                        'clientKey' => $client['key'],
                                    ]);
                                    $latestVisit = HealthRecordsNonResidentFamilyPlanning::latestVisit($client);
                                    $editUrl = $latestVisit !== null
                                        ? route('health-records.family-planning.non-residents.visits.edit', [
                                            'clientKey' => $client['key'],
                                            'visitId' => $latestVisit['id'],
                                        ])
                                        : route('health-records.family-planning.non-residents.visits.create', [
                                            'clientKey' => $client['key'],
                                        ]);
                                    $editAriaLabel = $latestVisit !== null
                                        ? 'Edit latest visit for '.$client['full_name']
                                        : 'Add visit for '.$client['full_name'];
                                @endphp
                                <tr
                                    data-hr-fp-nr-row
                                    data-name="{{ strtolower($client['full_name']) }}"
                                    data-barangay="{{ $client['barangay'] }}"
                                    data-year="{{ $client['year'] }}"
                                >
                                    <th scope="row" class="lml-hr-fp-nr__cell lml-hr-fp-nr__cell--name">
                                        <a
                                            href="{{ $showUrl }}"
                                            class="lml-hr-fp-nr__row-link lml-focus-ring"
                                        >
                                            {{ $client['full_name'] }}
                                        </a>
                                    </th>
                                    <td class="lml-hr-fp-nr__cell lml-hr-fp-nr__cell--age">{{ $client['age'] ?? '—' }}</td>
                                    <td class="lml-hr-fp-nr__cell lml-hr-fp-nr__cell--method">{{ $client['method'] }}</td>
                                    <td class="lml-hr-fp-nr__cell lml-hr-fp-nr__cell--date">{{ $client['start_date'] }}</td>
                                    <td class="lml-hr-fp-nr__cell lml-hr-fp-nr__cell--date">{{ $client['last_visit'] }}</td>
                                    <td class="lml-hr-fp-nr__cell lml-hr-fp-nr__cell--actions">
                                        <div class="lml-hr-fp-nr__row-actions" role="group" aria-label="Actions for {{ $client['full_name'] }}">
                                            <a
                                                href="{{ $showUrl }}"
                                                class="lml-hr-fp-nr__action-btn lml-hr-fp-nr__action-btn--view lml-focus-ring"
                                                aria-label="View {{ $client['full_name'] }}"
                                            >
                                                <i class="bi bi-eye" aria-hidden="true"></i>
                                                <span>View</span>
                                            </a>
                                            <a
                                                href="{{ $editUrl }}"
                                                class="lml-hr-fp-nr__action-btn lml-hr-fp-nr__action-btn--edit lml-focus-ring"
                                                aria-label="{{ $editAriaLabel }}"
                                            >
                                                <i class="bi bi-pencil" aria-hidden="true"></i>
                                                <span>Edit</span>
                                            </a>
                                            <button
                                                type="button"
                                                class="lml-hr-fp-nr__action-btn lml-hr-fp-nr__action-btn--delete lml-focus-ring"
                                                data-hr-fp-nr-delete-client
                                                data-client-key="{{ $client['key'] }}"
                                                data-client-name="{{ $client['full_name'] }}"
                                                aria-label="Delete {{ $client['full_name'] }}"
                                            >
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                                <span>Delete</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div
                    class="lml-hr-fp-nr__empty"
                    data-hr-fp-nr-empty
                    role="status"
                    hidden
                >
                    <div class="lml-hr-fp-nr__empty-icon" aria-hidden="true">
                        <i class="bi bi-search"></i>
                    </div>
                    <p class="lml-hr-fp-nr__empty-title">
                        No non-resident clients match the selected filters.
                    </p>
                    <p class="lml-hr-fp-nr__empty-hint">Try adjusting search, barangay, or year.</p>
                </div>

                <div class="lml-hr-fp-nr__table-foot">
                    <p class="lml-hr-fp-nr__results" data-hr-fp-nr-results aria-live="polite">
                        Showing 1 to {{ $totalUnfiltered }} of {{ $totalUnfiltered }} entries
                    </p>
                    <nav class="lml-hr-fp-nr__pager" aria-label="Non-resident client listing pages">
                        <button
                            type="button"
                            class="lml-hr-fp-nr__pager-btn lml-focus-ring"
                            data-hr-fp-nr-page-prev
                            aria-label="Previous page"
                            aria-disabled="true"
                            disabled
                        >
                            <i class="bi bi-chevron-left" aria-hidden="true"></i>
                        </button>
                        <span class="lml-hr-fp-nr__pager-page" aria-current="page">1</span>
                        <button
                            type="button"
                            class="lml-hr-fp-nr__pager-btn lml-focus-ring"
                            data-hr-fp-nr-page-next
                            aria-label="Next page"
                            aria-disabled="true"
                            disabled
                        >
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </button>
                    </nav>
                </div>
            </div>

            <div
                class="lml-hr-fp-nr__dialog-backdrop"
                data-hr-fp-nr-delete-dialog
                hidden
            >
                <div
                    class="lml-hr-fp-nr__dialog"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="lml-hr-fp-nr-delete-title"
                    aria-describedby="lml-hr-fp-nr-delete-message"
                    tabindex="-1"
                    data-hr-fp-nr-delete-dialog-panel
                >
                    <h2 id="lml-hr-fp-nr-delete-title" class="lml-hr-fp-nr__dialog-title">
                        Delete Non-Resident Record?
                    </h2>
                    <p id="lml-hr-fp-nr-delete-message" class="lml-hr-fp-nr__dialog-message">
                        Are you sure you want to delete the Family Planning record for
                        <strong data-hr-fp-nr-delete-name></strong>?
                        This action cannot be undone.
                    </p>
                    <div class="lml-hr-fp-nr__dialog-actions">
                        <button
                            type="button"
                            class="lml-hr-fp-nr__dialog-btn lml-hr-fp-nr__dialog-btn--cancel lml-focus-ring"
                            data-hr-fp-nr-delete-cancel
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="lml-hr-fp-nr__dialog-btn lml-hr-fp-nr__dialog-btn--confirm lml-focus-ring"
                            data-hr-fp-nr-delete-confirm
                        >
                            Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
