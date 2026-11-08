{{--
    Health Records → Child Care Report Builder — choices before export,
    same pattern as Environmental Health's, Household Profiling's, Death's,
    Maternal Care's, Family Planning's, and Risk Assessment's Report
    Builder pages.
--}}
@extends('layouts.dashboard')

@section('title', 'Child Care Report - LMLinga')

@php
    use App\Support\HealthRecordsChildCare;

    $zones = $zones ?? [];
    $ageFilterOptions = $ageFilterOptions ?? HealthRecordsChildCare::ageFilterOptions();
    $maxAgeYears = $maxAgeYears ?? HealthRecordsChildCare::MAX_AGE_YEARS;
    $initialOptions = $initialOptions ?? ['zone' => 'all', 'age' => 'all', 'age_min' => '', 'age_max' => '', 'sex' => 'all'];
    $exportBase = $exportBase ?? route('health-records.child-care.export');
    $dashboardUrl = $dashboardUrl ?? route('health-records.child-care.index');
    $isCustomAge = ($initialOptions['age'] ?? 'all') === 'custom';
@endphp

@section('content')
    <div
        class="lml-hr-cc-report"
        data-hrcc-report
        data-export-base="{{ $exportBase }}"
        data-max-age-years="{{ $maxAgeYears }}"
        aria-labelledby="lml-hrcc-report-heading"
    >
        <header class="lml-hr-cc-report__head">
            <div>
                <a href="{{ $dashboardUrl }}" class="lml-hr-cc-report__back lml-focus-ring">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back to Child Care</span>
                </a>
                <h2 id="lml-hrcc-report-heading" class="lml-hr-cc-report__title">Child Care Report</h2>
                <p class="lml-hr-cc-report__subtitle">
                    Choose zone, age, or sex, preview the child care records, then export PDF.
                </p>
            </div>
        </header>

        <div class="lml-hr-cc-report__filters" data-hrcc-report-filters>
            <div class="lml-hr-cc-report__filter-grid">
                <label class="lml-hr-cc-report__field">
                    <span class="lml-hr-cc-report__label">Zone</span>
                    <select class="lml-hr-cc-report__control lml-focus-ring" data-hrcc-report-zone>
                        <option value="all" @selected(($initialOptions['zone'] ?? 'all') === 'all')>All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}" @selected(($initialOptions['zone'] ?? '') === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="lml-hr-cc-report__field">
                    <span class="lml-hr-cc-report__label">Age</span>
                    <select class="lml-hr-cc-report__control lml-focus-ring" data-hrcc-report-age>
                        <option value="all" @selected(($initialOptions['age'] ?? 'all') === 'all')>All Ages</option>
                        @foreach ($ageFilterOptions as $value => $label)
                            @continue($value === 'all')
                            <option value="{{ $value }}" @selected(($initialOptions['age'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <div
                    class="lml-hr-cc-report__age-range"
                    role="group"
                    aria-label="Custom age range in years"
                    data-hrcc-report-age-custom
                    @if (! $isCustomAge) hidden @endif
                >
                    <span class="lml-hr-cc-report__age-range-label" aria-hidden="true">Custom</span>
                    <label class="visually-hidden" for="lml-hrcc-report-age-min">Minimum age in years</label>
                    <input
                        type="number"
                        id="lml-hrcc-report-age-min"
                        class="lml-hr-cc-report__age-input lml-focus-ring"
                        data-hrcc-report-age-min
                        min="0"
                        max="{{ $maxAgeYears }}"
                        step="1"
                        inputmode="numeric"
                        placeholder="From"
                        autocomplete="off"
                        value="{{ $initialOptions['age_min'] ?? '' }}"
                        @if (! $isCustomAge) disabled @endif
                    >
                    <span class="lml-hr-cc-report__age-range-sep" aria-hidden="true">–</span>
                    <label class="visually-hidden" for="lml-hrcc-report-age-max">Maximum age in years</label>
                    <input
                        type="number"
                        id="lml-hrcc-report-age-max"
                        class="lml-hr-cc-report__age-input lml-focus-ring"
                        data-hrcc-report-age-max
                        min="0"
                        max="{{ $maxAgeYears }}"
                        step="1"
                        inputmode="numeric"
                        placeholder="To"
                        autocomplete="off"
                        value="{{ $initialOptions['age_max'] ?? '' }}"
                        @if (! $isCustomAge) disabled @endif
                    >
                    <span class="lml-hr-cc-report__age-range-unit" aria-hidden="true">yrs</span>
                </div>

                <label class="lml-hr-cc-report__field">
                    <span class="lml-hr-cc-report__label">Sex</span>
                    <select class="lml-hr-cc-report__control lml-focus-ring" data-hrcc-report-sex>
                        <option value="all" @selected(($initialOptions['sex'] ?? 'all') === 'all')>All Sexes</option>
                        <option value="female" @selected(($initialOptions['sex'] ?? '') === 'female')>Female</option>
                        <option value="male" @selected(($initialOptions['sex'] ?? '') === 'male')>Male</option>
                    </select>
                </label>
            </div>
        </div>

        <div class="lml-hr-cc-report__toolbar">
            <p class="lml-hr-cc-report__count" data-hrcc-report-count aria-live="polite">0 records in preview</p>
            <div class="lml-hr-cc-report__actions">
                <a
                    class="lml-hr-cc-report__export-btn lml-focus-ring"
                    data-hrcc-export
                    href="{{ $exportBase }}"
                >
                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                    <span>Export PDF</span>
                </a>
            </div>
        </div>

        <div class="lml-hr-cc-report__preview-letterhead">
            <div class="lml-hr-cc-report__preview-brand">
                <img
                    class="lml-hr-cc-report__preview-logo"
                    src="{{ asset('assets/images/logo/LMLogo.png') }}"
                    width="56"
                    height="56"
                    alt="La Medalla, Iriga City official seal"
                >
                <div>
                    <p class="lml-hr-cc-report__preview-office">La Medalla Iriga City</p>
                    <p class="lml-hr-cc-report__preview-office lml-hr-cc-report__preview-office--sub">Health Center</p>
                </div>
            </div>
            <p class="lml-hr-cc-report__preview-banner">HEALTH RECORDS — CHILD CARE RECORDS</p>
            <p class="lml-hr-cc-report__preview-meta" data-hrcc-report-preview-meta></p>
        </div>

        <div class="lml-hr-cc-report__preview-wrap" tabindex="0" role="region" aria-label="Report live preview">
            <div class="lml-hr-cc-report__preview-zones" data-hrcc-report-preview></div>
            <p class="lml-hr-cc-report__empty" data-hrcc-report-empty hidden>No child care records found.</p>
        </div>

        <script type="application/json" data-hrcc-report-rows>{!! json_encode($reportRows ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    </div>
@endsection
