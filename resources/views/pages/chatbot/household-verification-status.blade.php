@extends('layouts.app')

@section('title', 'Household verification status - LMLinga')

@php
    $staffVerified = \App\Support\HouseholdLinkingPolicy::requiresStaffVerification();
    $allowed = ['verifying', 'approved', 'rejected', 'failed-1', 'failed-2', 'failed-3', 'daily-limit'];
    $state = in_array((string) ($uiState ?? 'verifying'), $allowed, true)
        ? (string) $uiState
        : 'verifying';

    $copy = match ($state) {
        'verifying' => [
            'title' => 'Verification in progress',
            'text' => 'Your household information is being compared with barangay records. Matching is automatic and does not require Admin review.',
            'status' => 'Verification in progress',
        ],
        'approved' => [
            'title' => 'Match found',
            'text' => 'Your information matched a household record. Access was automatically approved.',
            'status' => 'Automatically approved',
        ],
        'rejected' => [
            'title' => 'No match found',
            'text' => 'We could not find a matching household record using the information provided. This request was automatically rejected.',
            'status' => 'Automatically rejected',
        ],
        'failed-1' => [
            'title' => 'Verification unsuccessful',
            'text' => 'The information provided did not match the household record. Failed attempt 1 of 3 today.',
            'status' => 'Failed attempt 1 of 3',
        ],
        'failed-2' => [
            'title' => 'Verification unsuccessful',
            'text' => 'The information provided did not match the household record. Failed attempt 2 of 3 today.',
            'status' => 'Failed attempt 2 of 3',
        ],
        'failed-3' => [
            'title' => 'Verification unsuccessful',
            'text' => 'You have reached the maximum number of failed verification attempts for today.',
            'status' => 'Failed attempt 3 of 3',
        ],
        default => [
            'title' => 'Daily request limit reached',
            'text' => 'You have reached the maximum number of household record requests allowed today. Submit Request is unavailable for now.',
            'status' => 'Daily request limit reached',
        ],
    };

    // Staff-verified linking: nothing is approved automatically, so the copy must say so.
    if ($staffVerified) {
        $copy = match ($state) {
            'verifying' => [
                'title' => 'Verification in progress',
                'text' => 'Your details were compared with barangay household records. Barangay staff must verify your identity before household access is granted. Please visit your Barangay Health Station with a valid ID, or contact your Barangay Health Worker.',
                'status' => 'Awaiting staff verification',
            ],
            'approved' => [
                'title' => 'Match found',
                'text' => 'Your identity was verified by barangay staff and your household access is active.',
                'status' => 'Verified',
            ],
            'rejected' => [
                'title' => 'No match found',
                'text' => 'This request could not be verified. Please visit your Barangay Health Station with a valid ID, or contact your Barangay Health Worker.',
                'status' => 'Not verified',
            ],
            default => $copy,
        };

        // Staff already verified identity + mobile: the remaining step is the SMS code.
        if ($state === 'verifying' && ($otpStepOpen ?? false)) {
            $copy = [
                'title' => 'Identity verified',
                'text' => 'Barangay staff verified your identity and mobile number. Enter the verification code sent by SMS to your verified mobile number to finish linking your household record.',
                'status' => 'Awaiting SMS code',
            ];
        }

        // Legacy Approved-without-OTP: no household access was ever granted; re-verify with staff.
        if ($needsResubmission ?? false) {
            $state = 'rejected';
            $copy = [
                'title' => 'Verification required',
                'text' => 'Your household record access has not been verified. Submit a new request, then barangay staff will verify your identity before access is granted. Please bring a valid ID to your Barangay Health Station or contact your Barangay Health Worker.',
                'status' => 'Verification required',
            ];
        }
    }

    $otpStepOpen = (bool) ($otpStepOpen ?? false);

    $canRetry = in_array($state, ['rejected', 'failed-1', 'failed-2'], true);
    $isBlocked = in_array($state, ['failed-3', 'daily-limit'], true);
    $grantHouseholdAccess = false;
    $isSuccess = $state === 'approved' && $grantHouseholdAccess;
    $isPending = $state === 'verifying';
@endphp

@section('body')
    <div class="lml-chatbot-household-request" data-hh-verification-state="{{ $state }}">
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
                    class="lml-chatbot-household-request__card lml-surface lml-surface--elevated lml-hh-status"
                    aria-labelledby="household-status-heading"
                >
                    <p
                        class="lml-hh-status__badge lml-hh-status__badge--{{ $state }}"
                        role="status"
                    >
                        {{ $copy['status'] }}
                    </p>
                    <h1 id="household-status-heading" class="lml-chatbot-household-request__title">
                        {{ $copy['title'] }}
                    </h1>
                    <p class="lml-chatbot-household-request__intro">
                        {{ $copy['text'] }}
                    </p>
                    <p class="lml-hh-status__note">
                        @if ($staffVerified)
                            Household access is granted only after barangay staff verify your identity and you confirm the code sent by SMS to your verified mobile number.
                        @else
                            This is an automatic verification result. Admin does not manually approve or reject Household Requests.
                        @endif
                    </p>

                    <div class="lml-chatbot-household-request__actions">
                        @if ($isPending && ! ($staffVerified && $otpStepOpen))
                            <span class="lml-hh-status__pending" aria-hidden="true"></span>
                        @endif
                        @if ($staffVerified && $otpStepOpen)
                            <a
                                href="{{ route('chatbot.household.verification.sms') }}"
                                class="lml-chatbot-household-request__btn lml-chatbot-household-request__btn--submit lml-focus-ring"
                                data-hh-continue-otp
                            >
                                Enter SMS code
                            </a>
                        @elseif ($isSuccess)
                            <a
                                href="{{ route('chatbot.household.information') }}"
                                data-lml-page-loader="Loading household information…"
                                class="lml-chatbot-household-request__btn lml-chatbot-household-request__btn--submit lml-focus-ring"
                            >
                                Continue to Household Information
                            </a>
                        @elseif ($canRetry)
                            <a
                                href="{{ route('chatbot.household.verification') }}"
                                class="lml-chatbot-household-request__btn lml-chatbot-household-request__btn--submit lml-focus-ring"
                            >
                                Try again
                            </a>
                        @elseif ($isBlocked)
                            <p class="lml-hh-status__blocked" role="status">
                                Submit Request is unavailable until the daily limit resets.
                            </p>
                        @endif
                        <a
                            href="{{ route('chatbot.main') }}"
                            class="lml-chatbot-household-request__btn lml-chatbot-household-request__btn--cancel lml-focus-ring"
                        >
                            Back to chatbot
                        </a>
                    </div>
                </section>
            </main>
        </div>
    </div>
@endsection
