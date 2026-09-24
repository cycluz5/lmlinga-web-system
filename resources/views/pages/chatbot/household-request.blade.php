@extends('layouts.app')

@section('title', 'Household Record Access Request - LMLinga')

@section('body')
    @php
        $previewState = (string) request()->query('state', '');
        $isDailyLimit = $previewState === 'daily-limit';
    @endphp
    <div
        class="lml-chatbot-household-request"
        data-hh-preview-state="{{ $previewState }}"
    >
        <div class="lml-chatbot-household-request__inner">
            <header class="lml-chatbot-household-request__header">
                <a
                    href="{{ route('chatbot.main') }}"
                    class="lml-chatbot-household-request__back lml-focus-ring"
                >
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back to chatbot</span>
                </a>
            </header>

            <main class="lml-chatbot-household-request__main" id="main-content">
                <section
                    class="lml-chatbot-household-request__card lml-surface lml-surface--elevated"
                    aria-labelledby="household-request-heading"
                >
                    <div class="lml-chatbot-household-request__hero">
                        <span class="lml-chatbot-household-request__hero-icon" aria-hidden="true">
                            <i class="bi bi-file-earmark-text"></i>
                        </span>
                        <h1 id="household-request-heading" class="lml-chatbot-household-request__title">
                            Request Household Record
                        </h1>
                        <p class="lml-chatbot-household-request__intro">
                            Complete this form so the system can automatically compare your details with barangay household records.
                            Matching is automatic. This is not a manual Admin approval step.
                        </p>
                    </div>

                    @if (session('household_request_validation'))
                        <p class="lml-chatbot-household-request__intro" role="status">
                            {{ session('household_request_validation') }}
                        </p>
                    @endif

                    @if ($isDailyLimit)
                        <div class="lml-hh-status lml-hh-status--inline" role="alert">
                            <p class="lml-hh-status__badge lml-hh-status__badge--daily-limit">
                                Daily request limit reached
                            </p>
                            <p class="lml-chatbot-household-request__intro">
                                You have used 3 failed Household Request attempts today. Submit Request is unavailable until the daily limit resets.
                            </p>
                            <p class="lml-hh-status__note">
                                You have reached the maximum number of household record requests allowed today.
                            </p>
                        </div>
                    @else
                    <form
                        action="{{ route('chatbot.household.verification.store') }}"
                        method="post"
                        novalidate
                        data-lml-household-request-form
                    >
                        @csrf
                        <fieldset class="lml-chatbot-household-request__fieldset">
                            <legend class="lml-chatbot-household-request__legend">
                                <span class="lml-chatbot-household-request__legend-heading">
                                    <span class="lml-chatbot-household-request__legend-icon" aria-hidden="true">
                                        <i class="bi bi-house-door-fill"></i>
                                    </span>
                                    <span class="lml-chatbot-household-request__legend-text">Household Information</span>
                                </span>
                                <span class="lml-chatbot-household-request__legend-line" aria-hidden="true"></span>
                            </legend>
                            <div class="lml-chatbot-household-request__grid lml-chatbot-household-request__grid--two">
                                <div class="lml-chatbot-household-request__field">
                                    <label class="lml-form-label lml-form-label--required" for="hh-household-no">Household No.</label>
                                    <input
                                        id="hh-household-no"
                                        name="householdNo"
                                        type="text"
                                        value="{{ old('householdNo') }}"
                                        class="form-control lml-form-control lml-focus-ring{{ $errors->has('householdNo') ? ' is-invalid' : '' }}"
                                        placeholder="Enter household number"
                                        required
                                        aria-required="true"
                                        @if ($errors->has('householdNo')) aria-invalid="true" aria-describedby="hh-household-no-error" @endif
                                    >
                                    <p class="lml-form-error" id="hh-household-no-error" @if (! $errors->has('householdNo')) hidden @endif>{{ $errors->first('householdNo') }}</p>
                                </div>

                                <div class="lml-chatbot-household-request__field">
                                    <label class="lml-form-label lml-form-label--required" for="hh-relationship">
                                        Relationship to Household
                                    </label>
                                    <select
                                        id="hh-relationship"
                                        name="relationship"
                                        class="form-select lml-form-control lml-focus-ring{{ $errors->has('relationship') ? ' is-invalid' : '' }}"
                                        required
                                        aria-required="true"
                                        @if ($errors->has('relationship')) aria-invalid="true" aria-describedby="hh-relationship-error" @endif
                                    >
                                        <option value="">Select your relationship</option>
                                        <option @selected(old('relationship') === 'Household Head')>Household Head</option>
                                        <option @selected(old('relationship') === 'Spouse')>Spouse</option>
                                        <option @selected(old('relationship') === 'Son')>Son</option>
                                        <option @selected(old('relationship') === 'Daughter')>Daughter</option>
                                        <option @selected(old('relationship') === 'Parent')>Parent</option>
                                        <option @selected(old('relationship') === 'Sibling')>Sibling</option>
                                        <option @selected(old('relationship') === 'Grandparent')>Grandparent</option>
                                        <option @selected(old('relationship') === 'Grandchild')>Grandchild</option>
                                        <option @selected(old('relationship') === 'Relative')>Relative</option>
                                        <option @selected(old('relationship') === 'Non-relative household member')>Non-relative household member</option>
                                        <option @selected(old('relationship') === 'Other')>Other</option>
                                    </select>
                                    <p class="lml-form-error" id="hh-relationship-error" @if (! $errors->has('relationship')) hidden @endif>{{ $errors->first('relationship') }}</p>
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="lml-chatbot-household-request__fieldset">
                            <legend class="lml-chatbot-household-request__legend">
                                <span class="lml-chatbot-household-request__legend-heading">
                                    <span class="lml-chatbot-household-request__legend-icon" aria-hidden="true">
                                        <i class="bi bi-person-fill"></i>
                                    </span>
                                    <span class="lml-chatbot-household-request__legend-text">Requester Information</span>
                                </span>
                                <span class="lml-chatbot-household-request__legend-line" aria-hidden="true"></span>
                            </legend>
                            <div class="lml-chatbot-household-request__grid lml-chatbot-household-request__grid--three">
                                <div class="lml-chatbot-household-request__field">
                                    <label class="lml-form-label lml-form-label--required" for="hh-first-name">First Name</label>
                                    <input
                                        id="hh-first-name"
                                        name="firstName"
                                        type="text"
                                        value="{{ old('firstName') }}"
                                        class="form-control lml-form-control lml-focus-ring{{ $errors->has('firstName') ? ' is-invalid' : '' }}"
                                        placeholder="First name"
                                        required
                                        aria-required="true"
                                        @if ($errors->has('firstName')) aria-invalid="true" aria-describedby="hh-first-name-error" @endif
                                    >
                                    <p class="lml-form-error" id="hh-first-name-error" @if (! $errors->has('firstName')) hidden @endif>{{ $errors->first('firstName') }}</p>
                                </div>

                                <div class="lml-chatbot-household-request__field">
                                    <label class="lml-form-label lml-form-label--required" for="hh-middle-name">
                                        Middle Name
                                    </label>
                                    <input
                                        id="hh-middle-name"
                                        name="middleName"
                                        type="text"
                                        value="{{ old('middleName') }}"
                                        class="form-control lml-form-control lml-focus-ring{{ $errors->has('middleName') ? ' is-invalid' : '' }}"
                                        placeholder="Middle name"
                                        required
                                        aria-required="true"
                                        @if ($errors->has('middleName')) aria-invalid="true" aria-describedby="hh-middle-name-error" @endif
                                    >
                                    <p class="lml-form-error" id="hh-middle-name-error" @if (! $errors->has('middleName')) hidden @endif>{{ $errors->first('middleName') }}</p>
                                </div>

                                <div class="lml-chatbot-household-request__field">
                                    <label class="lml-form-label lml-form-label--required" for="hh-last-name">Last Name</label>
                                    <input
                                        id="hh-last-name"
                                        name="lastName"
                                        type="text"
                                        value="{{ old('lastName') }}"
                                        class="form-control lml-form-control lml-focus-ring{{ $errors->has('lastName') ? ' is-invalid' : '' }}"
                                        placeholder="Last name"
                                        required
                                        aria-required="true"
                                        @if ($errors->has('lastName')) aria-invalid="true" aria-describedby="hh-last-name-error" @endif
                                    >
                                    <p class="lml-form-error" id="hh-last-name-error" @if (! $errors->has('lastName')) hidden @endif>{{ $errors->first('lastName') }}</p>
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="lml-chatbot-household-request__fieldset">
                            <legend class="lml-chatbot-household-request__legend">
                                <span class="lml-chatbot-household-request__legend-heading">
                                    <span class="lml-chatbot-household-request__legend-icon" aria-hidden="true">
                                        <i class="bi bi-telephone-fill"></i>
                                    </span>
                                    <span class="lml-chatbot-household-request__legend-text">Contact Information</span>
                                </span>
                                <span class="lml-chatbot-household-request__legend-line" aria-hidden="true"></span>
                            </legend>
                            <div class="lml-chatbot-household-request__grid lml-chatbot-household-request__grid--two">
                                <div class="lml-chatbot-household-request__field">
                                    <label class="lml-form-label lml-form-label--required" for="hh-mobile-number">Mobile Number</label>
                                    <input
                                        id="hh-mobile-number"
                                        name="mobileNumber"
                                        type="tel"
                                        inputmode="numeric"
                                        autocomplete="tel"
                                        value="{{ old('mobileNumber') }}"
                                        class="form-control lml-form-control lml-focus-ring{{ $errors->has('mobileNumber') ? ' is-invalid' : '' }}"
                                        placeholder="09XXXXXXXXX"
                                        required
                                        aria-required="true"
                                        @if ($errors->has('mobileNumber')) aria-invalid="true" aria-describedby="hh-mobile-number-error" @endif
                                    >
                                    <p class="lml-form-error" id="hh-mobile-number-error" @if (! $errors->has('mobileNumber')) hidden @endif>{{ $errors->first('mobileNumber') }}</p>
                                </div>

                                <div class="lml-chatbot-household-request__field">
                                    <label class="lml-form-label lml-form-label--required" for="hh-email-address">Email Address</label>
                                    <input
                                        id="hh-email-address"
                                        name="emailAddress"
                                        type="email"
                                        autocomplete="email"
                                        value="{{ old('emailAddress') }}"
                                        class="form-control lml-form-control lml-focus-ring{{ $errors->has('emailAddress') ? ' is-invalid' : '' }}"
                                        placeholder="name@example.com"
                                        required
                                        aria-required="true"
                                        @if ($errors->has('emailAddress')) aria-invalid="true" aria-describedby="hh-email-address-error" @endif
                                    >
                                    <p class="lml-form-error" id="hh-email-address-error" @if (! $errors->has('emailAddress')) hidden @endif>{{ $errors->first('emailAddress') }}</p>
                                </div>
                            </div>
                        </fieldset>

                        <div class="lml-chatbot-household-request__actions">
                            <a
                                href="{{ route('chatbot.main') }}"
                                class="lml-chatbot-household-request__btn lml-chatbot-household-request__btn--cancel lml-focus-ring"
                            >
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                                <span>Cancel</span>
                            </a>
                            <button
                                type="submit"
                                class="lml-chatbot-household-request__btn lml-chatbot-household-request__btn--submit lml-focus-ring"
                            >
                                <i class="bi bi-send-fill" aria-hidden="true"></i>
                                <span>Submit Request</span>
                            </button>
                        </div>
                    </form>
                    @endif
                </section>
            </main>
        </div>
    </div>
@endsection
