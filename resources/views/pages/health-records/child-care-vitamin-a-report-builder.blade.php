{{--
    Health Records → Child Care → Vitamin A Report Builder — choices
    before export, same pattern as Environmental Health's, Household
    Profiling's, Death's, Maternal Care's, Family Planning's, Risk
    Assessment's, and Child Care's own Report Builder pages.

    Vitamin A rows are pre-aggregated server-side per zone/year/month (not
    per-resident), so unlike the other Report Builders this page reloads
    on filter change rather than filtering a client-side JSON payload —
    the aggregation itself, not just which rows are visible, changes with
    the filters.
--}}
@extends('layouts.dashboard')

@section('title', 'Vitamin A Report - LMLinga')

@php
    use App\Support\HealthRecordsVitaminA;

    $rows = $rows ?? [];
    $zones = $zones ?? [];
    $years = $years ?? [];
    $months = $months ?? HealthRecordsVitaminA::monthOptions();
    $initialOptions = $initialOptions ?? ['zone' => 'all', 'year' => 'all', 'month' => 'all'];
    $exportBase = $exportBase ?? route('health-records.child-care.vitamin-a.export');
    $reportBuilderUrl = route('health-records.child-care.vitamin-a.report-builder');
    $dashboardUrl = $dashboardUrl ?? route('health-records.child-care.vitamin-a');

    $selectedZone = $initialOptions['zone'] ?? 'all';
    $selectedYear = $initialOptions['year'] ?? 'all';
    $selectedMonth = $initialOptions['month'] ?? 'all';

    $zoneLabel = $selectedZone !== 'all' ? $selectedZone : 'All Zones';
    $periodLabel = HealthRecordsVitaminA::periodLabel($selectedYear, $selectedMonth);
    $targetTotal = (int) (collect($rows)->firstWhere('is_total', true)['target'] ?? 0);

    $exportQuery = array_filter([
        'zone' => $selectedZone !== 'all' ? $selectedZone : null,
        'year' => $selectedYear !== 'all' ? $selectedYear : null,
        'month' => $selectedMonth !== 'all' ? $selectedMonth : null,
    ]);
    $exportUrl = route('health-records.child-care.vitamin-a.export', $exportQuery);
@endphp

