{{--
    Health Records — Risk Assessment barangay-wide summary (Figma-aligned).

    Data: App\Support\HealthRecordsRiskAssessment — UI-phase fixture only.
    Not mapped from Household Profiling DemoRiskAssessment (frozen).
    Filters are client-side. Add / Export use UI-phase toasts.
--}}
@extends('layouts.dashboard')

@section('title', 'Risk Assessment - LMLinga')

@section('content')
    @php
        $rows = $rows ?? [];
        $zones = $zones ?? [];
        $years = $years ?? [];
        $summary = $summary ?? ['total' => 0, 'zones' => []];
        $totalUnfiltered = $totalUnfiltered ?? count($rows);
        $pageDescription = 'Record and management of risk assessment details for monitoring and tracking health risks.';
        $zoneCounts = $summary['zones'] ?? [];
    @endphp

    <div
        class="lml-hr-risk"
        data-lml-hr-risk
        data-ra-data-mode="figma-preview"
        data-total="{{ $totalUnfiltered }}"
    >
        <div class="lml-hr-risk__panel">
            <header class="lml-hr-risk__top">
                <div class="lml-hr-risk__title-block">
                    <h2 class="lml-hr-risk__title" id="lml-hr-risk-heading">Risk Assessment</h2>
                    <p class="lml-hr-risk__description" id="lml-hr-risk-desc">
                        {{ $pageDescription }}
                    </p>
                </div>

                <div class="lml-hr-risk__actions" role="group" aria-label="Risk Assessment actions">
                    <button
                        type="button"
                        class="lml-hr-risk__add-btn lml-focus-ring"
                        data-hr-ra-add
                        aria-label="Add risk assessment record"
                    >
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        <span>Add</span>
                    </button>
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
            </header>

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
                <article class="lml-hr-risk__card lml-hr-risk__card--total">
                    <div class="lml-hr-risk__card-body">
                        <p class="lml-hr-risk__card-label">Total Assessed Clients</p>
                        <p class="lml-hr-risk__card-value" data-ra-stat="total">{{ $summary['total'] }}</p>
                    </div>
                </article>

                @foreach ($zones as $zone)
                    @php
                        $zoneKey = \Illuminate\Support\Str::slug($zone);
                        $zoneCount = (int) ($zoneCounts[$zone] ?? 0);
                    @endphp
                    <article class="lml-hr-risk__card lml-hr-risk__card--zone">
                        <div class="lml-hr-risk__card-body">
                            <p class="lml-hr-risk__card-label">{{ $zone }}</p>
                            <p
                                class="lml-hr-risk__card-value lml-hr-risk__card-value--zone"
                                data-ra-stat="{{ $zoneKey }}"
                            >
                                <span class="lml-hr-risk__zone-circle">{{ $zoneCount }}</span>
                            </p>
                        </div>
                    </article>
                @endforeach
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
                >
                    <table class="lml-hr-risk__table">
                        <caption class="visually-hidden">
                            Barangay-wide risk assessment summary by full name,
                            BMI status, BP status, smoking status, alcohol status,
                            physical activity risk, family history risk, and chronic disease.
                            Figures and rows are UI-phase preview/demo values.
                        </caption>
                        <colgroup>
                            <col class="lml-hr-risk__col lml-hr-risk__col--name">
                            <col class="lml-hr-risk__col lml-hr-risk__col--bmi">
                            <col class="lml-hr-risk__col lml-hr-risk__col--bp">
                            <col class="lml-hr-risk__col lml-hr-risk__col--smoking">
                            <col class="lml-hr-risk__col lml-hr-risk__col--alcohol">
                            <col class="lml-hr-risk__col lml-hr-risk__col--activity">
                            <col class="lml-hr-risk__col lml-hr-risk__col--family">
                            <col class="lml-hr-risk__col lml-hr-risk__col--chronic">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">
                                    <span class="lml-hr-risk__th-main">Full Name</span>
                                </th>
                                <th scope="col">
                                    <span class="lml-hr-risk__th-main">BMI Status</span>
                                    <span class="lml-hr-risk__th-sub">(Underweight/<br>Normal/<br>Overweight/<br>Obese)</span>
                                </th>
                                <th scope="col">
                                    <span class="lml-hr-risk__th-main">BP Status</span>
                                    <span class="lml-hr-risk__th-sub">(Normal/<br>Pre-HTN/<br>HTN stage1/<br>stage2)</span>
                                </th>
                                <th scope="col">
                                    <span class="lml-hr-risk__th-main">Smoking Status</span>
                                    <span class="lml-hr-risk__th-sub">(Never/<br>Current/<br>Quit)</span>
                                </th>
                                <th scope="col">
                                    <span class="lml-hr-risk__th-main">Alcohol Status</span>
                                    <span class="lml-hr-risk__th-sub">(None/<br>Moderate/<br>Excessive)</span>
                                </th>
                                <th scope="col">
                                    <span class="lml-hr-risk__th-main">Physical Activity Risk</span>
                                    <span class="lml-hr-risk__th-sub">(Active/<br>Inactive)</span>
                                </th>
                                <th scope="col">
                                    <span class="lml-hr-risk__th-main">Family History Risk</span>
                                    <span class="lml-hr-risk__th-sub">(Yes/<br>No)</span>
                                </th>
                                <th scope="col">
                                    <span class="lml-hr-risk__th-main">Chronic Disease</span>
                                    <span class="lml-hr-risk__th-sub">(CVD/<br>Diabetes/<br>None)</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody data-hr-ra-tbody>
                            @foreach ($rows as $row)
                                <tr
                                    data-hr-ra-row
                                    data-name="{{ strtolower($row['full_name']) }}"
                                    data-zone="{{ $row['zone'] }}"
                                    data-year="{{ $row['year'] }}"
                                    data-row-key="{{ $row['key'] }}"
                                >
                                    <th scope="row" class="lml-hr-risk__cell lml-hr-risk__cell--name">
                                        {{ $row['full_name'] }}
                                    </th>
                                    <td class="lml-hr-risk__cell lml-hr-risk__cell--status">
                                        {{ $row['bmi_status'] }}
                                    </td>
                                    <td class="lml-hr-risk__cell lml-hr-risk__cell--status">
                                        {{ $row['bp_status'] }}
                                    </td>
                                    <td class="lml-hr-risk__cell lml-hr-risk__cell--status">
                                        {{ $row['smoking_status'] }}
                                    </td>
                                    <td class="lml-hr-risk__cell lml-hr-risk__cell--status">
                                        {{ $row['alcohol_status'] }}
                                    </td>
                                    <td class="lml-hr-risk__cell lml-hr-risk__cell--status">
                                        {{ $row['physical_activity_risk'] }}
                                    </td>
                                    <td class="lml-hr-risk__cell lml-hr-risk__cell--status">
                                        {{ $row['family_history_risk'] }}
                                    </td>
                                    <td class="lml-hr-risk__cell lml-hr-risk__cell--status">
                                        {{ $row['chronic_disease'] }}
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
                    hidden
                >
                    <div class="lml-hr-risk__empty-icon" aria-hidden="true">
                        <i class="bi bi-search"></i>
                    </div>
                    <p class="lml-hr-risk__empty-title">
                        No risk assessment records match the selected filters.
                    </p>
                    <p class="lml-hr-risk__empty-hint">Try adjusting search, zone, or year.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
