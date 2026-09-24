<?php
/**
 * Screenshot-authoritative SMS Verification UI — wired to SMS send/verify POSTs.
 */
?>
@extends('layouts.app')

@section('title', 'SMS Verification - LMLinga')

@section('body')
    @php
        $maskedMobile = $maskedMobile ?? '09******000';
        $otpSmsError = trim((string) ($otpSmsError ?? ''));
        $otpVerifyError = trim((string) ($otpVerifyError ?? ''));
        $otpSmsSuccess = trim((string) (session('household_otp_sms_success', '')));
        $otpSeconds = (int) ($otpSeconds ?? 0);
        if ($otpSeconds < 0) {
            $otpSeconds = 0;
        }
        $otpLockSeconds = (int) ($otpLockSeconds ?? 0);
        if ($otpLockSeconds < 0) {
            $otpLockSeconds = 0;
        }
        $otpLocked = $otpLockSeconds > 0;
    @endphp

    <div
        class="lml-chatbot-sms-verify"
        data-lml-sms-verify
        data-otp-seconds="{{ $otpSeconds }}"
        data-otp-lock-seconds="{{ $otpLockSeconds }}"
        data-resend-success-message="New verification SMS sent."
        @if ($otpLocked) data-otp-locked="true" @endif
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
                    aria-labelledby="sms-verify-heading"
                >
                    <div class="lml-chatbot-sms-verify__hero">
                        <span class="lml-chatbot-sms-verify__hero-icon" aria-hidden="true">
                            <i class="bi bi-shield-lock-fill"></i>
                        </span>
                        <h1 id="sms-verify-heading" class="lml-chatbot-sms-verify__title">
                            SMS Verification
                        </h1>
                        @if ($otpSmsError !== '')
                            <p class="lml-chatbot-sms-verify__intro" role="alert">
                                {{ $otpSmsError }}
                            </p>
                        @elseif ($otpSmsSuccess !== '')
                            <p class="lml-chatbot-sms-verify__intro" role="status">
                                {{ $otpSmsSuccess }}
                            </p>
                        @endif
                        <p class="lml-chatbot-sms-verify__intro">
                            We've sent a 6-digit OTP to your mobile number
                            <span class="lml-chatbot-sms-verify__masked">{{ $maskedMobile }}</span>.
                        </p>
                        <p class="lml-chatbot-sms-verify__intro lml-chatbot-sms-verify__intro--secondary">
                            Enter the 6-digit code below to verify your identity and access the household record.
                        </p>
                    </div>

                    <form
                        class="lml-chatbot-sms-verify__form"
                        action="{{ route('chatbot.household.verification.sms.verify') }}"
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
                                    <label class="visually-hidden" for="sms-otp-{{ $i }}">
                                        Digit {{ $i + 1 }} of 6
                                    </label>
                                    <input
                                        id="sms-otp-{{ $i }}"
                                        class="lml-chatbot-sms-verify__otp-input lml-focus-ring"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}"
                                        maxlength="1"
                                        pattern="[0-9]"
                                        data-lml-otp-digit
                                        data-otp-index="{{ $i }}"
                                        aria-invalid="false"
                                        aria-describedby="sms-otp-error"
                                        @disabled($otpLocked)
                                    >
                                @endfor
                            </div>
                            <p
                                id="sms-otp-error"
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
                            @if ($otpLocked) hidden @endif
                        >
                            <i class="bi bi-clock" aria-hidden="true"></i>
                            <span data-lml-otp-timer-text>
                                The code will expire in
                                <strong data-lml-otp-timer-value>{{ sprintf('%02d:%02d', intdiv($otpSeconds, 60), $otpSeconds % 60) }}</strong>
                            </span>
                        </p>
                        <p
                            class="lml-chatbot-sms-verify__timer is-expired"
                            data-lml-otp-lock-timer
                            @if (! $otpLocked) hidden @endif
                        >
                            <i class="bi bi-shield-lock" aria-hidden="true"></i>
                            <span data-lml-otp-lock-timer-text>
                                You have reached the maximum number of attempts. Please try again in
                                <strong data-lml-otp-lock-timer-value>{{ sprintf('%02d:%02d', intdiv($otpLockSeconds, 60), $otpLockSeconds % 60) }}</strong>.
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
                                formaction="{{ route('chatbot.household.verification.sms.send') }}"
                                formmethod="post"
                                data-lml-otp-resend-server
                                @disabled($otpLocked)
                                @if ($otpLocked)
                                    title="Verification is temporarily locked"
                                @endif
                            >
                                Resend OTP
                            </button>
                        </p>
                    </form>

                    <div class="lml-chatbot-sms-verify__divider">
                        <span class="lml-chatbot-sms-verify__divider-line" aria-hidden="true"></span>
                        <span class="lml-chatbot-sms-verify__divider-label">OR</span>
                        <span class="lml-chatbot-sms-verify__divider-line" aria-hidden="true"></span>
                    </div>

                    <form
                        method="post"
                        action="{{ route('chatbot.household.verification.email.send') }}"
                        class="lml-chatbot-sms-verify__alt-form"
                    >
                        @csrf
                        <button
                            type="submit"
                            class="lml-chatbot-sms-verify__alt-btn lml-focus-ring"
                        >
                            <i class="bi bi-envelope-fill" aria-hidden="true"></i>
                            <span>Try Other Way (Send via Email)</span>
                        </button>
                    </form>
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
