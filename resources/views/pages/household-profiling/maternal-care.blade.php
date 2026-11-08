{{--
    Household Profiling — Maternal Care.
    DB-14: MySQL for persisted residents; session preview for demo members.
--}}
@extends('layouts.dashboard')

@section('title', ($demoMember['name'] ?? 'Member') . ' — Maternal Care - LMLinga')

@section('content')
    @php
        $mcMode = $mcMode ?? 'landing';
        $pregnancy = $pregnancy ?? null;
        $history = $history ?? [];
        $canPersist = (bool) ($canPersist ?? false);
        $mcWriteEligible = (bool) ($mcWriteEligible ?? true);
        $readOnly = (bool) ($readOnly ?? false);
        $routeParams = [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ];
        $statusMessage = session('status');
    @endphp

    <div
        class="lml-mc"
        data-lml-mc
        data-lml-mc-mode="{{ $mcMode }}"
        data-demo="{{ $canPersist ? 'false' : 'true' }}"
        data-persistence="{{ $canPersist ? 'database' : 'session-preview' }}"
        data-mc-readonly="{{ $readOnly ? 'true' : 'false' }}"
        data-mc-write-eligible="{{ $mcWriteEligible ? 'true' : 'false' }}"
        data-household-no="{{ $householdNo }}"
        data-member-id="{{ $memberId }}"
        @if ($demoMember)
            data-member-name="{{ $demoMember['name'] }}"
        @endif
    >
        @if ($statusMessage)
            <p class="lml-mc__toast" role="status" data-mc-toast>
                {{ $statusMessage }}
            </p>
        @endif

        @if ($errors->any())
            <div class="lml-mc__form-errors" role="alert">
                <p>{{ $errors->first() }}</p>
            </div>
        @endif

        @if (! $demoHousehold || ! $demoMember)
            <section class="lml-mc__not-found" aria-labelledby="lml-mc-nf-title">
                <h2 id="lml-mc-nf-title" class="lml-mc__not-found-title">
                    Member not found
                </h2>
                <p class="lml-mc__not-found-message">
                    @if (! $demoHousehold)
                        No household matches <strong>{{ $householdNo }}</strong>.
                    @else
                        No member <strong>{{ $memberId }}</strong> belongs to household
                        <strong>{{ $householdNo }}</strong>.
                    @endif
                </p>
                <a
                    href="{{ $demoHousehold ? route('household-profiling.view', ['householdNo' => $householdNo]) : route('household-profiling.index') }}"
                    class="lml-mc__not-found-link lml-focus-ring"
                >
                    {{ $demoHousehold ? 'Back to Household' : 'Return to Household List' }}
                </a>
            </section>
        @else
            @include('pages.household-profiling.partials.maternal-care-member-card', [
                'demoMember' => $demoMember,
                'householdNo' => $householdNo,
                'memberId' => $memberId,
                'pregnancy' => $pregnancy,
                'showActivePregnancy' => is_array($pregnancy) && ($pregnancy['status'] ?? '') === 'active',
                'historyStatus' => $mcMode === 'history-show' ? (string) ($pregnancy['status'] ?? '') : '',
                'backUrl' => in_array($mcMode, ['landing', 'overview', 'register', 'history', 'history-show'], true)
                    ? ($mcMode === 'history-show'
                        ? route('household-profiling.members.maternal-care.history', $routeParams)
                        : route('household-profiling.members.show', $routeParams))
                    : route('household-profiling.members.maternal-care.index', $routeParams),
                'backLabel' => $mcMode === 'history-show'
                    ? 'Back to Pregnancy History for '.$demoMember['name']
                    : (in_array($mcMode, ['landing', 'overview', 'register', 'history'], true)
                    ? 'Back to Health Summary Records for '.$demoMember['name']
                    : 'Back to Maternal Care overview for '.$demoMember['name']),
            ])

            @if ($mcMode === 'landing')
                @include('pages.household-profiling.partials.maternal-care-landing', [
                    'mcWriteEligible' => $mcWriteEligible,
                    'hasClosedEpisode' => $hasClosedEpisode ?? false,
                ])
            @elseif ($mcMode === 'register')
                @include('pages.household-profiling.partials.maternal-care-register', [
                    'routeParams' => $routeParams,
                    'canPersist' => $canPersist,
                ])
            @elseif ($mcMode === 'overview')
                @include('pages.household-profiling.partials.maternal-care-overview', [
                    'pregnancy' => $pregnancy,
                    'routeParams' => $routeParams,
                    'history' => $history,
                ])
            @elseif ($mcMode === 'history')
                @include('pages.household-profiling.partials.maternal-care-history', [
                    'history' => $history,
                    'pregnancy' => $pregnancy,
                    'routeParams' => $routeParams,
                ])
            @elseif ($mcMode === 'history-show')
                @include('pages.household-profiling.partials.maternal-care-history-show', [
                    'pregnancy' => $pregnancy,
                    'routeParams' => $routeParams,
                ])
            @elseif ($mcMode === 'trans-out')
                @include('pages.household-profiling.partials.maternal-care-trans-out', [
                    'pregnancy' => $pregnancy,
                    'routeParams' => $routeParams,
                    'canPersist' => $canPersist,
                ])
            @elseif ($mcMode === 'prenatal')
                @include('pages.household-profiling.partials.maternal-care-prenatal', [
                    'pregnancy' => $pregnancy,
                    'routeParams' => $routeParams,
                    'canPersist' => $canPersist,
                ])
            @elseif ($mcMode === 'immunizations')
                @include('pages.household-profiling.partials.maternal-care-immunizations', [
                    'pregnancy' => $pregnancy,
                    'routeParams' => $routeParams,
                    'canPersist' => $canPersist,
                ])
            @elseif ($mcMode === 'supplementations')
                @include('pages.household-profiling.partials.maternal-care-supplementations', [
                    'pregnancy' => $pregnancy,
                    'routeParams' => $routeParams,
                    'canPersist' => $canPersist,
                ])
            @elseif ($mcMode === 'laboratory')
                @include('pages.household-profiling.partials.maternal-care-laboratory', [
                    'pregnancy' => $pregnancy,
                    'routeParams' => $routeParams,
                    'canPersist' => $canPersist,
                ])
            @elseif ($mcMode === 'delivery')
                @include('pages.household-profiling.partials.maternal-care-delivery', [
                    'pregnancy' => $pregnancy,
                    'routeParams' => $routeParams,
                    'canPersist' => $canPersist,
                ])
            @elseif ($mcMode === 'postnatal')
                @include('pages.household-profiling.partials.maternal-care-postnatal', [
                    'pregnancy' => $pregnancy,
                    'routeParams' => $routeParams,
                    'canPersist' => $canPersist,
                ])
            @endif
        @endif
    </div>
@endsection
