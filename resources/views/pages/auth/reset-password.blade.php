@extends('layouts.app')

@section('title', 'Reset Password - LMLinga')

@php
    $resetEmail = old('email', $email ?? '');
    $resetToken = old('token', $token ?? '');
@endphp

@section('body')
    <div class="lml-register-page lml-login-page lml-reset-page">
        <header class="lml-register-header">
            <a href="{{ route('landing') }}" class="logo lml-register-logo text-decoration-none lml-focus-ring rounded-2">
                <img src="{{ asset('assets/images/logo/logo.png') }}" alt="">
                <span>LMLinga</span>
            </a>
        </header>

        <main class="lml-register-main">
            <section
                class="lml-register-card lml-login-card lml-recovery-card lml-reset-card"
                aria-labelledby="reset-password-heading"
            >
                <a
                    href="{{ route('login') }}"
                    class="lml-register-card__close lml-recovery-card__close lml-focus-ring"
                    aria-label="Close reset password and return to login"
                >
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>

                <div class="lml-recovery-card__stack">
                    <div class="lml-recovery-hero" aria-hidden="true">
                        <span class="lml-recovery-hero__badge">
                            <i class="bi bi-lock lml-recovery-hero__icon"></i>
                        </span>
                    </div>

                    <div class="lml-login-card__intro lml-reset-card__intro">
                        <h1 id="reset-password-heading" class="lml-login-card__heading lml-reset-card__heading">
                            Reset Password
                        </h1>
                        <p class="lml-login-card__subtitle lml-reset-card__subtitle">
                            Please enter a new password below to change your password.
                        </p>
                    </div>

                    @if ($errors->any())
                        <p class="lml-login-error" role="alert" aria-live="assertive">
                            {{ $errors->first() }}
                        </p>
                    @endif

                    <form
                        class="lml-login-form lml-recovery-form lml-reset-form"
                        action="{{ route('password.update') }}"
                        method="post"
                        novalidate
                    >
                        @csrf
                        <input type="hidden" name="token" value="{{ $resetToken }}">
                        <input type="hidden" name="email" value="{{ $resetEmail }}">

                        <div class="lml-login-field lml-reset-field">
                            <label for="password" class="lml-login-field__label">
                                <i class="bi bi-lock-fill" aria-hidden="true"></i>
                                <span>New Password</span>
                            </label>
                            <x-lml.password-input
                                name="password"
                                id="password"
                                placeholder=""
                                :toggle="true"
                                autocomplete="new-password"
                                class="lml-login-field__control w-100"
                            />
                            <p class="lml-login-card__subtitle">
                                {{ \App\Support\StrongPassword::MESSAGE }}
                            </p>
                        </div>

                        <div class="lml-login-field lml-reset-field">
                            <label for="password_confirmation" class="lml-login-field__label">
                                <i class="bi bi-lock-fill" aria-hidden="true"></i>
                                <span>Confirm New Password</span>
                            </label>
                            <x-lml.password-input
                                name="password_confirmation"
                                id="password_confirmation"
                                placeholder=""
                                :toggle="true"
                                autocomplete="new-password"
                                class="lml-login-field__control w-100"
                            />
                        </div>

                        <div class="lml-login-actions lml-reset-actions">
                            <button type="submit" class="lml-register-submit lml-login-submit lml-recovery-submit lml-focus-ring">
                                Reset Password
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </main>
    </div>
@endsection
