@extends('layouts.app')

@section('title', 'Reset Password - LMLinga')

@section('body')
    <div class="lml-chatbot-reset-password">
        <div class="lml-chatbot-reset-password__layout">
            {{-- Left panel: reset-password form (~55%) --}}
            <main class="lml-chatbot-reset-password__panel" id="main-content">
                <a
                    href="{{ route('chatbot.login') }}"
                    class="lml-chatbot-reset-password__close lml-focus-ring"
                    aria-label="Close reset password and return to chatbot login"
                >
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>

                <section
                    class="lml-chatbot-reset-password__card"
                    aria-labelledby="chatbot-reset-password-heading"
                >
                    <header class="lml-chatbot-reset-password__card-header">
                        <h1
                            id="chatbot-reset-password-heading"
                            class="lml-chatbot-reset-password__card-heading"
                        >
                            Reset Your Password
                        </h1>
                        <p class="lml-chatbot-reset-password__card-subtitle">
                            Please enter a new password below to change your password.
                        </p>
                    </header>

                    <form
                        class="lml-chatbot-reset-password__form"
                        action="{{ route('chatbot.password.update') }}"
                        method="post"
                        novalidate
                    >
                        @csrf

                        @if ($errors->has('email') || $errors->has('token'))
                            <p class="lml-form-error" role="alert">
                                {{ $errors->first('email') ?: $errors->first('token') }}
                            </p>
                        @endif

                        <input type="hidden" name="token" value="{{ $token ?? request()->route('token') ?? request('token') }}">
                        <input type="hidden" name="email" value="{{ old('email', $email ?? request('email')) }}">

                        <x-lml.form-group
                            label="New Password"
                            name="password"
                            icon="bi-lock-fill"
                            :required="true"
                            class="lml-chatbot-reset-password__field"
                        >
                            <x-lml.password-input
                                name="password"
                                id="password"
                                :required="true"
                                placeholder=""
                                :toggle="true"
                                autocomplete="new-password"
                                class="lml-chatbot-reset-password__control w-100"
                            />
                        </x-lml.form-group>

                        <x-lml.form-group
                            label="Confirm Password"
                            name="password_confirmation"
                            icon="bi-lock-fill"
                            :required="true"
                            class="lml-chatbot-reset-password__field"
                        >
                            <x-lml.password-input
                                name="password_confirmation"
                                id="password_confirmation"
                                :required="true"
                                placeholder=""
                                :toggle="true"
                                autocomplete="new-password"
                                class="lml-chatbot-reset-password__control w-100"
                            />
                        </x-lml.form-group>

                        <div class="lml-chatbot-reset-password__actions">
                            <button
                                type="submit"
                                class="lml-chatbot-reset-password__submit lml-focus-ring"
                            >
                                Reset Password
                            </button>
                        </div>
                    </form>
                </section>
            </main>

            {{-- Right panel: promotional content (~45%) — matches forgot-password --}}
            <aside
                class="lml-chatbot-reset-password__promo"
                aria-label="LMLinga chatbot information"
            >
                <div class="lml-chatbot-reset-password__promo-group">
                    <img
                        class="lml-chatbot-reset-password__brand-mark"
                        src="{{ asset('assets/images/logo/logo.png') }}"
                        alt="LMLinga official healthcare logo"
                        width="96"
                        height="96"
                        decoding="async"
                    >

                    <p class="lml-chatbot-reset-password__promo-text">
                        A multilingual <strong>chatbot</strong> for health<br>
                        information and education only.
                    </p>

                    <div class="lml-chatbot-reset-password__promo-media">
                        <img
                            class="lml-chatbot-reset-password__bot"
                            src="{{ asset('assets/images/logo/bot.png') }}"
                            alt=""
                            width="160"
                            height="160"
                            decoding="async"
                            fetchpriority="high"
                            aria-hidden="true"
                        >

                        <ul
                            class="lml-chatbot-reset-password__languages"
                            aria-label="Supported chatbot languages"
                        >
                            <li class="lml-chatbot-reset-password__language">
                                <i class="bi bi-chat-dots-fill" aria-hidden="true"></i>
                                <span>English</span>
                            </li>
                            <li class="lml-chatbot-reset-password__language">
                                <i class="bi bi-chat-dots-fill" aria-hidden="true"></i>
                                <span>Tagalog</span>
                            </li>
                            <li class="lml-chatbot-reset-password__language">
                                <i class="bi bi-chat-dots-fill" aria-hidden="true"></i>
                                <span>Bikol Iriga</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </aside>
        </div>
    </div>
@endsection
