{{--
    Household Profiling — Register Household (DB-17 Phase 2A).
    Persists to households table; household_no is entered by staff.
    R01-C: also reachable from Spot Mapping → Plot New Household.
--}}
@extends('layouts.dashboard')

@section('title', 'Register Household - LMLinga')

@section('content')
    @php
        $householdValues = session()->hasOldInput() ? old() : ($householdValues ?? []);
        $fromSpotMapping = ($from ?? null) === 'spot-mapping'
            || old('from') === 'spot-mapping';
        $backUrl = $fromSpotMapping
            ? route('spot-mapping.index')
            : route('household-profiling.index');
        $backLabel = $fromSpotMapping ? 'Back to Spot Mapping' : 'Back to Household List';
        $plotExistingUrl = route('spot-mapping.index', ['plot' => 1]);
    @endphp

    <div class="lml-hh-member-form" data-lml-hh-shell-form data-mode="create">
        <a
            href="{{ $backUrl }}"
            class="lml-hh-member-form__back lml-focus-ring"
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span>{{ $backLabel }}</span>
        </a>

        <div class="lml-hh-member-form__card">
            <div class="lml-hh-member-form__titlebar">
                <i class="bi bi-house-add-fill" aria-hidden="true"></i>
                <h2 class="lml-hh-member-form__title">Register Household</h2>
            </div>

            @if ($fromSpotMapping)
                <p class="lml-hh-member-form__context">
                    Already registered?
                    <a href="{{ $plotExistingUrl }}" class="lml-focus-ring">Plot an existing household on Spot Mapping</a>
                    instead.
                </p>
            @endif

            @if ($errors->any())
                <div class="lml-hh-member-form__summary" role="alert" tabindex="-1">
                    <p class="lml-hh-member-form__summary-text">
                        Please review the information below.
                    </p>
                    <ul class="lml-hh-member-form__summary-list">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form
                class="lml-hh-member-form__form"
                method="post"
                action="{{ route('household-profiling.store') }}"
                novalidate
                data-offline-operation="HOUSEHOLD_CREATE"
            >
                <p class="lml-offline-form-pending" data-lml-offline-form-pending hidden>Waiting to sync</p>
                @csrf
                @if ($fromSpotMapping)
                    <input type="hidden" name="from" value="spot-mapping">
                @endif
                @include('pages.household-profiling.partials.household-form-fields', [
                    'householdValues' => $householdValues,
                    'showHouseholdNo' => true,
                    'householdNoEditable' => true,
                ])

                <div class="lml-hh-member-form__actions">
                    <a
                        href="{{ $backUrl }}"
                        class="lml-hh-member-form__btn lml-hh-member-form__btn--cancel lml-focus-ring"
                    >
                        Cancel
                    </a>
                    <button type="submit" class="lml-hh-member-form__btn lml-hh-member-form__btn--save lml-focus-ring">
                        Save
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
