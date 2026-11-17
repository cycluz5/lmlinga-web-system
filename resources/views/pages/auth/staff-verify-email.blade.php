@extends('layouts.app')

@section('title', 'Verify Your Email - LMLinga')

@section('body')
    <div class="lml-register-page lml-login-page">
        <header class="lml-register-header">
            <a href="{{ route('landing') }}" class="logo lml-register-logo text-decoration-none lml-focus-ring rounded-2">
                <img src="{{ asset('assets/images/logo/logo.png') }}" alt="">
                <span>LMLinga</span>
            </a>
        </header>

        <main class="lml-register-main">
            <section class="lml-register-card lml-login-card" aria-labelledby="staff-verify-heading" data-lml-staff-email-verify>
                <div class="lml-login-card__intro">
                    <h1 id="staff-verify-heading" class="lml-login-card__heading">Verify Your Email</h1>
                    <p class="lml-login-card__subtitle">
                        Your account must be verified before first use. Enter the 6-digit code we sent to
                        <strong>{{ $maskedEmail }}</strong>. The code expires in {{ $expiryMinutes }} minutes.
                    </p>
                </div>

                @if (session('status'))
                    <p class="lml-login-status" role="status" aria-live="polite">{{ session('status') }}</p>
                @endif

                <form
                    class="lml-login-form"
                    action="{{ route('staff.email-verification.verify') }}"
                    method="post"
                    novalidate
                    autocomplete="off"
                >
                    @csrf

                    @if ($errors->any())
                        <p class="lml-login-error" role="alert" aria-live="assertive" id="lml-staff-otp-error">
                            {{ $errors->first() }}
                        </p>
                    @endif

                    <div class="lml-login-field">
                        <label for="otp" class="lml-login-field__label">
                            <i class="bi bi-shield-lock-fill" aria-hidden="true"></i>
                            <span>Verification code</span>
                        </label>
                        <input
                            type="text"
                            name="otp"
                            id="otp"
                            value=""
                            inputmode="numeric"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            autocomplete="one-time-code"
                            required
                            aria-required="true"
                            aria-invalid="{{ $errors->any() ? 'true' : 'false' }}"
                            @if ($errors->any()) aria-describedby="lml-staff-otp-error" @endif
                            class="form-control lml-form-control lml-login-field__control lml-focus-ring"
                        >
                    </div>

                    <p class="lml-login-card__subtitle" data-lml-staff-otp-remaining="{{ $secondsRemaining }}">
                        @if ($secondsRemaining > 0)
                            Code valid for about {{ (int) ceil($secondsRemaining / 60) }} more minute(s).
                        @else
                            No active code. Request a new code below.
                        @endif
                    </p>

                    <div class="lml-login-actions">
                        <button type="submit" class="lml-register-submit lml-login-submit lml-focus-ring">
                            Verify
                        </button>
                    </div>
                </form>

                <form action="{{ route('staff.email-verification.resend') }}" method="post" class="lml-login-forgot">
                    @csrf
                    <button
                        type="submit"
                        class="btn btn-link lml-login-forgot__link lml-focus-ring"
                        @if ($cooldownRemaining > 0) title="You can request a new code in {{ $cooldownRemaining }} seconds." @endif
                    >
                        Send a new code
                    </button>
                </form>

                <form action="{{ route('staff.email-verification.cancel') }}" method="post" class="lml-login-forgot">
                    @csrf
                    <button type="submit" class="btn btn-link lml-login-forgot__link lml-focus-ring">
                        Back to login
                    </button>
                </form>
            </section>
        </main>
    </div>
@endsection
