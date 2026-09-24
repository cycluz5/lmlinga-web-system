@extends('layouts.app')

@section('title', 'Forgot Password - LMLinga')

@section('body')
    <div class="lml-register-page lml-login-page lml-forgot-page">
        <header class="lml-register-header">
            <a href="{{ route('landing') }}" class="logo lml-register-logo text-decoration-none lml-focus-ring rounded-2">
                <img src="{{ asset('assets/images/logo/logo.png') }}" alt="">
                <span>LMLinga</span>
            </a>
        </header>

        <main class="lml-register-main">
            <section
                class="lml-register-card lml-login-card lml-recovery-card lml-forgot-card"
                aria-labelledby="forgot-password-heading"
            >
                <a
                    href="{{ route('login') }}"
                    class="lml-register-card__close lml-recovery-card__close lml-focus-ring"
                    aria-label="Close forgot password and return to login"
                >
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>

                <div class="lml-recovery-card__stack">
                    <div class="lml-recovery-hero" aria-hidden="true">
                        <span class="lml-recovery-hero__badge">
                            <i class="bi bi-envelope lml-recovery-hero__icon"></i>
                        </span>
                    </div>

                    <div class="lml-login-card__intro lml-forgot-card__intro">
                        <h1 id="forgot-password-heading" class="lml-login-card__heading lml-forgot-card__heading">
                            Forgot Password
                        </h1>
                        <p class="lml-login-card__subtitle lml-forgot-card__subtitle">
                            Please enter the email address associated with your account. We will send you a secure link to verify your identity and reset your password.
                        </p>
                    </div>

                    @if (session('status'))
                        <p class="lml-login-status" role="status" aria-live="polite">
                            {{ session('status') }}
                        </p>
                    @endif

                    @if ($errors->any())
                        <p class="lml-login-error" role="alert" aria-live="assertive">
                            {{ $errors->first() }}
                        </p>
                    @endif

                    <form
                        class="lml-login-form lml-recovery-form lml-forgot-form"
                        action="{{ route('password.email') }}"
                        method="post"
                        novalidate
                    >
                        @csrf
                        <div class="lml-login-field lml-forgot-field">
                            <label for="email" class="lml-login-field__label">
                                <i class="bi bi-person-fill" aria-hidden="true"></i>
                                <span>Your email</span>
                            </label>
                            <input
                                type="email"
                                name="email"
                                id="email"
                                value="{{ old('email') }}"
                                autocomplete="email"
                                class="form-control lml-form-control lml-login-field__control w-100"
                            >
                        </div>

                        <div class="lml-login-actions lml-forgot-actions">
                            <button type="submit" class="lml-register-submit lml-login-submit lml-recovery-submit lml-focus-ring">
                                Send Verification
                            </button>

                            <p class="lml-register-footer lml-login-footer lml-forgot-footer">
                                Already have an account?
                                <a href="{{ route('login') }}" class="lml-register-login-link">Login</a>
                            </p>
                        </div>
                    </form>
                </div>
            </section>
        </main>
    </div>
@endsection
