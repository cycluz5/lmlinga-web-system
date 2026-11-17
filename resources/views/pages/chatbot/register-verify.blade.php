@extends('layouts.app')

@section('title', 'Verify Your Email - LMLinga')

@section('body')
    <div class="lml-chatbot-register">
        <div class="lml-chatbot-register__layout">
            <aside class="lml-chatbot-register__promo" aria-label="LMLinga chatbot information">
                <a
                    href="{{ route('chatbot.landing') }}"
                    class="lml-chatbot-register__brand lml-focus-ring rounded-2"
                >
                    <img
                        class="lml-chatbot-register__brand-mark"
                        src="{{ asset('assets/images/logo/logo.png') }}"
                        alt=""
                        width="72"
                        height="72"
                        decoding="async"
                    >
                    <span>LMLinga</span>
                </a>
            </aside>

            <main class="lml-chatbot-register__panel" id="main-content">
                <section class="lml-chatbot-register__card" aria-labelledby="chatbot-register-verify-heading" data-lml-resident-register-verify>
                    <header class="lml-chatbot-register__card-header">
                        <i class="bi bi-envelope-check-fill lml-chatbot-register__card-icon" aria-hidden="true"></i>
                        <h1 id="chatbot-register-verify-heading" class="lml-chatbot-register__card-heading">
                            Verify Your Email
                        </h1>
                    </header>

                    <p>
                        Enter the 6-digit code we sent to <strong>{{ $maskedEmail }}</strong>.
                        The code expires in {{ $expiryMinutes }} minutes. Your account is created once it is verified.
                    </p>

                    @if (session('status'))
                        <p role="status" aria-live="polite">{{ session('status') }}</p>
                    @endif

                    <form
                        class="lml-chatbot-register__form"
                        action="{{ route('chatbot.register.verify.submit') }}"
                        method="post"
                        novalidate
                        autocomplete="off"
                        data-lml-register-verify-form
                    >
                        @csrf

                        @if ($errors->any())
                            <p class="text-danger" role="alert" aria-live="assertive" id="lml-resident-otp-error">
                                {{ $errors->first() }}
                            </p>
                        @endif

                        <x-lml.form-group
                            label="Verification code"
                            name="otp"
                            :required="true"
                            icon="bi-shield-lock"
                            class="lml-chatbot-register__field"
                        >
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
                                @if ($errors->any()) aria-describedby="lml-resident-otp-error" @endif
                                class="form-control lml-form-control lml-chatbot-register__control w-100"
                            >
                        </x-lml.form-group>

                        <p data-lml-resident-otp-remaining="{{ $secondsRemaining }}">
                            @if ($secondsRemaining > 0)
                                Code valid for about {{ (int) ceil($secondsRemaining / 60) }} more minute(s).
                            @else
                                No active code. Request a new code below.
                            @endif
                        </p>

                        <div class="lml-chatbot-register__actions">
                            <button
                                type="submit"
                                class="lml-chatbot-register__submit lml-focus-ring"
                                data-lml-register-verify-submit
                            >
                                Verify
                            </button>
                        </div>
                    </form>

                    <form action="{{ route('chatbot.register.verify.resend') }}" method="post">
                        @csrf
                        <button
                            type="submit"
                            class="btn btn-link lml-focus-ring"
                            data-lml-resend-cooldown="{{ $cooldownRemaining }}"
                            @if ($cooldownRemaining > 0) title="You can request a new code in {{ $cooldownRemaining }} seconds." @endif
                        >
                            Send a new code
                        </button>
                    </form>

                    <form action="{{ route('chatbot.register.verify.cancel') }}" method="post">
                        @csrf
                        <button type="submit" class="btn btn-link lml-focus-ring">
                            Use a different email
                        </button>
                    </form>
                </section>
            </main>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.querySelector('[data-lml-register-verify-form]');
            var button = form ? form.querySelector('[data-lml-register-verify-submit]') : null;
            if (form && button) {
                form.addEventListener('submit', function (event) {
                    if (button.disabled) {
                        event.preventDefault();
                        return;
                    }
                    if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                        return;
                    }
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                    button.textContent = 'Verifying...';
                });
            }

            var resend = document.querySelector('[data-lml-resend-cooldown]');
            var remaining = resend ? parseInt(resend.getAttribute('data-lml-resend-cooldown'), 10) : 0;
            if (resend && remaining > 0) {
                var label = resend.textContent.trim();
                resend.disabled = true;
                var timer = setInterval(function () {
                    remaining -= 1;
                    if (remaining <= 0) {
                        clearInterval(timer);
                        resend.disabled = false;
                        resend.textContent = label;
                        return;
                    }
                    resend.textContent = label + ' (' + remaining + 's)';
                }, 1000);
            }
        });
    </script>
@endpush
