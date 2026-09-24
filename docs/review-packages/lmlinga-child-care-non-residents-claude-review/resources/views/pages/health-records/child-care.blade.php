{{--
    Health Records — Child Care barangay-wide summary (Figma-aligned).
    Data: App\Support\HealthRecordsChildCare (household demo catalog).
--}}
@extends('layouts.dashboard')

@section('title', 'Child Care - LMLinga')

@section('content')
    @php
        use App\Support\HealthRecordsChildCare;

        $summary = $summary ?? ['total' => 0, 'female' => 0, 'male' => 0];
        $rows = $rows ?? [];
        $zones = $zones ?? [];
        $ageFilterOptions = $ageFilterOptions ?? HealthRecordsChildCare::ageFilterOptions();
        $totalUnfiltered = $totalUnfiltered ?? count($rows);
        $vitaminAUrl = route('health-records.child-care.vitamin-a');
        $dewormingUrl = route('health-records.child-care.deworming');
        $operationTimbangUrl = route('health-records.child-care.operation-timbang');
        $nonResidentsUrl = route('health-records.child-care.non-residents.index');
    @endphp

    <div
        class="lml-hr-child-care"
        data-lml-hr-child-care
        data-total="{{ $totalUnfiltered }}"
    >
        <div class="lml-hr-child-care__panel">
            <header class="lml-hr-child-care__top">
                <div class="lml-hr-child-care__title-row">
                    <div class="lml-hr-child-care__title-cluster">
                        <h2 class="lml-hr-child-care__title" id="lml-hr-child-care-heading">Child Care</h2>
                        <a
                            href="{{ $nonResidentsUrl }}"
                            class="lml-hr-child-care__scope-pill lml-focus-ring"
                            data-hr-cc-non-residents
                        >
                            <i class="bi bi-people" aria-hidden="true"></i>
                            <span>Non-Residents</span>
                        </a>
                    </div>
                    <nav class="lml-hr-child-care__nav-pills" aria-label="Child Care service summaries">
                        <a
                            href="{{ $vitaminAUrl }}"
                            class="lml-hr-child-care__pill lml-focus-ring"
                        >
                            Vitamin A
                        </a>
                        <a
                            href="{{ $dewormingUrl }}"
                            class="lml-hr-child-care__pill lml-focus-ring"
                        >
                            Deworming
                        </a>
                        <a
                            href="{{ $operationTimbangUrl }}"
                            class="lml-hr-child-care__pill lml-focus-ring"
                        >
                            Operation Timbang
                        </a>
                    </nav>
                </div>

                <div class="lml-hr-child-care__actions" role="group" aria-label="Child Care actions">
                    {{--
                        Add has no barangay-level create destination in the current architecture.
                        Household member create requires a householdNo; do not invent a fake route.
                    --}}
                    <button
                        type="button"
                        class="lml-hr-child-care__add-btn lml-focus-ring"
                        data-hr-cc-add
                        aria-label="Add child care record"
                    >
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        <span>Add</span>
                    </button>
                    <button
                        type="button"
                        class="lml-hr-child-care__export-btn lml-focus-ring"
                        data-hr-cc-export
                    >
                        <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                        <span>Export Data</span>
                    </button>
                </div>
            </header>

            <div
                class="lml-hr-child-care__stats"
                role="group"
                aria-label="Child care population summary"
            >
                <article class="lml-hr-child-care__card lml-hr-child-care__card--total">
                    <div class="lml-hr-child-care__card-body">
                        <p class="lml-hr-child-care__card-label">Total Infants</p>
                        <p class="lml-hr-child-care__card-value" data-stat="total">{{ $summary['total'] }}</p>
                    </div>
                    <span class="lml-hr-child-care__card-icon lml-hr-child-care__card-icon--total" aria-hidden="true">
                        <i class="bi bi-person-arms-up"></i>
                    </span>
                </article>
                <article class="lml-hr-child-care__card lml-hr-child-care__card--female">
                    <div class="lml-hr-child-care__card-body">
                        <p class="lml-hr-child-care__card-label">Female</p>
                        <p class="lml-hr-child-care__card-value" data-stat="female">{{ $summary['female'] }}</p>
                    </div>
                    <span class="lml-hr-child-care__card-icon lml-hr-child-care__card-icon--female" aria-hidden="true">
                        <i class="bi bi-person-standing-dress"></i>
                    </span>
                </article>
                <article class="lml-hr-child-care__card lml-hr-child-care__card--male">
                    <div class="lml-hr-child-care__card-body">
                        <p class="lml-hr-child-care__card-label">Male</p>
                        <p class="lml-hr-child-care__card-value" data-stat="male">{{ $summary['male'] }}</p>
                    </div>
                    <span class="lml-hr-child-care__card-icon lml-hr-child-care__card-icon--male" aria-hidden="true">
                        <i class="bi bi-person-standing"></i>
                    </span>
                </article>
            </div>

            <div class="lml-hr-child-care__filters" role="toolbar" aria-label="Infant search and filters">
                <div class="lml-hr-child-care__search">
                    <label class="visually-hidden" for="lml-hr-cc-search">Search Infant</label>
                    <i class="bi bi-search lml-hr-child-care__search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="lml-hr-cc-search"
                        class="lml-hr-child-care__search-input lml-focus-ring"
                        data-hr-cc-search
                        placeholder="Search Infant"
                        autocomplete="off"
                    >
                </div>

                <div class="lml-hr-child-care__select-wrap">
                    <label class="visually-hidden" for="lml-hr-cc-zone">Filter by zone</label>
                    <select
                        id="lml-hr-cc-zone"
                        class="lml-hr-child-care__select lml-focus-ring"
                        data-hr-cc-zone
                    >
                        <option value="all">All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}">{{ $zone }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-child-care__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-child-care__select-wrap">
                    <label class="visually-hidden" for="lml-hr-cc-age">Filter by age</label>
                    <select
                        id="lml-hr-cc-age"
                        class="lml-hr-child-care__select lml-focus-ring"
                        data-hr-cc-age
                    >
                        @foreach ($ageFilterOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-child-care__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-child-care__select-wrap lml-hr-child-care__select-wrap--sex">
                    <label class="visually-hidden" for="lml-hr-cc-sex">Filter by sex</label>
                    <select
                        id="lml-hr-cc-sex"
                        class="lml-hr-child-care__select lml-focus-ring"
                        data-hr-cc-sex
                    >
                        <option value="all">Sex</option>
                        <option value="female">Female</option>
                        <option value="male">Male</option>
                    </select>
                    <i class="bi bi-chevron-down lml-hr-child-care__select-icon" aria-hidden="true"></i>
                </div>
            </div>

            <div
                class="lml-hr-child-care__toast"
                data-hr-cc-toast
                role="status"
                aria-live="polite"
                hidden
            ></div>

            <p class="lml-hr-child-care__results visually-hidden" data-hr-cc-results aria-live="polite">
                Showing {{ $totalUnfiltered }} of {{ $totalUnfiltered }} infants
            </p>

            <div class="lml-hr-child-care__table-card">
                <div class="lml-hr-child-care__table-scroll" tabindex="0" aria-labelledby="lml-hr-child-care-heading">
                    <table class="lml-hr-child-care__table">
                        <colgroup>
                            <col class="lml-hr-child-care__col lml-hr-child-care__col--name">
                            <col class="lml-hr-child-care__col lml-hr-child-care__col--birth">
                            <col class="lml-hr-child-care__col lml-hr-child-care__col--age">
                            <col class="lml-hr-child-care__col lml-hr-child-care__col--health">
                            <col class="lml-hr-child-care__col lml-hr-child-care__col--action">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Full Name</th>
                                <th scope="col">Birth Status</th>
                                <th scope="col">Age</th>
                                <th scope="col">Health Status</th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody data-hr-cc-tbody>
                            @forelse ($rows as $row)
                                <tr
                                    data-hr-cc-row
                                    data-name="{{ strtolower($row['full_name']) }}"
                                    data-zone="{{ $row['zone'] }}"
                                    data-age-months="{{ $row['age_months'] }}"
                                    data-sex="{{ $row['sex_normalized'] }}"
                                >
                                    <td class="lml-hr-child-care__cell lml-hr-child-care__cell--name" data-label="Full Name">
                                        <span class="lml-hr-child-care__record-name">{{ $row['full_name'] }}</span>
                                    </td>
                                    <td class="lml-hr-child-care__cell lml-hr-child-care__cell--detail" data-label="Birth Status"><span class="lml-hr-child-care__record-value">{{ $row['birth_status'] }}</span></td>
                                    <td class="lml-hr-child-care__cell lml-hr-child-care__cell--detail" data-label="Age"><span class="lml-hr-child-care__record-value">{{ $row['age_label'] }}</span></td>
                                    <td class="lml-hr-child-care__cell lml-hr-child-care__cell--detail" data-label="Health Status"><span class="lml-hr-child-care__record-value">{{ $row['health_status'] }}</span></td>
                                    <td class="lml-hr-child-care__cell lml-hr-child-care__cell--action" data-label="Action">
                                        <a
                                            href="{{ $row['view_url'] }}"
                                            class="lml-hr-child-care__view-btn lml-focus-ring"
                                            aria-label="View child care record for {{ $row['full_name'] }}"
                                        >
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                            <span>View</span>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr data-hr-cc-empty-row>
                                    <td colspan="5">No child care records found in the demo catalog.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="lml-hr-child-care__empty" data-hr-cc-empty hidden>
                    <span class="lml-hr-child-care__empty-icon" aria-hidden="true">
                        <i class="bi bi-search"></i>
                    </span>
                    <p class="lml-hr-child-care__empty-title">No infants match your filters</p>
                    <p class="lml-hr-child-care__empty-hint">Try adjusting search, zone, age, or sex.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
