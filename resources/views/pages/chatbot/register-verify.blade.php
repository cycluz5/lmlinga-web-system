@extends('layouts.app')

@section('title', 'Verify Your Email - LMLinga')

@section('body')
    @php
        $maskedEmail = $maskedEmail ?? '***';
        $otpVerifyError = trim((string) ($otpVerifyError ?? ''));
        $otpStatus = trim((string) ($otpStatus ?? ''));
        $otpSeconds = max(0, (int) ($otpSeconds ?? 0));
    @endphp

    {{-- Same OTP UI as household email verification (chatbot-household-sms.js); account created only after the code. --}}
    <div
        class="lml-chatbot-sms-verify"
        data-lml-sms-verify
        data-resend-success-message="A new code has been sent."
        data-otp-seconds="{{ $otpSeconds }}"
        data-otp-lock-seconds="0"
    >
        <div class="lml-chatbot-sms-verify__inner">
            <header class="lml-chatbot-sms-verify__header">
                <a
                    href="{{ route('chatbot.register') }}"
                    class="lml-chatbot-sms-verify__back lml-focus-ring"
                >
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back</span>
                </a>
            </header>

            <main class="lml-chatbot-sms-verify__main" id="main-content">
                <section
                    class="lml-chatbot-sms-verify__card lml-surface lml-surface--elevated"
                    aria-labelledby="register-verify-heading"
                >
                    <div class="lml-chatbot-sms-verify__hero">
                        <span class="lml-chatbot-sms-verify__hero-icon" aria-hidden="true">
                            <i class="bi bi-envelope-check-fill"></i>
                        </span>
                        <h1 id="register-verify-heading" class="lml-chatbot-sms-verify__title">
                            Verify Your Email
                        </h1>
                        @if ($otpStatus !== '')
                            <p class="lml-chatbot-sms-verify__intro" role="status">{{ $otpStatus }}</p>
                        @endif
                        <p class="lml-chatbot-sms-verify__intro">
                            We've sent a 6-digit code to
                            <span class="lml-chatbot-sms-verify__masked">{{ $maskedEmail }}</span>.
                        </p>
                        <p class="lml-chatbot-sms-verify__intro lml-chatbot-sms-verify__intro--secondary">
                            Enter the code to finish creating your account. Your account is created only after your email is verified.
                        </p>
                    </div>

                    <form
                        class="lml-chatbot-sms-verify__form"
                        action="{{ route('chatbot.register.verify.submit') }}"
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
                                    <label class="visually-hidden" for="register-otp-{{ $i }}">
                                        Digit {{ $i + 1 }} of 6
                                    </label>
                                    <input
                                        id="register-otp-{{ $i }}"
                                        class="lml-chatbot-sms-verify__otp-input lml-focus-ring"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}"
                                        maxlength="1"
                                        pattern="[0-9]"
                                        data-lml-otp-digit
                                        data-otp-index="{{ $i }}"
                                        aria-invalid="false"
                                        aria-describedby="register-otp-error"
                                    >
                                @endfor
                            </div>
                            <p
                                id="register-otp-error"
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
                                formaction="{{ route('chatbot.register.resend') }}"
                                formmethod="post"
                                data-lml-otp-resend-server
                            >
                                Resend Email
                            </button>
                        </p>
                    </form>

                    <form action="{{ route('chatbot.register.cancel') }}" method="post" class="lml-chatbot-sms-verify__resend">
                        @csrf
                        <button type="submit" class="lml-chatbot-sms-verify__resend-btn lml-focus-ring">
                            Use a different email
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
