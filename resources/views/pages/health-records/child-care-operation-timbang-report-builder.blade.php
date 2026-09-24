{{--
    Health Records → Child Care → Operation Timbang Report Builder —
    choices before export, same pattern as Environmental Health's,
    Household Profiling's, Death's, Maternal Care's, Family Planning's,
    Risk Assessment's, and Child Care's own Report Builder pages.

    Hybrid filtering: Year/Month choose the weigh-in session and reload
    this page server-side (rows are session-scoped, not a single
    unfiltered payload — same reasoning as the Vitamin A Report Builder).
    Zone/Sex/Status then filter that session's rows client-side, live,
    same as the Deworming/Family Planning Report Builders.
--}}
@extends('layouts.dashboard')

@section('title', 'Operation Timbang Report - LMLinga')

@php
    use App\Support\HealthRecordsOperationTimbang;

    $zones = $zones ?? [];
    $statusFilterOptions = $statusFilterOptions ?? [];
    $years = $years ?? [(int) date('Y')];
    $initialOptions = $initialOptions ?? [
        'year' => (int) date('Y'),
        'month' => (int) date('n'),
        'zone' => 'all',
        'sex' => 'all',
        'status' => 'all',
    ];

    $selectedYear = (int) ($initialOptions['year'] ?? date('Y'));
    $selectedMonth = (int) ($initialOptions['month'] ?? date('n'));
    $selectedZone = $initialOptions['zone'] ?? 'all';
    $selectedSex = $initialOptions['sex'] ?? 'all';
    $selectedStatus = $initialOptions['status'] ?? 'all';

    $exportBase = $exportBase ?? route('health-records.child-care.operation-timbang.export');
    $reportBuilderUrl = route('health-records.child-care.operation-timbang.report-builder');
    $dashboardUrl = $dashboardUrl ?? route('health-records.child-care.operation-timbang');
    $sessionLabel = HealthRecordsOperationTimbang::sessionLabel($selectedYear, $selectedMonth);

    $monthOptions = [];
    for ($m = 1; $m <= 12; $m++) {
        $monthOptions[] = ['value' => $m, 'label' => date('F', mktime(0, 0, 0, $m, 1, $selectedYear))];
    }

    $exportQuery = array_filter([
        'year' => $selectedYear,
        'month' => $selectedMonth,
        'zone' => $selectedZone !== 'all' ? $selectedZone : null,
        'sex' => $selectedSex !== 'all' ? $selectedSex : null,
        'status' => $selectedStatus !== 'all' ? $selectedStatus : null,
    ]);
    $exportUrl = route('health-records.child-care.operation-timbang.export', $exportQuery);

    $summary = $summary ?? HealthRecordsOperationTimbang::summaryCards($selectedYear, $selectedMonth);
@endphp

