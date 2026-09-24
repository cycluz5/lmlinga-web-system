{{--
    Health Records — Child Care → Operation Timbang monitoring summary (Figma-aligned).

    Data: App\Support\HealthRecordsOperationTimbang — UI-phase Figma preview/demo
    display values only (not persisted; not authoritative production aggregates).
    Month/year session controls and filters operate client-side on displayed
    preview rows. Export matches Vitamin A / Deworming UI-phase toast.
--}}
@extends('layouts.dashboard')

@section('title', 'Child Care — Operation Timbang - LMLinga')

@section('content')
    @php
        $rows = $rows ?? [];
        $zones = $zones ?? [];
        $summary = $summary ?? [
            'ps_0_23' => '0',
            'measured_0_23' => '0',
            'over_age' => '0',
            'transferred' => '0',
            'dead' => '0',
            'not_available' => '0',
            'new_cases' => '0',
            'total_male' => '0',
            'total_female' => '0',
        ];
        $statusFilterOptions = $statusFilterOptions ?? ['all' => 'Status'];
        $monthSessions = $monthSessions ?? [];
        $years = $years ?? [2026];
        $selectedYear = $selectedYear ?? 2026;
        $selectedMonth = $selectedMonth ?? 1;
        $selectedSessionKey = sprintf('%04d-%02d', $selectedYear, $selectedMonth);
        $selectedSessionLabel = date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear));
        $vitaminAUrl = route('health-records.child-care.vitamin-a');
        $dewormingUrl = route('health-records.child-care.deworming');
        $operationTimbangUrl = route('health-records.child-care.operation-timbang');
        $pageDescription = 'Record and management of Operation Timbang weigh-in details for monitoring and tracking nutritional status.';
        $totalRows = count($rows);
    @endphp

    <div
        class="lml-hr-child-care lml-hr-child-care--operation-timbang"
        data-lml-hr-operation-timbang
        data-ot-data-mode="figma-preview"
        data-total="{{ $totalRows }}"
        data-selected-year="{{ $selectedYear }}"
        data-selected-month="{{ $selectedMonth }}"
    >
        <div class="lml-hr-child-care__panel">
            <header class="lml-hr-child-care__top">
                <div class="lml-hr-child-care__title-row">
                    <h2 class="lml-hr-child-care__title" id="lml-hr-ot-heading">Child Care</h2>
                    <nav class="lml-hr-child-care__nav-pills" aria-label="Child Care related summaries">
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
                            class="lml-hr-child-care__pill lml-hr-child-care__pill--active lml-focus-ring"
                            aria-current="page"
                        >
                            Operation Timbang
                        </a>
                    </nav>
                </div>

                <div class="lml-hr-child-care__actions" role="group" aria-label="Operation Timbang actions">
                    <button
                        type="button"
                        class="lml-hr-child-care__export-btn lml-focus-ring"
                        data-hr-ot-export
                        aria-label="Export Operation Timbang data"
                    >
                        <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                        <span>Export Data</span>
                    </button>
                </div>
            </header>

            <p class="lml-hr-child-care__description" id="lml-hr-ot-desc">
                {{ $pageDescription }}
            </p>

            <div
                class="lml-hr-child-care__toast"
                data-hr-ot-toast
                role="status"
                aria-live="polite"
                hidden
            ></div>

            <section
                class="lml-hr-ot-sessions"
                aria-labelledby="lml-hr-ot-month-label"
            >
                <div class="lml-hr-ot-sessions__row">
                    <div class="lml-hr-ot-sessions__months">
                        <span class="lml-hr-ot-sessions__month-label" id="lml-hr-ot-month-label">Month:</span>
                        <div
                            class="lml-hr-ot-sessions__pills"
                            role="tablist"
                            aria-label="Monthly Operation Timbang weigh-in sessions"
                            data-hr-ot-month-list
                        >
                            @foreach ($monthSessions as $session)
                                @php
                                    $isSelected = $session['key'] === $selectedSessionKey;
                                @endphp
                                <button
                                    type="button"
                                    class="lml-hr-ot-month-pill lml-focus-ring{{ $isSelected ? ' lml-hr-ot-month-pill--active' : '' }}"
                                    role="tab"
                                    id="lml-hr-ot-month-{{ $session['key'] }}"
                                    data-hr-ot-month
                                    data-month="{{ $session['month'] }}"
                                    data-year="{{ $session['year'] }}"
                                    data-key="{{ $session['key'] }}"
                                    data-label="{{ $session['label'] }}"
                                    aria-selected="{{ $isSelected ? 'true' : 'false' }}"
                                    @if ($isSelected) aria-current="true" @endif
                                >
                                    <span class="lml-hr-ot-month-pill__text">{{ $session['label'] }}</span>
                                    @if ($isSelected)
                                        <span class="visually-hidden">(selected session)</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="lml-hr-ot-sessions__year">
                        <label class="visually-hidden" for="lml-hr-ot-year">Year</label>
                        <span class="lml-hr-ot-sessions__year-label" aria-hidden="true">Year</span>
                        <div class="lml-hr-child-care__select-wrap lml-hr-ot-year-wrap">
                            <select
                                id="lml-hr-ot-year"
                                class="lml-hr-child-care__select lml-hr-ot-year-select lml-focus-ring"
                                data-hr-ot-year
                            >
                                @foreach ($years as $year)
                                    <option value="{{ $year }}" @selected((int) $year === (int) $selectedYear)>
                                        {{ $year }}
                                    </option>
                                @endforeach
                            </select>
                            <i class="bi bi-chevron-down lml-hr-child-care__select-icon" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>

                <p class="lml-hr-ot-sessions__hint">
                    Each tab is a monthly weigh-in session — tap to switch.
                </p>
            </section>

            <section
                class="lml-hr-ot-summary"
                aria-labelledby="lml-hr-ot-summary-heading"
            >
                <h3 class="lml-hr-ot-summary__heading" id="lml-hr-ot-summary-heading">
                    Summary: <span data-hr-ot-summary-label>{{ $selectedSessionLabel }}</span>
                </h3>

                <div
                    class="lml-hr-ot-summary__grid"
                    role="group"
                    aria-label="Operation Timbang monthly summary metrics"
                >
                    <article class="lml-hr-ot-metric">
                        <p class="lml-hr-ot-metric__label">No. of 0–23 Months PS</p>
                        <p class="lml-hr-ot-metric__value" data-ot-stat="ps-0-23">{{ $summary['ps_0_23'] }}</p>
                    </article>

                    <article class="lml-hr-ot-metric">
                        <p class="lml-hr-ot-metric__label">No. of 0–23 Months Old Measured</p>
                        <p class="lml-hr-ot-metric__value" data-ot-stat="measured-0-23">{{ $summary['measured_0_23'] }}</p>
                    </article>

                    <article class="lml-hr-ot-metric">
                        <p class="lml-hr-ot-metric__label">No. of Over age</p>
                        <p class="lml-hr-ot-metric__value" data-ot-stat="over-age">{{ $summary['over_age'] }}</p>
                    </article>

                    <article class="lml-hr-ot-metric">
                        <p class="lml-hr-ot-metric__label">No. of Transferred/ Moveout</p>
                        <p class="lml-hr-ot-metric__value" data-ot-stat="transferred">{{ $summary['transferred'] }}</p>
                    </article>

                    <article class="lml-hr-ot-metric">
                        <p class="lml-hr-ot-metric__label">No. of Dead</p>
                        <p class="lml-hr-ot-metric__value" data-ot-stat="dead">{{ $summary['dead'] }}</p>
                    </article>

                    <article class="lml-hr-ot-metric">
                        <p class="lml-hr-ot-metric__label">No. Not Available</p>
                        <p class="lml-hr-ot-metric__value" data-ot-stat="not-available">{{ $summary['not_available'] }}</p>
                    </article>

                    <article class="lml-hr-ot-metric">
                        <p class="lml-hr-ot-metric__label">No. of New Cases</p>
                        <p class="lml-hr-ot-metric__value" data-ot-stat="new-cases">{{ $summary['new_cases'] }}</p>
                    </article>

                    <article class="lml-hr-ot-metric lml-hr-ot-metric--sex-split">
                        <p class="lml-hr-ot-metric__label">Total Number of 0–23 Months</p>
                        <div class="lml-hr-ot-metric__sex-split" data-ot-stat="total-0-23">
                            <div class="lml-hr-ot-sex lml-hr-ot-sex--male">
                                <span class="lml-hr-ot-sex__value">
                                    <span class="lml-hr-ot-sex__abbr" aria-hidden="true">M –</span>
                                    <span class="visually-hidden">Male </span>
                                    <span data-ot-stat="total-male">{{ $summary['total_male'] }}</span>
                                </span>
                                <span class="lml-hr-ot-sex__caption">Male</span>
                            </div>
                            <div class="lml-hr-ot-sex lml-hr-ot-sex--female">
                                <span class="lml-hr-ot-sex__value">
                                    <span class="lml-hr-ot-sex__abbr" aria-hidden="true">F –</span>
                                    <span class="visually-hidden">Female </span>
                                    <span data-ot-stat="total-female">{{ $summary['total_female'] }}</span>
                                </span>
                                <span class="lml-hr-ot-sex__caption">Female</span>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <div
                class="lml-hr-child-care__filters lml-hr-child-care__filters--operation-timbang"
                role="toolbar"
                aria-label="Operation Timbang search and filters"
            >
                <div class="lml-hr-child-care__search">
                    <label class="visually-hidden" for="lml-hr-ot-search">Name of Child</label>
                    <i class="bi bi-search lml-hr-child-care__search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="lml-hr-ot-search"
                        class="lml-hr-child-care__search-input lml-focus-ring"
                        data-hr-ot-search
                        placeholder="Name of Child"
                        autocomplete="off"
                    >
                </div>

                <div class="lml-hr-child-care__select-wrap">
                    <label class="visually-hidden" for="lml-hr-ot-zone">Filter by zone</label>
                    <select
                        id="lml-hr-ot-zone"
                        class="lml-hr-child-care__select lml-focus-ring"
                        data-hr-ot-zone
                    >
                        <option value="all">All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}">{{ $zone }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-child-care__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-child-care__select-wrap lml-hr-child-care__select-wrap--sex">
                    <label class="visually-hidden" for="lml-hr-ot-sex">Filter by sex</label>
                    <select
                        id="lml-hr-ot-sex"
                        class="lml-hr-child-care__select lml-focus-ring"
                        data-hr-ot-sex
                    >
                        <option value="all">Sex</option>
                        <option value="female">Female</option>
                        <option value="male">Male</option>
                    </select>
                    <i class="bi bi-chevron-down lml-hr-child-care__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-child-care__select-wrap">
                    <label class="visually-hidden" for="lml-hr-ot-status">Filter by status</label>
                    <select
                        id="lml-hr-ot-status"
                        class="lml-hr-child-care__select lml-focus-ring"
                        data-hr-ot-status
                    >
                        @foreach ($statusFilterOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-child-care__select-icon" aria-hidden="true"></i>
                </div>
            </div>

            <p class="lml-hr-child-care__results visually-hidden" data-hr-ot-results aria-live="polite">
                Showing {{ $totalRows }} of {{ $totalRows }} children
            </p>

            <div class="lml-hr-child-care__table-card lml-hr-child-care__table-card--operation-timbang">
                <div
                    class="lml-hr-child-care__table-scroll lml-hr-child-care__table-scroll--operation-timbang"
                    tabindex="0"
                    aria-labelledby="lml-hr-ot-heading"
                    aria-describedby="lml-hr-ot-desc"
                >
                    <table class="lml-hr-child-care__table lml-hr-child-care__table--operation-timbang">
                        <caption class="visually-hidden">
                            Operation Timbang weigh-in records by child name, age,
                            weight, height, MUAC, and status.
                            Figures and rows are UI-phase Figma preview/demo values
                            pending authoritative backend integration.
                        </caption>
                        <colgroup>
                            <col class="lml-hr-ot-col lml-hr-ot-col--name">
                            <col class="lml-hr-ot-col lml-hr-ot-col--age">
                            <col class="lml-hr-ot-col lml-hr-ot-col--weight">
                            <col class="lml-hr-ot-col lml-hr-ot-col--height">
                            <col class="lml-hr-ot-col lml-hr-ot-col--muac">
                            <col class="lml-hr-ot-col lml-hr-ot-col--status">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Full Name</th>
                                <th scope="col">Age</th>
                                <th scope="col">Weight</th>
                                <th scope="col">Height</th>
                                <th scope="col">MUAC</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody data-hr-ot-tbody>
                            @foreach ($rows as $row)
                                <tr
                                    data-hr-ot-row
                                    data-name="{{ strtolower($row['full_name']) }}"
                                    data-zone="{{ $row['zone'] }}"
                                    data-sex="{{ $row['sex'] }}"
                                    data-status="{{ $row['status'] }}"
                                    data-child-key="{{ $row['key'] }}"
                                >
                                    <th scope="row" class="lml-hr-ot-cell lml-hr-ot-cell--name">
                                        {{ $row['full_name'] }}
                                    </th>
                                    <td class="lml-hr-ot-cell lml-hr-ot-cell--age">
                                        {{ $row['age_label'] }}
                                    </td>
                                    <td class="lml-hr-ot-cell lml-hr-ot-cell--weight">
                                        {{ $row['weight'] }}
                                    </td>
                                    <td class="lml-hr-ot-cell lml-hr-ot-cell--height">
                                        {{ $row['height'] }}
                                    </td>
                                    <td class="lml-hr-ot-cell lml-hr-ot-cell--muac">
                                        {{ $row['muac'] }}
                                    </td>
                                    <td class="lml-hr-ot-cell lml-hr-ot-cell--status">
                                        <span
                                            class="lml-hr-ot-status lml-hr-ot-status--{{ $row['status'] }}"
                                        >
                                            {{ $row['status_label'] }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div
                    class="lml-hr-child-care__empty"
                    data-hr-ot-empty
                    hidden
                >
                    <div class="lml-hr-child-care__empty-icon" aria-hidden="true">
                        <i class="bi bi-search"></i>
                    </div>
                    <p class="lml-hr-child-care__empty-title">No matching children</p>
                    <p class="lml-hr-child-care__empty-hint">Try adjusting search or filters.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
