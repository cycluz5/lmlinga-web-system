@extends('layouts.app')

@section('title', 'Change Password - LMLinga')

@section('body')
    <div class="lml-register-page lml-login-page lml-reset-page lml-change-password-page">
        <header class="lml-register-header">
            <span class="logo lml-register-logo">
                <img src="{{ asset('assets/images/logo/logo.png') }}" alt="">
                <span>LMLinga</span>
            </span>
        </header>

        <main class="lml-register-main">
            <section
                class="lml-register-card lml-login-card lml-recovery-card lml-reset-card"
                aria-labelledby="change-password-heading"
            >
                <div class="lml-recovery-card__stack">
                    <div class="lml-recovery-hero" aria-hidden="true">
                        <span class="lml-recovery-hero__badge">
                            <i class="bi bi-shield-lock lml-recovery-hero__icon"></i>
                        </span>
                    </div>

                    <div class="lml-login-card__intro lml-reset-card__intro">
                        <h1 id="change-password-heading" class="lml-login-card__heading lml-reset-card__heading">
                            Change Password
                        </h1>
                        <p class="lml-login-card__subtitle lml-reset-card__subtitle">
                            Choose a new password for your staff account. You can return to your profile without changing it.
                        </p>
                    </div>

                    <p
                        class="lml-hw-wizard__alert lml-change-password__alert"
                        role="alert"
                        aria-live="assertive"
                        hidden
                        data-change-password-alert
                    ></p>
                    <p
                        class="lml-hw-wizard__toast lml-change-password__toast"
                        role="status"
                        aria-live="polite"
                        hidden
                        data-change-password-status
                    ></p>

                    @if ($errors->any())
                        <p class="lml-hw-wizard__alert lml-change-password__alert" role="alert" aria-live="assertive">
                            {{ $errors->first() }}
                        </p>
                    @endif

                    <form
                        class="lml-login-form lml-recovery-form lml-reset-form"
                        action="{{ route('password.change.store') }}"
                        method="post"
                        novalidate
                        data-lml-change-password-form
                    >
                        @csrf
                        <div class="lml-login-field lml-reset-field">
                            <label for="new_password" class="lml-login-field__label">
                                <i class="bi bi-lock-fill" aria-hidden="true"></i>
                                <span>New Password</span>
                            </label>
                            <x-lml.password-input
                                name="new_password"
                                id="new_password"
                                placeholder="Enter a new password"
                                :toggle="true"
                                :required="true"
                                autocomplete="new-password"
                                class="lml-login-field__control w-100"
                            />
                            <p class="lml-login-card__subtitle" data-change-password-hint>
                                {{ \App\Support\StrongPassword::MESSAGE }}
                            </p>
                            <p class="lml-form-error" id="new_password-error" hidden></p>
                        </div>

                        <div class="lml-login-field lml-reset-field">
                            <label for="new_password_confirmation" class="lml-login-field__label">
                                <i class="bi bi-lock-fill" aria-hidden="true"></i>
                                <span>Confirm New Password</span>
                            </label>
                            <x-lml.password-input
                                name="new_password_confirmation"
                                id="new_password_confirmation"
                                placeholder="Confirm your new password"
                                :toggle="true"
                                :required="true"
                                autocomplete="new-password"
                                class="lml-login-field__control w-100"
                            />
                            <p class="lml-form-error" id="new_password_confirmation-error" hidden></p>
                        </div>

                        <div class="lml-login-actions lml-reset-actions">
                            <button type="submit" class="lml-register-submit lml-login-submit lml-recovery-submit lml-focus-ring">
                                Update Password
                            </button>
                            <a
                                href="{{ route('profile.show') }}"
                                class="lml-change-password__back lml-focus-ring"
                                data-change-password-back
                            >
                                Back to User Profile
                            </a>
                        </div>
                    </form>
                </div>
            </section>
        </main>
    </div>
@endsection
