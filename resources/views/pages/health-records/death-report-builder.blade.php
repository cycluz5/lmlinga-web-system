{{--
    Health Records → Death Report Builder — choices before export, same
    pattern as Environmental Health's and Household Profiling's Report
    Builder pages.
--}}
@extends('layouts.dashboard')

@section('title', 'Death Records Report - LMLinga')

@php
    $zones = $zones ?? [];
    $years = $years ?? [];
    $months = $months ?? [];
    $initialOptions = $initialOptions ?? ['zone' => 'all', 'year' => 'all', 'month' => 'all'];
    $exportBase = $exportBase ?? route('health-records.death.export');
    $dashboardUrl = $dashboardUrl ?? route('health-records.death.index');
@endphp

@section('content')
    <div
        class="lml-hrd-report"
        data-hrd-report
        data-export-base="{{ $exportBase }}"
        aria-labelledby="lml-hrd-report-heading"
    >
        <header class="lml-hrd-report__head">
            <div>
                <a href="{{ $dashboardUrl }}" class="lml-hrd-report__back lml-focus-ring">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back to Death</span>
                </a>
                <h2 id="lml-hrd-report-heading" class="lml-hrd-report__title">Death Records Report</h2>
                <p class="lml-hrd-report__subtitle">
                    Choose zone, year, or month, preview the verified death records, then export PDF.
                </p>
            </div>
        </header>

        <div class="lml-hrd-report__filters" data-hrd-report-filters>
            <div class="lml-hrd-report__filter-grid">
                <label class="lml-hrd-report__field">
                    <span class="lml-hrd-report__label">Zone</span>
                    <select class="lml-hrd-report__control lml-focus-ring" data-hrd-report-zone>
                        <option value="all" @selected(($initialOptions['zone'] ?? 'all') === 'all')>All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}" @selected(($initialOptions['zone'] ?? '') === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-hrd-report__field">
                    <span class="lml-hrd-report__label">Year</span>
                    <select class="lml-hrd-report__control lml-focus-ring" data-hrd-report-year>
                        <option value="all" @selected(($initialOptions['year'] ?? 'all') === 'all')>All Years</option>
                        @foreach ($years as $year)
                            <option value="{{ $year }}" @selected(($initialOptions['year'] ?? '') === $year)>{{ $year }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-hrd-report__field">
                    <span class="lml-hrd-report__label">Month</span>
                    <select class="lml-hrd-report__control lml-focus-ring" data-hrd-report-month>
                        <option value="all" @selected(($initialOptions['month'] ?? 'all') === 'all')>All Months</option>
                        @foreach ($months as $month)
                            <option value="{{ $month['value'] }}" @selected(($initialOptions['month'] ?? '') === $month['value'])>{{ $month['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        <div class="lml-hrd-report__toolbar">
            <p class="lml-hrd-report__count" data-hrd-report-count aria-live="polite">0 records in preview</p>
            <div class="lml-hrd-report__actions">
                <a
                    class="lml-hrd-report__export-btn lml-focus-ring"
                    data-hrd-export
                    href="{{ $exportBase }}"
                >
                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                    <span>Export PDF</span>
                </a>
            </div>
        </div>

        <div class="lml-hrd-report__preview-letterhead">
            <div class="lml-hrd-report__preview-brand">
                <img
                    class="lml-hrd-report__preview-logo"
                    src="{{ asset('assets/images/logo/LMLogo.png') }}"
                    width="56"
                    height="56"
                    alt="La Medalla, Iriga City official seal"
                >
                <div>
                    <p class="lml-hrd-report__preview-office">La Medalla Iriga City</p>
                    <p class="lml-hrd-report__preview-office lml-hrd-report__preview-office--sub">Health Center</p>
                </div>
            </div>
            <p class="lml-hrd-report__preview-banner">HEALTH RECORDS — VERIFIED DEATH RECORDS</p>
            <p class="lml-hrd-report__preview-meta" data-hrd-report-preview-meta></p>
        </div>

        <div class="lml-hrd-report__preview-wrap" tabindex="0" role="region" aria-label="Report live preview">
            <div class="lml-hrd-report__preview-zones" data-hrd-report-preview></div>
            <p class="lml-hrd-report__empty" data-hrd-report-empty hidden>No verified death records found.</p>
        </div>

        <script type="application/json" data-hrd-report-rows>{!! json_encode($reportRows ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    </div>
@endsection
