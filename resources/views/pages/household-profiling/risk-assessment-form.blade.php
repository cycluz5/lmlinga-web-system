{{--
    Household Profiling — Risk Assessment Add wizard / historical View.
    Five-step assessment. Optional fields.
    DB-12: persisted residents POST to store; demo-only remains preview-compatible.
--}}
@extends('layouts.dashboard')

@section('title', ($demoMember['name'] ?? 'Member') . ' — Risk Assessment - LMLinga')

@section('content')
    @php
        use App\Support\DemoRiskAssessment;

        $mode = $mode ?? 'create'; // create | view
        $isView = $mode === 'view';
        $canPersist = (bool) ($canPersist ?? false);
        $riskAssessmentErdActive = (bool) ($riskAssessmentErdActive ?? false);
        $riskAssessmentEligible = (bool) ($riskAssessmentEligible ?? \App\Support\RiskAssessmentService::isEligibleForRiskAssessment($demoMember ?? null));
        $wizardStepCount = \App\Support\RiskAssessmentErdMode::wizardStepCount();
        $lifestyleStep = 4;
        $physicalStep = 5;
        $fields = DemoRiskAssessment::fieldDefinitions();
        $assessment = $assessment ?? [];
        $selected = static function (string $group, string $key) use ($assessment, $isView): bool {
            $old = old($group);
            if ($old !== null) {
                if (is_array($old)) {
                    return in_array($key, (array) $old, true);
                }

                return (string) $old === $key;
            }
            if (! $isView) {
                return false;
            }
            $value = $assessment[$group] ?? null;
            if (is_array($value)) {
                return in_array($key, $value, true);
            }

            return (string) $value === $key;
        };
        $inputValue = static function (string $key) use ($assessment, $isView): string {
            $old = old($key);
            if ($old !== null) {
                return (string) $old;
            }
            if (! $isView) {
                return '';
            }

            return (string) ($assessment[$key] ?? '');
        };

        $historyUrl = route('household-profiling.members.risk-assessment', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);
        $memberBackUrl = $isView ? $historyUrl : $historyUrl;
        $memberBackLabel = $isView
            ? 'Back to Risk Assessment History for '.($demoMember['name'] ?? 'member')
            : 'Back to Risk Assessment History for '.($demoMember['name'] ?? 'member');
    @endphp

    <div
        class="lml-risk-assess"
        data-lml-risk-assess
        data-lml-risk-assess-mode="{{ $mode }}"
        @unless ($isView)
            data-current-step="1"
            data-wizard-total="{{ $wizardStepCount }}"
        @endunless
        data-demo="true"
        data-household-no="{{ $householdNo }}"
        data-member-id="{{ $memberId }}"
        @if ($demoMember)
            data-member-name="{{ $demoMember['name'] }}"
        @endif
        @if ($isView && isset($assessment['id']))
            data-assessment-id="{{ $assessment['id'] }}"
        @endif
    >
        <div
            class="lml-risk-assess__toast"
            data-risk-assess-toast
            role="status"
            aria-live="polite"
            hidden
        ></div>

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
        @elseif ($isView && empty($assessment))
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
                'backUrl' => $memberBackUrl,
                'backLabel' => $memberBackLabel,
            ])

            @unless ($isView || $riskAssessmentEligible)
                @include('pages.household-profiling.partials.risk-assessment-ineligible')
            @else
            <section
                class="lml-risk-assess__panel"
                aria-labelledby="lml-risk-assess-form-title"
                data-risk-assess-wizard
            >
                <header class="lml-risk-assess__form-head">
                    <h2 id="lml-risk-assess-form-title" class="lml-risk-assess__panel-title">
                        @if ($isView)
                            RISK ASSESSMENT
                        @else
                            RISK ASSESSMENT
                        @endif
                    </h2>
                    <p class="lml-risk-assess__panel-subtitle">
                        Record and monitor health risk factors for preventive healthcare.
                    </p>
                    @if ($isView)
                        <p class="lml-risk-assess__view-banner" role="status">
                            Viewing existing assessment
                            @if (! empty($assessment['id']))
                                <strong>{{ $assessment['id'] }}</strong>
                            @endif
                            @if (! empty($assessment['conducted_at']))
                                · {{ DemoRiskAssessment::formatConductedDate((string) $assessment['conducted_at']) }}
                            @endif
                            (read-only)
                        </p>
                    @endif
                </header>

                <nav
                    class="lml-risk-assess__stepper"
                    aria-label="Risk assessment steps"
                    data-risk-assess-stepper
                >
                    <ol class="lml-risk-assess__stepper-list">
                        @for ($step = 1; $step <= $wizardStepCount; $step++)
                            <li
                                class="lml-risk-assess__stepper-item{{ $isView ? ' is-complete' : ($step === 1 ? ' is-current' : '') }}"
                                data-risk-assess-step-indicator="{{ $step }}"
                                @if (! $isView && $step === 1)
                                    aria-current="step"
                                @endif
                            >
                                <span class="lml-risk-assess__stepper-circle" aria-hidden="true">{{ $step }}</span>
                                <span class="visually-hidden">Step {{ $step }} of {{ $wizardStepCount }}</span>
                            </li>
                        @endfor
                    </ol>
                </nav>

                <form
                    class="lml-risk-assess__form"
                    data-risk-assess-form
                    @if ($isView)
                        data-risk-assess-readonly="true"
                    @elseif ($canPersist ?? false)
                        method="post"
                        action="{{ route('household-profiling.members.risk-assessment.store', [
                            'householdNo' => $householdNo,
                            'memberId' => $memberId,
                        ]) }}"
                        @include('offline.form-attrs', [
                            'offlineOperation' => 'HEALTH_SERVICE_WRITE',
                            'offlineHealthAction' => 'risk_assessment_store',
                            'offlineHouseholdNo' => $householdNo,
                            'offlineMemberNo' => $memberId,
                        ])
                    @endif
                    novalidate
                >
                    @unless ($isView)
                        @if ($canPersist ?? false)
                            @csrf
                        @endif
                    @endunless

                    @if (! $isView && $errors->any())
                        <div class="lml-risk-assess__form-errors" role="alert">
                            <p>{{ $errors->first() }}</p>
                        </div>
                    @endif
                    {{-- Step 1: Red Flag Assessment --}}
                    <fieldset
                        class="lml-risk-assess__step"
                        data-risk-assess-step="1"
                    >
                        <legend class="lml-risk-assess__step-legend">
                            <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                            <span>Red Flag Assessment</span>
                        </legend>
                        <p class="lml-risk-assess__step-hint">Check all that apply</p>

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
                                            @checked($selected('red_flags', $key))
                                            @disabled($isView)
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
                                            @checked($selected('red_flags', $key))
                                            @disabled($isView)
                                            @if ($key === 'none') data-risk-assess-none @endif
                                        >
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </fieldset>

                    {{-- Step 2: Past Medical History --}}
                    <fieldset
                        class="lml-risk-assess__step"
                        data-risk-assess-step="2"
                        @unless ($isView) hidden @endunless
                    >
                        <legend class="lml-risk-assess__step-legend">
                            <i class="bi bi-clock-history" aria-hidden="true"></i>
                            <span>Past Medical History</span>
                        </legend>
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
                                            @checked($selected('past_medical', $key))
                                            @disabled($isView)
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
                                            @checked($selected('past_medical', $key))
                                            @disabled($isView)
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
                                    @checked($selected('past_medical', 'none'))
                                    @disabled($isView)
                                >
                                <span>{{ $fields['past_medical']['none'] }}</span>
                            </label>
                        </div>
                    </fieldset>

                    {{-- Step 3: Family History --}}
                    <fieldset
                        class="lml-risk-assess__step"
                        data-risk-assess-step="3"
                        @unless ($isView) hidden @endunless
                    >
                        <legend class="lml-risk-assess__step-legend">
                            <i class="bi bi-people" aria-hidden="true"></i>
                            <span>Family History</span>
                        </legend>
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
                                            @checked($selected('family_history', $key))
                                            @disabled($isView)
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
                                            @checked($selected('family_history', $key))
                                            @disabled($isView)
                                            @if ($key === 'none') data-risk-assess-none @endif
                                        >
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </fieldset>

                    {{-- Lifestyle step --}}
                    <fieldset
                        class="lml-risk-assess__step"
                        data-risk-assess-step="{{ $lifestyleStep }}"
                        @unless ($isView) hidden @endunless
                    >
                        <legend class="lml-risk-assess__step-legend">
                            <i class="bi bi-heart" aria-hidden="true"></i>
                            <span>Lifestyle &amp; Risk Factor</span>
                        </legend>

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
                                                @checked($selected('tobacco', $key))
                                                @disabled($isView)
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
                                                @checked($selected('alcohol', $key))
                                                @disabled($isView)
                                            >
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>

                            <fieldset class="lml-risk-assess__option-group">
                                <legend class="lml-risk-assess__option-legend">Dietary Habits</legend>
                                <p class="lml-risk-assess__option-question">{{ DemoRiskAssessment::dietaryQuestion() }}</p>
                                <div class="lml-risk-assess__option-list" role="radiogroup" aria-label="{{ DemoRiskAssessment::dietaryQuestion() }}">
                                    @foreach ($fields['dietary'] as $key => $label)
                                        <label class="lml-risk-assess__check-row">
                                            <input
                                                type="radio"
                                                name="dietary"
                                                value="{{ $key }}"
                                                class="lml-focus-ring"
                                                @checked($selected('dietary', $key))
                                                @disabled($isView)
                                            >
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>

                            <fieldset class="lml-risk-assess__option-group">
                                <legend class="lml-risk-assess__option-legend">Physical Activity</legend>
                                <p class="lml-risk-assess__option-question">{{ DemoRiskAssessment::physicalActivityQuestion() }}</p>
                                <div class="lml-risk-assess__option-list" role="radiogroup" aria-label="{{ DemoRiskAssessment::physicalActivityQuestion() }}">
                                    @foreach ($fields['physical_activity'] as $key => $label)
                                        <label class="lml-risk-assess__check-row">
                                            <input
                                                type="radio"
                                                name="physical_activity"
                                                value="{{ $key }}"
                                                class="lml-focus-ring"
                                                @checked($selected('physical_activity', $key))
                                                @disabled($isView)
                                            >
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        </div>
                    </fieldset>

                    {{-- Physical measurements step --}}
                    <fieldset
                        class="lml-risk-assess__step"
                        data-risk-assess-step="{{ $physicalStep }}"
                        @unless ($isView) hidden @endunless
                    >
                        <legend class="lml-risk-assess__step-legend">
                            <i class="bi bi-person" aria-hidden="true"></i>
                            <span>Physical Measurements &amp; Clinical Screening</span>
                        </legend>

                        <div class="lml-risk-assess__measure-block">
                            <h3 class="lml-risk-assess__measure-title">Body Measurement</h3>
                            <div class="lml-risk-assess__measure-grid">
                                <div class="lml-risk-assess__field">
                                    <label for="lml-risk-height">Height (cm)</label>
                                    <input
                                        id="lml-risk-height"
                                        type="text"
                                        name="height_cm"
                                        class="lml-risk-assess__input lml-focus-ring"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        value="{{ $inputValue('height_cm') }}"
                                        data-risk-assess-height
                                        @disabled($isView)
                                    >
                                </div>
                                <div class="lml-risk-assess__field">
                                    <label for="lml-risk-weight">Weight (kg)</label>
                                    <input
                                        id="lml-risk-weight"
                                        type="text"
                                        name="weight_kg"
                                        class="lml-risk-assess__input lml-focus-ring"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        value="{{ $inputValue('weight_kg') }}"
                                        data-risk-assess-weight
                                        @disabled($isView)
                                    >
                                </div>
                                <div class="lml-risk-assess__field">
                                    <label for="lml-risk-bmi">BMI</label>
                                    <div id="lml-risk-bmi" class="lml-risk-assess__input lml-risk-assess__bmi-display">
                                        <span data-risk-assess-bmi-value>{{ $inputValue('bmi') }}</span>
                                        <span
                                            class="lml-risk-assess__bmi-status-inline"
                                            data-risk-assess-bmi-status
                                            data-bmi-status="{{ strtolower((string) $inputValue('bmi_status')) }}"
                                        >{{ $inputValue('bmi_status') ? '('.$inputValue('bmi_status').')' : '' }}</span>
                                    </div>
                                    <input
                                        type="hidden"
                                        @unless ($riskAssessmentErdActive) name="bmi" @endunless
                                        value="{{ $inputValue('bmi') }}"
                                        data-risk-assess-bmi
                                    >
                                </div>
                                <div class="lml-risk-assess__field">
                                    <label for="lml-risk-waist">Waist (cm)</label>
                                    <input
                                        id="lml-risk-waist"
                                        type="text"
                                        name="waist_cm"
                                        class="lml-risk-assess__input lml-focus-ring"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        value="{{ $inputValue('waist_cm') }}"
                                        @disabled($isView)
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="lml-risk-assess__measure-block">
                            <h3 class="lml-risk-assess__measure-title">Blood Pressure</h3>
                            <div class="lml-risk-assess__measure-grid lml-risk-assess__measure-grid--bp">
                                <div class="lml-risk-assess__field">
                                    <label for="lml-risk-systolic">Systolic (mmHg)</label>
                                    <input
                                        id="lml-risk-systolic"
                                        type="text"
                                        name="systolic"
                                        class="lml-risk-assess__input lml-focus-ring"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        value="{{ $inputValue('systolic') }}"
                                        data-risk-assess-systolic
                                        @disabled($isView)
                                    >
                                </div>
                                <div class="lml-risk-assess__field">
                                    <label for="lml-risk-diastolic">Diastolic (mmHg)</label>
                                    <input
                                        id="lml-risk-diastolic"
                                        type="text"
                                        name="diastolic"
                                        class="lml-risk-assess__input lml-focus-ring"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        value="{{ $inputValue('diastolic') }}"
                                        data-risk-assess-diastolic
                                        @disabled($isView)
                                    >
                                </div>
                                <div class="lml-risk-assess__field lml-risk-assess__field--wide">
                                    <label for="lml-risk-bp-status">Status</label>
                                    <input
                                        id="lml-risk-bp-status"
                                        type="text"
                                        @unless ($riskAssessmentErdActive) name="bp_status" @endunless
                                        class="lml-risk-assess__input lml-focus-ring"
                                        autocomplete="off"
                                        value="{{ $inputValue('bp_status') }}"
                                        data-risk-assess-bp-status
                                        readonly
                                        @disabled($isView)
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
                                        @checked((bool) old('visual_no_screening', $isView ? ($assessment['visual_no_screening'] ?? false) : false))
                                        @disabled($isView)
                                    >
                                    <span>No Visual Screening in the past year</span>
                                </label>
                                <label class="lml-risk-assess__check-row lml-risk-assess__check-row--blurred">
                                    <input
                                        type="checkbox"
                                        name="visual_blurred"
                                        value="1"
                                        class="lml-focus-ring"
                                        @checked((bool) old('visual_blurred', $isView ? ($assessment['visual_blurred'] ?? false) : false))
                                        @disabled($isView)
                                    >
                                    <span>Blurred Vision</span>
                                    <input
                                        type="text"
                                        name="visual_blurred_note"
                                        class="lml-risk-assess__input lml-risk-assess__input--inline lml-focus-ring"
                                        autocomplete="off"
                                        aria-label="Blurred vision details"
                                        value="{{ $inputValue('visual_blurred_note') }}"
                                        @disabled($isView)
                                    >
                                </label>
                            </div>
                        </div>
                        @endunless
                    </fieldset>

                    <div class="lml-risk-assess__actions">
                        @if ($isView)
                            <a
                                href="{{ $historyUrl }}"
                                class="lml-risk-assess__btn lml-risk-assess__btn--back lml-focus-ring"
                            >
                                Back
                            </a>
                        @else
                            <button
                                type="button"
                                class="lml-risk-assess__btn lml-risk-assess__btn--back lml-focus-ring"
                                data-risk-assess-back
                                hidden
                            >
                                Back
                            </button>
                            <button
                                type="button"
                                class="lml-risk-assess__btn lml-risk-assess__btn--next lml-focus-ring"
                                data-risk-assess-next
                            >
                                Next
                            </button>
                            <button
                                type="submit"
                                class="lml-risk-assess__btn lml-risk-assess__btn--save lml-focus-ring"
                                data-risk-assess-save
                                hidden
                            >
                                Save
                            </button>
                        @endif
                    </div>
                </form>
            </section>
            @endunless
        @endif
    </div>
@endsection
