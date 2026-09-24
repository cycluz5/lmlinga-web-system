{{--
    Health Records — Death record for a selected resident.
    Maker-checker submission. Certificate is persisted on the private disk.
--}}
@extends('layouts.dashboard')

@section('title', ($demoMember['name'] ?? 'Resident') . ' — Death Record - LMLinga')

@section('content')
    @php
        use App\Support\DemoDeath;
        use App\Support\HealthRecordsDeath;

        $deathMode = $deathMode ?? 'missing';
        $deathRequest = $deathRequest ?? null;
        $isDeceased = $isDeceased ?? false;
        $vitalLabel = $vitalLabel ?? 'Active';
        $emptyRecord = '—';
        $memberName = (string) ($demoMember['name'] ?? 'Resident');
        $memberSex = (string) ($demoMember['sex'] ?? '');
        $memberAge = $demoMember['age'] ?? null;
        $dateBirth = $demoMember
            ? lml_demo_member_display($demoMember, 'birthday')
            : $emptyRecord;
        $address = (string) ($demoHousehold['address'] ?? $emptyRecord);
        $householdDisplay = (string) ($demoHousehold['displayNo'] ?? $householdNo);
        $zone = (string) ($demoHousehold['zone'] ?? $demoHousehold['purok'] ?? '');
        $initials = HealthRecordsDeath::initials($memberName);
        $causeValue = old('cause_of_death', $deathRequest && $deathRequest->isRejected() ? $deathRequest->cause_of_death : '');
        $dateValue = old('date_of_death', $deathRequest && $deathRequest->isRejected() ? $deathRequest->date_of_death?->format('Y-m-d') : '');
        $certificateNo = old('certificate_no', $deathRequest && $deathRequest->isRejected() ? $deathRequest->certificate_no : '');
        $registryNo = old('registry_no', $deathRequest && $deathRequest->isRejected() ? $deathRequest->registry_no : '');
        $isFormMode = $deathMode === 'create';
        $statusMessage = session('status');
        $backUrl = route('health-records.death.index');
    @endphp

    <div
        class="lml-hr-death-form"
        data-lml-hr-death-form
        data-death-mode="{{ $deathMode }}"
        data-resident-name="{{ $memberName }}"
        data-resident-sex="{{ $memberSex }}"
        data-household-no="{{ $householdNo }}"
        data-member-id="{{ $memberId }}"
    >
        @if ($statusMessage)
            <p class="lml-hr-death-form__toast" role="status" data-death-toast>
                {{ $statusMessage }}
            </p>
        @endif

        @if (! $demoHousehold || ! $demoMember)
            <section class="lml-hr-death-form__card" aria-labelledby="lml-hr-death-nf-title">
                <h2 id="lml-hr-death-nf-title" class="lml-hr-death-form__title">Resident not found</h2>
                <p class="lml-hr-death-form__hint">
                    A death submission must identify a specific resident from the household catalog.
                </p>
                <a href="{{ $backUrl }}" class="lml-hr-death-form__back lml-focus-ring">
                    Return to Death records
                </a>
            </section>
        @else
            <article class="lml-hr-death-form__profile" aria-labelledby="lml-hr-death-member-name">
                <a
                    href="{{ $backUrl }}"
                    class="lml-hr-death-form__back-icon lml-focus-ring"
                    aria-label="Back to Death records"
                >
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                </a>
                <span class="lml-hr-death-form__avatar" aria-hidden="true">{{ $initials }}</span>
                <div class="lml-hr-death-form__identity">
                    <div class="lml-hr-death-form__name-row">
                        <h2 id="lml-hr-death-member-name" class="lml-hr-death-form__name">
                            {{ $memberName }}
                        </h2>
                        <span
                            class="lml-hr-death-form__vital{{ $isDeceased ? ' lml-hr-death-form__vital--deceased' : '' }}"
                        >
                            {{ $isDeceased ? 'Deceased' : $vitalLabel }}
                        </span>
                    </div>
                    <dl class="lml-hr-death-form__meta">
                        <div>
                            <dt>Member ID</dt>
                            <dd>{{ $memberId !== '' ? $memberId : $emptyRecord }}</dd>
                        </div>
                        <div>
                            <dt>Relationship</dt>
                            <dd>{{ ($demoMember['relationship'] ?? '') !== '' ? $demoMember['relationship'] : $emptyRecord }}</dd>
                        </div>
                        <div>
                            <dt>Sex</dt>
                            <dd>{{ $memberSex !== '' ? $memberSex : $emptyRecord }}</dd>
                        </div>
                        <div>
                            <dt>Age</dt>
                            <dd>{{ $memberAge !== null && $memberAge !== '' ? $memberAge : $emptyRecord }}</dd>
                        </div>
                        <div>
                            <dt>Date of Birth</dt>
                            <dd>{{ $dateBirth !== '' ? $dateBirth : $emptyRecord }}</dd>
                        </div>
                        <div>
                            <dt>Household</dt>
                            <dd>{{ $householdDisplay }}</dd>
                        </div>
                        <div>
                            <dt>Zone</dt>
                            <dd>{{ $zone !== '' ? $zone : $emptyRecord }}</dd>
                        </div>
                        <div>
                            <dt>Address</dt>
                            <dd>{{ $address !== '' ? $address : $emptyRecord }}</dd>
                        </div>
                    </dl>
                </div>
            </article>

            @if ($deathMode === 'pending' && $deathRequest)
                <div
                    class="lml-hr-death-form__banner lml-hr-death-form__banner--pending"
                    role="status"
                    data-death-pending-banner
                >
                    <p class="lml-hr-death-form__banner-title">Pending Admin Verification</p>
                    <p class="lml-hr-death-form__banner-text">
                        This death record has been submitted. Admin review is still required.
                        {{ $memberName }} has not received final deceased status.
                    </p>
                </div>
            @endif

            @if ($deathMode === 'create' && $deathRequest && $deathRequest->isRejected())
                <div
                    class="lml-hr-death-form__banner lml-hr-death-form__banner--rejected"
                    role="alert"
                    data-death-rejected-banner
                >
                    <p class="lml-hr-death-form__banner-title">Rejected</p>
                    <p class="lml-hr-death-form__banner-text" data-death-rejection-reason>
                        {{ $deathRequest->rejection_reason }}
                    </p>
                    <p class="lml-hr-death-form__banner-hint">
                        Correct the details below and submit again for Admin verification.
                        The resident remains not deceased.
                    </p>
                </div>
            @endif

            @if ($deathMode === 'approved' && $deathRequest)
                <div
                    class="lml-hr-death-form__banner lml-hr-death-form__banner--approved"
                    role="status"
                    data-death-approved-banner
                >
                    <p class="lml-hr-death-form__banner-title">Deceased</p>
                    <p class="lml-hr-death-form__banner-text">
                        This death record was approved. Historical health records are retained.
                    </p>
                </div>
            @endif

            @if ($isFormMode)
                <section class="lml-hr-death-form__card" aria-labelledby="lml-hr-death-form-title">
                    <h2 id="lml-hr-death-form-title" class="lml-hr-death-form__title">
                        Death record
                    </h2>
                    <p class="lml-hr-death-form__hint">
                        All fields are required. Submission does not mark the resident deceased until Admin approval.
                    </p>

                    <form
                        method="post"
                        action="{{ route('health-records.death.store', ['householdNo' => $householdNo, 'memberId' => $memberId]) }}"
                        enctype="multipart/form-data"
                        class="lml-hr-death-form__form"
                        data-death-submit-form
                        novalidate
                    >
                        @csrf

                        <div class="lml-hr-death-form__grid">
                            <div class="lml-hr-death-form__field">
                                <label for="lml-death-cause" class="lml-hr-death-form__label">
                                    Cause of Death
                                    <span class="lml-hr-death-form__required" aria-hidden="true">*</span>
                                    <span class="visually-hidden">(required)</span>
                                </label>
                                <input
                                    type="text"
                                    id="lml-death-cause"
                                    name="cause_of_death"
                                    class="lml-hr-death-form__input lml-focus-ring{{ $errors->has('cause_of_death') ? ' is-invalid' : '' }}"
                                    value="{{ $causeValue }}"
                                    maxlength="500"
                                    required
                                    aria-required="true"
                                    aria-invalid="{{ $errors->has('cause_of_death') ? 'true' : 'false' }}"
                                    @if ($errors->has('cause_of_death'))
                                        aria-describedby="lml-death-cause-error"
                                    @endif
                                    data-death-cause
                                >
                                @error('cause_of_death')
                                    <p id="lml-death-cause-error" class="lml-hr-death-form__error" role="alert">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>

                            <div class="lml-hr-death-form__field">
                                <label for="lml-death-date" class="lml-hr-death-form__label">
                                    Date of Death
                                    <span class="lml-hr-death-form__required" aria-hidden="true">*</span>
                                    <span class="visually-hidden">(required)</span>
                                </label>
                                <input
                                    type="date"
                                    id="lml-death-date"
                                    name="date_of_death"
                                    class="lml-hr-death-form__input lml-focus-ring{{ $errors->has('date_of_death') ? ' is-invalid' : '' }}"
                                    value="{{ $dateValue }}"
                                    max="{{ now()->toDateString() }}"
                                    required
                                    aria-required="true"
                                    aria-invalid="{{ $errors->has('date_of_death') ? 'true' : 'false' }}"
                                    @if ($errors->has('date_of_death'))
                                        aria-describedby="lml-death-date-error"
                                    @endif
                                    data-death-date
                                >
                                @error('date_of_death')
                                    <p id="lml-death-date-error" class="lml-hr-death-form__error" role="alert">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>

                            <div class="lml-hr-death-form__field">
                                <label for="lml-death-registry-no" class="lml-hr-death-form__label">
                                    Registry No.
                                    <span class="lml-hr-death-form__required" aria-hidden="true">*</span>
                                    <span class="visually-hidden">(required)</span>
                                </label>
                                <input
                                    type="text"
                                    id="lml-death-registry-no"
                                    name="registry_no"
                                    class="lml-hr-death-form__input lml-focus-ring{{ $errors->has('registry_no') ? ' is-invalid' : '' }}"
                                    value="{{ $registryNo }}"
                                    maxlength="100"
                                    required
                                    aria-required="true"
                                    aria-invalid="{{ $errors->has('registry_no') ? 'true' : 'false' }}"
                                    @if ($errors->has('registry_no'))
                                        aria-describedby="lml-death-registry-no-error"
                                    @endif
                                    data-death-registry-no
                                >
                                @error('registry_no')
                                    <p id="lml-death-registry-no-error" class="lml-hr-death-form__error" role="alert">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>

                            <div class="lml-hr-death-form__field">
                                <label for="lml-death-cert-no" class="lml-hr-death-form__label">
                                    Death Certificate No.
                                    <span class="lml-hr-death-form__required" aria-hidden="true">*</span>
                                    <span class="visually-hidden">(required)</span>
                                </label>
                                <input
                                    type="text"
                                    id="lml-death-cert-no"
                                    name="certificate_no"
                                    class="lml-hr-death-form__input lml-focus-ring{{ $errors->has('certificate_no') ? ' is-invalid' : '' }}"
                                    value="{{ $certificateNo }}"
                                    maxlength="100"
                                    required
                                    aria-required="true"
                                    aria-invalid="{{ $errors->has('certificate_no') ? 'true' : 'false' }}"
                                    @if ($errors->has('certificate_no'))
                                        aria-describedby="lml-death-cert-no-error"
                                    @endif
                                    data-death-certificate-no
                                >
                                @error('certificate_no')
                                    <p id="lml-death-cert-no-error" class="lml-hr-death-form__error" role="alert">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>
                        </div>

                        <div class="lml-hr-death-form__upload" data-death-upload>
                            <label for="lml-death-certificate" class="lml-hr-death-form__label">
                                Death Certificate
                                <span class="lml-hr-death-form__required" aria-hidden="true">*</span>
                                <span class="visually-hidden">(required)</span>
                            </label>
                            <p id="lml-death-cert-hint" class="lml-hr-death-form__hint">
                                Upload a PNG, JPG, or PDF file. Maximum 5 MB.
                            </p>
                            <input
                                type="file"
                                id="lml-death-certificate"
                                name="death_certificate"
                                class="lml-hr-death-form__file"
                                accept=".png,.jpg,.jpeg,.pdf,image/png,image/jpeg,application/pdf"
                                required
                                aria-required="true"
                                aria-describedby="lml-death-cert-hint lml-death-cert-status"
                                @if ($errors->has('death_certificate'))
                                    aria-invalid="true"
                                    aria-errormessage="lml-death-cert-error"
                                @endif
                                data-death-certificate-input
                            >
                            <p
                                id="lml-death-cert-status"
                                class="lml-hr-death-form__file-status"
                                data-death-file-status
                            >
                                No file selected.
                            </p>
                            @error('death_certificate')
                                <p id="lml-death-cert-error" class="lml-hr-death-form__error" role="alert">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div class="lml-hr-death-form__actions">
                            <button
                                type="submit"
                                class="lml-hr-death-form__submit lml-focus-ring"
                                data-death-submit
                                disabled
                                aria-disabled="true"
                            >
                                Submit for Verification
                            </button>
                        </div>
                    </form>
                </section>

                <div class="lml-hr-death-form__dialog-root" data-death-confirm hidden>
                    <div class="lml-hr-death-form__dialog-backdrop" data-death-confirm-backdrop></div>
                    <div
                        class="lml-hr-death-form__dialog"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="lml-death-confirm-title"
                        aria-describedby="lml-death-confirm-message"
                        tabindex="-1"
                        data-death-confirm-panel
                    >
                        <h2 id="lml-death-confirm-title" class="lml-hr-death-form__dialog-title">
                            Submit for verification?
                        </h2>
                        <p id="lml-death-confirm-message" class="lml-hr-death-form__dialog-text" data-death-confirm-message>
                            You are about to record {{ $memberName }}{{ $memberSex !== '' ? ', '.$memberSex : '' }}{{ $memberId !== '' ? ', '.$memberId : '' }} as deceased.
                            This action requires Admin verification before it takes effect.
                            Continue?
                        </p>
                        <div class="lml-hr-death-form__dialog-actions">
                            <button
                                type="button"
                                class="lml-hr-death-form__dialog-btn lml-hr-death-form__dialog-btn--cancel lml-focus-ring"
                                data-death-confirm-cancel
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                class="lml-hr-death-form__dialog-btn lml-hr-death-form__dialog-btn--confirm lml-focus-ring"
                                data-death-confirm-submit
                            >
                                Submit for Verification
                            </button>
                        </div>
                    </div>
                </div>
            @elseif ($deathRequest)
                <section class="lml-hr-death-form__card" aria-labelledby="lml-hr-death-view-title">
                    <h2 id="lml-hr-death-view-title" class="lml-hr-death-form__title">
                        Submitted details
                    </h2>
                    <dl class="lml-hr-death-form__details">
                        <div>
                            <dt>Cause of Death</dt>
                            <dd>{{ $deathRequest->cause_of_death }}</dd>
                        </div>
                        <div>
                            <dt>Date of Death</dt>
                            <dd>{{ $deathRequest->formattedDateOfDeath() }}</dd>
                        </div>
                        <div>
                            <dt>Registry No.</dt>
                            <dd>{{ $deathRequest->registry_no }}</dd>
                        </div>
                        <div>
                            <dt>Death Certificate No.</dt>
                            <dd>{{ $deathRequest->certificate_no }}</dd>
                        </div>
                        <div>
                            <dt>Submitted by</dt>
                            <dd>{{ $deathRequest->submitted_by_name }}</dd>
                        </div>
                        <div>
                            <dt>Submitted on</dt>
                            <dd>{{ $deathRequest->submitted_at?->timezone(config('app.timezone'))->format('F j, Y, g:i A') }}</dd>
                        </div>
                    </dl>

                    <div class="lml-hr-death-form__file-item">
                        <p class="lml-hr-death-form__label">Death Certificate</p>
                        <a
                            href="{{ route('health-records.death.certificate', ['householdNo' => $householdNo, 'memberId' => $memberId]) }}"
                            class="lml-hr-death-form__file-link lml-focus-ring"
                            aria-label="View death certificate {{ $deathRequest->certificate_original_name }}"
                        >
                            <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                            <span>{{ $deathRequest->certificate_original_name }}</span>
                            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                        </a>
                    </div>
                </section>
            @endif
        @endif
    </div>
@endsection
