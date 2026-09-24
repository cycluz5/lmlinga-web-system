@extends('layouts.app')

@section('title', 'Forgot Password - LMLinga')

@section('body')
    <div class="lml-chatbot-forgot-password">
        <div class="lml-chatbot-forgot-password__layout">
            {{-- Left panel: forgot-password form (~55%) --}}
            <main class="lml-chatbot-forgot-password__panel" id="main-content">
                <a
                    href="{{ route('chatbot.login') }}"
                    class="lml-chatbot-forgot-password__close lml-focus-ring"
                    aria-label="Close forgot password and return to chatbot login"
                >
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>

                <section
                    class="lml-chatbot-forgot-password__card"
                    aria-labelledby="chatbot-forgot-password-heading"
                >
                    <header class="lml-chatbot-forgot-password__card-header">
                        <h1
                            id="chatbot-forgot-password-heading"
                            class="lml-chatbot-forgot-password__card-heading"
                        >
                            Forgot Your Password?
                        </h1>
                        <p class="lml-chatbot-forgot-password__card-subtitle">
                            Please enter the email address with your account.<br class="lml-chatbot-forgot-password__card-subtitle-break">
                            We will send a secure link to verify and reset your password.
                        </p>
                    </header>

                    <form
                        class="lml-chatbot-forgot-password__form"
                        action="{{ route('chatbot.password.email') }}"
                        method="post"
                        novalidate
                    >
                        @csrf

                        @if (session('status'))
                            <p class="lml-chatbot-forgot-password__card-subtitle" role="status">
                                {{ session('status') }}
                            </p>
                        @endif

                        <x-lml.form-group
                            label="Your Email"
                            name="email"
                            icon="bi-envelope-fill"
                            :required="true"
                            class="lml-chatbot-forgot-password__field"
                        >
                            @php
                                $emailError = $errors->first('email');
                                $emailErrorId = filled($emailError) ? 'email-error' : null;
                            @endphp
                            <input
                                type="email"
                                name="email"
                                id="email"
                                value="{{ old('email') }}"
                                required
                                aria-required="true"
                                autocomplete="email"
                                inputmode="email"
                                placeholder="Email"
                                @if (filled($emailError)) aria-invalid="true" aria-describedby="{{ $emailErrorId }}" @endif
                                class="form-control lml-form-control lml-chatbot-forgot-password__control w-100{{ filled($emailError) ? ' is-invalid' : '' }}"
                            >
                        </x-lml.form-group>

                        <div class="lml-chatbot-forgot-password__actions">
                            <button
                                type="submit"
                                class="lml-chatbot-forgot-password__submit lml-focus-ring"
                            >
                                Send Verification Link
                            </button>

                            <p class="lml-chatbot-forgot-password__footer">
                                Already have an account?
                                <a
                                    href="{{ route('chatbot.login') }}"
                                    class="lml-chatbot-forgot-password__login-link lml-focus-ring"
                                >
                                    Login
                                </a>
                            </p>
                        </div>
                    </form>
                </section>
            </main>

            {{-- Right panel: promotional content (~45%) --}}
            <aside
                class="lml-chatbot-forgot-password__promo"
                aria-label="LMLinga chatbot information"
            >
                <div class="lml-chatbot-forgot-password__promo-group">
                    <img
                        class="lml-chatbot-forgot-password__brand-mark"
                        src="{{ asset('assets/images/logo/logo.png') }}"
                        alt="LMLinga official healthcare logo"
                        width="96"
                        height="96"
                        decoding="async"
                    >

                    <p class="lml-chatbot-forgot-password__promo-text">
                        A multilingual <strong>chatbot</strong> for health<br>
                        information and education only.
                    </p>

                    <div class="lml-chatbot-forgot-password__promo-media">
                        <img
                            class="lml-chatbot-forgot-password__bot"
                            src="{{ asset('assets/images/logo/bot.png') }}"
                            alt=""
                            width="160"
                            height="160"
                            decoding="async"
                            fetchpriority="high"
                            aria-hidden="true"
                        >

                        <ul
                            class="lml-chatbot-forgot-password__languages"
                            aria-label="Supported chatbot languages"
                        >
                            <li class="lml-chatbot-forgot-password__language">
                                <i class="bi bi-chat-dots-fill" aria-hidden="true"></i>
                                <span>English</span>
                            </li>
                            <li class="lml-chatbot-forgot-password__language">
                                <i class="bi bi-chat-dots-fill" aria-hidden="true"></i>
                                <span>Tagalog</span>
                            </li>
                            <li class="lml-chatbot-forgot-password__language">
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
