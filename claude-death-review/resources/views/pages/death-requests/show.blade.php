{{--
    Admin Verify Death Record. White-card LMLinga theme (not the dark mock).
--}}
@extends('layouts.dashboard')

@section('title', 'Verify Death Record - LMLinga')

@section('content')
    @php
        use App\Support\HealthRecordsDeath;

        $deathRequest = $deathRequest ?? null;
        $certificateExists = $certificateExists ?? false;
        $statusMessage = session('status');
        $initials = HealthRecordsDeath::initials((string) ($deathRequest->resident_name ?? ''));
        $canDecide = $deathRequest && $deathRequest->isPending();
    @endphp

    <div
        class="lml-dr-verify"
        data-lml-death-verify
        data-dr-has-reject-errors="{{ $errors->has('rejection_reason') ? 'true' : 'false' }}"
    >
        @if ($statusMessage)
            <p class="lml-hr-death-form__toast" role="status">{{ $statusMessage }}</p>
        @endif

        <article class="lml-dr-verify__card">
            <header class="lml-dr-verify__header">
                <a
                    href="{{ route('death-requests.index') }}"
                    class="lml-dr-verify__back lml-focus-ring"
                    aria-label="Back to Death Requests"
                >
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                </a>
                <span class="lml-hr-table__status lml-dr__status--{{ $deathRequest->status }}">
                    {{ $deathRequest->statusLabel() }}
                </span>
            </header>

            <div class="lml-dr-verify__resident">
                <span class="lml-hr-death-form__avatar" aria-hidden="true">{{ $initials }}</span>
                <div>
                    <h2 class="lml-dr-verify__resident-name">{{ $deathRequest->resident_name }}</h2>
                </div>
            </div>

            @php
                $householdNoDisplay = trim((string) ($deathRequest->household_display_no ?: $deathRequest->household_no));
                $zoneDisplay = trim((string) ($deathRequest->zone ?: ''));
            @endphp

            <dl class="lml-hr-death-form__details lml-dr-verify__details">
                <div>
                    <dt>Household No.</dt>
                    <dd>{{ $householdNoDisplay !== '' ? $householdNoDisplay : '—' }}</dd>
                </div>
                <div>
                    <dt>Zone</dt>
                    <dd>{{ $zoneDisplay !== '' ? $zoneDisplay : '—' }}</dd>
                </div>
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
                    <dt>Certificate No.</dt>
                    <dd>{{ $deathRequest->certificate_no }}</dd>
                </div>
                <div>
                    <dt>Submitted By</dt>
                    <dd>{{ $deathRequest->submitted_by_name }}</dd>
                </div>
                <div>
                    <dt>Submitted On</dt>
                    <dd>{{ $deathRequest->submitted_at?->timezone(config('app.timezone'))->format('F j, Y, g:i A') }}</dd>
                </div>
                @if ($deathRequest->reviewed_at)
                    <div>
                        <dt>Reviewed By</dt>
                        <dd>{{ $deathRequest->reviewed_by_name ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt>Reviewed On</dt>
                        <dd>{{ $deathRequest->reviewed_at->timezone(config('app.timezone'))->format('F j, Y, g:i A') }}</dd>
                    </div>
                @endif
                @if ($deathRequest->isRejected() && filled($deathRequest->rejection_reason))
                    <div>
                        <dt>Rejection reason</dt>
                        <dd data-death-rejection-reason>{{ $deathRequest->rejection_reason }}</dd>
                    </div>
                @endif
            </dl>

            <div class="lml-hr-death-form__file-item lml-dr-verify__file">
                <p class="lml-hr-death-form__label">Death Certificate</p>
                @if ($certificateExists)
                    <a
                        href="{{ route('death-requests.certificate', $deathRequest) }}"
                        class="lml-hr-death-form__file-link lml-focus-ring"
                        aria-label="View death certificate {{ $deathRequest->certificate_original_name }}"
                    >
                        <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                        <span>{{ $deathRequest->certificate_original_name }}</span>
                        <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                    </a>
                @else
                    <p class="lml-hr-death-form__hint" role="status">Certificate file is not available.</p>
                @endif
            </div>

            @if ($canDecide)
                <div class="lml-dr-verify__actions">
                    <button
                        type="button"
                        class="lml-dr-verify__btn lml-dr-verify__btn--reject lml-focus-ring"
                        data-dr-open-reject
                    >
                        Reject
                    </button>
                    <button
                        type="button"
                        class="lml-dr-verify__btn lml-dr-verify__btn--approve lml-focus-ring"
                        data-dr-open-approve
                    >
                        Approve
                    </button>
                </div>
            @endif
        </article>

        @if ($canDecide)
            <div class="lml-hr-death-form__dialog-root" data-dr-approve hidden>
                <div class="lml-hr-death-form__dialog-backdrop" data-dr-approve-backdrop></div>
                <div
                    class="lml-hr-death-form__dialog"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="lml-dr-approve-title"
                    aria-describedby="lml-dr-approve-message"
                    tabindex="-1"
                    data-dr-approve-panel
                >
                    <h2 id="lml-dr-approve-title" class="lml-hr-death-form__dialog-title">
                        Approve death record?
                    </h2>
                    <p id="lml-dr-approve-message" class="lml-hr-death-form__dialog-text">
                        Approving will mark {{ $deathRequest->resident_name }}{{ $deathRequest->member_id ? ' ('.$deathRequest->member_id.($deathRequest->resident_sex ? ', '.$deathRequest->resident_sex : '').')' : '' }} as deceased.
                        Historical health records will be retained. Continue?
                    </p>
                    <form
                        method="post"
                        action="{{ route('death-requests.approve', $deathRequest) }}"
                        class="lml-hr-death-form__dialog-actions"
                    >
                        @csrf
                        <button
                            type="button"
                            class="lml-hr-death-form__dialog-btn lml-hr-death-form__dialog-btn--cancel lml-focus-ring"
                            data-dr-approve-cancel
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="lml-hr-death-form__dialog-btn lml-hr-death-form__dialog-btn--confirm lml-focus-ring"
                        >
                            Approve
                        </button>
                    </form>
                </div>
            </div>

            <div class="lml-hr-death-form__dialog-root" data-dr-reject hidden>
                <div class="lml-hr-death-form__dialog-backdrop" data-dr-reject-backdrop></div>
                <div
                    class="lml-hr-death-form__dialog"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="lml-dr-reject-title"
                    aria-describedby="lml-dr-reject-message"
                    tabindex="-1"
                    data-dr-reject-panel
                >
                    <h2 id="lml-dr-reject-title" class="lml-hr-death-form__dialog-title">
                        Reject death record
                    </h2>
                    <p id="lml-dr-reject-message" class="lml-hr-death-form__dialog-text">
                        A rejection reason is required. The resident will remain not deceased.
                    </p>
                    <form
                        method="post"
                        action="{{ route('death-requests.reject', $deathRequest) }}"
                    >
                        @csrf
                        <label for="lml-dr-rejection-reason" class="lml-hr-death-form__label">
                            Rejection reason
                            <span class="lml-hr-death-form__required" aria-hidden="true">*</span>
                            <span class="visually-hidden">(required)</span>
                        </label>
                        <textarea
                            id="lml-dr-rejection-reason"
                            name="rejection_reason"
                            class="lml-hr-death-form__input lml-focus-ring{{ $errors->has('rejection_reason') ? ' is-invalid' : '' }}"
                            rows="4"
                            maxlength="1000"
                            required
                            aria-required="true"
                            aria-invalid="{{ $errors->has('rejection_reason') ? 'true' : 'false' }}"
                            @if ($errors->has('rejection_reason'))
                                aria-describedby="lml-dr-rejection-error"
                            @endif
                            data-dr-rejection-reason
                        >{{ old('rejection_reason') }}</textarea>
                        @error('rejection_reason')
                            <p id="lml-dr-rejection-error" class="lml-hr-death-form__error" role="alert">
                                {{ $message }}
                            </p>
                        @enderror
                        <div class="lml-hr-death-form__dialog-actions">
                            <button
                                type="button"
                                class="lml-hr-death-form__dialog-btn lml-hr-death-form__dialog-btn--cancel lml-focus-ring"
                                data-dr-reject-cancel
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="lml-hr-death-form__dialog-btn lml-hr-death-form__dialog-btn--reject lml-focus-ring"
                                data-dr-reject-submit
                            >
                                Reject
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection
