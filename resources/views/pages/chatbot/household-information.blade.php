@extends('layouts.app')

@section('title', 'Household Member Information - LMLinga')

@section('body')
    <div class="lml-chatbot-household-info" data-lml-household-info>
        <div
            class="lml-chatbot-household-info__overlay"
            data-lml-household-info-overlay
            hidden
        ></div>

        <div class="lml-chatbot-household-info__shell">
            <aside
                id="household-info-sidebar"
                class="lml-chatbot-household-info__sidebar"
                data-lml-household-info-sidebar
                aria-label="Verified resident navigation"
            >
                <div class="lml-chatbot-household-info__sidebar-top">
                    <div class="lml-chatbot-household-info__profile">
                        <span class="lml-chatbot-household-info__avatar" aria-hidden="true">
                            <i class="bi bi-person-fill"></i>
                        </span>
                        <div class="lml-chatbot-household-info__profile-text">
                            <p class="lml-chatbot-household-info__resident-name">{{ $residentDisplayName }}</p>
                            <p class="lml-chatbot-household-info__household-number">
                                <i class="bi bi-house-door" aria-hidden="true"></i>
                                <span>{{ $householdDisplayNo }}</span>
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="lml-chatbot-household-info__sidebar-toggle lml-focus-ring"
                        data-lml-household-info-sidebar-toggle
                        aria-controls="household-info-sidebar"
                        aria-expanded="true"
                        aria-label="Collapse sidebar"
                        title="Collapse sidebar"
                    >
                        <i class="bi bi-chevron-bar-left" aria-hidden="true"></i>
                    </button>
                </div>

                <a
                    href="{{ route('chatbot.household.information') }}"
                    class="lml-chatbot-household-info__access lml-focus-ring"
                    aria-current="page"
                    data-lml-sidebar-tab="household"
                >
                    <span class="lml-chatbot-household-info__access-compact" aria-hidden="true">HH</span>
                    <span class="lml-chatbot-household-info__sidebar-label">Access Household Record</span>
                    <i class="bi bi-patch-check-fill" aria-hidden="true"></i>
                    <span class="visually-hidden">Verified</span>
                </a>

                <section class="lml-chatbot-household-info__summary" aria-labelledby="quick-summary-heading">
                    <p id="quick-summary-heading" class="lml-chatbot-household-info__summary-title">
                        Quick Summary
                    </p>
                    <dl class="lml-chatbot-household-info__summary-list">
                        <div>
                            <dt>Adults</dt>
                            <dd>{{ $summaryAdults }}</dd>
                        </div>
                        <div>
                            <dt>Youth</dt>
                            <dd>{{ $summaryYouth }}</dd>
                        </div>
                        <div>
                            <dt>Children</dt>
                            <dd>{{ $summaryChildren }}</dd>
                        </div>
                    </dl>
                </section>

                <div class="lml-chatbot-household-info__sidebar-footer">
                    <form method="post" action="{{ route('chatbot.logout') }}">
                        @csrf
                        <button
                            type="submit"
                            class="lml-chatbot-household-info__logout lml-focus-ring"
                        >
                            <i class="bi bi-box-arrow-left" aria-hidden="true"></i>
                            <span class="lml-chatbot-household-info__sidebar-label">Logout</span>
                        </button>
                    </form>
                </div>
            </aside>

            <main class="lml-chatbot-household-info__main" id="main-content">
                <div class="lml-chatbot-household-info__mobile-bar">
                    <button
                        type="button"
                        class="lml-chatbot-household-info__mobile-toggle lml-focus-ring"
                        data-lml-household-info-mobile-toggle
                        aria-controls="household-info-sidebar"
                        aria-expanded="false"
                        aria-label="Open sidebar"
                    >
                        <i class="bi bi-list" aria-hidden="true"></i>
                    </button>
                    <span>Household Record</span>
                </div>

                <header class="lml-chatbot-household-info__header">
                    <div class="lml-chatbot-household-info__heading">
                        <a
                            href="{{ route('chatbot.main') }}"
                            class="lml-chatbot-household-info__back lml-focus-ring"
                            aria-label="Back to verified resident chatbot"
                        >
                            <i class="bi bi-arrow-left" aria-hidden="true"></i>
                        </a>
                        <div>
                            <h1>Household Member Information</h1>
                            <p>Track and Stay Informed on Household Health</p>
                        </div>
                    </div>

                    <div
                        class="lml-chatbot-household-info__total"
                        aria-label="{{ count($members) }} total household members"
                    >
                        <strong>{{ count($members) }}</strong>
                        <span>Total<br>Members</span>
                    </div>
                </header>

                <section class="lml-chatbot-household-info__members" aria-label="Household members">
                    @foreach ($members as $member)
                        <article
                            id="{{ $member['id'] }}"
                            class="lml-chatbot-household-info__member-card{{ $member['relationship'] === 'Head of Household' ? ' lml-chatbot-household-info__member-card--head' : '' }}"
                        >
                            @if ($member['relationship'] === 'Head of Household')
                                <div class="lml-chatbot-household-info__head-ribbon">
                                    <i class="bi bi-star-fill" aria-hidden="true"></i>
                                    <span>Head of Household</span>
                                </div>
                            @endif

                            <header class="lml-chatbot-household-info__member-header">
                                <span class="lml-chatbot-household-info__member-avatar" aria-hidden="true">
                                    <i class="bi bi-person-fill"></i>
                                </span>
                                <div class="lml-chatbot-household-info__member-identity">
                                    <h2>{{ $member['name'] }}</h2>
                                    <p>{{ $member['age'] }}</p>
                                </div>
                                <span
                                    class="lml-chatbot-household-info__sex-badge lml-chatbot-household-info__sex-badge--{{ strtolower(str_replace(' ', '', $member['sex'])) }}"
                                >
                                    {{ $member['sex'] }}
                                </span>
                            </header>

                            <section
                                class="lml-chatbot-household-info__personal"
                                aria-labelledby="{{ $member['id'] }}-personal-heading"
                            >
                                <h3 id="{{ $member['id'] }}-personal-heading">Personal Information</h3>
                                <dl class="lml-chatbot-household-info__details-list">
                                    <div class="lml-chatbot-household-info__detail-row">
                                        <dt>Relationship to Head</dt>
                                        <dd>{{ $member['relationship'] }}</dd>
                                    </div>
                                    <div class="lml-chatbot-household-info__detail-row">
                                        <dt>Birthday</dt>
                                        <dd>{{ $member['birthday'] }}</dd>
                                    </div>
                                    <div class="lml-chatbot-household-info__detail-row">
                                        <dt>Civil Status</dt>
                                        <dd>{{ $member['civilStatus'] }}</dd>
                                    </div>
                                    <div class="lml-chatbot-household-info__detail-row">
                                        <dt>Occupation</dt>
                                        <dd>{{ $member['occupation'] }}</dd>
                                    </div>
                                </dl>
                            </section>

                            <ul class="lml-chatbot-household-info__health" aria-label="Health summary">
                                <li>
                                    <i class="bi bi-speedometer2" aria-hidden="true"></i>
                                    <span>
                                        <span class="lml-chatbot-household-info__health-label">Weight</span>
                                        <strong>{{ $member['weight'] }}</strong>
                                    </span>
                                </li>
                                <li>
                                    <i class="bi bi-rulers" aria-hidden="true"></i>
                                    <span>
                                        <span class="lml-chatbot-household-info__health-label">Height</span>
                                        <strong>{{ $member['height'] }}</strong>
                                    </span>
                                </li>
                                <li>
                                    <i class="bi bi-heart-pulse" aria-hidden="true"></i>
                                    <span>
                                        <span class="lml-chatbot-household-info__health-label">
                                            Nutritional Status
                                        </span>
                                        <strong>{{ $member['nutrition'] }}</strong>
                                    </span>
                                </li>
                            </ul>

                            <footer class="lml-chatbot-household-info__member-footer">
                                <a
                                    href="{{ route('chatbot.household.members.show', ['member' => $member['residentId']]) }}"
                                    class="lml-chatbot-household-info__view-record lml-focus-ring"
                                    aria-label="View record for {{ $member['name'] }}"
                                >
                                    <span>View Record</span>
                                    <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
                                </a>
                            </footer>
                        </article>
                    @endforeach
                </section>
            </main>
        </div>
    </div>
@endsection
