{{--
    Health Records — Child Care barangay-wide summary (Figma-aligned).
    Data: App\Support\HealthRecordsChildCare — persisted residents aged 0–18 years.
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
        $hasRecords = $totalUnfiltered > 0;
        $maxAgeYears = HealthRecordsChildCare::MAX_AGE_YEARS;
        $vitaminAUrl = route('health-records.child-care.vitamin-a');
        $dewormingUrl = route('health-records.child-care.deworming');
        $operationTimbangUrl = route('health-records.child-care.operation-timbang');
    @endphp

    <div
        class="lml-hr-child-care"
        data-lml-hr-child-care
        data-total="{{ $totalUnfiltered }}"
        data-has-records="{{ $hasRecords ? '1' : '0' }}"
        data-max-age-years="{{ $maxAgeYears }}"
        data-export-url="{{ route('health-records.child-care.report-builder') }}"
    >
        <div class="lml-hr-child-care__panel">
            <header class="lml-hr-child-care__top">
                <div class="lml-hr-child-care__title-row">
                    <h2 class="lml-hr-child-care__title" id="lml-hr-child-care-heading">Child Care</h2>
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
                        <p class="lml-hr-child-care__card-label">Total</p>
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

            <div class="lml-hr-child-care__filters" role="toolbar" aria-label="Resident search and filters">
                <div class="lml-hr-child-care__search">
                    <label class="visually-hidden" for="lml-hr-cc-search">Search resident</label>
                    <i class="bi bi-search lml-hr-child-care__search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="lml-hr-cc-search"
                        class="lml-hr-child-care__search-input lml-focus-ring"
                        data-hr-cc-search
                        placeholder="Search resident"
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

                <div
                    class="lml-hr-child-care__age-range"
                    role="group"
                    aria-label="Custom age range in years"
                    data-hr-cc-age-custom
                    hidden
                >
                    <span class="lml-hr-child-care__age-range-label" aria-hidden="true">Custom</span>
                    <label class="visually-hidden" for="lml-hr-cc-age-min">Minimum age in years</label>
                    <input
                        type="number"
                        id="lml-hr-cc-age-min"
                        class="lml-hr-child-care__age-input lml-focus-ring"
                        data-hr-cc-age-min
                        min="0"
                        max="{{ $maxAgeYears }}"
                        step="1"
                        inputmode="numeric"
                        placeholder="From"
                        autocomplete="off"
                        disabled
                    >
                    <span class="lml-hr-child-care__age-range-sep" aria-hidden="true">–</span>
                    <label class="visually-hidden" for="lml-hr-cc-age-max">Maximum age in years</label>
                    <input
                        type="number"
                        id="lml-hr-cc-age-max"
                        class="lml-hr-child-care__age-input lml-focus-ring"
                        data-hr-cc-age-max
                        min="0"
                        max="{{ $maxAgeYears }}"
                        step="1"
                        inputmode="numeric"
                        placeholder="To"
                        autocomplete="off"
                        disabled
                    >
                    <span class="lml-hr-child-care__age-range-unit" aria-hidden="true">yrs</span>
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
                Showing {{ $totalUnfiltered }} of {{ $totalUnfiltered }} residents
            </p>

            <div class="lml-hr-child-care__table-card">
                <div class="lml-hr-child-care__table-scroll" tabindex="0" aria-labelledby="lml-hr-child-care-heading" @if (! $hasRecords) hidden @endif>
                    <table class="lml-hr-child-care__table">
                        <colgroup>
                            <col class="lml-hr-child-care__col lml-hr-child-care__col--name">
                            <col class="lml-hr-child-care__col lml-hr-child-care__col--age">
                            <col class="lml-hr-child-care__col lml-hr-child-care__col--birthday">
                            <col class="lml-hr-child-care__col lml-hr-child-care__col--sex">
                            <col class="lml-hr-child-care__col lml-hr-child-care__col--action">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Full Name</th>
                                <th scope="col">Age</th>
                                <th scope="col">Birthday</th>
                                <th scope="col">Sex</th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody data-hr-cc-tbody>
                            @foreach ($rows as $row)
                                <tr
                                    data-hr-cc-row
                                    data-name="{{ strtolower($row['full_name']) }}"
                                    data-zone="{{ $row['zone'] }}"
                                    data-age-months="{{ $row['age_months'] }}"
                                    data-age-years="{{ $row['age_years'] }}"
                                    data-sex="{{ $row['sex_normalized'] }}"
                                >
                                    <td class="lml-hr-child-care__cell lml-hr-child-care__cell--name" data-label="Full Name">
                                        <span class="lml-hr-child-care__record-name">{{ $row['full_name'] }}</span>
                                    </td>
                                    <td class="lml-hr-child-care__cell lml-hr-child-care__cell--detail" data-label="Age">
                                        <span class="lml-hr-child-care__record-value">{{ $row['age_label'] }}</span>
                                    </td>
                                    <td class="lml-hr-child-care__cell lml-hr-child-care__cell--detail" data-label="Birthday">
                                        <span class="lml-hr-child-care__record-value">{{ $row['birthday'] }}</span>
                                    </td>
                                    <td class="lml-hr-child-care__cell lml-hr-child-care__cell--detail" data-label="Sex">
                                        <span class="lml-hr-child-care__record-value">{{ $row['sex'] }}</span>
                                    </td>
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
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="lml-hr-child-care__empty" data-hr-cc-empty @if ($hasRecords) hidden @endif>
                    <span class="lml-hr-child-care__empty-icon" aria-hidden="true">
                        <i class="bi bi-{{ $hasRecords ? 'search' : 'inbox' }}"></i>
                    </span>
                    <p
                        class="lml-hr-child-care__empty-title"
                        data-hr-cc-empty-no-records
                        @if ($hasRecords) hidden @endif
                    >
                        No child care records are available.
                    </p>
                    <p
                        class="lml-hr-child-care__empty-title"
                        data-hr-cc-empty-filtered
                        hidden
                    >
                        No residents match your filters
                    </p>
                    <p
                        class="lml-hr-child-care__empty-hint"
                        data-hr-cc-empty-hint
                        @if (! $hasRecords) hidden @endif
                    >
                        Try adjusting search, zone, age, or sex.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
