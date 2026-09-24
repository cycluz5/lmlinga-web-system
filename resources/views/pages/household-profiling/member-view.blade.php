{{--
    Household Profiling — View Member Information.
    Database-backed via HouseholdMemberResolver. Unknown members render not-found.
--}}
@extends('layouts.dashboard')

@section('title', ($demoMember['name'] ?? 'Member') . ' - LMLinga')

@section('content')
    @php
        $householdSource = $householdSource ?? null;
        $isLocalMember = ! empty($offlineLocalMember);
        $isDbMember = $householdSource === 'db' && ! $isLocalMember;
        $riskAssessmentUrl = ($demoHousehold && $demoMember)
            ? route('household-profiling.members.risk-assessment', [
                'householdNo' => $demoHousehold['householdNo'],
                'memberId' => $demoMember['id'],
            ])
            : null;
        $riskAssessmentEligible = $demoMember
            && \App\Support\RiskAssessmentService::isEligibleForRiskAssessment($demoMember);
        $familyPlanningEligible = $demoMember
            && \App\Support\FamilyPlanningEligibility::allows(
                $demoMember['sex'] ?? null,
                $demoMember['birthday'] ?? null
            );
        $familyPlanningUrl = ($demoHousehold && $demoMember && $familyPlanningEligible)
            ? route('household-profiling.members.family-planning.index', [
                'householdNo' => $demoHousehold['householdNo'],
                'memberId' => $demoMember['id'],
            ])
            : null;
        $adultImmunizationUrl = ($demoHousehold && $demoMember)
            ? route('household-profiling.members.adult-immunization', [
                'householdNo' => $demoHousehold['householdNo'],
                'memberId' => $demoMember['id'],
            ])
            : null;
        $adultImmunizationEligible = $demoMember
            && \App\Support\AdultImmunizationEligibility::allows(
                $demoMember['sex'] ?? null,
                $demoMember['birthday'] ?? null
            );
        $maternalCareSexEligible = $demoMember
            && \App\Support\MaternalCareEligibility::allows($demoMember['sex'] ?? null);
        $maternalCareWorkflowEligible = $demoMember
            && \App\Support\MaternalCareEligibility::allowsWorkflow(
                $demoMember['sex'] ?? null,
                $demoMember['birthday'] ?? null
            );
        $maternalCareHasHistory = (bool) ($maternalCareHasHistory ?? false);
        $maternalCareUrl = ($demoHousehold && $demoMember && $maternalCareSexEligible
            && ($maternalCareWorkflowEligible || $maternalCareHasHistory))
            ? route('household-profiling.members.maternal-care.index', [
                'householdNo' => $demoHousehold['householdNo'],
                'memberId' => $demoMember['id'],
            ])
            : null;
        $deathUrl = ($demoHousehold && $demoMember && ! $isLocalMember)
            ? route('household-profiling.members.death.index', [
                'householdNo' => $demoHousehold['householdNo'],
                'memberId' => $demoMember['id'],
            ])
            : null;
        $nutritionFromCatalog = $nutritionFromCatalog ?? ! $isDbMember;
        if ($nutritionFromCatalog) {
            $nutrition = data_get($demoMember, 'nutrition', [
                'weight' => '—',
                'height' => '—',
                'bmi' => '—',
                'status' => '—',
            ]);
        } else {
            $nutrition = $nutritionCard ?? [
                'weight' => '—',
                'height' => '—',
                'bmi' => '—',
                'status' => '—',
            ];
        }

        // Future scaffolding: active-child / routeLocked state becomes reachable
        // when the Child Care destination pages replace the current redirect stubs.
        $activeChildCareKey = null;
        if (request()->routeIs('household-profiling.members.child-immunization')) {
            $activeChildCareKey = 'child-immunization';
        } elseif (request()->routeIs('household-profiling.members.school-based-immunization')) {
            $activeChildCareKey = 'school-based-immunization';
        } elseif (request()->routeIs('household-profiling.members.child-nutrition')) {
            $activeChildCareKey = 'child-nutrition';
        } elseif (request()->routeIs('household-profiling.members.deworming*')) {
            $activeChildCareKey = 'deworming';
        }
        $childCareRouteLocked = $activeChildCareKey !== null;
        $childCareExpanded = $childCareRouteLocked;
        $childCarePanelId = 'lml-hh-mv-child-care-panel';
        $pendingHealthModule = session('lml_pending_health_module');

        // Child Immunization / SBI / Nutrition keep their own destination eligibility.
        // Deworming is available for all household members (no age limit).
        $childCareLinks = [];
        if ($demoMember) {
            $childCareLinks = [
                [
                    'key' => 'child-immunization',
                    'label' => 'Child Immunization',
                    'icon' => 'bi-shield-plus',
                    'route' => 'household-profiling.members.child-immunization',
                ],
                [
                    'key' => 'school-based-immunization',
                    'label' => 'School-Based Immunization',
                    'icon' => 'bi-building',
                    'route' => 'household-profiling.members.school-based-immunization',
                ],
                [
                    'key' => 'child-nutrition',
                    'label' => 'Child Nutrition',
                    'icon' => 'bi-egg-fried',
                    'route' => 'household-profiling.members.child-nutrition',
                ],
            ];
        }
    @endphp

    <div
        class="lml-hh-member-view"
        data-lml-hh-member-view
        data-source="{{ $householdSource ?? 'none' }}"
        @if ($demoHousehold && $demoMember)
            data-household-no="{{ $demoHousehold['householdNo'] }}"
            data-member-id="{{ $demoMember['id'] }}"
            data-member-name="{{ $demoMember['name'] }}"
            @if (! empty($offlineResidentPk))
                data-resident-id="{{ $offlineResidentPk }}"
            @endif
            @if (! empty($offlineLocalMember))
                data-offline-local-member="1"
            @endif
        @endif
        @if ($pendingHealthModule)
            data-pending-health-module="{{ $pendingHealthModule }}"
        @endif
    >
        <div
            class="lml-hh-member-view__toast"
            data-hh-member-view-toast
            role="status"
            aria-live="polite"
            hidden
        ></div>

        @if (! $demoHousehold || ! $demoMember)
            <section class="lml-hh-member-view__not-found" aria-labelledby="lml-hh-member-view-nf-title">
                <span class="lml-hh-member-view__not-found-icon" aria-hidden="true">
                    <i class="bi bi-person-x"></i>
                </span>
                <h2 id="lml-hh-member-view-nf-title" class="lml-hh-member-view__not-found-title">
                    Member not found
                </h2>
                <p class="lml-hh-member-view__not-found-message">
                    @if (! $demoHousehold)
                        No registered household matches <strong>{{ $householdNo }}</strong>.
                    @else
                        No registered member <strong>{{ $memberId }}</strong> belongs to household
                        <strong>{{ $householdNo }}</strong>.
                    @endif
                    Nothing was loaded from a database.
                </p>
                <a
                    href="{{ $demoHousehold ? route('household-profiling.view', ['householdNo' => $householdNo]) : route('household-profiling.index') }}"
                    class="lml-hh-member-view__not-found-link lml-focus-ring"
                >
                    {{ $demoHousehold ? 'Back to Household' : 'Return to Household List' }}
                </a>
            </section>
        @else
            <header class="lml-hh-member-view__header">
                <div class="lml-hh-member-view__identity">
                    <a
                        href="{{ route('household-profiling.view', ['householdNo' => $demoHousehold['householdNo']]) }}"
                        class="lml-hh-member-view__back lml-focus-ring"
                        aria-label="Back to household {{ $demoHousehold['householdNo'] }}"
                    >
                        <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    </a>

                    <span class="lml-hh-member-view__avatar" aria-hidden="true">
                        <i class="bi bi-person-fill"></i>
                    </span>

                    <div class="lml-hh-member-view__identity-text">
                        <h2 class="lml-hh-member-view__name" data-offline-local-field="name">{{ $demoMember['name'] }}</h2>
                        <p class="lml-hh-member-view__subtitle">Member Information</p>
                    </div>
                </div>

                <div class="lml-hh-member-view__header-actions">
                    @unless ($isDbMember)
                        <button
                            type="button"
                            class="lml-hh-member-view__btn lml-hh-member-view__btn--delete lml-focus-ring"
                            data-hh-member-view-delete
                            aria-label="Delete {{ $demoMember['name'] }}"
                        >
                            Delete
                        </button>
                    @endunless
                    <a
                        href="{{ route('household-profiling.members.edit', [
                            'householdNo' => $demoHousehold['householdNo'],
                            'memberId' => $demoMember['id'],
                        ]) }}"
                        class="lml-hh-member-view__btn lml-hh-member-view__btn--edit lml-focus-ring"
                        data-hh-nav="edit-member"
                        aria-label="Edit {{ $demoMember['name'] }}"
                    >
                        <i class="bi bi-pencil-square" aria-hidden="true"></i>
                        <span>Edit</span>
                    </a>
                </div>
            </header>

            <div class="lml-hh-member-view__layout">
                <article class="lml-hh-member-view__main-card">
                    <section class="lml-hh-member-view__section" aria-labelledby="lml-hh-mv-personal">
                        <h3 id="lml-hh-mv-personal" class="lml-hh-member-view__section-title">
                            <i class="bi bi-person-fill" aria-hidden="true"></i>
                            <span>Personal Information</span>
                        </h3>
                        <dl class="lml-hh-member-view__dl lml-hh-member-view__dl--paired">
                            <div class="lml-hh-member-view__item">
                                <dt>Full Name</dt>
                                <dd data-offline-local-field="name">{{ $demoMember['name'] }}</dd>
                            </div>
                            <div class="lml-hh-member-view__item">
                                <dt>Relation to Household Head</dt>
                                <dd data-offline-local-field="relation">{{ lml_demo_member_display($demoMember, 'relation') }}</dd>
                            </div>
                            <div class="lml-hh-member-view__item">
                                <dt>Relationship Status</dt>
                                <dd data-offline-local-field="relationship_status">{{ $demoMember['relationship_status'] }}</dd>
                            </div>
                            <div class="lml-hh-member-view__item">
                                <dt>Birthday</dt>
                                <dd data-offline-local-field="birthday">{{ lml_demo_member_display($demoMember, 'birthday') }}</dd>
                            </div>
                            <div class="lml-hh-member-view__item">
                                <dt>Sex</dt>
                                <dd data-offline-local-field="sex">{{ $demoMember['sex'] }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section class="lml-hh-member-view__section" aria-labelledby="lml-hh-mv-socio">
                        <h3 id="lml-hh-mv-socio" class="lml-hh-member-view__section-title">
                            <i class="bi bi-bar-chart-fill" aria-hidden="true"></i>
                            <span>Socio-Economic Details</span>
                        </h3>
                        <dl class="lml-hh-member-view__dl lml-hh-member-view__dl--paired">
                            <div class="lml-hh-member-view__item">
                                <dt>Occupation</dt>
                                <dd data-offline-local-field="occupation">{{ lml_demo_member_display($demoMember, 'occupation') }}</dd>
                            </div>
                            <div class="lml-hh-member-view__item">
                                <dt>Monthly Income</dt>
                                <dd data-offline-local-field="monthly_income">{{ lml_demo_member_display($demoMember, 'monthly_income') }}</dd>
                            </div>
                            <div class="lml-hh-member-view__item">
                                <dt>Religion</dt>
                                <dd data-offline-local-field="religion">{{ lml_demo_member_display($demoMember, 'religion') }}</dd>
                            </div>
                            <div class="lml-hh-member-view__item">
                                <dt>Educational Attainment</dt>
                                <dd data-offline-local-field="education">{{ lml_demo_member_display($demoMember, 'education') }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section class="lml-hh-member-view__section" aria-labelledby="lml-hh-mv-health">
                        <h3 id="lml-hh-mv-health" class="lml-hh-member-view__section-title">
                            <i class="bi bi-heart-pulse-fill" aria-hidden="true"></i>
                            <span>Health &amp; Welfare</span>
                        </h3>
                        <dl class="lml-hh-member-view__dl lml-hh-member-view__dl--paired">
                            <div class="lml-hh-member-view__item">
                                <dt>PhilHealth Number</dt>
                                <dd data-offline-local-field="philhealth">{{ filled($demoMember['philhealth'] ?? null) ? $demoMember['philhealth'] : '—' }}</dd>
                            </div>
                            <div class="lml-hh-member-view__item">
                                <dt>Family Planning</dt>
                                <dd data-offline-local-field="fp_user">{{ filled($demoMember['fp_user'] ?? null) ? $demoMember['fp_user'] : '—' }}</dd>
                            </div>
                            <div class="lml-hh-member-view__item">
                                <dt>Disability Type</dt>
                                <dd data-offline-local-field="disability">{{ lml_demo_member_display($demoMember, 'disability') }}</dd>
                            </div>
                            <div class="lml-hh-member-view__item">
                                <dt>Medical History</dt>
                                <dd data-offline-local-field="medical_history">{{ lml_demo_member_display($demoMember, 'medical_history') }}</dd>
                            </div>
                        </dl>
                    </section>
                </article>

                <aside class="lml-hh-member-view__aside">
                    <section class="lml-hh-member-view__side-card" aria-labelledby="lml-hh-mv-records">
                        <h3 id="lml-hh-mv-records" class="lml-hh-member-view__side-title">
                            <i class="bi bi-journal-medical" aria-hidden="true"></i>
                            <span>Health Summary Records</span>
                        </h3>
                        <ul class="lml-hh-member-view__records">
                            <li
                                class="lml-hh-member-view__record lml-hh-member-view__record--group{{ $childCareExpanded ? ' is-expanded' : '' }}{{ $childCareRouteLocked ? ' is-active-parent' : '' }}"
                                data-hh-member-child-care-group
                            >
                                <button
                                    type="button"
                                    id="lml-hh-mv-child-care-toggle"
                                    class="lml-hh-member-view__accordion-trigger lml-focus-ring"
                                    data-hh-member-child-care-toggle
                                    aria-expanded="{{ $childCareExpanded ? 'true' : 'false' }}"
                                    aria-controls="{{ $childCarePanelId }}"
                                    @if ($childCareRouteLocked) data-route-locked="true" @endif
                                >
                                    <span class="lml-hh-member-view__accordion-label">
                                        <i class="bi bi-heart-pulse" aria-hidden="true"></i>
                                        <span>Child Care</span>
                                    </span>
                                    <i
                                        class="bi bi-chevron-right lml-hh-member-view__accordion-chevron"
                                        aria-hidden="true"
                                    ></i>
                                </button>

                                <div
                                    id="{{ $childCarePanelId }}"
                                    class="lml-hh-member-view__child-panel"
                                    data-hh-member-child-care-panel
                                    role="region"
                                    aria-labelledby="lml-hh-mv-child-care-toggle"
                                    @if (! $childCareExpanded) hidden @endif
                                >
                                    <ul class="lml-hh-member-view__child-list">
                                        @foreach ($childCareLinks as $childLink)
                                            @php
                                                $isActiveChild = $activeChildCareKey === $childLink['key'];
                                                $childHref = route($childLink['route'], [
                                                    'householdNo' => $demoHousehold['householdNo'],
                                                    'memberId' => $demoMember['id'],
                                                ]);
                                            @endphp
                                            <li>
                                                <a
                                                    href="{{ $childHref }}"
                                                    class="lml-hh-member-view__child-link lml-focus-ring{{ $isActiveChild ? ' is-active' : '' }}"
                                                    data-hh-nav="health-record"
                                                    @if ($isActiveChild) aria-current="page" @endif
                                                >
                                                    <span class="lml-hh-member-view__child-link-main">
                                                        <i class="bi {{ $childLink['icon'] }}" aria-hidden="true"></i>
                                                        <span>{{ $childLink['label'] }}</span>
                                                    </span>
                                                    <span class="lml-hh-member-view__child-link-action">View</span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </li>

                            @if ($riskAssessmentUrl)
                                <li class="lml-hh-member-view__record">
                                    <span>Risk Assessment</span>
                                    @if ($riskAssessmentEligible)
                                        <a
                                            href="{{ $riskAssessmentUrl }}"
                                            class="lml-hh-member-view__record-view lml-focus-ring"
                                            data-hh-member-risk-assessment
                                            data-hh-nav="health-record"
                                            aria-label="View Risk Assessment history for {{ $demoMember['name'] }}"
                                        >
                                            View
                                        </a>
                                    @else
                                        <p
                                            class="lml-hh-member-view__record-unavailable"
                                            data-hh-member-risk-assessment-unavailable
                                        >
                                            {{ \App\Support\RiskAssessmentService::INELIGIBLE_MESSAGE }}
                                        </p>
                                    @endif
                                </li>
                            @endif

                            @if ($adultImmunizationUrl)
                                <li class="lml-hh-member-view__record">
                                    <span>Immunization</span>
                                    @if ($adultImmunizationEligible)
                                        <a
                                            href="{{ $adultImmunizationUrl }}"
                                            class="lml-hh-member-view__record-view lml-focus-ring"
                                            data-hh-member-adult-immunization
                                            data-hh-nav="health-record"
                                            aria-label="View Immunization records for {{ $demoMember['name'] }}"
                                        >
                                            View
                                        </a>
                                    @else
                                        <p
                                            class="lml-hh-member-view__record-unavailable"
                                            data-hh-member-adult-immunization-unavailable
                                        >
                                            {{ \App\Support\AdultImmunizationEligibility::INELIGIBLE_MESSAGE }}
                                        </p>
                                    @endif
                                </li>
                            @endif

                            @if ($familyPlanningUrl)
                                <li class="lml-hh-member-view__record">
                                    <span>Family Planning</span>
                                    <a
                                        href="{{ $familyPlanningUrl }}"
                                        class="lml-hh-member-view__record-view lml-focus-ring"
                                        data-hh-member-family-planning
                                        data-hh-nav="health-record"
                                        aria-label="View Family Planning visit records for {{ $demoMember['name'] }}"
                                    >
                                        View
                                    </a>
                                </li>
                            @endif

                            @if ($maternalCareSexEligible)
                                <li class="lml-hh-member-view__record">
                                    <span>Maternal</span>
                                    @if ($maternalCareUrl)
                                        <a
                                            href="{{ $maternalCareUrl }}"
                                            class="lml-hh-member-view__record-view lml-focus-ring"
                                            data-hh-member-maternal-care
                                            data-hh-nav="health-record"
                                            aria-label="View Maternal Care for {{ $demoMember['name'] }}"
                                        >
                                            View
                                        </a>
                                    @else
                                        <p
                                            class="lml-hh-member-view__record-unavailable"
                                            data-hh-member-maternal-care-unavailable
                                        >
                                            {{ \App\Support\MaternalCareEligibility::WORKFLOW_INELIGIBLE_MESSAGE }}
                                        </p>
                                    @endif
                                </li>
                            @endif

                            @if ($deathUrl)
                                <li class="lml-hh-member-view__record">
                                    <span>Death</span>
                                    <a
                                        href="{{ $deathUrl }}"
                                        class="lml-hh-member-view__record-view lml-focus-ring"
                                        data-hh-member-death
                                        data-death-entry="index"
                                        data-hh-nav="health-record"
                                        aria-label="View Death Information for {{ $demoMember['name'] }}"
                                    >
                                        View
                                    </a>
                                </li>
                            @endif
                        </ul>
                    </section>

                    <section class="lml-hh-member-view__side-card" aria-labelledby="lml-hh-mv-nutrition">
                        <div class="lml-hh-member-view__nutrition-head">
                            <h3 id="lml-hh-mv-nutrition" class="lml-hh-member-view__side-title">
                                <i class="bi bi-speedometer2" aria-hidden="true"></i>
                                <span>Nutritional Status</span>
                            </h3>
                            @if ($isDbMember)
                                <a
                                    href="{{ ($offlineResidentPk ?? null)
                                        ? route('households.residents.nutritional-status', [
                                            'householdNo' => $demoHousehold['householdNo'],
                                            'residentId' => $offlineResidentPk,
                                        ])
                                        : route('household-profiling.members.nutritional-status', [
                                            'householdNo' => $demoHousehold['householdNo'],
                                            'memberId' => $demoMember['id'],
                                        ]) }}"
                                    class="lml-hh-member-view__btn lml-hh-member-view__btn--edit lml-focus-ring"
                                    data-hh-nav="edit-nutrition"
                                    aria-label="View nutritional status history for {{ $demoMember['name'] }}"
                                >
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                    <span>Edit</span>
                                </a>
                            @endif
                        </div>
                        <dl class="lml-hh-member-view__nutrition">
                            <div class="lml-hh-member-view__nutrition-row">
                                <dt>Weight</dt>
                                <dd>{{ $nutrition['weight'] }} kg</dd>
                            </div>
                            <div class="lml-hh-member-view__nutrition-row">
                                <dt>Height</dt>
                                <dd>{{ $nutrition['height'] }} cm</dd>
                            </div>
                            @php
                                $nutritionMode = $nutrition['mode'] ?? 'bmi';
                                $nutritionBmi = $nutrition['bmi'] ?? '—';
                                $nutritionStatus = $nutrition['status'] ?? '—';
                            @endphp
                            <div class="lml-hh-member-view__nutrition-row">
                                <dt>{{ $nutritionMode === 'bmi' ? 'BMI' : 'Status' }}</dt>
                                <dd>
                                    @if ($nutritionMode === 'bmi')
                                        @if ($nutritionBmi !== '—')
                                            <span
                                                class="lml-hh-member-view__bmi-badge"
                                                title="Body Mass Index {{ $nutritionBmi }}"
                                            >
                                                @if ($nutritionStatus !== '—')
                                                    {{ $nutritionBmi }} ({{ $nutritionStatus }})
                                                @else
                                                    {{ $nutritionBmi }}
                                                @endif
                                            </span>
                                        @else
                                            —
                                        @endif
                                    @else
                                        @if ($nutritionStatus !== '—')
                                            <span
                                                class="lml-hh-member-view__bmi-badge"
                                                title="Overall Nutritional Status"
                                            >
                                                {{ $nutritionStatus }}
                                            </span>
                                        @else
                                            —
                                        @endif
                                    @endif
                                </dd>
                            </div>
                            @if ($nutrition['muac_applicable'] ?? false)
                                <div class="lml-hh-member-view__nutrition-row">
                                    <dt>MUAC</dt>
                                    <dd>
                                        @if (($nutrition['muac'] ?? '—') !== '—')
                                            <span
                                                class="lml-hh-member-view__bmi-badge"
                                                title="Mid-Upper Arm Circumference {{ $nutrition['muac'] }} cm"
                                            >
                                                @if (($nutrition['muac_status'] ?? '—') !== '—')
                                                    {{ $nutrition['muac'] }} cm ({{ $nutrition['muac_status'] }})
                                                @else
                                                    {{ $nutrition['muac'] }} cm
                                                @endif
                                            </span>
                                        @else
                                            —
                                        @endif
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    </section>
                </aside>
            </div>

            <p class="lml-hh-member-view__demo-note">
                @if ($isLocalMember)
                    Saved on this device for household {{ $demoHousehold['householdNo'] }}. Waiting to sync.
                @elseif ($isDbMember)
                    Registered member {{ $demoMember['id'] }} in household {{ $demoHousehold['householdNo'] }}.
                    Linked health-record sections on this page may still use preview placeholders.
                @else
                    Demo preview for {{ $demoMember['id'] }} in household {{ $demoHousehold['householdNo'] }}.
                    Records are placeholders and are not saved.
                @endif
            </p>

            @unless ($isDbMember)
                <div class="lml-hh-member-view__dialog-backdrop" data-hh-member-view-dialog hidden>
                    <div
                        class="lml-hh-member-view__dialog"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="lml-hh-member-view-delete-title"
                        aria-describedby="lml-hh-member-view-delete-message"
                        tabindex="-1"
                        data-hh-member-view-dialog-panel
                    >
                        <h2 id="lml-hh-member-view-delete-title" class="lml-hh-member-view__dialog-title">
                            Delete member?
                        </h2>
                        <p id="lml-hh-member-view-delete-message" class="lml-hh-member-view__dialog-message">
                            Are you sure you want to delete
                            <strong data-hh-member-view-delete-name>{{ $demoMember['name'] }}</strong>
                            from household
                            <strong>{{ $demoHousehold['householdNo'] }}</strong>?
                        </p>
                        <div class="lml-hh-member-view__dialog-actions">
                            <button
                                type="button"
                                class="lml-hh-member-view__dialog-btn lml-hh-member-view__dialog-btn--cancel lml-focus-ring"
                                data-hh-member-view-dialog-cancel
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                class="lml-hh-member-view__dialog-btn lml-hh-member-view__dialog-btn--confirm lml-focus-ring"
                                data-hh-member-view-dialog-confirm
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            @endunless
        @endif
    </div>
@endsection
