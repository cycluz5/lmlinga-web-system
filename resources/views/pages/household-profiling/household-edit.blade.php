{{--
    Household Profiling — Edit Household (DB-17 Phase 2A).
    Persists to households table; household_no is read-only.
--}}
@extends('layouts.dashboard')

@section('title', 'Edit Household - LMLinga')

@section('content')
    @php
        $persistable = $persistable ?? false;
        $householdSource = $householdSource ?? null;
        $householdValues = session()->hasOldInput()
            ? array_merge($householdValues ?? [], old())
            : ($householdValues ?? []);
    @endphp

    <div
        class="lml-hh-member-form"
        data-lml-hh-shell-form
        data-mode="edit"
        data-source="{{ $householdSource ?? 'none' }}"
    >
        <a
            href="{{ $demoHousehold ? route('household-profiling.view', ['householdNo' => $householdNo]) : route('household-profiling.index') }}"
            class="lml-hh-member-form__back lml-focus-ring"
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span>Back to Household</span>
        </a>

        @if (! $persistable)
            <section class="lml-hh-member-form__not-found" aria-labelledby="lml-hh-shell-nf-title">
                <span class="lml-hh-member-form__not-found-icon" aria-hidden="true">
                    <i class="bi bi-house-x"></i>
                </span>
                <h2 id="lml-hh-shell-nf-title" class="lml-hh-member-form__not-found-title">
                    Household not found
                </h2>
                <p class="lml-hh-member-form__not-found-message">
                    No registered household matches <strong>{{ $householdNo }}</strong>.
                    Demo-only households cannot be edited here.
                </p>
                <a href="{{ route('household-profiling.index') }}" class="lml-hh-member-form__not-found-link lml-focus-ring">
                    Return to Household List
                </a>
            </section>
        @else
            <div class="lml-hh-member-form__card">
                <div class="lml-hh-member-form__titlebar">
                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                    <h2 class="lml-hh-member-form__title">Edit Household</h2>
                </div>

                <p class="lml-hh-member-form__context">
                    Editing household <strong>{{ $demoHousehold['displayNo'] ?? $householdNo }}</strong>.
                    Household number cannot be changed.
                </p>

                <p class="lml-hh-member-form__required-note">
                    Fields marked with <span aria-hidden="true">*</span><span class="visually-hidden">asterisk</span> are required.
                </p>

                @if ($errors->any())
                    <div class="lml-hh-member-form__summary" role="alert" tabindex="-1">
                        <p class="lml-hh-member-form__summary-text">
                            Please correct the highlighted fields before saving.
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
                    action="{{ route('household-profiling.update', ['householdNo' => $householdNo]) }}"
                    novalidate
                    data-offline-operation="HOUSEHOLD_UPDATE"
                    @if (! empty($offlineHouseholdPk))
                        data-offline-parent-household-id="{{ $offlineHouseholdPk }}"
                    @endif
                    data-offline-parent-household-no="{{ $householdNo }}"
                    @if (! empty($offlineFieldHash))
                        data-offline-field-hash="{{ $offlineFieldHash }}"
                    @endif
                >
                    <p class="lml-offline-form-pending" data-lml-offline-form-pending hidden>Waiting to sync</p>
                    @csrf
                    @method('PUT')
                    @include('pages.household-profiling.partials.household-form-fields', [
                        'householdValues' => $householdValues,
                        'showHouseholdNo' => true,
                    ])

                    <div class="lml-hh-member-form__actions">
                        <a
                            href="{{ route('household-profiling.view', ['householdNo' => $householdNo]) }}"
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
        @endif
    </div>
@endsection
