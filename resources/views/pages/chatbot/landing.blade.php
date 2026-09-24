@extends('layouts.app')

@section('title', 'Resident Health Chatbot - LMLinga')

@section('body')
    <div class="lml-chatbot-landing">
        <header class="lml-chatbot-landing__header">
            <a
                href="{{ route('chatbot.landing') }}"
                class="lml-chatbot-landing__brand lml-focus-ring rounded-2"
            >
                <img
                    class="lml-chatbot-landing__brand-mark"
                    src="{{ asset('assets/images/logo/logo.png') }}"
                    alt=""
                    width="72"
                    height="72"
                    decoding="async"
                >
                <span>LMLinga</span>
            </a>
        </header>

        <main class="lml-chatbot-landing__main" id="main-content">
            <section class="lml-chatbot-hero" aria-labelledby="chatbot-hero-heading">
                <div class="lml-chatbot-hero__content">
                    <h1 id="chatbot-hero-heading" class="lml-chatbot-hero__title">
                        <span class="lml-chatbot-hero__title-line">Smart Health Support</span>
                        <span class="lml-chatbot-hero__title-line">For Every Resident</span>
                    </h1>

                    <p class="lml-chatbot-hero__description">
                        A multilingual chatbot offering quick and accessible health information to inform and educate, not diagnose.
                    </p>

                    <div class="lml-chatbot-hero__actions">
                        <a
                            href="{{ route('chatbot.login') }}"
                            class="lml-chatbot-hero__btn lml-chatbot-hero__btn--primary lml-focus-ring"
                        >
                            Login
                        </a>
                        <a
                            href="{{ route('chatbot.register') }}"
                            class="lml-chatbot-hero__btn lml-chatbot-hero__btn--secondary lml-focus-ring"
                        >
                            Register
                        </a>
                    </div>
                </div>

                <div class="lml-chatbot-hero__media">
                    <img
                        class="lml-chatbot-hero__bot"
                        src="{{ asset('assets/images/logo/bot.png') }}"
                        alt="LMLinga multilingual health chatbot"
                        width="1050"
                        height="1050"
                        decoding="async"
                        fetchpriority="high"
                    >
                </div>
            </section>
        </main>
    </div>
@endsection
