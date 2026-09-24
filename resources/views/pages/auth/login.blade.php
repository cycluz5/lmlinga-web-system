@extends('layouts.app')

@section('title', 'Login - LMLinga')

@section('body')
    <div class="lml-register-page lml-login-page">
        <header class="lml-register-header">
            <a href="{{ route('landing') }}" class="logo lml-register-logo text-decoration-none lml-focus-ring rounded-2">
                <img src="{{ asset('assets/images/logo/logo.png') }}" alt="">
                <span>LMLinga</span>
            </a>
        </header>

        <main class="lml-register-main">
            <section class="lml-register-card lml-login-card" aria-labelledby="login-heading">
                <div class="lml-login-card__top">
                    <a
                        href="{{ route('landing') }}"
                        class="lml-register-card__close lml-focus-ring"
                        aria-label="Close login"
                    >
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </a>
                </div>

                <div class="lml-login-card__intro">
                    <h1 id="login-heading" class="lml-login-card__heading">Login to Your Account</h1>
                    <p class="lml-login-card__subtitle">
                        Welcome back! Enter your details to log in to your account
                    </p>
                </div>

                @if (session('status'))
                    <p class="lml-login-status" role="status" aria-live="polite">
                        {{ session('status') }}
                    </p>
                @endif

                {{--
                    Staff login: POST + CSRF only. Credentials never in the URL.
                    Identity and password stay empty on GET and after a failed attempt.
                    Password uses the shared show/hide toggle; Forgot Password stays below.
                    autocomplete is off (never current-password). Chrome may still inject
                    saved credentials after paint; staff-login.js clears those until the user types.
                --}}
                <form
                    class="lml-login-form"
                    action="{{ route('login.store') }}"
                    method="post"
                    novalidate
                    autocomplete="off"
                    data-lml-staff-login
                    data-form-type="other"
                >
                    @csrf

                    @if ($errors->any())
                        <p
                            class="lml-login-error"
                            role="alert"
                            aria-live="assertive"
                            id="lml-login-error"
                        >
                            {{ $errors->first() }}
                        </p>
                    @endif

                    <div class="lml-login-field">
                        <label for="email" class="lml-login-field__label">
                            <i class="bi bi-envelope-fill" aria-hidden="true"></i>
                            <span>Email</span>
                        </label>
                        <input
                            type="email"
                            name="email"
                            id="email"
                            value=""
                            autocomplete="off"
                            autocapitalize="none"
                            autocorrect="off"
                            spellcheck="false"
                            data-lpignore="true"
                            data-1p-ignore="true"
                            inputmode="email"
                            placeholder="name@example.com"
                            required
                            aria-required="true"
                            aria-invalid="{{ $errors->any() ? 'true' : 'false' }}"
                            @if ($errors->any()) aria-describedby="lml-login-error" @endif
                            class="form-control lml-form-control lml-login-field__control lml-focus-ring"
                        >
                    </div>

                    <div class="lml-login-field lml-login-field--password">
                        <label for="password" class="lml-login-field__label">
                            <i class="bi bi-lock-fill" aria-hidden="true"></i>
                            <span>Password</span>
                        </label>
                        <x-lml.password-input
                            name="password"
                            id="password"
                            placeholder=""
                            :required="true"
                            autocomplete="off"
                            value=""
                            autocapitalize="none"
                            autocorrect="off"
                            spellcheck="false"
                            data-lpignore="true"
                            data-1p-ignore="true"
                            class="lml-login-field__control lml-focus-ring"
                            :aria-invalid="$errors->any() ? 'true' : 'false'"
                            :describedby="$errors->any() ? 'lml-login-error' : null"
                        />
                    </div>

                    <div class="lml-login-forgot">
                        <a
                            href="{{ route('password.request') }}"
                            class="lml-login-forgot__link lml-focus-ring"
                        >
                            Forgot Password?
                        </a>
                    </div>

                    <div class="lml-login-actions">
                        <button type="submit" class="lml-register-submit lml-login-submit lml-focus-ring">
                            Login
                        </button>
                    </div>
                </form>
            </section>
        </main>
    </div>
@endsection
