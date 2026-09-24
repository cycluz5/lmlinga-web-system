{{--
    Environmental Health Report Builder — separate page from the monitoring dashboard.
    Preview layout mirrors the sectioned PDF export.
--}}
@extends('layouts.dashboard')

@section('title', 'Environmental Health Report - LMLinga')

@php
    use App\Support\EnvironmentalHealthReport;
    use App\Support\EnvironmentalHealthReportHeader;

    $reportOptions = $reportOptions ?? EnvironmentalHealthReport::normalizeOptions([]);
    $availablePeriods = $availablePeriods ?? ['years' => [], 'months_by_year' => []];
    $zones = $zones ?? [];
    $exportBase = $exportBase ?? route('environmental-health.export');
    $dashboardUrl = $dashboardUrl ?? route('environmental-health.index');
    $monthNames = [
        '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
        '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
        '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December',
    ];
@endphp

@section('content')
    <div
        class="lml-eh-report"
        data-eh-report
        data-export-base="{{ $exportBase }}"
        aria-labelledby="lml-eh-report-heading"
    >
        <header class="lml-eh-report__head">
            <div>
                <a href="{{ $dashboardUrl }}" class="lml-eh-report__back lml-focus-ring">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back to Environmental Health</span>
                </a>
                <h2 id="lml-eh-report-heading" class="lml-eh-report__title">Environmental Health Report</h2>
                <p class="lml-eh-report__subtitle">
                    Choose zone and survey period from recorded households, preview the sectioned report, then export PDF.
                </p>
            </div>
        </header>

        <div class="lml-eh-report__filters" data-eh-report-filters>
            <div class="lml-eh-report__filter-grid">
                <label class="lml-eh-report__field">
                    <span class="lml-eh-report__label">Report scope</span>
                    <select class="lml-eh-report__control lml-focus-ring" data-eh-report-scope>
                        <option value="all_zones" @selected(($reportOptions['scope'] ?? 'all_zones') === 'all_zones')>All Zones</option>
                        <option value="zone" @selected(($reportOptions['scope'] ?? '') === 'zone')>Specific Zone</option>
                    </select>
                </label>

                <label class="lml-eh-report__field" data-eh-report-zone-wrap @if (($reportOptions['scope'] ?? 'all_zones') !== 'zone') hidden @endif>
                    <span class="lml-eh-report__label">Zone</span>
                    <select class="lml-eh-report__control lml-focus-ring" data-eh-report-zone>
                        <option value="all">Select zone</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}" @selected(($reportOptions['zone'] ?? '') === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-eh-report__field">
                    <span class="lml-eh-report__label">Time period</span>
                    <select class="lml-eh-report__control lml-focus-ring" data-eh-report-period-mode>
                        <option value="all" @selected(($reportOptions['period_mode'] ?? 'all') === 'all')>All Time</option>
                        <option value="year" @selected(($reportOptions['period_mode'] ?? '') === 'year') @disabled(count($availablePeriods['years'] ?? []) === 0)>By Year</option>
                        <option value="month" @selected(($reportOptions['period_mode'] ?? '') === 'month') @disabled(count($availablePeriods['years'] ?? []) === 0)>By Month</option>
                    </select>
                </label>

                <label class="lml-eh-report__field" data-eh-report-year-wrap @if (! in_array($reportOptions['period_mode'] ?? 'all', ['year', 'month'], true)) hidden @endif>
                    <span class="lml-eh-report__label">Year (from survey dates)</span>
                    <select class="lml-eh-report__control lml-focus-ring" data-eh-report-year>
                        @forelse (($availablePeriods['years'] ?? []) as $year)
                            <option value="{{ $year }}" @selected((string) ($reportOptions['year'] ?? '') === (string) $year)>{{ $year }}</option>
                        @empty
                            <option value="">No survey years available</option>
                        @endforelse
                    </select>
                </label>

                <label class="lml-eh-report__field" data-eh-report-month-wrap @if (($reportOptions['period_mode'] ?? 'all') !== 'month') hidden @endif>
                    <span class="lml-eh-report__label">Month (from survey dates)</span>
                    <select class="lml-eh-report__control lml-focus-ring" data-eh-report-month>
                        @php
                            $selectedYear = (string) ($reportOptions['year'] ?? ($availablePeriods['years'][0] ?? ''));
                            $monthsForYear = $availablePeriods['months_by_year'][$selectedYear] ?? [];
                        @endphp
                        @forelse ($monthsForYear as $monthValue)
                            <option value="{{ $monthValue }}" @selected(($reportOptions['month'] ?? '') === $monthValue)>
                                {{ $monthNames[$monthValue] ?? $monthValue }}
                            </option>
                        @empty
                            <option value="">No survey months available</option>
                        @endforelse
                    </select>
                </label>

                <label class="lml-eh-report__field lml-eh-report__field--wide">
                    <span class="lml-eh-report__label">Program banner</span>
                    <input
                        type="text"
                        class="lml-eh-report__control lml-focus-ring"
                        data-eh-report-banner
                        maxlength="160"
                        value="{{ $reportOptions['program_banner'] ?? EnvironmentalHealthReportHeader::DEFAULT_PROGRAM_BANNER }}"
                    >
                </label>
            </div>
        </div>

        <div class="lml-eh-report__toolbar">
            <p class="lml-eh-report__count" data-eh-report-count aria-live="polite">0 households in preview</p>
            <div class="lml-eh-report__actions">
                <a
                    class="lml-eh-report__export-btn lml-eh-report__export-btn--pdf lml-focus-ring"
                    data-eh-export="pdf"
                    href="{{ $exportBase }}?format=pdf"
                >
                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                    <span>Export PDF</span>
                </a>
            </div>
        </div>

        <div class="lml-eh-report__preview-letterhead">
            <div class="lml-eh-report__preview-brand">
                <img
                    class="lml-eh-report__preview-logo"
                    src="{{ asset('assets/images/logo/LMLogo.png') }}"
                    width="56"
                    height="56"
                    alt="La Medalla, Iriga City official seal"
                >
                <div>
                    <p class="lml-eh-report__preview-office">La Medalla Iriga City</p>
                    <p class="lml-eh-report__preview-office lml-eh-report__preview-office--sub">Health Center</p>
                </div>
            </div>
            <p class="lml-eh-report__preview-banner" data-eh-report-preview-banner>
                {{ $reportOptions['program_banner'] ?? EnvironmentalHealthReportHeader::DEFAULT_PROGRAM_BANNER }}
            </p>
            <p class="lml-eh-report__preview-meta" data-eh-report-preview-meta></p>
        </div>

        <div class="lml-eh-report__preview-wrap" tabindex="0" role="region" aria-label="Report live preview">
            <div class="lml-eh-report__preview-zones" data-eh-report-preview></div>
            <p class="lml-eh-report__empty" data-eh-report-empty hidden>No records found.</p>
        </div>

        <script type="application/json" data-eh-report-rows>{!! json_encode($reportPreviewRows ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
        <script type="application/json" data-eh-report-sections>{!! json_encode(\App\Support\EnvironmentalHealthReport::cardSections(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
        <script type="application/json" data-eh-report-periods>{!! json_encode($availablePeriods, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    </div>
@endsection
