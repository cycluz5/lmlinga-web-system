{{--
    Health Records → Family Planning Report Builder — choices before
    export, same pattern as Environmental Health's, Household Profiling's,
    Death's, and Maternal Care's Report Builder pages.
--}}
@extends('layouts.dashboard')

@section('title', 'Family Planning Report - LMLinga')

@php
    $zones = $zones ?? [];
    $years = $years ?? [];
    $months = $months ?? [];
    $initialOptions = $initialOptions ?? ['zone' => 'all', 'year' => 'all', 'month' => 'all'];
    $exportBase = $exportBase ?? route('health-records.family-planning.export');
    $dashboardUrl = $dashboardUrl ?? route('health-records.family-planning.index');
@endphp

@section('content')
    <div
        class="lml-hr-fp-report"
        data-hrfp-report
        data-export-base="{{ $exportBase }}"
        aria-labelledby="lml-hrfp-report-heading"
    >
        <header class="lml-hr-fp-report__head">
            <div>
                <a href="{{ $dashboardUrl }}" class="lml-hr-fp-report__back lml-focus-ring">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back to Family Planning</span>
                </a>
                <h2 id="lml-hrfp-report-heading" class="lml-hr-fp-report__title">Family Planning Report</h2>
                <p class="lml-hr-fp-report__subtitle">
                    Choose zone, year, or month, preview the commodities given, then export PDF.
                </p>
            </div>
        </header>

        <div class="lml-hr-fp-report__filters" data-hrfp-report-filters>
            <div class="lml-hr-fp-report__filter-grid">
                <label class="lml-hr-fp-report__field">
                    <span class="lml-hr-fp-report__label">Zone</span>
                    <select class="lml-hr-fp-report__control lml-focus-ring" data-hrfp-report-zone>
                        <option value="all" @selected(($initialOptions['zone'] ?? 'all') === 'all')>All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}" @selected(($initialOptions['zone'] ?? '') === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-hr-fp-report__field">
                    <span class="lml-hr-fp-report__label">Year</span>
                    <select class="lml-hr-fp-report__control lml-focus-ring" data-hrfp-report-year>
                        <option value="all" @selected(($initialOptions['year'] ?? 'all') === 'all')>All Years</option>
                        @foreach ($years as $year)
                            <option value="{{ $year }}" @selected(($initialOptions['year'] ?? '') === $year)>{{ $year }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-hr-fp-report__field">
                    <span class="lml-hr-fp-report__label">Month</span>
                    <select class="lml-hr-fp-report__control lml-focus-ring" data-hrfp-report-month>
                        <option value="all" @selected(($initialOptions['month'] ?? 'all') === 'all')>All Months</option>
                        @foreach ($months as $month)
                            <option value="{{ $month['value'] }}" @selected(($initialOptions['month'] ?? '') === $month['value'])>{{ $month['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        <div class="lml-hr-fp-report__toolbar">
            <p class="lml-hr-fp-report__count" data-hrfp-report-count aria-live="polite">0 records in preview</p>
            <div class="lml-hr-fp-report__actions">
                <a
                    class="lml-hr-fp-report__export-btn lml-focus-ring"
                    data-hrfp-export
                    href="{{ $exportBase }}"
                >
                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                    <span>Export PDF</span>
                </a>
            </div>
        </div>

        <div class="lml-hr-fp-report__preview-letterhead">
            <div class="lml-hr-fp-report__preview-brand">
                <img
                    class="lml-hr-fp-report__preview-logo"
                    src="{{ asset('assets/images/logo/LMLogo.png') }}"
                    width="56"
                    height="56"
                    alt="La Medalla, Iriga City official seal"
                >
                <div>
                    <p class="lml-hr-fp-report__preview-office">La Medalla Iriga City</p>
                    <p class="lml-hr-fp-report__preview-office lml-hr-fp-report__preview-office--sub">Health Center</p>
                </div>
            </div>
            <p class="lml-hr-fp-report__preview-banner">HEALTH RECORDS — FAMILY PLANNING COMMODITIES</p>
            <p class="lml-hr-fp-report__preview-meta" data-hrfp-report-preview-meta></p>
        </div>

        <div class="lml-hr-fp-report__preview-wrap" tabindex="0" role="region" aria-label="Report live preview">
            <div class="lml-hr-fp-report__preview-zones" data-hrfp-report-preview></div>
            <p class="lml-hr-fp-report__empty" data-hrfp-report-empty hidden>No family planning commodity records found.</p>
        </div>

        <script type="application/json" data-hrfp-report-rows>{!! json_encode($reportRows ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    </div>
@endsection
