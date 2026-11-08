@extends('layouts.app')

@section('title', 'Verify Your Email - LMLinga')

@section('body')
    @php
        $maskedEmail = $maskedEmail ?? '***';
        $codeSent = (bool) ($codeSent ?? false);
        $otpVerifyError = trim((string) ($otpVerifyError ?? ''));
        $otpStatus = trim((string) ($otpStatus ?? ''));
        $gateNotice = trim((string) ($gateNotice ?? ''));
        $otpSeconds = max(0, (int) ($otpSeconds ?? 0));
    @endphp

    {{-- One-time email verification for existing resident accounts; code goes only to the stored email. --}}
    <div
        class="lml-chatbot-sms-verify"
        @if ($codeSent) data-lml-sms-verify @endif
        data-resend-success-message="A new code has been sent."
        data-otp-seconds="{{ $otpSeconds }}"
        data-otp-lock-seconds="0"
    >
        <div class="lml-chatbot-sms-verify__inner">
            <header class="lml-chatbot-sms-verify__header">
                <a
                    href="{{ route('chatbot.main') }}"
                    class="lml-chatbot-sms-verify__back lml-focus-ring"
                >
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back</span>
                </a>
            </header>

            <main class="lml-chatbot-sms-verify__main" id="main-content">
                <section
                    class="lml-chatbot-sms-verify__card lml-surface lml-surface--elevated"
                    aria-labelledby="email-verify-heading"
                >
                    <div class="lml-chatbot-sms-verify__hero">
                        <span class="lml-chatbot-sms-verify__hero-icon" aria-hidden="true">
                            <i class="bi bi-envelope-check-fill"></i>
                        </span>
                        <h1 id="email-verify-heading" class="lml-chatbot-sms-verify__title">
                            Verify Your Email
                        </h1>
                        @if ($gateNotice !== '')
                            <p class="lml-chatbot-sms-verify__intro" role="status">{{ $gateNotice }}</p>
                        @endif
                        @if ($otpStatus !== '')
                            <p class="lml-chatbot-sms-verify__intro" role="status">{{ $otpStatus }}</p>
                        @endif
                        @if ($codeSent)
                            <p class="lml-chatbot-sms-verify__intro">
                                We've sent a 6-digit code to
                                <span class="lml-chatbot-sms-verify__masked">{{ $maskedEmail }}</span>.
                            </p>
                        @else
                            <p class="lml-chatbot-sms-verify__intro">
                                We will send a 6-digit code to your account email
                                <span class="lml-chatbot-sms-verify__masked">{{ $maskedEmail }}</span>.
                            </p>
                            @if ($otpVerifyError !== '')
                                <p class="lml-chatbot-sms-verify__error" role="alert">{{ $otpVerifyError }}</p>
                            @endif
                        @endif
                        <p class="lml-chatbot-sms-verify__intro lml-chatbot-sms-verify__intro--secondary">
                            Verifying your email does not change your account, household record or requests.
                        </p>
                    </div>

                    @if ($codeSent)
                        <form
                            class="lml-chatbot-sms-verify__form"
                            action="{{ route('chatbot.email.verify.submit') }}"
                            method="post"
                            novalidate
                            data-lml-sms-form
                            data-lml-otp-server-submit="true"
                        >
                            @csrf
                            <input type="hidden" name="otp" value="" data-lml-otp-value>

                            <fieldset class="lml-chatbot-sms-verify__otp-fieldset">
                                <legend class="visually-hidden">
                                    Enter the 6-digit verification code
                                </legend>
                                <div
                                    class="lml-chatbot-sms-verify__otp"
                                    data-lml-otp-group
                                >
                                    @for ($i = 0; $i < 6; $i++)
                                        <label class="visually-hidden" for="account-email-otp-{{ $i }}">
                                            Digit {{ $i + 1 }} of 6
                                        </label>
                                        <input
                                            id="account-email-otp-{{ $i }}"
                                            class="lml-chatbot-sms-verify__otp-input lml-focus-ring"
                                            type="text"
                                            inputmode="numeric"
                                            autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}"
                                            maxlength="1"
                                            pattern="[0-9]"
                                            data-lml-otp-digit
                                            data-otp-index="{{ $i }}"
                                            aria-invalid="false"
                                            aria-describedby="account-email-otp-error"
                                        >
                                    @endfor
                                </div>
                                <p
                                    id="account-email-otp-error"
                                    class="lml-chatbot-sms-verify__error"
                                    data-lml-otp-error
                                    @if ($otpVerifyError === '') hidden @endif
                                >{{ $otpVerifyError }}</p>
                            </fieldset>

                            <button
                                type="submit"
                                class="lml-chatbot-sms-verify__verify-btn lml-focus-ring"
                                data-lml-otp-verify
                                aria-busy="false"
                                disabled
                            >
                                Verify
                            </button>

                            <p
                                class="lml-chatbot-sms-verify__timer"
                                data-lml-otp-timer
                            >
                                <i class="bi bi-clock" aria-hidden="true"></i>
                                <span data-lml-otp-timer-text>
                                    The code will expire in
                                    <strong data-lml-otp-timer-value>{{ sprintf('%02d:%02d', intdiv($otpSeconds, 60), $otpSeconds % 60) }}</strong>
                                </span>
                            </p>
                            <span
                                class="visually-hidden"
                                data-lml-otp-announcement
                                aria-live="polite"
                                aria-atomic="true"
                            ></span>

                            <p class="lml-chatbot-sms-verify__resend">
                                <i class="bi bi-info-circle" aria-hidden="true"></i>
                                <span>Didn't receive a code?</span>
                                <button
                                    type="submit"
                                    class="lml-chatbot-sms-verify__resend-btn lml-focus-ring"
                                    formaction="{{ route('chatbot.email.verify.send') }}"
                                    formmethod="post"
                                    data-lml-otp-resend-server
                                >
                                    Resend Email
                                </button>
                            </p>
                        </form>
                    @else
                        <form
                            class="lml-chatbot-sms-verify__form"
                            action="{{ route('chatbot.email.verify.send') }}"
                            method="post"
                        >
                            @csrf
                            <button type="submit" class="lml-chatbot-sms-verify__verify-btn lml-focus-ring">
                                Send verification code
                            </button>
                        </form>
                    @endif
                </section>
            </main>
        </div>

        <div
            class="lml-chatbot-sms-verify__toast"
            data-lml-sms-toast
            role="status"
            aria-live="polite"
            hidden
        ></div>
    </div>
@endsection
