{{--
    Resident household / verification placeholders (UI-only).
    No forms, persistence, or approval logic yet.
--}}
@extends('layouts.app')

@php
    $mode = $mode ?? 'request';
    $copy = match ($mode) {
        'status' => [
            'title' => 'Verification Request Status',
            'description' => 'Your household verification request is pending review by the barangay health team. Household Information will become available once your account is verified. This is a UI placeholder — no live status data is loaded.',
        ],
        'information' => [
            'title' => 'Household Information',
            'description' => 'This is a placeholder for the resident Household Information module. Verified residents will view household details here in a later phase. No household data is loaded yet.',
        ],
        default => [
            'title' => 'Household Verification Request',
            'description' => 'This is a placeholder for the resident verification request form. Residents who are not yet verified will submit supporting details here in a later phase. No form or backend submission is available yet.',
        ],
    };
@endphp

@section('title', $copy['title'].' - LMLinga')

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
                >
                <span>LMLinga</span>
            </a>
        </header>

        <main class="lml-chatbot-landing__main" id="main-content">
            <section
                class="lml-chatbot-hero__content lml-chatbot-hero__content--narrow"
                aria-labelledby="household-placeholder-heading"
            >
                <h1
                    id="household-placeholder-heading"
                    class="lml-chatbot-hero__title lml-chatbot-hero__title--placeholder"
                >
                    {{ $copy['title'] }}
                </h1>
                <p class="lml-chatbot-hero__description">
                    {{ $copy['description'] }}
                </p>
                <div class="lml-chatbot-hero__actions">
                    <a href="{{ route('chatbot.main') }}" class="lml-chatbot-hero__btn lml-chatbot-hero__btn--primary">
                        Back to chatbot
                    </a>
                </div>
            </section>
        </main>
    </div>
@endsection
