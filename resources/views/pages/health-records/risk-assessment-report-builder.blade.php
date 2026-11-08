{{--
    Health Records → Risk Assessment Report Builder — choices before
    export, same pattern as Environmental Health's, Household Profiling's,
    Death's, and Maternal Care's Report Builder pages.
--}}
@extends('layouts.dashboard')

@section('title', 'Risk Assessment Report - LMLinga')

@php
    $zones = $zones ?? [];
    $years = $years ?? [];
    $months = $months ?? [];
    $initialOptions = $initialOptions ?? ['zone' => 'all', 'year' => 'all', 'month' => 'all'];
    $exportBase = $exportBase ?? route('health-records.risk-assessment.export');
    $dashboardUrl = $dashboardUrl ?? route('health-records.risk-assessment.index');
@endphp

@section('content')
    <div
        class="lml-hr-ra-report"
        data-hrra-report
        data-export-base="{{ $exportBase }}"
        aria-labelledby="lml-hrra-report-heading"
    >
        <header class="lml-hr-ra-report__head">
            <div>
                <a href="{{ $dashboardUrl }}" class="lml-hr-ra-report__back lml-focus-ring">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back to Risk Assessment</span>
                </a>
                <h2 id="lml-hrra-report-heading" class="lml-hr-ra-report__title">Risk Assessment Report</h2>
                <p class="lml-hr-ra-report__subtitle">
                    Choose zone, year, or month, preview the risk assessment records, then export PDF.
                </p>
            </div>
        </header>

        <div class="lml-hr-ra-report__filters" data-hrra-report-filters>
            <div class="lml-hr-ra-report__filter-grid">
                <label class="lml-hr-ra-report__field">
                    <span class="lml-hr-ra-report__label">Zone</span>
                    <select class="lml-hr-ra-report__control lml-focus-ring" data-hrra-report-zone>
                        <option value="all" @selected(($initialOptions['zone'] ?? 'all') === 'all')>All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}" @selected(($initialOptions['zone'] ?? '') === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-hr-ra-report__field">
                    <span class="lml-hr-ra-report__label">Year</span>
                    <select class="lml-hr-ra-report__control lml-focus-ring" data-hrra-report-year>
                        <option value="all" @selected(($initialOptions['year'] ?? 'all') === 'all')>All Years</option>
                        @foreach ($years as $year)
                            <option value="{{ $year }}" @selected(($initialOptions['year'] ?? '') === $year)>{{ $year }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-hr-ra-report__field">
                    <span class="lml-hr-ra-report__label">Month</span>
                    <select class="lml-hr-ra-report__control lml-focus-ring" data-hrra-report-month>
                        <option value="all" @selected(($initialOptions['month'] ?? 'all') === 'all')>All Months</option>
                        @foreach ($months as $month)
                            <option value="{{ $month['value'] }}" @selected(($initialOptions['month'] ?? '') === $month['value'])>{{ $month['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        <div class="lml-hr-ra-report__toolbar">
            <p class="lml-hr-ra-report__count" data-hrra-report-count aria-live="polite">0 records in preview</p>
            <div class="lml-hr-ra-report__actions">
                <a
                    class="lml-hr-ra-report__export-btn lml-focus-ring"
                    data-hrra-export
                    href="{{ $exportBase }}"
                >
                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                    <span>Export PDF</span>
                </a>
            </div>
        </div>

        <div class="lml-hr-ra-report__preview-letterhead">
            <div class="lml-hr-ra-report__preview-brand">
                <img
                    class="lml-hr-ra-report__preview-logo"
                    src="{{ asset('assets/images/logo/LMLogo.png') }}"
                    width="56"
                    height="56"
                    alt="La Medalla, Iriga City official seal"
                >
                <div>
                    <p class="lml-hr-ra-report__preview-office">La Medalla Iriga City</p>
                    <p class="lml-hr-ra-report__preview-office lml-hr-ra-report__preview-office--sub">Health Center</p>
                </div>
            </div>
            <p class="lml-hr-ra-report__preview-banner">HEALTH RECORDS — RISK ASSESSMENT RECORDS</p>
            <p class="lml-hr-ra-report__preview-meta" data-hrra-report-preview-meta></p>
        </div>

        <div class="lml-hr-ra-report__preview-wrap" tabindex="0" role="region" aria-label="Report live preview">
            <div class="lml-hr-ra-report__preview-zones" data-hrra-report-preview></div>
            <p class="lml-hr-ra-report__empty" data-hrra-report-empty hidden>No risk assessment records found.</p>
        </div>

        <script type="application/json" data-hrra-report-rows>{!! json_encode($reportRows ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
        <script type="application/json" data-hrra-report-column-pages>{!! json_encode($columnPages ?? \App\Support\HealthRecordsRiskAssessment::columnPages(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    </div>
@endsection
