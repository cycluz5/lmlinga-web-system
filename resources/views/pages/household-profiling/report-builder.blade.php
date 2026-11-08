{{--
    Household Profiling Report Builder — choices before export, same
    pattern as Environmental Health's Report Builder page.
--}}
@extends('layouts.dashboard')

@section('title', 'Household Profiling Report - LMLinga')

@php
    $zones = $zones ?? [];
    $initialOptions = $initialOptions ?? ['zone' => 'all'];
    $exportBase = $exportBase ?? route('household-profiling.export');
    $dashboardUrl = $dashboardUrl ?? route('household-profiling.index');
@endphp

@section('content')
    <div
        class="lml-hp-report"
        data-hp-report
        data-export-base="{{ $exportBase }}"
        aria-labelledby="lml-hp-report-heading"
    >
        <header class="lml-hp-report__head">
            <div>
                <a href="{{ $dashboardUrl }}" class="lml-hp-report__back lml-focus-ring">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back to Household Profiling</span>
                </a>
                <h2 id="lml-hp-report-heading" class="lml-hp-report__title">Household Profiling Report</h2>
                <p class="lml-hp-report__subtitle">
                    Choose a zone, preview the personal-information report, then export PDF.
                </p>
            </div>
        </header>

        <div class="lml-hp-report__filters" data-hp-report-filters>
            <div class="lml-hp-report__filter-grid">
                <label class="lml-hp-report__field">
                    <span class="lml-hp-report__label">Zone</span>
                    <select class="lml-hp-report__control lml-focus-ring" data-hp-report-zone>
                        <option value="all" @selected(($initialOptions['zone'] ?? 'all') === 'all')>All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}" @selected(($initialOptions['zone'] ?? '') === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        <div class="lml-hp-report__toolbar">
            <p class="lml-hp-report__count" data-hp-report-count aria-live="polite">0 members in preview</p>
            <div class="lml-hp-report__actions">
                <a
                    class="lml-hp-report__export-btn lml-focus-ring"
                    data-hp-export
                    href="{{ $exportBase }}"
                >
                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                    <span>Export PDF</span>
                </a>
            </div>
        </div>

        <div class="lml-hp-report__preview-letterhead">
            <div class="lml-hp-report__preview-brand">
                <img
                    class="lml-hp-report__preview-logo"
                    src="{{ asset('assets/images/logo/LMLogo.png') }}"
                    width="56"
                    height="56"
                    alt="La Medalla, Iriga City official seal"
                >
                <div>
                    <p class="lml-hp-report__preview-office">La Medalla Iriga City</p>
                    <p class="lml-hp-report__preview-office lml-hp-report__preview-office--sub">Health Center</p>
                </div>
            </div>
            <p class="lml-hp-report__preview-banner">HOUSEHOLD PROFILING — PERSONAL INFORMATION PER MEMBER</p>
            <p class="lml-hp-report__preview-meta" data-hp-report-preview-meta></p>
        </div>

        <div class="lml-hp-report__preview-wrap" tabindex="0" role="region" aria-label="Report live preview">
            <div class="lml-hp-report__preview-zones" data-hp-report-preview></div>
            <p class="lml-hp-report__empty" data-hp-report-empty hidden>No records found.</p>
        </div>

        <script type="application/json" data-hp-report-rows>{!! json_encode($reportRows ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
        <script type="application/json" data-hp-report-sections>{!! json_encode(\App\Support\HouseholdProfilingMemberPdf::sections(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    </div>
@endsection
