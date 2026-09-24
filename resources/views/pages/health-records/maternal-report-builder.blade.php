{{--
    Health Records → Maternal Report Builder — choices before export, same
    pattern as Environmental Health's, Household Profiling's, and Death's
    Report Builder pages.
--}}
@extends('layouts.dashboard')

@section('title', 'Maternal Care Report - LMLinga')

@php
    $zones = $zones ?? [];
    $years = $years ?? [];
    $months = $months ?? [];
    $initialOptions = $initialOptions ?? ['zone' => 'all', 'year' => 'all', 'month' => 'all'];
    $exportBase = $exportBase ?? route('health-records.maternal.export');
    $dashboardUrl = $dashboardUrl ?? route('health-records.maternal.index');
@endphp

@section('content')
    <div
        class="lml-hr-mc-report"
        data-hrmc-report
        data-export-base="{{ $exportBase }}"
        aria-labelledby="lml-hrmc-report-heading"
    >
        <header class="lml-hr-mc-report__head">
            <div>
                <a href="{{ $dashboardUrl }}" class="lml-hr-mc-report__back lml-focus-ring">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back to Maternal Care</span>
                </a>
                <h2 id="lml-hrmc-report-heading" class="lml-hr-mc-report__title">Maternal Care Report</h2>
                <p class="lml-hr-mc-report__subtitle">
                    Choose zone, year, or month, preview the maternal care records, then export PDF.
                </p>
            </div>
        </header>

        <div class="lml-hr-mc-report__filters" data-hrmc-report-filters>
            <div class="lml-hr-mc-report__filter-grid">
                <label class="lml-hr-mc-report__field">
                    <span class="lml-hr-mc-report__label">Zone</span>
                    <select class="lml-hr-mc-report__control lml-focus-ring" data-hrmc-report-zone>
                        <option value="all" @selected(($initialOptions['zone'] ?? 'all') === 'all')>All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}" @selected(($initialOptions['zone'] ?? '') === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-hr-mc-report__field">
                    <span class="lml-hr-mc-report__label">Year</span>
                    <select class="lml-hr-mc-report__control lml-focus-ring" data-hrmc-report-year>
                        <option value="all" @selected(($initialOptions['year'] ?? 'all') === 'all')>All Years</option>
                        @foreach ($years as $year)
                            <option value="{{ $year }}" @selected(($initialOptions['year'] ?? '') === $year)>{{ $year }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-hr-mc-report__field">
                    <span class="lml-hr-mc-report__label">Month</span>
                    <select class="lml-hr-mc-report__control lml-focus-ring" data-hrmc-report-month>
                        <option value="all" @selected(($initialOptions['month'] ?? 'all') === 'all')>All Months</option>
                        @foreach ($months as $month)
                            <option value="{{ $month['value'] }}" @selected(($initialOptions['month'] ?? '') === $month['value'])>{{ $month['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        <div class="lml-hr-mc-report__toolbar">
            <p class="lml-hr-mc-report__count" data-hrmc-report-count aria-live="polite">0 records in preview</p>
            <div class="lml-hr-mc-report__actions">
                <a
                    class="lml-hr-mc-report__export-btn lml-focus-ring"
                    data-hrmc-export
                    href="{{ $exportBase }}"
                >
                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                    <span>Export PDF</span>
                </a>
            </div>
        </div>

        <div class="lml-hr-mc-report__preview-letterhead">
            <div class="lml-hr-mc-report__preview-brand">
                <img
                    class="lml-hr-mc-report__preview-logo"
                    src="{{ asset('assets/images/logo/LMLogo.png') }}"
                    width="56"
                    height="56"
                    alt="La Medalla, Iriga City official seal"
                >
                <div>
                    <p class="lml-hr-mc-report__preview-office">La Medalla Iriga City</p>
                    <p class="lml-hr-mc-report__preview-office lml-hr-mc-report__preview-office--sub">Health Center</p>
                </div>
            </div>
            <p class="lml-hr-mc-report__preview-banner">HEALTH RECORDS — MATERNAL CARE RECORDS</p>
            <p class="lml-hr-mc-report__preview-meta" data-hrmc-report-preview-meta></p>
        </div>

        <div class="lml-hr-mc-report__preview-wrap" tabindex="0" role="region" aria-label="Report live preview">
            <div class="lml-hr-mc-report__preview-zones" data-hrmc-report-preview></div>
            <p class="lml-hr-mc-report__empty" data-hrmc-report-empty hidden>No maternal care records found.</p>
        </div>

        <script type="application/json" data-hrmc-report-rows>{!! json_encode($reportRows ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
        <script type="application/json" data-hrmc-report-column-pages>{!! json_encode($columnPages ?? \App\Support\HealthRecordsMaternal::columnPages(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    </div>
@endsection