@section('content')
    <div
        class="lml-hr-va-report"
        data-hrva-report
        data-report-url="{{ $reportBuilderUrl }}"
        aria-labelledby="lml-hrva-report-heading"
    >
        <header class="lml-hr-va-report__head">
            <div>
                <a href="{{ $dashboardUrl }}" class="lml-hr-va-report__back lml-focus-ring">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back to Vitamin A</span>
                </a>
                <h2 id="lml-hrva-report-heading" class="lml-hr-va-report__title">Vitamin A Monitoring Report</h2>
                <p class="lml-hr-va-report__subtitle">
                    Choose zone, year, or month, preview the coverage table, then export PDF.
                </p>
            </div>
        </header>

        <div class="lml-hr-va-report__filters">
            <div class="lml-hr-va-report__filter-grid">
                <label class="lml-hr-va-report__field">
                    <span class="lml-hr-va-report__label">Zone</span>
                    <select class="lml-hr-va-report__control lml-focus-ring" data-hrva-report-zone>
                        <option value="all" @selected($selectedZone === 'all')>All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}" @selected($selectedZone === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-hr-va-report__field">
                    <span class="lml-hr-va-report__label">Year</span>
                    <select class="lml-hr-va-report__control lml-focus-ring" data-hrva-report-year>
                        <option value="all" @selected($selectedYear === 'all')>All Years</option>
                        @foreach ($years as $year)
                            <option value="{{ $year }}" @selected($selectedYear === $year)>{{ $year }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-hr-va-report__field">
                    <span class="lml-hr-va-report__label">Month</span>
                    <select class="lml-hr-va-report__control lml-focus-ring" data-hrva-report-month>
                        <option value="all" @selected($selectedMonth === 'all')>All Months</option>
                        @foreach ($months as $month)
                            <option value="{{ $month['value'] }}" @selected($selectedMonth === $month['value'])>{{ $month['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        <div class="lml-hr-va-report__toolbar">
            <p class="lml-hr-va-report__count">{{ $targetTotal }} target population in preview</p>
            <div class="lml-hr-va-report__actions">
                <a
                    class="lml-hr-va-report__export-btn lml-focus-ring"
                    data-hrva-export
                    href="{{ $exportUrl }}"
                >
                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                    <span>Export PDF</span>
                </a>
            </div>
        </div>

        <div class="lml-hr-va-report__preview-letterhead">
            <div class="lml-hr-va-report__preview-brand">
                <img
                    class="lml-hr-va-report__preview-logo"
                    src="{{ asset('assets/images/logo/LMLogo.png') }}"
                    width="56"
                    height="56"
                    alt="La Medalla, Iriga City official seal"
                >
                <div>
                    <p class="lml-hr-va-report__preview-office">La Medalla Iriga City</p>
                    <p class="lml-hr-va-report__preview-office lml-hr-va-report__preview-office--sub">Health Center</p>
                </div>
            </div>
            <p class="lml-hr-va-report__preview-banner">HEALTH RECORDS — VITAMIN A MONITORING</p>
            <p class="lml-hr-va-report__preview-meta">
                Overall Target Population : {{ $targetTotal }}
                <span class="lml-hr-va-report__preview-meta-sep">·</span>
                {{ $zoneLabel }}
                <span class="lml-hr-va-report__preview-meta-sep">·</span>
                {{ $periodLabel }}
            </p>
        </div>

        <div class="lml-hr-va-report__preview-wrap" tabindex="0" role="region" aria-label="Report live preview">
            <div class="lml-hr-va-report__table-card">
                <div class="lml-hr-va-report__table-scroll">
                    <table class="lml-hr-va-report__table">
                        <caption class="visually-hidden">
                            Barangay-wide Vitamin A monitoring summary by age group,
                            dose category, sex, and percentage accomplishment
                            from persisted Vitamin A administrations.
                        </caption>
                        <colgroup>
                            <col class="lml-hr-va-col lml-hr-va-col--age">
                            <col class="lml-hr-va-col lml-hr-va-col--target">
                            <col class="lml-hr-va-col lml-hr-va-col--metric" span="6">
                            <col class="lml-hr-va-col lml-hr-va-col--pct">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col" rowspan="3" class="lml-hr-va-th lml-hr-va-th--age">
                                    Age Group
                                </th>
                                <th scope="col" rowspan="3" class="lml-hr-va-th lml-hr-va-th--target">
                                    <span class="lml-hr-va-th__stack">
                                        <span>Target</span>
                                        <span>6mos</span>
                                        <span>to 6yrs old</span>
                                    </span>
                                </th>
                                <th
                                    scope="colgroup"
                                    colspan="6"
                                    class="lml-hr-va-th lml-hr-va-th--group"
                                >
                                    Total Number of children given vitamin A
                                </th>
                                <th scope="col" rowspan="3" class="lml-hr-va-th lml-hr-va-th--pct">
                                    <span class="lml-hr-va-th__stack">
                                        <span>Percentage</span>
                                        <span>Accomplishment</span>
                                        <span>(%)</span>
                                    </span>
                                </th>
                            </tr>
                            <tr>
                                <th
                                    scope="colgroup"
                                    colspan="3"
                                    class="lml-hr-va-th lml-hr-va-th--dose"
                                >
                                    Vitamin A 100,000 IU
                                </th>
                                <th
                                    scope="colgroup"
                                    colspan="3"
                                    class="lml-hr-va-th lml-hr-va-th--dose"
                                >
                                    Vitamin A 200,000 IU
                                </th>
                            </tr>
                            <tr>
                                <th scope="col" class="lml-hr-va-th lml-hr-va-th--sex">Male</th>
                                <th scope="col" class="lml-hr-va-th lml-hr-va-th--sex">Female</th>
                                <th scope="col" class="lml-hr-va-th lml-hr-va-th--sex">Total</th>
                                <th scope="col" class="lml-hr-va-th lml-hr-va-th--sex">Male</th>
                                <th scope="col" class="lml-hr-va-th lml-hr-va-th--sex">Female</th>
                                <th scope="col" class="lml-hr-va-th lml-hr-va-th--sex">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr
                                    @class([
                                        'lml-hr-va-row',
                                        'lml-hr-va-row--total' => ! empty($row['is_total']),
                                    ])
                                    data-age-group="{{ $row['key'] }}"
                                >
                                    <th scope="row" class="lml-hr-va-cell lml-hr-va-cell--age">
                                        {{ $row['label'] }}
                                    </th>
                                    @foreach (['target', 'va_100k_male', 'va_100k_female', 'va_100k_total', 'va_200k_male', 'va_200k_female', 'va_200k_total'] as $key)
                                        <td class="lml-hr-va-cell lml-hr-va-cell--num">
                                            @if ($row[$key] === '' || $row[$key] === null)
                                                <span class="visually-hidden">No data</span>
                                            @else
                                                {{ $row[$key] }}
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="lml-hr-va-cell lml-hr-va-cell--num lml-hr-va-cell--pct">
                                        @if ($row['percentage'] === '' || $row['percentage'] === null)
                                            <span class="visually-hidden">No data</span>
                                        @else
                                            {{ $row['percentage'] }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
