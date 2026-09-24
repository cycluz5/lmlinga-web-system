@extends('layouts.app')

@section('title', 'Login - LMLinga')

@section('body')
    <div class="lml-chatbot-login">
        <div class="lml-chatbot-login__layout">
            {{-- Left panel: login form --}}
            <main class="lml-chatbot-login__panel" id="main-content">
                <a
                    href="{{ route('chatbot.landing') }}"
                    class="lml-chatbot-login__close lml-focus-ring"
                    aria-label="Return to chatbot landing page"
                >
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>

                <section class="lml-chatbot-login__card" aria-labelledby="chatbot-login-heading">
                    <header class="lml-chatbot-login__card-header">
                        <h1 id="chatbot-login-heading" class="lml-chatbot-login__card-heading">
                            Login to Your Account
                        </h1>
                        <p class="lml-chatbot-login__card-subtitle">
                            Welcome back! Enter your details to log in to your account
                        </p>
                    </header>

                    {{--
                        Resident login: POST + CSRF only. Credentials never in the URL.
                        Owned by Auth\ResidentLoginController@store (chatbot.login.store).
                        Failed attempts are locked after 5 consecutive failures.
                    --}}
                    <form
                        class="lml-chatbot-login__form"
                        action="{{ route('chatbot.login.store') }}"
                        method="post"
                        novalidate
                    >
                        @csrf

                        @if (session('success'))
                            <p class="lml-chatbot-login__card-subtitle" role="status">
                                {{ session('success') }}
                            </p>
                        @endif

                        @if ($errors->any())
                            <p
                                class="lml-chatbot-login__error"
                                role="alert"
                                aria-live="assertive"
                                data-resident-login-error
                            >
                                {{ $errors->first() }}
                            </p>
                        @endif

                        <x-lml.form-group
                            label="Email"
                            name="email"
                            icon="bi-envelope-fill"
                            :required="true"
                            class="lml-chatbot-login__field"
                        >
                            <x-lml.text-input
                                type="email"
                                name="email"
                                id="email"
                                :required="true"
                                autocomplete="email"
                                inputmode="email"
                                placeholder="name@example.com"
                                class="lml-chatbot-login__control w-100"
                            />
                        </x-lml.form-group>

                        <x-lml.form-group
                            label="Password"
                            name="password"
                            icon="bi-lock-fill"
                            :required="true"
                            class="lml-chatbot-login__field"
                        >
                            <x-lml.password-input
                                name="password"
                                id="password"
                                :required="true"
                                placeholder=""
                                :toggle="true"
                                autocomplete="current-password"
                                class="lml-chatbot-login__control w-100"
                            />
                        </x-lml.form-group>

                        <div class="lml-chatbot-login__forgot">
                            <a
                                href="{{ route('chatbot.password.request') }}"
                                class="lml-chatbot-login__forgot-link lml-focus-ring"
                            >
                                Forgot Password?
                            </a>
                        </div>

                        <div class="lml-chatbot-login__actions">
                            <button type="submit" class="lml-chatbot-login__submit lml-focus-ring">
                                Login
                            </button>
                        </div>

                        <p class="lml-chatbot-login__footer">
                            Don't have an account?
                            <a
                                href="{{ route('chatbot.register') }}"
                                class="lml-chatbot-login__register-link lml-focus-ring"
                            >
                                Register
                            </a>
                        </p>
                    </form>
                </section>
            </main>

            {{-- Right panel: promotional content --}}
            <aside class="lml-chatbot-login__promo" aria-label="LMLinga chatbot information">
                <div class="lml-chatbot-login__promo-group">
                    <img
                        class="lml-chatbot-login__brand-mark"
                        src="{{ asset('assets/images/logo/logo.png') }}"
                        alt="LMLinga official healthcare logo"
                        width="96"
                        height="96"
                        decoding="async"
                    >

                    <p class="lml-chatbot-login__promo-text">
                        A multilingual <strong>chatbot</strong> for health<br>
                        information and education only.
                    </p>

                    <div class="lml-chatbot-login__promo-media">
                        <img
                            class="lml-chatbot-login__bot"
                            src="{{ asset('assets/images/logo/bot.png') }}"
                            alt="LMLinga multilingual health chatbot"
                            width="160"
                            height="160"
                            decoding="async"
                            fetchpriority="high"
                        >

                        <ul class="lml-chatbot-login__languages" aria-label="Supported chatbot languages">
                            <li class="lml-chatbot-login__language">
                                <i class="bi bi-chat-dots-fill" aria-hidden="true"></i>
                                <span>English</span>
                            </li>
                            <li class="lml-chatbot-login__language">
                                <i class="bi bi-chat-dots-fill" aria-hidden="true"></i>
                                <span>Tagalog</span>
                            </li>
                            <li class="lml-chatbot-login__language">
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
