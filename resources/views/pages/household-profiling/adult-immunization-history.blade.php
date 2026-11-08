{{--
    Household Profiling — Adult Immunization (Health Summary).
    Same card-grid + Edit/Save pattern as Child Immunization, sized down to
    this feature's two 1-dose vaccines. Separate from Child Care immunization.
--}}
@extends('layouts.dashboard')

@section('title', ($demoMember['name'] ?? 'Member') . ' — Immunization - LMLinga')

@section('content')
    @php
        $memberUrl = route('household-profiling.members.show', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);
        $storeUrl = route('household-profiling.members.adult-immunization.store', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);
        $datesByType = $datesByType ?? [];
        $vaccineCards = [
            ['type' => \App\Support\AdultImmunizationErdMode::VACCINE_PNEUMOCOCCAL, 'title' => 'Pneumococcal Vaccine'],
            ['type' => \App\Support\AdultImmunizationErdMode::VACCINE_FLU, 'title' => 'Flu Vaccine'],
        ];
    @endphp

    <div class="lml-adult-imm" data-lml-adult-imm data-lml-adult-imm-mode="history">
        @if (! $demoHousehold || ! $demoMember)
            <section class="lml-adult-imm__not-found" aria-labelledby="lml-adult-imm-nf-title">
                <h2 id="lml-adult-imm-nf-title" class="lml-adult-imm__not-found-title">Member not found</h2>
                <p class="lml-adult-imm__not-found-message">No record found.</p>
                <a
                    href="{{ $demoHousehold ? route('household-profiling.view', ['householdNo' => $householdNo]) : route('household-profiling.index') }}"
                    class="lml-adult-imm__not-found-link lml-focus-ring"
                >
                    {{ $demoHousehold ? 'Back to Household' : 'Return to Household List' }}
                </a>
            </section>
        @elseif (! ($eligible ?? false))
            <section class="lml-adult-imm__ineligible" data-adult-imm-ineligible aria-labelledby="lml-adult-imm-ineligible-title">
                <a href="{{ $memberUrl }}" class="lml-adult-imm__back lml-focus-ring">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    Back to Member
                </a>
                <h2 id="lml-adult-imm-ineligible-title" class="lml-adult-imm__ineligible-title">Immunization unavailable</h2>
                <p class="lml-adult-imm__ineligible-message">
                    {{ \App\Support\AdultImmunizationEligibility::INELIGIBLE_MESSAGE }}
                </p>
            </section>
        @else
            <header class="lml-adult-imm__head">
                <a href="{{ $memberUrl }}" class="lml-adult-imm__back lml-focus-ring">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    Back to Member
                </a>
                <h1 class="lml-adult-imm__title">Immunization</h1>
                <p class="lml-adult-imm__member">{{ $demoMember['name'] }} · {{ $memberId }}</p>
            </header>

            @if (session('status'))
                <p class="lml-adult-imm__notice" role="status">{{ session('status') }}</p>
            @endif

            @if (! ($tableAvailable ?? false))
                <p class="lml-adult-imm__notice">{{ \App\Support\AdultImmunizationErdMode::TABLE_MISSING_MESSAGE }}</p>
            @elseif (! ($canPersist ?? false))
                <p class="lml-adult-imm__notice">Adult immunization can be saved only for registered household members.</p>
            @else
                <section class="lml-adult-imm__panel" aria-labelledby="lml-adult-imm-heading">
                    <form
                        class="lml-adult-imm__immunization"
                        data-adult-imm-form
                        data-editing="false"
                        method="post"
                        action="{{ $storeUrl }}"
                        novalidate
                    >
                        @csrf

                        @if ($errors->any())
                            <div class="lml-adult-imm__errors" role="alert">
                                <p>{{ $errors->first() }}</p>
                            </div>
                        @endif

                        <div class="lml-adult-imm__imm-head">
                            <div class="lml-adult-imm__imm-intro">
                                <h2 id="lml-adult-imm-heading" class="lml-adult-imm__imm-title">
                                    <i class="bi bi-syringe" aria-hidden="true"></i>
                                    <span>Immunization</span>
                                </h2>
                                <p class="lml-adult-imm__imm-desc">
                                    Vaccination records that support immunity and protection against infectious diseases.
                                </p>
                            </div>
                            <div class="lml-adult-imm__imm-actions">
                                <button
                                    type="button"
                                    class="lml-adult-imm__edit lml-focus-ring"
                                    data-adult-imm-edit
                                    aria-label="Edit adult immunization"
                                >
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                    <span>Edit</span>
                                </button>
                                <button
                                    type="submit"
                                    class="lml-adult-imm__save lml-focus-ring"
                                    data-adult-imm-save
                                    aria-label="Save adult immunization"
                                    hidden
                                >
                                    <span>Save</span>
                                </button>
                            </div>
                        </div>

                        <div class="lml-adult-imm__vaccine-grid">
                            @foreach ($vaccineCards as $card)
                                @php
                                    $fieldId = 'lml-adult-imm-'.\Illuminate\Support\Str::slug($card['type']);
                                    $existingDate = old("dates.{$card['type']}", $datesByType[$card['type']] ?? '');
                                @endphp
                                <section
                                    class="lml-adult-imm__vaccine-card"
                                    aria-labelledby="{{ $fieldId }}-title"
                                >
                                    <h3 id="{{ $fieldId }}-title" class="lml-adult-imm__vaccine-title">
                                        {{ $card['title'] }}
                                    </h3>
                                    <div class="lml-adult-imm__dose">
                                        <label class="lml-adult-imm__dose-label" for="{{ $fieldId }}">
                                            Date Given
                                        </label>
                                        <div class="lml-adult-imm__date-wrap">
                                            <input
                                                type="date"
                                                id="{{ $fieldId }}"
                                                name="dates[{{ $card['type'] }}]"
                                                value="{{ $existingDate }}"
                                                class="lml-adult-imm__date-input lml-focus-ring"
                                                data-adult-imm-field
                                                autocomplete="off"
                                                max="{{ now()->toDateString() }}"
                                                readonly
                                                disabled
                                            >
                                        </div>
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    </form>
                </section>
            @endif
        @endif
    </div>
@endsection
