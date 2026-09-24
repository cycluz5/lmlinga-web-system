{{--
    Health Records → Maternal → Non-Residents → Add Non-Resident Maternal Client.
    Female-only workflow. Sex is not collected. BMI is derived (kg / m²).
--}}
@extends('layouts.dashboard')

@section('title', 'Add Non-Resident Maternal Client - LMLinga')

@section('content')
    @php
        $listingUrl = route('health-records.maternal.non-residents.index');
        $statusOptions = $statusOptions ?? [];
        $storeUrl = route('health-records.maternal.non-residents.store');
    @endphp

    <div class="lml-hr-mc lml-hr-mc-add" data-lml-hr-mc-add>
        <header class="lml-hr-mc-add__head">
            <a
                href="{{ $listingUrl }}"
                class="lml-hr-mc__back-btn lml-focus-ring"
                data-hr-mc-add-back
                aria-label="Back to Non-Resident Maternal listing"
            >
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Back</span>
            </a>
        </header>

        <p class="lml-hr-mc-add__banner" data-hr-mc-add-banner>
            <span class="lml-hr-mc-add__banner-text">Add New Non Resident</span>
        </p>

        <form
            method="post"
            action="{{ $storeUrl }}"
            class="lml-hr-mc-add__form"
            data-hr-mc-add-form
            novalidate
        >
            @csrf

            <section
                class="lml-hr-mc-add__card"
                data-hr-mc-add-card="personal"
                aria-labelledby="lml-hr-mc-add-personal-heading"
            >
                <h2 class="lml-hr-mc-add__card-title" id="lml-hr-mc-add-personal-heading">
                    <i class="bi bi-person" aria-hidden="true"></i>
                    <span>Personal Information</span>
                </h2>

                    <div class="lml-hr-mc-add__grid lml-hr-mc-add__grid--3">
                        <div class="lml-hr-mc-add__field">
                            <label for="lml-hr-mc-first-name">First Name</label>
                            <input
                                id="lml-hr-mc-first-name"
                                name="first_name"
                                type="text"
                                class="lml-hr-mc-add__input lml-focus-ring @error('first_name') is-invalid @enderror"
                                value="{{ old('first_name') }}"
                                autocomplete="given-name"
                                aria-required="true"
                                @error('first_name') aria-invalid="true" aria-describedby="lml-hr-mc-first-name-error" @enderror
                            >
                            @error('first_name')
                                <p id="lml-hr-mc-first-name-error" class="lml-hr-mc-add__error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="lml-hr-mc-add__field">
                            <label for="lml-hr-mc-middle-name">Middle Name</label>
                            <input
                                id="lml-hr-mc-middle-name"
                                name="middle_name"
                                type="text"
                                class="lml-hr-mc-add__input lml-focus-ring @error('middle_name') is-invalid @enderror"
                                value="{{ old('middle_name') }}"
                                autocomplete="additional-name"
                                @error('middle_name') aria-invalid="true" aria-describedby="lml-hr-mc-middle-name-error" @enderror
                            >
                            @error('middle_name')
                                <p id="lml-hr-mc-middle-name-error" class="lml-hr-mc-add__error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="lml-hr-mc-add__field">
                            <label for="lml-hr-mc-last-name">Last Name</label>
                            <input
                                id="lml-hr-mc-last-name"
                                name="last_name"
                                type="text"
                                class="lml-hr-mc-add__input lml-focus-ring @error('last_name') is-invalid @enderror"
                                value="{{ old('last_name') }}"
                                autocomplete="family-name"
                                aria-required="true"
                                @error('last_name') aria-invalid="true" aria-describedby="lml-hr-mc-last-name-error" @enderror
                            >
                            @error('last_name')
                                <p id="lml-hr-mc-last-name-error" class="lml-hr-mc-add__error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="lml-hr-mc-add__grid lml-hr-mc-add__grid--2">
                        <div class="lml-hr-mc-add__field">
                            <label for="lml-hr-mc-birthday">Birthday</label>
                            <input
                                id="lml-hr-mc-birthday"
                                name="birthday"
                                type="date"
                                class="lml-hr-mc-add__input lml-focus-ring @error('birthday') is-invalid @enderror"
                                value="{{ old('birthday') }}"
                                autocomplete="bday"
                                aria-required="true"
                                @error('birthday') aria-invalid="true" aria-describedby="lml-hr-mc-birthday-error" @enderror
                            >
                            @error('birthday')
                                <p id="lml-hr-mc-birthday-error" class="lml-hr-mc-add__error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="lml-hr-mc-add__field">
                            <label for="lml-hr-mc-status">Status</label>
                            <select
                                id="lml-hr-mc-status"
                                name="status"
                                class="lml-hr-mc-add__input lml-focus-ring @error('status') is-invalid @enderror"
                                aria-required="true"
                                @error('status') aria-invalid="true" aria-describedby="lml-hr-mc-status-error" @enderror
                            >
                                <option value="">Select status</option>
                                @foreach ($statusOptions as $option)
                                    <option value="{{ $option }}" @selected(old('status') === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                            @error('status')
                                <p id="lml-hr-mc-status-error" class="lml-hr-mc-add__error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="lml-hr-mc-add__grid lml-hr-mc-add__grid--1">
                        <div class="lml-hr-mc-add__field">
                            <label for="lml-hr-mc-complete-address">Complete Address</label>
                            <input
                                id="lml-hr-mc-complete-address"
                                name="complete_address"
                                type="text"
                                class="lml-hr-mc-add__input lml-focus-ring @error('complete_address') is-invalid @enderror"
                                value="{{ old('complete_address') }}"
                                placeholder="Complete Address"
                                autocomplete="street-address"
                                aria-required="true"
                                @error('complete_address') aria-invalid="true" aria-describedby="lml-hr-mc-complete-address-error" @enderror
                            >
                            @error('complete_address')
                                <p id="lml-hr-mc-complete-address-error" class="lml-hr-mc-add__error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
            </section>

            <section
                class="lml-hr-mc-add__card"
                data-hr-mc-add-card="pregnancy"
                aria-labelledby="lml-hr-mc-add-pregnancy-heading"
            >
                <h2 class="lml-hr-mc-add__card-title" id="lml-hr-mc-add-pregnancy-heading">
                    <i class="bi bi-heart-pulse" aria-hidden="true"></i>
                    <span>Pregnancy Information</span>
                </h2>
                    <div class="lml-hr-mc-add__grid lml-hr-mc-add__grid--4">
                        <div class="lml-hr-mc-add__field">
                            <label for="lml-hr-mc-lmp">Last Menstrual Period</label>
                            <input
                                id="lml-hr-mc-lmp"
                                name="lmp"
                                type="date"
                                class="lml-hr-mc-add__input lml-focus-ring @error('lmp') is-invalid @enderror"
                                value="{{ old('lmp') }}"
                                data-hr-mc-lmp
                                aria-required="true"
                                @error('lmp') aria-invalid="true" aria-describedby="lml-hr-mc-lmp-error" @enderror
                            >
                            @error('lmp')
                                <p id="lml-hr-mc-lmp-error" class="lml-hr-mc-add__error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="lml-hr-mc-add__field">
                            <label for="lml-hr-mc-gravida">Gravida</label>
                            <input
                                id="lml-hr-mc-gravida"
                                name="gravida"
                                type="number"
                                min="0"
                                step="1"
                                inputmode="numeric"
                                class="lml-hr-mc-add__input lml-focus-ring @error('gravida') is-invalid @enderror"
                                value="{{ old('gravida') }}"
                                aria-required="true"
                                @error('gravida') aria-invalid="true" aria-describedby="lml-hr-mc-gravida-error" @enderror
                            >
                            @error('gravida')
                                <p id="lml-hr-mc-gravida-error" class="lml-hr-mc-add__error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="lml-hr-mc-add__field">
                            <label for="lml-hr-mc-parity">Parity</label>
                            <input
                                id="lml-hr-mc-parity"
                                name="parity"
                                type="number"
                                min="0"
                                step="1"
                                inputmode="numeric"
                                class="lml-hr-mc-add__input lml-focus-ring @error('parity') is-invalid @enderror"
                                value="{{ old('parity') }}"
                                aria-required="true"
                                @error('parity') aria-invalid="true" aria-describedby="lml-hr-mc-parity-error" @enderror
                            >
                            @error('parity')
                                <p id="lml-hr-mc-parity-error" class="lml-hr-mc-add__error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="lml-hr-mc-add__field">
                            <label for="lml-hr-mc-edd">EDD (Expected Date of Delivery)</label>
                            <input
                                id="lml-hr-mc-edd"
                                name="edd"
                                type="date"
                                class="lml-hr-mc-add__input lml-focus-ring @error('edd') is-invalid @enderror"
                                value="{{ old('edd') }}"
                                data-hr-mc-edd
                                @error('edd') aria-invalid="true" aria-describedby="lml-hr-mc-edd-error" @enderror
                            >
                            @error('edd')
                                <p id="lml-hr-mc-edd-error" class="lml-hr-mc-add__error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                <div class="lml-hr-mc-add__nested" data-hr-mc-add-nutrition>
                    <h3 class="lml-hr-mc-add__card-title lml-hr-mc-add__card-title--nested" id="lml-hr-mc-add-nutrition-heading">
                        <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                        <span>Nutritional Assessment</span>
                    </h3>
                    <div class="lml-hr-mc-add__grid lml-hr-mc-add__grid--4">
                        <div class="lml-hr-mc-add__field">
                            <label for="lml-hr-mc-weight">Weight</label>
                            <input
                                id="lml-hr-mc-weight"
                                name="weight"
                                type="number"
                                min="1"
                                step="0.1"
                                inputmode="decimal"
                                class="lml-hr-mc-add__input lml-focus-ring @error('weight') is-invalid @enderror"
                                value="{{ old('weight') }}"
                                data-hr-mc-weight
                                aria-required="true"
                                @error('weight') aria-invalid="true" aria-describedby="lml-hr-mc-weight-error" @enderror
                            >
                            @error('weight')
                                <p id="lml-hr-mc-weight-error" class="lml-hr-mc-add__error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="lml-hr-mc-add__field">
                            <label for="lml-hr-mc-height">Height</label>
                            <input
                                id="lml-hr-mc-height"
                                name="height"
                                type="number"
                                min="40"
                                step="0.1"
                                inputmode="decimal"
                                class="lml-hr-mc-add__input lml-focus-ring @error('height') is-invalid @enderror"
                                value="{{ old('height') }}"
                                data-hr-mc-height
                                aria-required="true"
                                @error('height') aria-invalid="true" aria-describedby="lml-hr-mc-height-error" @enderror
                            >
                            @error('height')
                                <p id="lml-hr-mc-height-error" class="lml-hr-mc-add__error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="lml-hr-mc-add__field">
                            <label for="lml-hr-mc-bmi">BMI</label>
                            <input
                                id="lml-hr-mc-bmi"
                                type="text"
                                class="lml-hr-mc-add__input lml-hr-mc-add__input--derived lml-focus-ring"
                                data-hr-mc-bmi
                                readonly
                                aria-readonly="true"
                                placeholder="Auto calculated"
                                tabindex="0"
                            >
                        </div>
                        <div class="lml-hr-mc-add__field">
                            <label for="lml-hr-mc-bp">Blood Pressure</label>
                            <input
                                id="lml-hr-mc-bp"
                                name="blood_pressure"
                                type="text"
                                class="lml-hr-mc-add__input lml-focus-ring @error('blood_pressure') is-invalid @enderror"
                                value="{{ old('blood_pressure') }}"
                                autocomplete="off"
                                placeholder="e.g. 120/80"
                                aria-required="true"
                                @error('blood_pressure') aria-invalid="true" aria-describedby="lml-hr-mc-bp-error" @enderror
                            >
                            @error('blood_pressure')
                                <p id="lml-hr-mc-bp-error" class="lml-hr-mc-add__error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </section>

            <div class="lml-hr-mc-add__actions">
                <a
                    href="{{ $listingUrl }}"
                    class="lml-hr-mc-add__btn lml-hr-mc-add__btn--cancel lml-focus-ring"
                    data-hr-mc-add-cancel
                >
                    Cancel
                </a>
                <button
                    type="submit"
                    class="lml-hr-mc-add__btn lml-hr-mc-add__btn--save lml-focus-ring"
                    data-hr-mc-add-save
                >
                    Save
                </button>
            </div>
        </form>
    </div>
@endsection
