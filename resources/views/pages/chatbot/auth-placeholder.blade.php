{{--
    Temporary resident auth placeholder.
    Dedicated resident register/login screens are not implemented yet.
    Staff routes /login and /register must not be used from the chatbot landing.
--}}
@extends('layouts.app')

@php
    $isRegister = ($mode ?? 'login') === 'register';
    $pageTitle = $isRegister ? 'Resident Registration' : 'Resident Login';
@endphp

@section('title', $pageTitle.' - LMLinga')

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
                    alt="LMLinga official healthcare logo"
                    width="72"
                    height="72"
                >
                <span>LMLinga</span>
            </a>
        </header>

        <main class="lml-chatbot-landing__main" id="main-content">
            <section class="lml-chatbot-hero__content" aria-labelledby="auth-placeholder-heading" style="max-width: 36rem; margin-inline: auto;">
                <h1 id="auth-placeholder-heading" class="lml-chatbot-hero__title" style="text-transform: none; font-size: 1.75rem;">
                    {{ $pageTitle }}
                </h1>
                <p class="lml-chatbot-hero__description">
                    Resident {{ $isRegister ? 'registration' : 'login' }} is not available yet.
                    This is a temporary placeholder so the chatbot landing page can link to a resident-facing route
                    without using staff authentication screens.
                </p>
                <div class="lml-chatbot-hero__actions">
                    <a href="{{ route('chatbot.landing') }}" class="lml-chatbot-hero__btn lml-chatbot-hero__btn--primary">
                        Back to chatbot
                    </a>
                </div>
            </section>
        </main>
    </div>
@endsection
