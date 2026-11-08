{{--
    Household Profiling — Edit Member (DB-05 Phase 4).
    Persistable only for database-backed residents.
--}}
@extends('layouts.dashboard')

@section('title', 'Edit Member - LMLinga')

@section('content')
    @php
        $formMode = $formMode ?? 'edit';
        $memberValues = $memberValues ?? ($demoMember ?? []);
    @endphp

    <div
        class="lml-hh-member-form"
        data-lml-hh-member-form
        data-mode="edit"
        data-source="{{ $householdSource ?? 'none' }}"
        data-persistable="{{ ($persistable ?? false) ? '1' : '0' }}"
        data-household-no="{{ $householdNo }}"
        data-member-id="{{ $memberId }}"
        data-view-url="{{ route('household-profiling.members.show', ['householdNo' => $householdNo, 'memberId' => $memberId]) }}"
    >
        <a
            href="{{ route('household-profiling.members.show', ['householdNo' => $householdNo, 'memberId' => $memberId]) }}"
            class="lml-hh-member-form__back lml-focus-ring"
            data-hh-member-back
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span>Back to Member</span>
        </a>

        @if (! $demoHousehold || ! $demoMember)
            <section class="lml-hh-member-form__not-found" aria-labelledby="lml-hh-member-nf-title">
                <span class="lml-hh-member-form__not-found-icon" aria-hidden="true">
                    <i class="bi bi-person-x"></i>
                </span>
                <h2 id="lml-hh-member-nf-title" class="lml-hh-member-form__not-found-title">
                    Member not found
                </h2>
                <p class="lml-hh-member-form__not-found-message">
                    The requested household or member could not be found for editing.
                </p>
                <a
                    href="{{ $demoHousehold ? route('household-profiling.view', ['householdNo' => $householdNo]) : route('household-profiling.index') }}"
                    class="lml-hh-member-form__not-found-link lml-focus-ring"
                >
                    {{ $demoHousehold ? 'Back to Household' : 'Return to Household List' }}
                </a>
            </section>
        @else
            <div class="lml-hh-member-form__card">
                <div class="lml-hh-member-form__titlebar">
                    <i class="bi bi-person-fill" aria-hidden="true"></i>
                    <h2 class="lml-hh-member-form__title">Edit Member</h2>
                </div>

                <p class="lml-hh-member-form__context">
                    Editing <strong data-offline-local-field="name">{{ $demoMember['name'] }}</strong> in household <strong>{{ $demoHousehold['displayNo'] }}</strong>.
                </p>

                <p class="lml-hh-member-form__required-note">
                    Fields marked with <span aria-hidden="true">*</span><span class="visually-hidden">asterisk</span> are required.
                </p>

                <div class="lml-hh-member-form__toast" data-hh-member-toast role="status" aria-live="polite" hidden></div>

                @if ($errors->any())
                    <div class="lml-hh-member-form__summary" id="lml-hh-member-summary" data-hh-member-summary role="alert" tabindex="-1">
                        <p class="lml-hh-member-form__summary-text">
                            Please complete the required fields before updating this member.
                        </p>
                        <ul class="lml-hh-member-form__summary-list" data-hh-member-summary-list>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <div class="lml-hh-member-form__summary" id="lml-hh-member-summary" data-hh-member-summary role="alert" tabindex="-1" hidden>
                        <p class="lml-hh-member-form__summary-text">
                            Please complete the required fields before updating this member.
                        </p>
                        <ul class="lml-hh-member-form__summary-list" data-hh-member-summary-list></ul>
                    </div>
                @endif

                <form
                    class="lml-hh-member-form__form"
                    data-hh-member-form-el
                    novalidate
                    method="post"
                    @if (! empty($offlineLocalMember))
                        action="{{ route('household-profiling.members.edit', ['householdNo' => $householdNo, 'memberId' => $memberId]) }}"
                    @else
                        action="{{ route('household-profiling.members.update', ['householdNo' => $householdNo, 'memberId' => $memberId]) }}"
                    @endif
                    data-offline-operation="RESIDENT_UPDATE"
                    @if (! empty($offlineHouseholdPk))
                        data-offline-parent-household-id="{{ $offlineHouseholdPk }}"
                    @endif
                    data-offline-parent-household-no="{{ $householdNo }}"
                    @if (! empty($offlineResidentPk))
                        data-offline-parent-resident-id="{{ $offlineResidentPk }}"
                    @endif
                    data-offline-parent-member-no="{{ $memberId }}"
                    @if (! empty($offlineLocalMember))
                        data-offline-local-member="1"
                    @endif
                    @if (! empty($offlineFieldHash))
                        data-offline-field-hash="{{ $offlineFieldHash }}"
                    @endif
                >
                    <p class="lml-offline-form-pending" data-lml-offline-form-pending hidden>Waiting to sync</p>
                    @csrf
                    @unless (! empty($offlineLocalMember))
                        @method('PUT')
                    @endunless
                    @include('pages.household-profiling.partials.member-form-fields', [
                        'memberValues' => session()->hasOldInput() ? old() : ($memberValues ?? []),
                    ])

                    <div class="lml-hh-member-form__actions">
                        <button type="button" class="lml-hh-member-form__btn lml-hh-member-form__btn--cancel lml-focus-ring" data-hh-member-cancel>
                            Cancel
                        </button>
                        <button type="submit" class="lml-hh-member-form__btn lml-hh-member-form__btn--save lml-focus-ring" data-hh-member-save>
                            Update
                        </button>
                    </div>
                </form>
            </div>

            <div class="lml-hh-member-form__dialog-backdrop" data-hh-member-dialog hidden>
                <div
                    class="lml-hh-member-form__dialog"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="lml-hh-member-discard-title"
                    aria-describedby="lml-hh-member-discard-message"
                    tabindex="-1"
                    data-hh-member-dialog-panel
                >
                    <h2 id="lml-hh-member-discard-title" class="lml-hh-member-form__dialog-title">
                        Discard member changes?
                    </h2>
                    <p id="lml-hh-member-discard-message" class="lml-hh-member-form__dialog-message">
                        You have unsaved changes. Are you sure you want to leave this page?
                    </p>
                    <div class="lml-hh-member-form__dialog-actions">
                        <button type="button" class="lml-hh-member-form__dialog-btn lml-hh-member-form__dialog-btn--continue lml-focus-ring" data-hh-member-dialog-continue>
                            Continue Editing
                        </button>
                        <button type="button" class="lml-hh-member-form__dialog-btn lml-hh-member-form__dialog-btn--discard lml-focus-ring" data-hh-member-dialog-discard>
                            Discard Changes
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
