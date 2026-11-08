{{-- Household Profiling — Add timbang measurement (FR-12). Visual language from NR measurement Figma. --}}
@extends('layouts.dashboard')

@section('title', 'Add Measurement — Nutritional Status - LMLinga')

@section('content')
    @php
        $persistenceSource = $persistenceSource ?? 'preview';
        $isDbPersisted = $persistenceSource === 'db';
        $memberName = (string) ($demoMember['name'] ?? 'Member');
        $urlStyle = $urlStyle ?? 'member';
        $historyUrl = $urlStyle === 'resident'
            ? route('households.residents.nutritional-status', [
                'householdNo' => $householdNo,
                'residentId' => $residentId,
            ])
            : route('household-profiling.members.nutritional-status', [
                'householdNo' => $householdNo,
                'memberId' => $memberId,
            ]);
        $storeUrl = $urlStyle === 'resident'
            ? route('households.residents.nutritional-status.store', [
                'householdNo' => $householdNo,
                'residentId' => $residentId,
            ])
            : route('household-profiling.members.nutritional-status.store', [
                'householdNo' => $householdNo,
                'memberId' => $memberId,
            ]);
        $today = now()->toDateString();
        $assessment = $assessment ?? null;
        $ageLabel = $assessment['age_label'] ?? 'Age not recorded';
        $sexLabel = $assessment['sex_label'] ?? ($demoMember['sex'] ?? null);
        $muacApplicable = (bool) ($assessment['muac_applicable'] ?? false);
        $bmiApplicable = (bool) ($assessment['bmi_applicable'] ?? false);
        $wfaApplicable = (bool) ($assessment['weight_for_age_applicable'] ?? false);
        $ageBand = $assessment['age_band'] ?? null;
        $muacHelper = match ($ageBand) {
            '0-5m' => 'Not applicable below 6 months.',
            '5-19y', 'adult' => 'Not applicable for residents 5 years and older — BMI is used instead.',
            '6-59m' => null,
            default => 'MUAC becomes available at 6 months.',
        };
        $memberProfileUrl = $memberProfileUrl ?? null;
    @endphp

    <div
        class="lml-hr-cc-nr lml-hh-timbang"
        data-lml-hh-timbang
        data-persistence="{{ $persistenceSource }}"
    >
        <a href="{{ $historyUrl }}" class="lml-hr-cc-nr__page-back lml-focus-ring" aria-label="Back to nutritional status">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </a>

        @if ($memberProfileUrl)
            <nav class="lml-hh-timbang__breadcrumb" aria-label="Breadcrumb">
                <a href="{{ $memberProfileUrl }}" class="lml-hh-timbang__breadcrumb-link lml-focus-ring">
                    <i class="bi bi-person-lines-fill" aria-hidden="true"></i>
                    Back to {{ $memberName }}'s profile
                </a>
                <span aria-hidden="true">/</span>
                <a href="{{ $historyUrl }}" class="lml-hh-timbang__breadcrumb-link lml-focus-ring">Nutritional Status history</a>
            </nav>
        @endif

        @if ($demoMember)
            <section class="lml-hr-cc-nr__form-panel lml-hr-cc-nr__form-panel--measure" aria-labelledby="lml-hh-timbang-measure-title">
                <div class="lml-hr-cc-nr__measure-head">
                    <h2 class="lml-hr-cc-nr__measure-title" id="lml-hh-timbang-measure-title">
                        <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                        Add Measurement for {{ $memberName }}
                    </h2>
                    <p class="lml-hr-cc-nr__form-lead">Track growth and nutrition over time</p>
                </div>

                <dl class="lml-hh-timbang__resident-summary" aria-label="Resident information">
                    <div>
                        <dt>Resident</dt>
                        <dd>{{ $memberName }}</dd>
                    </div>
                    <div>
                        <dt>Age at measurement</dt>
                        <dd data-timbang-age-label>{{ $ageLabel }}</dd>
                    </div>
                    <div>
                        <dt>Sex</dt>
                        <dd>{{ $sexLabel ?? '—' }}</dd>
                    </div>
                </dl>

                @if ($errors->any())
                    <div class="lml-hr-cc-nr__empty" role="alert">
                        <p class="lml-hr-cc-nr__empty-title">Please correct the following:</p>
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form
                    class="lml-hr-cc-nr__form"
                    @if ($isDbPersisted)
                        action="{{ $storeUrl }}"
                        method="post"
                        data-lml-hh-timbang-form
                        data-resident-birthday="{{ $residentBirthday ?? '' }}"
                        data-preview-url="{{ $previewUrl ?? '' }}"
                        @include('offline.form-attrs', [
                            'offlineOperation' => 'HEALTH_SERVICE_WRITE',
                            'offlineHealthAction' => 'timbang_record_store',
                            'offlineHouseholdNo' => $householdNo,
                            'offlineMemberNo' => $demoMember['id'] ?? $memberId,
                            'offlineResidentPk' => $residentId ?? null,
                        ])
                    @else
                        method="get"
                        action="{{ $historyUrl }}"
                    @endif
                    novalidate
                >
                    @if ($isDbPersisted)
                        @csrf
                    @endif

                    <fieldset class="lml-hr-cc-nr__fieldset">
                        <legend class="lml-hr-cc-nr__section-title">MEASUREMENT <span class="lml-hh-timbang__legend-tag">User input</span></legend>

                        <div class="lml-hr-cc-nr__field-grid lml-hr-cc-nr__field-grid--4">
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hh-timbang-date">Date</label>
                                <input
                                    id="lml-hh-timbang-date"
                                    name="measurement_date"
                                    type="date"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    value="{{ old('measurement_date') }}"
                                    max="{{ $today }}"
                                    required
                                >
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hh-timbang-weight">Weight (kg)</label>
                                <input
                                    id="lml-hh-timbang-weight"
                                    name="weight_kg"
                                    type="number"
                                    inputmode="decimal"
                                    step="0.01"
                                    min="0.01"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    value="{{ old('weight_kg') }}"
                                >
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hh-timbang-height">Height (cm)</label>
                                <input
                                    id="lml-hh-timbang-height"
                                    name="height_cm"
                                    type="number"
                                    inputmode="decimal"
                                    step="0.01"
                                    min="0.01"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    value="{{ old('height_cm') }}"
                                >
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hh-timbang-muac">MUAC (cm)</label>
                                <input
                                    id="lml-hh-timbang-muac"
                                    name="muac_cm"
                                    type="number"
                                    inputmode="decimal"
                                    step="0.1"
                                    min="0.1"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    value="{{ old('muac_cm') }}"
                                    data-timbang-muac
                                    @disabled(! $muacApplicable)
                                >
                                <p class="lml-hr-cc-nr__empty-hint" data-timbang-muac-helper @if (! $muacHelper) hidden @endif>{{ $muacHelper }}</p>
                            </div>
                        </div>

                    </fieldset>

                    <fieldset
                        class="lml-hr-cc-nr__fieldset"
                        data-timbang-child-indicators
                        @if (! $wfaApplicable) hidden @endif
                    >
                        <legend class="lml-hr-cc-nr__section-title">GROWTH STATUS <span class="lml-hh-timbang__legend-tag">System calculated</span></legend>
                        <div class="lml-hr-cc-nr__field-grid lml-hr-cc-nr__field-grid--3">
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hh-timbang-wfa">Weight-for-Age</label>
                                <input
                                    id="lml-hh-timbang-wfa"
                                    type="text"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    value="{{ $assessment['weight_for_age'] ?? '—' }}"
                                    data-timbang-wfa-value
                                    readonly
                                    tabindex="-1"
                                    aria-label="Calculated Weight-for-Age"
                                >
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hh-timbang-hfa">Height-for-Age</label>
                                <input
                                    id="lml-hh-timbang-hfa"
                                    type="text"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    value="{{ $assessment['height_for_age'] ?? '—' }}"
                                    data-timbang-hfa-value
                                    readonly
                                    tabindex="-1"
                                    aria-label="Calculated Height-for-Age"
                                >
                            </div>
                            <div class="lml-hr-cc-nr__field" data-timbang-muac-status-field @if (! $muacApplicable) hidden @endif>
                                <label for="lml-hh-timbang-muac-status">MUAC Status</label>
                                <input
                                    id="lml-hh-timbang-muac-status"
                                    type="text"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    value="{{ $assessment['muac_status'] ?? '—' }}"
                                    data-timbang-muac-status-value
                                    readonly
                                    tabindex="-1"
                                    aria-label="Calculated MUAC status"
                                >
                            </div>
                        </div>
                    </fieldset>

                    <fieldset
                        class="lml-hr-cc-nr__fieldset"
                        data-timbang-bmi-field
                        @if (! $bmiApplicable) hidden @endif
                    >
                        <legend class="lml-hr-cc-nr__section-title">BMI STATUS <span class="lml-hh-timbang__legend-tag">System calculated</span></legend>
                        <div class="lml-hr-cc-nr__field-grid lml-hr-cc-nr__field-grid--2">
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hh-timbang-bmi">BMI</label>
                                <input
                                    id="lml-hh-timbang-bmi"
                                    type="text"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    value="{{ $assessment['bmi_value'] ?? '—' }}"
                                    data-timbang-bmi-value
                                    readonly
                                    tabindex="-1"
                                    aria-label="Calculated BMI"
                                >
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hh-timbang-bmi-status">BMI Status</label>
                                <input
                                    id="lml-hh-timbang-bmi-status"
                                    type="text"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    value="{{ $assessment['bmi_status'] ?? '—' }}"
                                    data-timbang-bmi-status-value
                                    readonly
                                    tabindex="-1"
                                    aria-label="Calculated BMI status"
                                >
                                <p class="lml-hr-cc-nr__empty-hint" data-timbang-bmi-pending @if ($ageBand !== '5-19y') hidden @endif>
                                    BMI-for-Age classification unavailable — reference data required.
                                </p>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="lml-hr-cc-nr__fieldset">
                        <legend class="lml-hr-cc-nr__section-title">OVERALL <span class="lml-hh-timbang__legend-tag">System calculated</span></legend>
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hh-timbang-overall">Overall Nutritional Status</label>
                            <input
                                id="lml-hh-timbang-overall"
                                type="text"
                                class="lml-hr-cc-nr__input lml-focus-ring"
                                value="{{ $assessment['overall_nutritional_status'] ?? '—' }}"
                                data-timbang-overall-value
                                readonly
                                tabindex="-1"
                                aria-label="Calculated overall nutritional status"
                            >
                        </div>
                    </fieldset>

                    <fieldset class="lml-hr-cc-nr__fieldset">
                        <legend class="lml-hr-cc-nr__section-title">REMARKS <span class="lml-hh-timbang__legend-tag">User input</span></legend>
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hh-timbang-remarks" class="visually-hidden">Remarks</label>
                            <textarea
                                id="lml-hh-timbang-remarks"
                                name="remarks"
                                class="lml-hr-cc-nr__input lml-hr-cc-nr__textarea lml-focus-ring"
                                rows="3"
                            >{{ old('remarks') }}</textarea>
                        </div>
                    </fieldset>

                    <div class="lml-hr-cc-nr__form-actions">
                        <a href="{{ $historyUrl }}" class="lml-hr-cc-nr__cancel-btn lml-focus-ring">Cancel</a>
                        @if ($isDbPersisted)
                            <button type="submit" class="lml-hr-cc-nr__save-btn lml-focus-ring">Save</button>
                        @endif
                    </div>
                </form>
            </section>
        @else
            <section class="lml-hr-cc-nr__age-box" role="status">
                <p class="lml-hr-cc-nr__empty-title">Member not found.</p>
            </section>
        @endif
    </div>
@endsection
