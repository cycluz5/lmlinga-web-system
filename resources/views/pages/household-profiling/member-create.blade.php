{{--
    Household Profiling — Add New Member (DB-05 Phase 4).
    Persistable only when the route household exists in the database.
--}}
@extends('layouts.dashboard')

@section('title', 'Add New Member - LMLinga')

@section('content')
    @php
        $formMode = $formMode ?? 'create';
        $memberValues = $memberValues ?? [];
        $persistable = $persistable ?? false;
        $householdSource = $householdSource ?? null;
    @endphp

    <div
        class="lml-hh-member-form"
        data-lml-hh-member-form
        data-mode="create"
        data-source="{{ $householdSource ?? 'none' }}"
        data-persistable="{{ $persistable ? '1' : '0' }}"
        data-household-no="{{ $householdNo }}"
        data-view-url="{{ route('household-profiling.view', ['householdNo' => $householdNo]) }}"
    >
        <a
            href="{{ route('household-profiling.view', ['householdNo' => $householdNo]) }}"
            class="lml-hh-member-form__back lml-focus-ring"
            data-hh-member-back
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span>Back to Household</span>
        </a>

        @if (! $demoHousehold)
            <section class="lml-hh-member-form__not-found" aria-labelledby="lml-hh-member-nf-title">
                <span class="lml-hh-member-form__not-found-icon" aria-hidden="true">
                    <i class="bi bi-house-x"></i>
                </span>
                <h2 id="lml-hh-member-nf-title" class="lml-hh-member-form__not-found-title">
                    Household not found
                </h2>
                <p class="lml-hh-member-form__not-found-message">
                    No registered household matches <strong>{{ $householdNo }}</strong>.
                </p>
                <a href="{{ route('household-profiling.index') }}" class="lml-hh-member-form__not-found-link lml-focus-ring">
                    Return to Household List
                </a>
            </section>
        @elseif (! $persistable)
            <section class="lml-hh-member-form__not-found" aria-labelledby="lml-hh-member-preview-title">
                <span class="lml-hh-member-form__not-found-icon" aria-hidden="true">
                    <i class="bi bi-house-lock"></i>
                </span>
                <h2 id="lml-hh-member-preview-title" class="lml-hh-member-form__not-found-title">
                    Demo preview only
                </h2>
                <p class="lml-hh-member-form__not-found-message">
                    Household <strong>{{ $householdNo }}</strong> exists only in the demo catalog.
                    Members cannot be saved until this household is registered in the database.
                    Nothing is saved.
                </p>
                <a href="{{ route('household-profiling.view', ['householdNo' => $householdNo]) }}" class="lml-hh-member-form__not-found-link lml-focus-ring">
                    Back to Household
                </a>
            </section>
        @else
            <div class="lml-hh-member-form__card">
                <div class="lml-hh-member-form__titlebar">
                    <i class="bi bi-person-plus-fill" aria-hidden="true"></i>
                    <h2 class="lml-hh-member-form__title">Add New Member</h2>
                </div>

                <p class="lml-hh-member-form__context">
                    Adding to household <strong>{{ $demoHousehold['displayNo'] }}</strong>
                    ({{ $demoHousehold['houseHead'] }}).
                </p>

                <p class="lml-hh-member-form__required-note">
                    Fields marked with <span aria-hidden="true">*</span><span class="visually-hidden">asterisk</span> are required.
                </p>

                <div class="lml-hh-member-form__toast" data-hh-member-toast role="status" aria-live="polite" hidden></div>

                @if (session('status'))
                    <p class="lml-hh-member-form__context" role="status">{{ session('status') }}</p>
                @endif

                @if ($errors->any())
                    <div class="lml-hh-member-form__summary" id="lml-hh-member-summary" data-hh-member-summary role="alert" tabindex="-1">
                        <p class="lml-hh-member-form__summary-text">
                            Please complete the required fields before adding this member.
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
                            Please complete the required fields before adding this member.
                        </p>
                        <ul class="lml-hh-member-form__summary-list" data-hh-member-summary-list></ul>
                    </div>
                @endif

                <form
                    class="lml-hh-member-form__form"
                    data-hh-member-form-el
                    novalidate
                    method="post"
                    action="{{ route('household-profiling.members.store', ['householdNo' => $householdNo]) }}"
                    data-offline-operation="RESIDENT_CREATE"
                    @if (! empty($offlineHouseholdPk))
                        data-offline-parent-household-id="{{ $offlineHouseholdPk }}"
                    @endif
                    data-offline-parent-household-no="{{ $householdNo }}"
                >
                    <p class="lml-offline-form-pending" data-lml-offline-form-pending hidden>Waiting to sync</p>
                    @csrf
                    @include('pages.household-profiling.partials.member-form-fields', [
                        'memberValues' => session()->hasOldInput() ? old() : ($memberValues ?? []),
                    ])

                    <div class="lml-hh-member-form__actions">
                        <button type="button" class="lml-hh-member-form__btn lml-hh-member-form__btn--cancel lml-focus-ring" data-hh-member-cancel>
                            Cancel
                        </button>
                        <button type="submit" class="lml-hh-member-form__btn lml-hh-member-form__btn--save lml-focus-ring" data-hh-member-save>
                            Save
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
                        Discard member information?
                    </h2>
                    <p id="lml-hh-member-discard-message" class="lml-hh-member-form__dialog-message">
                        You have unsaved information. Are you sure you want to leave this form?
                    </p>
                    <div class="lml-hh-member-form__dialog-actions">
                        <button type="button" class="lml-hh-member-form__dialog-btn lml-hh-member-form__dialog-btn--continue lml-focus-ring" data-hh-member-dialog-continue>
                            Continue Editing
                        </button>
                        <button type="button" class="lml-hh-member-form__dialog-btn lml-hh-member-form__dialog-btn--discard lml-focus-ring" data-hh-member-dialog-discard>
                            Discard
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <template data-offline-local-member-template>
        <div class="lml-hh-member-view" data-lml-hh-member-view data-offline-local-member="1">
            <h2 class="lml-hh-member-view__name" data-offline-local-field="name">Queued member</h2>
            <p>This member is saved on this device and will sync when you reconnect.</p>
            <dl>
                <div><dt>Last name</dt><dd data-offline-local-field="last_name">—</dd></div>
                <div><dt>First name</dt><dd data-offline-local-field="first_name">—</dd></div>
                <div><dt>Middle name</dt><dd data-offline-local-field="middle_name">—</dd></div>
                <div><dt>Relation</dt><dd data-offline-local-field="relation">—</dd></div>
                <div><dt>Sex</dt><dd data-offline-local-field="sex">—</dd></div>
                <div><dt>Birthday</dt><dd data-offline-local-field="birthday">—</dd></div>
            </dl>
        </div>
    </template>
@endsection