@section('content')
    <div
        class="lml-hr-ot-report"
        data-hrot-report
        data-report-url="{{ $reportBuilderUrl }}"
        data-export-base="{{ $exportBase }}"
        aria-labelledby="lml-hrot-report-heading"
    >
        <header class="lml-hr-ot-report__head">
            <div>
                <a href="{{ $dashboardUrl }}" class="lml-hr-ot-report__back lml-focus-ring">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back to Operation Timbang</span>
                </a>
                <h2 id="lml-hrot-report-heading" class="lml-hr-ot-report__title">Operation Timbang Report</h2>
                <p class="lml-hr-ot-report__subtitle">
                    Choose session, zone, sex, or status, preview the weigh-in records, then export PDF.
                </p>
            </div>
        </header>

        <div class="lml-hr-ot-report__filters">
            <div class="lml-hr-ot-report__filter-grid">
                <label class="lml-hr-ot-report__field">
                    <span class="lml-hr-ot-report__label">Year</span>
                    <select class="lml-hr-ot-report__control lml-focus-ring" data-hrot-report-year>
                        @foreach ($years as $year)
                            <option value="{{ $year }}" @selected((int) $year === $selectedYear)>{{ $year }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-hr-ot-report__field">
                    <span class="lml-hr-ot-report__label">Month</span>
                    <select class="lml-hr-ot-report__control lml-focus-ring" data-hrot-report-month>
                        @foreach ($monthOptions as $month)
                            <option value="{{ $month['value'] }}" @selected((int) $month['value'] === $selectedMonth)>{{ $month['label'] }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-hr-ot-report__field">
                    <span class="lml-hr-ot-report__label">Zone</span>
                    <select class="lml-hr-ot-report__control lml-focus-ring" data-hrot-report-zone>
                        <option value="all" @selected($selectedZone === 'all')>All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}" @selected($selectedZone === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-hr-ot-report__field">
                    <span class="lml-hr-ot-report__label">Sex</span>
                    <select class="lml-hr-ot-report__control lml-focus-ring" data-hrot-report-sex>
                        <option value="all" @selected($selectedSex === 'all')>All Sexes</option>
                        <option value="female" @selected($selectedSex === 'female')>Female</option>
                        <option value="male" @selected($selectedSex === 'male')>Male</option>
                    </select>
                </label>

                <label class="lml-hr-ot-report__field">
                    <span class="lml-hr-ot-report__label">Status</span>
                    <select class="lml-hr-ot-report__control lml-focus-ring" data-hrot-report-status>
                        <option value="all" @selected($selectedStatus === 'all')>All Statuses</option>
                        @foreach ($statusFilterOptions as $value => $label)
                            @continue($value === 'all')
                            <option value="{{ $value }}" @selected($selectedStatus === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        <div class="lml-hr-ot-report__toolbar">
            <p class="lml-hr-ot-report__count" data-hrot-report-count aria-live="polite">0 records in preview</p>
            <div class="lml-hr-ot-report__actions">
                <a
                    class="lml-hr-ot-report__export-btn lml-focus-ring"
                    data-hrot-export
                    href="{{ $exportUrl }}"
                >
                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                    <span>Export PDF</span>
                </a>
            </div>
        </div>

        <section
            class="lml-hr-ot-summary"
            aria-labelledby="lml-hrot-report-summary-heading"
        >
            <h3 class="lml-hr-ot-summary__heading" id="lml-hrot-report-summary-heading">
                Summary: <span data-hrot-report-summary-label>{{ $sessionLabel }}</span>
            </h3>

            <div
                class="lml-hr-ot-summary__grid"
                role="group"
                aria-label="Operation Timbang monthly summary metrics"
            >
                <article class="lml-hr-ot-metric">
                    <p class="lml-hr-ot-metric__label">No. of 0–23 Months PS</p>
                    <p class="lml-hr-ot-metric__value">{{ $summary['ps_0_23'] }}</p>
                </article>

                <article class="lml-hr-ot-metric">
                    <p class="lml-hr-ot-metric__label">No. of 0–23 Months Old Measured</p>
                    <p class="lml-hr-ot-metric__value">{{ $summary['measured_0_23'] }}</p>
                </article>

                <article class="lml-hr-ot-metric">
                    <p class="lml-hr-ot-metric__label">No. of Over age</p>
                    <p class="lml-hr-ot-metric__value">{{ $summary['over_age'] }}</p>
                </article>

                <article class="lml-hr-ot-metric">
                    <p class="lml-hr-ot-metric__label">No. of Transferred/ Moveout</p>
                    <p class="lml-hr-ot-metric__value">{{ $summary['transferred'] }}</p>
                </article>

                <article class="lml-hr-ot-metric">
                    <p class="lml-hr-ot-metric__label">No. of Dead</p>
                    <p class="lml-hr-ot-metric__value">{{ $summary['dead'] }}</p>
                </article>

                <article class="lml-hr-ot-metric">
                    <p class="lml-hr-ot-metric__label">No. Not Available</p>
                    <p class="lml-hr-ot-metric__value">{{ $summary['not_available'] }}</p>
                </article>

                <article class="lml-hr-ot-metric">
                    <p class="lml-hr-ot-metric__label">No. of New Cases</p>
                    <p class="lml-hr-ot-metric__value">{{ $summary['new_cases'] }}</p>
                </article>

                <article class="lml-hr-ot-metric lml-hr-ot-metric--sex-split">
                    <p class="lml-hr-ot-metric__label">Total Number of 0–23 Months</p>
                    <div class="lml-hr-ot-metric__sex-split">
                        <div class="lml-hr-ot-sex lml-hr-ot-sex--male">
                            <span class="lml-hr-ot-sex__value">
                                <span class="lml-hr-ot-sex__abbr" aria-hidden="true">M –</span>
                                <span class="visually-hidden">Male </span>
                                <span>{{ $summary['total_male'] }}</span>
                            </span>
                            <span class="lml-hr-ot-sex__caption">Male</span>
                        </div>
                        <div class="lml-hr-ot-sex lml-hr-ot-sex--female">
                            <span class="lml-hr-ot-sex__value">
                                <span class="lml-hr-ot-sex__abbr" aria-hidden="true">F –</span>
                                <span class="visually-hidden">Female </span>
                                <span>{{ $summary['total_female'] }}</span>
                            </span>
                            <span class="lml-hr-ot-sex__caption">Female</span>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <div class="lml-hr-ot-report__preview-letterhead">
            <div class="lml-hr-ot-report__preview-brand">
                <img
                    class="lml-hr-ot-report__preview-logo"
                    src="{{ asset('assets/images/logo/LMLogo.png') }}"
                    width="56"
                    height="56"
                    alt="La Medalla, Iriga City official seal"
                >
                <div>
                    <p class="lml-hr-ot-report__preview-office">La Medalla Iriga City</p>
                    <p class="lml-hr-ot-report__preview-office lml-hr-ot-report__preview-office--sub">Health Center</p>
                </div>
            </div>
            <p class="lml-hr-ot-report__preview-banner">HEALTH RECORDS — OPERATION TIMBANG MONITORING</p>
            <p class="lml-hr-ot-report__preview-meta" data-hrot-report-preview-meta>{{ $sessionLabel }}</p>
        </div>

        <div class="lml-hr-ot-report__preview-wrap" tabindex="0" role="region" aria-label="Report live preview">
            <div class="lml-hr-ot-report__preview-zones" data-hrot-report-preview></div>
            <p class="lml-hr-ot-report__empty" data-hrot-report-empty hidden>No Operation Timbang records found.</p>
        </div>

        <script type="application/json" data-hrot-report-rows>{!! json_encode($reportRows ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    </div>
@endsection
