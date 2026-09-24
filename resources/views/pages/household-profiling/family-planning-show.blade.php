{{--
    Household Profiling — Family Planning Record View.
    DB-13: DB-backed for persisted residents; demo catalog fallback otherwise.
--}}
@extends('layouts.dashboard')

@section('title', ($demoMember['name'] ?? 'Member') . ' — Family Planning Visit - LMLinga')

@section('content')
    @php
        use App\Support\DemoFamilyPlanning;
        use App\Support\FamilyPlanningErdMode;

        $visit = $visit ?? [];
        $commoditiesSupported = (bool) ($commoditiesSupported ?? true);
        $historyUrl = route('household-profiling.members.family-planning.index', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);
        $editUrl = ! empty($visit['id'])
            ? route('household-profiling.members.family-planning.edit', [
                'householdNo' => $householdNo,
                'memberId' => $memberId,
                'visitId' => $visit['id'],
            ])
            : null;
        $stats = DemoFamilyPlanning::summaryStats($visitsForStats ?? []);
        $visitCommodities = is_array($visit['commodities'] ?? null) ? $visit['commodities'] : [];
        if ($visitCommodities === []) {
            $visitCommodities = [['name' => '—', 'quantity' => '—']];
        }
    @endphp

    <div
        class="lml-fp"
        data-lml-fp
        data-lml-fp-mode="view"
        data-demo="{{ ($canPersist ?? false) ? 'false' : 'true' }}"
        data-household-no="{{ $householdNo }}"
        data-member-id="{{ $memberId }}"
        @if ($demoMember)
            data-member-name="{{ $demoMember['name'] }}"
        @endif
        @if (! empty($visit['id']))
            data-visit-id="{{ $visit['id'] }}"
        @endif
    >
        @if (! $demoHousehold || ! $demoMember)
            <section class="lml-fp__not-found" aria-labelledby="lml-fp-nf-title">
                <h2 id="lml-fp-nf-title" class="lml-fp__not-found-title">
                    Member not found
                </h2>
                <p class="lml-fp__not-found-message">
                    @if (! $demoHousehold)
                        No record found.
                    @else
                        No record found.
                    @endif
                </p>
                <a
                    href="{{ $demoHousehold ? route('household-profiling.view', ['householdNo' => $householdNo]) : route('household-profiling.index') }}"
                    class="lml-fp__not-found-link lml-focus-ring"
                >
                    {{ $demoHousehold ? 'Back to Household' : 'Return to Household List' }}
                </a>
            </section>
        @elseif (empty($visit))
            <section class="lml-fp__not-found" aria-labelledby="lml-fp-nf-title">
                <h2 id="lml-fp-nf-title" class="lml-fp__not-found-title">
                    Visit not found
                </h2>
                <p class="lml-fp__not-found-message">
                    No family planning visit <strong>{{ $visitId ?? '' }}</strong> exists for
                    <strong>{{ $memberId }}</strong> in household <strong>{{ $householdNo }}</strong>.
                </p>
                <a href="{{ $historyUrl }}" class="lml-fp__not-found-link lml-focus-ring">
                    Back to Family Planning Visit Records
                </a>
            </section>
        @else
            @include('pages.household-profiling.partials.family-planning-member-card', [
                'demoMember' => $demoMember,
                'householdNo' => $householdNo,
                'memberId' => $memberId,
                'backUrl' => $historyUrl,
                'backLabel' => 'Back to Family Planning Visit Records for '.$demoMember['name'],
                'totalVisits' => $stats['total_visits'],
                'lastVisitLabel' => $stats['last_visit_label'],
                'lastVisitIso' => $stats['last_visit'],
            ])

            <section
                class="lml-fp__panel"
                aria-labelledby="lml-fp-view-title"
                data-fp-view-panel
            >
                <header class="lml-fp__form-head">
                    <h2 id="lml-fp-view-title" class="lml-fp__panel-title lml-fp__panel-title--form">
                        VIEW FAMILY PLANNING RECORD
                    </h2>
                    @if ($editUrl)
                        <a
                            href="{{ $editUrl }}"
                            class="lml-fp__btn lml-fp__btn--save lml-focus-ring"
                            data-fp-edit
                            aria-label="Edit family planning visit for {{ $demoMember['name'] }}"
                        >
                            Edit
                        </a>
                    @endif
                </header>

                <div class="lml-fp__form-grid">
                    <fieldset class="lml-fp__fieldset" disabled>
                        <legend class="lml-fp__fieldset-legend">Visit Information</legend>

                        <div class="lml-fp__field">
                            <label for="lml-fp-view-date">Date</label>
                            <div class="lml-fp__input-with-icon">
                                <i class="bi bi-calendar3" aria-hidden="true"></i>
                                <input
                                    id="lml-fp-view-date"
                                    type="text"
                                    class="lml-fp__input"
                                    value="{{ DemoFamilyPlanning::formatVisitDate((string) $visit['visited_at']) }}"
                                    readonly
                                >
                            </div>
                        </div>

                        <div class="lml-fp__field">
                            <label for="lml-fp-view-remarks">Remarks</label>
                            <textarea
                                id="lml-fp-view-remarks"
                                class="lml-fp__textarea"
                                rows="5"
                                readonly
                            >{{ $visit['remarks'] !== '' ? $visit['remarks'] : '—' }}</textarea>
                        </div>
                    </fieldset>

                    @if ($commoditiesSupported)
                    <fieldset class="lml-fp__fieldset" disabled>
                        <legend class="lml-fp__fieldset-legend">Commodities Given</legend>

                        <div class="lml-fp__commodity-list">
                            @foreach ($visitCommodities as $index => $commodity)
                                <div class="lml-fp__commodity-row">
                                    <div class="lml-fp__field">
                                        <label for="lml-fp-view-commodity-{{ $index }}">Commodity</label>
                                        <input
                                            id="lml-fp-view-commodity-{{ $index }}"
                                            type="text"
                                            class="lml-fp__input"
                                            value="{{ $commodity['name'] !== '' ? $commodity['name'] : '—' }}"
                                            readonly
                                        >
                                    </div>
                                    <div class="lml-fp__field lml-fp__field--qty">
                                        <label for="lml-fp-view-qty-{{ $index }}">Quantity</label>
                                        <input
                                            id="lml-fp-view-qty-{{ $index }}"
                                            type="text"
                                            class="lml-fp__input"
                                            value="{{ $commodity['quantity'] }}"
                                            readonly
                                        >
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </fieldset>
                    @else
                    <div class="lml-fp__info-notice" role="note">
                        <p>{{ FamilyPlanningErdMode::COMMODITIES_UI_MESSAGE }}</p>
                    </div>
                    @endif
                </div>
            </section>
        @endif
    </div>
@endsection
