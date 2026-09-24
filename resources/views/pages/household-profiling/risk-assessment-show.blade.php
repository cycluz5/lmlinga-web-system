{{--
    Household Profiling — Risk Assessment History: single assessment overview.
    Section navigation cards (not a wizard). Identity = assessmentId under household+member.
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
        $hasAssessment = is_array($assessment) && ! empty($assessment['id']);
        $recordedDateLabel = RiskAssessmentErdMode::recordedDateLabel();
    @endphp

    <div
        class="lml-risk-assess"
        data-lml-risk-assess
        data-lml-risk-assess-mode="history-show"
        data-demo="true"
        data-household-no="{{ $householdNo }}"
        data-member-id="{{ $memberId }}"
        @if ($demoMember)
            data-member-name="{{ $demoMember['name'] }}"
        @endif
        @if ($hasAssessment)
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
                'backUrl' => $historyUrl,
                'backLabel' => 'Back to Risk Assessment History for '.$demoMember['name'],
                'conductedAt' => (string) ($assessment['conducted_at'] ?? ''),
                'dateLabel' => $recordedDateLabel,
            ])

            @php
                $riskAssessmentEligible = (bool) ($riskAssessmentEligible ?? \App\Support\RiskAssessmentService::isEligibleForRiskAssessment($demoMember));
            @endphp
            @unless ($riskAssessmentEligible)
                @include('pages.household-profiling.partials.risk-assessment-ineligible')
            @endunless

            <section
                class="lml-risk-assess__panel"
                aria-labelledby="lml-risk-assess-history-title"
                data-risk-assess-history-show
            >
                <header class="lml-risk-assess__panel-head lml-risk-assess__panel-head--stack">
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
                </header>

                <nav
                    class="lml-risk-assess__section-nav"
                    aria-label="Risk assessment history sections"
                    data-risk-assess-section-nav
                >
                    <ul class="lml-risk-assess__section-list">
                        @foreach ($historySections as $meta)
                            @php
                                $sectionUrl = route('household-profiling.members.risk-assessment.section', [
                                    'householdNo' => $householdNo,
                                    'memberId' => $memberId,
                                    'assessmentId' => $assessment['id'],
                                    'section' => $meta['slug'],
                                ]);
                            @endphp
                            <li>
                                <a
                                    href="{{ $sectionUrl }}"
                                    class="lml-risk-assess__section-card lml-focus-ring"
                                    data-risk-assess-section-card="{{ $meta['slug'] }}"
                                >
                                    <span class="lml-risk-assess__section-card-icon" aria-hidden="true">
                                        <i class="bi {{ $meta['icon'] }}"></i>
                                    </span>
                                    <span class="lml-risk-assess__section-card-label">
                                        {{ $meta['label'] }}
                                    </span>
                                    <span class="lml-risk-assess__section-card-chevron" aria-hidden="true">
                                        <i class="bi bi-chevron-right"></i>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            </section>
        @endif
    </div>
@endsection
