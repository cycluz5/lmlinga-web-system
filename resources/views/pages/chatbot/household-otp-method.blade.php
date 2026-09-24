@extends('layouts.app')

@section('title', 'Verification Method - LMLinga')

@section('body')
    @php
        $maskedEmail = $maskedEmail ?? '***@***';
        $smsAvailable = (bool) ($smsAvailable ?? false);
    @endphp

    <div class="lml-chatbot-sms-verify">
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
                    aria-labelledby="otp-method-heading"
                >
                    <div class="lml-chatbot-sms-verify__hero">
                        <span class="lml-chatbot-sms-verify__hero-icon" aria-hidden="true">
                            <i class="bi bi-shield-lock-fill"></i>
                        </span>
                        <h1 id="otp-method-heading" class="lml-chatbot-sms-verify__title">
                            Verification Method
                        </h1>
                        <p class="lml-chatbot-sms-verify__intro">
                            Choose how you want to receive your verification code.
                        </p>
                        <p class="lml-chatbot-sms-verify__intro lml-chatbot-sms-verify__intro--secondary">
                            Household record access is not granted until verification is completed later.
                        </p>
                    </div>

                    <div class="lml-chatbot-sms-verify__form" style="gap: 0.75rem;">
                        @if ($smsAvailable)
                            <form
                                method="post"
                                action="{{ route('chatbot.household.verification.sms.send') }}"
                            >
                                @csrf
                                <button
                                    type="submit"
                                    class="lml-chatbot-sms-verify__verify-btn lml-focus-ring"
                                    style="width: 100%;"
                                >
                                    Continue with SMS Verification
                                </button>
                            </form>
                        @else
                            <button
                                type="button"
                                class="lml-chatbot-sms-verify__verify-btn"
                                disabled
                                aria-disabled="true"
                                title="SMS verification is temporarily paused"
                            >
                                Send code by SMS (unavailable)
                            </button>
                            <p class="lml-chatbot-sms-verify__intro lml-chatbot-sms-verify__intro--secondary" style="margin: 0;">
                                SMS verification is temporarily paused.
                            </p>
                        @endif

                        <form
                            method="post"
                            action="{{ route('chatbot.household.verification.email.send') }}"
                        >
                            @csrf
                            <button
                                type="submit"
                                class="lml-chatbot-sms-verify__alt-btn lml-focus-ring"
                                style="width: 100%;"
                            >
                                <i class="bi bi-envelope-fill" aria-hidden="true"></i>
                                <span>Send code by Email ({{ $maskedEmail }})</span>
                            </button>
                        </form>
                    </div>
                </section>
            </main>
        </div>
    </div>
@endsection
