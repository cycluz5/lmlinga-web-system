{{--
    Health Records → Child Care → Deworming Report Builder — choices
    before export, same pattern as Environmental Health's, Household
    Profiling's, Death's, Maternal Care's, Family Planning's, Risk
    Assessment's, and Child Care's own Report Builder pages.
--}}
@extends('layouts.dashboard')

@section('title', 'Deworming Report - LMLinga')

@php
    $zones = $zones ?? [];
    $statusFilterOptions = $statusFilterOptions ?? [];
    $initialOptions = $initialOptions ?? ['zone' => 'all', 'sex' => 'all', 'status' => 'all'];
    $exportBase = $exportBase ?? route('health-records.child-care.deworming.export');
    $dashboardUrl = $dashboardUrl ?? route('health-records.child-care.deworming');
@endphp

@section('content')
    <div
        class="lml-hr-dw-report"
        data-hrdw-report
        data-export-base="{{ $exportBase }}"
        aria-labelledby="lml-hrdw-report-heading"
    >
        <header class="lml-hr-dw-report__head">
            <div>
                <a href="{{ $dashboardUrl }}" class="lml-hr-dw-report__back lml-focus-ring">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back to Deworming</span>
                </a>
                <h2 id="lml-hrdw-report-heading" class="lml-hr-dw-report__title">Deworming Report</h2>
                <p class="lml-hr-dw-report__subtitle">
                    Choose zone, sex, or status, preview the deworming records, then export PDF.
                </p>
            </div>
        </header>

        <div class="lml-hr-dw-report__filters" data-hrdw-report-filters>
            <div class="lml-hr-dw-report__filter-grid">
                <label class="lml-hr-dw-report__field">
                    <span class="lml-hr-dw-report__label">Zone</span>
                    <select class="lml-hr-dw-report__control lml-focus-ring" data-hrdw-report-zone>
                        <option value="all" @selected(($initialOptions['zone'] ?? 'all') === 'all')>All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}" @selected(($initialOptions['zone'] ?? '') === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-hr-dw-report__field">
                    <span class="lml-hr-dw-report__label">Sex</span>
                    <select class="lml-hr-dw-report__control lml-focus-ring" data-hrdw-report-sex>
                        <option value="all" @selected(($initialOptions['sex'] ?? 'all') === 'all')>All Sexes</option>
                        <option value="female" @selected(($initialOptions['sex'] ?? '') === 'female')>Female</option>
                        <option value="male" @selected(($initialOptions['sex'] ?? '') === 'male')>Male</option>
                    </select>
                </label>

                <label class="lml-hr-dw-report__field">
                    <span class="lml-hr-dw-report__label">Status</span>
                    <select class="lml-hr-dw-report__control lml-focus-ring" data-hrdw-report-status>
                        <option value="all" @selected(($initialOptions['status'] ?? 'all') === 'all')>All Statuses</option>
                        @foreach ($statusFilterOptions as $value => $label)
                            @continue($value === 'all')
                            <option value="{{ $value }}" @selected(($initialOptions['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        <div class="lml-hr-dw-report__toolbar">
            <p class="lml-hr-dw-report__count" data-hrdw-report-count aria-live="polite">0 records in preview</p>
            <div class="lml-hr-dw-report__actions">
                <a
                    class="lml-hr-dw-report__export-btn lml-focus-ring"
                    data-hrdw-export
                    href="{{ $exportBase }}"
                >
                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                    <span>Export PDF</span>
                </a>
            </div>
        </div>

        <div class="lml-hr-dw-report__preview-letterhead">
            <div class="lml-hr-dw-report__preview-brand">
                <img
                    class="lml-hr-dw-report__preview-logo"
                    src="{{ asset('assets/images/logo/LMLogo.png') }}"
                    width="56"
                    height="56"
                    alt="La Medalla, Iriga City official seal"
                >
                <div>
                    <p class="lml-hr-dw-report__preview-office">La Medalla Iriga City</p>
                    <p class="lml-hr-dw-report__preview-office lml-hr-dw-report__preview-office--sub">Health Center</p>
                </div>
            </div>
            <p class="lml-hr-dw-report__preview-banner">HEALTH RECORDS — DEWORMING MONITORING</p>
            <p class="lml-hr-dw-report__preview-meta" data-hrdw-report-preview-meta></p>
        </div>

        <div class="lml-hr-dw-report__preview-wrap" tabindex="0" role="region" aria-label="Report live preview">
            <div class="lml-hr-dw-report__preview-zones" data-hrdw-report-preview></div>
            <p class="lml-hr-dw-report__empty" data-hrdw-report-empty hidden>No deworming records found.</p>
        </div>

        <script type="application/json" data-hrdw-report-rows>{!! json_encode($reportRows ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    </div>
@endsection
