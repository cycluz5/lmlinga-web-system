{{--
    Household Profiling — Risk Assessment History: single section View / Edit.
    Loads stored values for THIS assessmentId. Save updates the same record.
--}}
@extends('layouts.dashboard')

@section('title', ($demoMember['name'] ?? 'Member') . ' — Risk Assessment History - LMLinga')

@section('content')
    @php
        use App\Support\RiskAssessmentErdMode;

        $historyUrl = route('household-profiling.members.risk-assessment', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);
        $showUrl = route('household-profiling.members.risk-assessment.show', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
            'assessmentId' => $assessmentId,
        ]);
        $sectionViewUrl = route('household-profiling.members.risk-assessment.section', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
            'assessmentId' => $assessmentId,
            'section' => $section,
        ]);
        $sectionEditUrl = route('household-profiling.members.risk-assessment.section.edit', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
            'assessmentId' => $assessmentId,
            'section' => $section,
        ]);
        $sectionUpdateUrl = route('household-profiling.members.risk-assessment.section.update', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
            'assessmentId' => $assessmentId,
            'section' => $section,
        ]);
        $hasAssessment = is_array($assessment) && ! empty($assessment['id']);
        $isEditing = (bool) ($isEditing ?? false);
        $readonly = ! $isEditing;
        $riskAssessmentErdActive = (bool) ($riskAssessmentErdActive ?? false);
        $riskAssessmentEligible = (bool) ($riskAssessmentEligible ?? \App\Support\RiskAssessmentService::isEligibleForRiskAssessment($demoMember ?? null));
        $sectionSupported = (bool) ($sectionSupported ?? true);
        $recordedDateLabel = $recordedDateLabel ?? RiskAssessmentErdMode::recordedDateLabel();

        $selected = static function (string $group, string $key) use ($assessment): bool {
            $value = $assessment[$group] ?? null;
            if (is_array($value)) {
                return in_array($key, $value, true);
            }

            return (string) $value === $key;
        };
        $inputValue = static function (string $key) use ($assessment): string {
            return (string) ($assessment[$key] ?? '');
        };

        $sectionIcons = [
            'red-flags' => 'bi-clipboard2-check',
            'past-medical' => 'bi-clock-history',
            'family-history' => 'bi-people',
            'lifestyle' => 'bi-heart',
            'physical' => 'bi-person',
        ];
        $legendIcon = $sectionIcons[$section] ?? 'bi-clipboard2-pulse';
    @endphp

    <div
        class="lml-risk-assess"
        data-lml-risk-assess
        data-lml-risk-assess-mode="history-section"
        data-history-editing="{{ $isEditing ? 'true' : 'false' }}"
        data-demo="true"
        data-household-no="{{ $householdNo }}"
        data-member-id="{{ $memberId }}"
        @if ($demoMember)
            data-member-name="{{ $demoMember['name'] }}"
        @endif
        @if ($hasAssessment)
            data-assessment-id="{{ $assessment['id'] }}"
        @endif
        data-history-section="{{ $section }}"
    >
        <div
            class="lml-risk-assess__toast"
            data-risk-assess-toast
            role="status"
            aria-live="polite"
            @if (! session('status')) hidden @endif
        >{{ session('status') }}</div>

        @if (! $demoHousehold || ! $demoMember)
            <section class="lml-risk-assess__not-found" aria-labelledby="lml-risk-assess-nf-title">
                <h2 id="lml-risk-assess-nf-title" class="lml-risk-assess__not-found-title">
                    Member not found
                </h2>
                <p class="lml-risk-assess__not-found-message">
                    @if (! $demoHousehold)
                        No record found.
                    @else
                        No record found.
                    @endif
                </p>
                <a
                    href="{{ $demoHousehold ? route('household-profiling.view', ['householdNo' => $householdNo]) : route('household-profiling.index') }}"
                    class="lml-risk-assess__not-found-link lml-focus-ring"
                >
                    {{ $demoHousehold ? 'Back to Household' : 'Return to Household List' }}
                </a>
            </section>
        @elseif (! $hasAssessment)
            <section class="lml-risk-assess__not-found" aria-labelledby="lml-risk-assess-nf-title">
                <h2 id="lml-risk-assess-nf-title" class="lml-risk-assess__not-found-title">
                    Assessment not found
                </h2>
                <p class="lml-risk-assess__not-found-message">
                    No demo risk assessment <strong>{{ $assessmentId ?? '' }}</strong> exists for
                    <strong>{{ $memberId }}</strong> in household <strong>{{ $householdNo }}</strong>.
                    Viewing does not create a new assessment.
                </p>
                <a href="{{ $historyUrl }}" class="lml-risk-assess__not-found-link lml-focus-ring">
                    Back to Risk Assessment History
                </a>
            </section>
        @else
            @include('pages.household-profiling.partials.risk-assessment-member-card', [
                'demoMember' => $demoMember,
                'householdNo' => $householdNo,
                'memberId' => $memberId,
                'backUrl' => $showUrl,
                'backLabel' => 'Back to Risk Assessment History sections for '.$demoMember['name'],
                'conductedAt' => (string) ($assessment['conducted_at'] ?? ''),
                'dateLabel' => $recordedDateLabel,
            ])

            @unless ($riskAssessmentEligible)
                @include('pages.household-profiling.partials.risk-assessment-ineligible')
            @endunless

            <section
                class="lml-risk-assess__panel"
                aria-labelledby="lml-risk-assess-history-title"
                data-risk-assess-history-section
            >
                <header class="lml-risk-assess__panel-head">
                    <div class="lml-risk-assess__panel-titles">
                        <h2 id="lml-risk-assess-history-title" class="lml-risk-assess__panel-title">
                            <i
                                class="bi bi-clipboard2-pulse lml-risk-assess__panel-title-icon"
                                aria-hidden="true"
                            ></i>
                            <span>RISK ASSESSMENT HISTORY</span>
                        </h2>
                        <p class="lml-risk-assess__panel-subtitle">
                            View previous risk assessments conducted for this individual.
                        </p>
                    </div>

                    <div class="lml-risk-assess__panel-controls">
                        @if ($sectionSupported && $riskAssessmentEligible)
                            @if ($isEditing)
                                <button
                                    type="submit"
                                    form="lml-risk-assess-section-form"
                                    class="lml-risk-assess__btn lml-risk-assess__btn--save lml-focus-ring"
                                    data-risk-assess-history-save
                                >
                                    Save
                                </button>
                            @else
                                <a
                                    href="{{ $sectionEditUrl }}"
                                    class="lml-risk-assess__btn lml-risk-assess__btn--edit lml-focus-ring"
                                    data-risk-assess-history-edit
                                >
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                    <span>Edit</span>
                                </a>
                            @endif
                        @endif
                    </div>
                </header>

                @if ($errors->any())
                    <div class="lml-risk-assess__form-errors" role="alert">
                        <p>{{ $errors->first() }}</p>
                    </div>
                @endif

                <form
                    id="lml-risk-assess-section-form"
                    class="lml-risk-assess__form"
                    method="post"
                    action="{{ $sectionUpdateUrl }}"
                    data-risk-assess-section-form
                    @if ($readonly) data-risk-assess-readonly="true" @endif
                    novalidate
                    @include('offline.form-attrs', [
                        'offlineOperation' => 'HEALTH_SERVICE_WRITE',
                        'offlineHealthAction' => 'risk_assessment_section_update',
                        'offlineHouseholdNo' => $householdNo,
                        'offlineMemberNo' => $memberId,
                        'offlineHealthAssessmentId' => $assessmentId ?? null,
                        'offlineHealthSection' => $section ?? null,
                    ])
                >
                    @csrf
                    @method('PUT')

                    <fieldset
                        class="lml-risk-assess__step lml-risk-assess__step--history"
                        data-risk-assess-step="{{ $section }}"
                        @if ($readonly) disabled @endif
                    >
                        <legend class="lml-risk-assess__step-legend">
                            <i class="bi {{ $legendIcon }}" aria-hidden="true"></i>
                            <span>{{ $sectionMeta['label'] }}</span>
                        </legend>

                        @if (! $sectionSupported)
                            <div class="lml-risk-assess__info-notice" role="note">
                                {{ RiskAssessmentErdMode::UNSUPPORTED_SECTION_MESSAGE }}
                            </div>
                        @elseif ($section === 'red-flags')
                            <p class="lml-risk-assess__step-hint">
                                Check which of the following conditions were selected:
                            </p>
                            <div
                                class="lml-risk-assess__check-grid"
                                data-risk-assess-exclusive-group="red_flags"
                                data-none-key="none"
                            >
                                <div class="lml-risk-assess__check-col">
                                    @foreach ($fields['red_flags']['left'] as $key => $label)
                                        @continue($riskAssessmentErdActive && ! \App\Support\RiskAssessmentErdMode::isErdUiKeySupported('red_flags', $key))
                                        <label class="lml-risk-assess__check-row">
                                            <input
                                                type="checkbox"
                                                name="red_flags[]"
                                                value="{{ $key }}"
                                                class="lml-focus-ring"
                                                @checked(old('red_flags') !== null ? in_array($key, (array) old('red_flags'), true) : $selected('red_flags', $key))
                                            >
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <div class="lml-risk-assess__check-col">
                                    @foreach ($fields['red_flags']['right'] as $key => $label)
                                        @continue($riskAssessmentErdActive && ! \App\Support\RiskAssessmentErdMode::isErdUiKeySupported('red_flags', $key))
                                        <label class="lml-risk-assess__check-row">
                                            <input
                                                type="checkbox"
                                                name="red_flags[]"
                                                value="{{ $key }}"
                                                class="lml-focus-ring"
                                                @checked(old('red_flags') !== null ? in_array($key, (array) old('red_flags'), true) : $selected('red_flags', $key))
                                                @if ($key === 'none') data-risk-assess-none @endif
                                            >
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @elseif ($section === 'past-medical')
                            <p class="lml-risk-assess__step-hint">Check all that apply</p>
                            <div
                                class="lml-risk-assess__check-grid"
                                data-risk-assess-exclusive-group="past_medical"
                                data-none-key="none"
                            >
                                <div class="lml-risk-assess__check-col">
                                    @foreach ($fields['past_medical']['left'] as $key => $label)
                                        @continue($riskAssessmentErdActive && ! \App\Support\RiskAssessmentErdMode::isErdUiKeySupported('past_medical', $key))
                                        <label class="lml-risk-assess__check-row">
                                            <input
                                                type="checkbox"
                                                name="past_medical[]"
                                                value="{{ $key }}"
                                                class="lml-focus-ring"
                                                @checked(old('past_medical') !== null ? in_array($key, (array) old('past_medical'), true) : $selected('past_medical', $key))
                                            >
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <div class="lml-risk-assess__check-col">
                                    @foreach ($fields['past_medical']['right'] as $key => $label)
                                        @continue($riskAssessmentErdActive && ! \App\Support\RiskAssessmentErdMode::isErdUiKeySupported('past_medical', $key))
                                        <label class="lml-risk-assess__check-row">
                                            <input
                                                type="checkbox"
                                                name="past_medical[]"
                                                value="{{ $key }}"
                                                class="lml-focus-ring"
                                                @checked(old('past_medical') !== null ? in_array($key, (array) old('past_medical'), true) : $selected('past_medical', $key))
                                            >
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <label class="lml-risk-assess__check-row lml-risk-assess__check-row--none">
                                    <input
                                        type="checkbox"
                                        name="past_medical[]"
                                        value="none"
                                        class="lml-focus-ring"
                                        data-risk-assess-none
                                        @checked(old('past_medical') !== null ? in_array('none', (array) old('past_medical'), true) : $selected('past_medical', 'none'))
                                    >
                                    <span>{{ $fields['past_medical']['none'] }}</span>
                                </label>
                            </div>
                        @elseif ($section === 'family-history')
                            <p class="lml-risk-assess__step-hint">Check all that apply</p>
                            <div
                                class="lml-risk-assess__check-grid"
                                data-risk-assess-exclusive-group="family_history"
                                data-none-key="none"
                            >
                                <div class="lml-risk-assess__check-col">
                                    @foreach ($fields['family_history']['left'] as $key => $label)
                                        <label class="lml-risk-assess__check-row">
                                            <input
                                                type="checkbox"
                                                name="family_history[]"
                                                value="{{ $key }}"
                                                class="lml-focus-ring"
                                                @checked(old('family_history') !== null ? in_array($key, (array) old('family_history'), true) : $selected('family_history', $key))
                                            >
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <div class="lml-risk-assess__check-col">
                                    @foreach ($fields['family_history']['right'] as $key => $label)
                                        <label class="lml-risk-assess__check-row">
                                            <input
                                                type="checkbox"
                                                name="family_history[]"
                                                value="{{ $key }}"
                                                class="lml-focus-ring"
                                                @checked(old('family_history') !== null ? in_array($key, (array) old('family_history'), true) : $selected('family_history', $key))
                                                @if ($key === 'none') data-risk-assess-none @endif
                                            >
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @elseif ($section === 'lifestyle')
                            <div class="lml-risk-assess__lifestyle-grid">
                                <fieldset class="lml-risk-assess__option-group">
                                    <legend class="lml-risk-assess__option-legend">Tobacco/Vape Usage</legend>
                                    <div class="lml-risk-assess__option-list" role="radiogroup" aria-label="Tobacco or vape usage">
                                        @foreach ($fields['tobacco'] as $key => $label)
                                            <label class="lml-risk-assess__check-row">
                                                <input
                                                    type="radio"
                                                    name="tobacco"
                                                    value="{{ $key }}"
                                                    class="lml-focus-ring"
                                                    @checked(old('tobacco', $assessment['tobacco'] ?? '') === $key)
                                                >
                                                <span>{{ $label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>

                                <fieldset class="lml-risk-assess__option-group">
                                    <legend class="lml-risk-assess__option-legend">Alcohol Intake</legend>
                                    <div class="lml-risk-assess__option-list" role="radiogroup" aria-label="Alcohol intake">
                                        @foreach ($fields['alcohol'] as $key => $label)
                                            <label class="lml-risk-assess__check-row">
                                                <input
                                                    type="radio"
                                                    name="alcohol"
                                                    value="{{ $key }}"
                                                    class="lml-focus-ring"
                                                    @checked(old('alcohol', $assessment['alcohol'] ?? '') === $key)
                                                >
                                                <span>{{ $label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>

                                <fieldset class="lml-risk-assess__option-group">
                                    <legend class="lml-risk-assess__option-legend">Dietary Habits</legend>
                                    <p class="lml-risk-assess__option-question">{{ \App\Support\DemoRiskAssessment::dietaryQuestion() }}</p>
                                    <div class="lml-risk-assess__option-list" role="radiogroup" aria-label="{{ \App\Support\DemoRiskAssessment::dietaryQuestion() }}">
                                        @foreach ($fields['dietary'] as $key => $label)
                                            <label class="lml-risk-assess__check-row">
                                                <input
                                                    type="radio"
                                                    name="dietary"
                                                    value="{{ $key }}"
                                                    class="lml-focus-ring"
                                                    @checked(old('dietary', $assessment['dietary'] ?? '') === $key)
                                                >
                                                <span>{{ $label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>

                                <fieldset class="lml-risk-assess__option-group">
                                    <legend class="lml-risk-assess__option-legend">Physical Activity</legend>
                                    <p class="lml-risk-assess__option-question">{{ \App\Support\DemoRiskAssessment::physicalActivityQuestion() }}</p>
                                    <div class="lml-risk-assess__option-list" role="radiogroup" aria-label="{{ \App\Support\DemoRiskAssessment::physicalActivityQuestion() }}">
                                        @foreach ($fields['physical_activity'] as $key => $label)
                                            <label class="lml-risk-assess__check-row">
                                                <input
                                                    type="radio"
                                                    name="physical_activity"
                                                    value="{{ $key }}"
                                                    class="lml-focus-ring"
                                                    @checked(old('physical_activity', $assessment['physical_activity'] ?? '') === $key)
                                                >
                                                <span>{{ $label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            </div>
                        @elseif ($section === 'physical')
                            <div class="lml-risk-assess__measure-block">
                                <h3 class="lml-risk-assess__measure-title">Body Measurement</h3>
                                <div class="lml-risk-assess__measure-grid">
                                    <div class="lml-risk-assess__field">
                                        <label for="lml-risk-hist-height">Height (cm)</label>
                                        <input
                                            id="lml-risk-hist-height"
                                            type="text"
                                            name="height_cm"
                                            class="lml-risk-assess__input lml-focus-ring"
                                            inputmode="decimal"
                                            autocomplete="off"
                                            value="{{ old('height_cm', $inputValue('height_cm')) }}"
                                            data-risk-assess-height
                                        >
                                    </div>
                                    <div class="lml-risk-assess__field">
                                        <label for="lml-risk-hist-weight">Weight (kg)</label>
                                        <input
                                            id="lml-risk-hist-weight"
                                            type="text"
                                            name="weight_kg"
                                            class="lml-risk-assess__input lml-focus-ring"
                                            inputmode="decimal"
                                            autocomplete="off"
                                            value="{{ old('weight_kg', $inputValue('weight_kg')) }}"
                                            data-risk-assess-weight
                                        >
                                    </div>
                                    <div class="lml-risk-assess__field">
                                        <label for="lml-risk-hist-bmi">BMI</label>
                                        @php
                                            $sectionBmiStatus = old('bmi_status', $inputValue('bmi_status'));
                                        @endphp
                                        <div id="lml-risk-hist-bmi" class="lml-risk-assess__input lml-risk-assess__bmi-display">
                                            <span data-risk-assess-bmi-value>{{ old('bmi', $inputValue('bmi')) }}</span>
                                            <span
                                                class="lml-risk-assess__bmi-status-inline"
                                                data-risk-assess-bmi-status
                                                data-bmi-status="{{ strtolower((string) $sectionBmiStatus) }}"
                                            >{{ $sectionBmiStatus ? '('.$sectionBmiStatus.')' : '' }}</span>
                                        </div>
                                        <input
                                            type="hidden"
                                            @unless ($riskAssessmentErdActive) name="bmi" @endunless
                                            value="{{ old('bmi', $inputValue('bmi')) }}"
                                            data-risk-assess-bmi
                                        >
                                    </div>
                                    <div class="lml-risk-assess__field">
                                        <label for="lml-risk-hist-waist">Waist (cm)</label>
                                        <input
                                            id="lml-risk-hist-waist"
                                            type="text"
                                            name="waist_cm"
                                            class="lml-risk-assess__input lml-focus-ring"
                                            inputmode="decimal"
                                            autocomplete="off"
                                            value="{{ old('waist_cm', $inputValue('waist_cm')) }}"
                                        >
                                    </div>
                                </div>
                            </div>

                            <div class="lml-risk-assess__measure-block">
                                <h3 class="lml-risk-assess__measure-title">Blood Pressure</h3>
                                <div class="lml-risk-assess__measure-grid lml-risk-assess__measure-grid--bp">
                                    <div class="lml-risk-assess__field">
                                        <label for="lml-risk-hist-systolic">Systolic (mmHg)</label>
                                        <input
                                            id="lml-risk-hist-systolic"
                                            type="text"
                                            name="systolic"
                                            class="lml-risk-assess__input lml-focus-ring"
                                            inputmode="numeric"
                                            autocomplete="off"
                                            value="{{ old('systolic', $inputValue('systolic')) }}"
                                            data-risk-assess-systolic
                                        >
                                    </div>
                                    <div class="lml-risk-assess__field">
                                        <label for="lml-risk-hist-diastolic">Diastolic (mmHg)</label>
                                        <input
                                            id="lml-risk-hist-diastolic"
                                            type="text"
                                            name="diastolic"
                                            class="lml-risk-assess__input lml-focus-ring"
                                            inputmode="numeric"
                                            autocomplete="off"
                                            value="{{ old('diastolic', $inputValue('diastolic')) }}"
                                            data-risk-assess-diastolic
                                        >
                                    </div>
                                    <div class="lml-risk-assess__field lml-risk-assess__field--wide">
                                        <label for="lml-risk-hist-bp-status">Status</label>
                                        <input
                                            id="lml-risk-hist-bp-status"
                                            type="text"
                                            @unless ($riskAssessmentErdActive) name="bp_status" @endunless
                                            class="lml-risk-assess__input lml-focus-ring"
                                            autocomplete="off"
                                            value="{{ old('bp_status', $inputValue('bp_status')) }}"
                                            data-risk-assess-bp-status
                                            readonly
                                        >
                                    </div>
                                </div>
                            </div>

                            @unless ($riskAssessmentErdActive)
                            <div class="lml-risk-assess__measure-block">
                                <h3 class="lml-risk-assess__measure-title">Visual Screening</h3>
                                <div class="lml-risk-assess__visual-list">
                                    <label class="lml-risk-assess__check-row">
                                        <input
                                            type="checkbox"
                                            name="visual_no_screening"
                                            value="1"
                                            class="lml-focus-ring"
                                            @checked((bool) old('visual_no_screening', $assessment['visual_no_screening'] ?? false))
                                        >
                                        <span>No Visual Screening in the past year</span>
                                    </label>
                                    <label class="lml-risk-assess__check-row lml-risk-assess__check-row--blurred">
                                        <input
                                            type="checkbox"
                                            name="visual_blurred"
                                            value="1"
                                            class="lml-focus-ring"
                                            @checked((bool) old('visual_blurred', $assessment['visual_blurred'] ?? false))
                                        >
                                        <span>Blurred Vision</span>
                                        <input
                                            type="text"
                                            name="visual_blurred_note"
                                            class="lml-risk-assess__input lml-risk-assess__input--inline lml-focus-ring"
                                            autocomplete="off"
                                            aria-label="Blurred vision details"
                                            value="{{ old('visual_blurred_note', $inputValue('visual_blurred_note')) }}"
                                        >
                                    </label>
                                </div>
                            </div>
                            @endunless
                        @endif
                    </fieldset>

                    <div class="lml-risk-assess__actions">
                        <a
                            href="{{ $showUrl }}"
                            class="lml-risk-assess__btn lml-risk-assess__btn--back lml-focus-ring"
                            data-risk-assess-history-back
                        >
                            Back
                        </a>
                    </div>
                </form>
            </section>
        @endif
    </div>
@endsection
