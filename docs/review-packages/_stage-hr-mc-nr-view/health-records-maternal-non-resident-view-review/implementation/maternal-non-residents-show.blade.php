{{--
    Health Records → Maternal → Non-Residents → individual client.
    Eligible non-resident females only. Does not use Household Profiling routes.
--}}
@extends('layouts.dashboard')

@section('title', ($client['full_name'] ?? 'Client').' — Maternal Care | Non Residents - LMLinga')

@section('content')
    @php
        $listingUrl = route('health-records.maternal.non-residents.index');
        $client = $client ?? [];
        $pregnancySummary = $pregnancySummary ?? null;
        $age = $client['age_years'] ?? null;
        $birthday = trim((string) ($client['birthday'] ?? ''));
        $birthdayLabel = $birthday !== ''
            ? \App\Support\HealthRecordsNonResidentMaternal::formatDisplayDate($birthday)
            : '';
        $status = trim((string) ($client['status'] ?? ''));
        $address = trim((string) ($client['complete_address'] ?? ''));
        $name = (string) ($client['full_name'] ?? 'Client');
    @endphp

    <div
        class="lml-hr-mc lml-hr-mc-show"
        data-lml-hr-mc
        data-lml-hr-mc-mode="non-resident-show"
        data-hr-mc-client-key="{{ $client['key'] ?? '' }}"
    >
        <div
            class="lml-hr-mc__toast"
            data-hr-mc-toast
            role="status"
            aria-live="polite"
            hidden
        ></div>

        <article class="lml-hr-mc-show__client" aria-labelledby="lml-hr-mc-show-name">
            <a
                href="{{ $listingUrl }}"
                class="lml-hr-mc-show__back lml-focus-ring"
                data-hr-mc-show-back
                aria-label="Back"
            >
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
            </a>

            <div class="lml-hr-mc-show__client-body">
                <span class="lml-hr-mc-show__avatar" aria-hidden="true">
                    <i class="bi bi-person-fill"></i>
                </span>
                <div class="lml-hr-mc-show__identity">
                    <h2 class="lml-hr-mc-show__name" id="lml-hr-mc-show-name">{{ $name }}</h2>
                    <span class="lml-hr-mc-show__sex">Female</span>
                    <dl class="lml-hr-mc-show__dl">
                        <div class="lml-hr-mc-show__item">
                            <dt>Age:</dt>
                            <dd>{{ $age }}</dd>
                        </div>
                        <div class="lml-hr-mc-show__item">
                            <dt>Date Birth:</dt>
                            <dd>{{ $birthdayLabel }}</dd>
                        </div>
                        <div class="lml-hr-mc-show__item">
                            <dt>Status:</dt>
                            <dd>{{ $status }}</dd>
                        </div>
                        <div class="lml-hr-mc-show__item lml-hr-mc-show__item--address">
                            <dt>Address:</dt>
                            <dd>{{ $address }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </article>

        <section class="lml-hr-mc-show__care" aria-labelledby="lml-hr-mc-show-care-title">
            <header class="lml-hr-mc-show__care-head">
                <h2 class="lml-hr-mc-show__care-title" id="lml-hr-mc-show-care-title">
                    <i class="bi bi-heart-pulse-fill" aria-hidden="true"></i>
                    <span>Maternal Care</span>
                </h2>
                <p class="lml-hr-mc-show__care-copy">
                    Record, monitor, and manage maternal healthcare throughout pregnancy and beyond.
                </p>
            </header>

            <div class="lml-hr-mc-show__history-bar">
                <h3 class="lml-hr-mc-show__history-title">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                    <span>Pregnancy History</span>
                </h3>
                <button
                    type="button"
                    class="lml-hr-mc-show__add-record lml-focus-ring"
                    data-hr-mc-nr-add-record
                    aria-label="Add pregnancy record. Not available yet for non-resident clients."
                >
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    <span>Add Record</span>
                </button>
            </div>

            @if ($pregnancySummary === null)
                <p class="lml-hr-mc-show__empty" data-hr-mc-show-empty>No pregnancy record yet.</p>
            @else
                <article
                    class="lml-hr-mc-show__pregnancy"
                    data-hr-mc-show-pregnancy
                    aria-labelledby="lml-hr-mc-show-pregnancy-title"
                >
                    <span class="lml-hr-mc-show__pregnancy-icon" aria-hidden="true">
                        <i class="bi bi-gender-female"></i>
                    </span>
                    <div class="lml-hr-mc-show__pregnancy-copy">
                        <h4 class="lml-hr-mc-show__pregnancy-title" id="lml-hr-mc-show-pregnancy-title">
                            Pregnancy {{ $pregnancySummary['number'] }}
                        </h4>
                        <p class="lml-hr-mc-show__pregnancy-meta">
                            {{ $pregnancySummary['gp_label'] }}
                            @if ($pregnancySummary['lmp_label'] !== '')
                                | LMP {{ $pregnancySummary['lmp_label'] }}
                            @endif
                        </p>
                    </div>
                    <div class="lml-hr-mc-show__pregnancy-aside">
                        <p class="lml-hr-mc-show__status">{{ $pregnancySummary['status_label'] }}</p>
                        @if ($pregnancySummary['delivery_type'] !== '')
                            <p class="lml-hr-mc-show__status-meta">{{ $pregnancySummary['delivery_type'] }}</p>
                        @endif
                    </div>
                    <span class="lml-hr-mc-show__chevron" aria-hidden="true">
                        <i class="bi bi-chevron-right"></i>
                    </span>
                </article>
            @endif
        </section>
    </div>
@endsection
