@extends('layouts.app')

@section('title', $memberName.' - LMLinga')

@section('body')
    <div class="lml-chatbot-member-record">
        <div class="lml-chatbot-member-record__inner">
            <header class="lml-chatbot-member-record__page-header">
                <div class="lml-chatbot-member-record__identity">
                    <a
                        href="{{ route('chatbot.household.information') }}"
                        class="lml-chatbot-member-record__back lml-focus-ring"
                        aria-label="Back to Household Record"
                    >
                        <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    </a>

                    <span class="lml-chatbot-member-record__avatar" aria-hidden="true">
                        <i class="bi bi-person-fill"></i>
                    </span>

                    <div class="lml-chatbot-member-record__identity-text">
                        <h1 id="member-record-heading" class="lml-chatbot-member-record__name">
                            {{ $memberName }}
                        </h1>
                        <p class="lml-chatbot-member-record__subtitle">Member Information</p>
                    </div>
                </div>
            </header>

            <main class="lml-chatbot-member-record__main" id="main-content">
                <div class="lml-chatbot-member-record__layout">
                    <article class="lml-chatbot-member-record__main-card">
                        <section
                            class="lml-chatbot-member-record__section"
                            aria-labelledby="member-personal-heading"
                        >
                            <h2 id="member-personal-heading" class="lml-chatbot-member-record__section-title">
                                <i class="bi bi-person-fill" aria-hidden="true"></i>
                                <span>Personal Information</span>
                            </h2>
                            <dl class="lml-chatbot-member-record__dl lml-chatbot-member-record__dl--paired">
                                @foreach ($personal as $row)
                                    <div class="lml-chatbot-member-record__item">
                                        <dt>{{ $row['label'] }}</dt>
                                        <dd>{{ $row['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>

                        <section
                            class="lml-chatbot-member-record__section"
                            aria-labelledby="member-socio-heading"
                        >
                            <h2 id="member-socio-heading" class="lml-chatbot-member-record__section-title">
                                <i class="bi bi-bar-chart-fill" aria-hidden="true"></i>
                                <span>Socio-Economic Details</span>
                            </h2>
                            <dl class="lml-chatbot-member-record__dl lml-chatbot-member-record__dl--paired">
                                @foreach ($socioEconomic as $row)
                                    <div class="lml-chatbot-member-record__item">
                                        <dt>{{ $row['label'] }}</dt>
                                        <dd>{{ $row['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>

                        <section
                            class="lml-chatbot-member-record__section"
                            aria-labelledby="member-health-heading"
                        >
                            <h2 id="member-health-heading" class="lml-chatbot-member-record__section-title">
                                <i class="bi bi-heart-pulse-fill" aria-hidden="true"></i>
                                <span>Health &amp; Welfare</span>
                            </h2>
                            <dl class="lml-chatbot-member-record__dl lml-chatbot-member-record__dl--paired">
                                @foreach ($healthWelfare as $row)
                                    <div class="lml-chatbot-member-record__item">
                                        <dt>{{ $row['label'] }}</dt>
                                        <dd>{{ $row['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>
                    </article>
                </div>
            </main>
        </div>
    </div>
@endsection
