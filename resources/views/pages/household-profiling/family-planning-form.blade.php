{{--
    Household Profiling — Family Planning Add / Edit Record.
    DB-13: persists for DB-backed residents; demo preview otherwise.
--}}
@extends('layouts.dashboard')

@section('title', ($demoMember['name'] ?? 'Member') . ' — Family Planning - LMLinga')

@section('content')
    @php
        use App\Support\DemoFamilyPlanning;
        use App\Support\FamilyPlanningErdMode;

        $mode = $mode ?? 'create'; // create | edit
        $isEdit = $mode === 'edit';
        $visit = $visit ?? [];
        $canPersist = (bool) ($canPersist ?? false);
        $commoditiesSupported = (bool) ($commoditiesSupported ?? true);
        $commodityOptions = DemoFamilyPlanning::commodityOptions();
        $oldCommodities = old('commodities');
        if (is_array($oldCommodities) && count($oldCommodities) > 0) {
            $visitCommodities = $oldCommodities;
        } else {
            $visitCommodities = is_array($visit['commodities'] ?? null) && count($visit['commodities']) > 0
                ? $visit['commodities']
                : [['name' => '', 'quantity' => '']];
        }

        $historyUrl = route('household-profiling.members.family-planning.index', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);
        $cancelUrl = $isEdit && ! empty($visit['id'])
            ? route('household-profiling.members.family-planning.show', [
                'householdNo' => $householdNo,
                'memberId' => $memberId,
                'visitId' => $visit['id'],
            ])
            : $historyUrl;
        $storeUrl = route('household-profiling.members.family-planning.store', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);
        $updateUrl = $isEdit && ! empty($visit['id'])
            ? route('household-profiling.members.family-planning.update', [
                'householdNo' => $householdNo,
                'memberId' => $memberId,
                'visitId' => $visit['id'],
            ])
            : null;
        $heading = $isEdit ? 'EDIT FAMILY PLANNING RECORD' : 'ADD RECORD';
        $visitedAtValue = (string) old('visited_at', $visit['visited_at'] ?? '');
        $remarksValue = (string) old('remarks', $visit['remarks'] ?? '');
        $stats = DemoFamilyPlanning::summaryStats($visitsForStats ?? []);
    @endphp

    <div
        class="lml-fp"
        data-lml-fp
        data-lml-fp-mode="{{ $mode }}"
        data-demo="{{ $canPersist ? 'false' : 'true' }}"
        data-household-no="{{ $householdNo }}"
        data-member-id="{{ $memberId }}"
        @if ($demoMember)
            data-member-name="{{ $demoMember['name'] }}"
        @endif
        @if ($isEdit && ! empty($visit['id']))
            data-visit-id="{{ $visit['id'] }}"
        @endif
    >
        <div
            class="lml-fp__toast"
            data-fp-toast
            role="status"
            aria-live="polite"
            hidden
        ></div>

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
        @elseif ($isEdit && empty($visit))
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
                aria-labelledby="lml-fp-form-title"
                data-fp-form-panel
            >
                <header class="lml-fp__form-head">
                    <h2 id="lml-fp-form-title" class="lml-fp__panel-title lml-fp__panel-title--form">
                        {{ $heading }}
                    </h2>
                    @if ($isEdit)
                        <button
                            type="submit"
                            form="lml-fp-visit-form"
                            class="lml-fp__btn lml-fp__btn--save lml-focus-ring"
                            data-fp-save-top
                        >
                            Save
                        </button>
                    @endif
                </header>

                <form
                    id="lml-fp-visit-form"
                    class="lml-fp__form"
                    data-fp-form
                    @if ($canPersist)
                        method="post"
                        @if ($isEdit && $updateUrl)
                            action="{{ $updateUrl }}"
                        @else
                            action="{{ $storeUrl }}"
                        @endif
                        @include('offline.form-attrs', [
                            'offlineOperation' => 'HEALTH_SERVICE_WRITE',
                            'offlineHealthAction' => $isEdit ? 'family_planning_update' : 'family_planning_store',
                            'offlineHouseholdNo' => $householdNo,
                            'offlineMemberNo' => $memberId,
                            'offlineHealthVisitId' => $isEdit ? ($visit['id'] ?? null) : null,
                        ])
                    @endif
                    novalidate
                >
                    @if ($canPersist)
                        @csrf
                        @if ($isEdit)
                            @method('PUT')
                        @endif
                    @endif

                    @if ($errors->any())
                        <div class="lml-fp__form-errors" role="alert">
                            <p>{{ $errors->first() }}</p>
                        </div>
                    @endif

                    @if (session('status'))
                        <div class="lml-fp__form-status" role="status">
                            <p>{{ session('status') }}</p>
                        </div>
                    @endif

                    <div class="lml-fp__form-grid">
                        <fieldset class="lml-fp__fieldset">
                            <legend class="lml-fp__fieldset-legend">Visit Information</legend>

                            <div class="lml-fp__field">
                                <label for="lml-fp-visit-date">Date</label>
                                <div class="lml-fp__input-with-icon">
                                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                                    <input
                                        id="lml-fp-visit-date"
                                        type="date"
                                        name="visited_at"
                                        class="lml-fp__input lml-focus-ring"
                                        value="{{ $visitedAtValue }}"
                                        max="{{ now()->toDateString() }}"
                                        autocomplete="off"
                                    >
                                </div>
                            </div>

                            <div class="lml-fp__field">
                                <label for="lml-fp-remarks">Remarks</label>
                                <textarea
                                    id="lml-fp-remarks"
                                    name="remarks"
                                    class="lml-fp__textarea lml-focus-ring"
                                    rows="5"
                                >{{ $remarksValue }}</textarea>
                            </div>
                        </fieldset>

                        @if ($commoditiesSupported)
                        <fieldset class="lml-fp__fieldset" data-fp-commodities>
                            <legend class="lml-fp__fieldset-legend">Commodities Given</legend>

                            <div class="lml-fp__commodity-list" data-fp-commodity-list>
                                @foreach ($visitCommodities as $index => $commodity)
                                    <div class="lml-fp__commodity-row" data-fp-commodity-row>
                                        <div class="lml-fp__field">
                                            <label for="lml-fp-commodity-{{ $index }}">Commodity</label>
                                            <select
                                                id="lml-fp-commodity-{{ $index }}"
                                                name="commodities[{{ $index }}][name]"
                                                class="lml-fp__input lml-focus-ring"
                                                data-fp-commodity-name
                                            >
                                                <option value="">Select commodity</option>
                                                @foreach ($commodityOptions as $option)
                                                    <option
                                                        value="{{ $option }}"
                                                        @selected(($commodity['name'] ?? '') === $option)
                                                    >
                                                        {{ $option }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="lml-fp__field lml-fp__field--qty">
                                            <label for="lml-fp-qty-{{ $index }}">Quantity</label>
                                            <input
                                                id="lml-fp-qty-{{ $index }}"
                                                type="number"
                                                name="commodities[{{ $index }}][quantity]"
                                                class="lml-fp__input lml-focus-ring"
                                                min="0"
                                                step="1"
                                                value="{{ $commodity['quantity'] !== '' && $commodity['quantity'] !== null ? $commodity['quantity'] : '' }}"
                                                data-fp-commodity-qty
                                                inputmode="numeric"
                                            >
                                        </div>
                                        <button
                                            type="button"
                                            class="lml-fp__commodity-remove lml-focus-ring"
                                            data-fp-commodity-remove
                                            aria-label="Remove commodity row"
                                            @if ($index === 0 && count($visitCommodities) === 1) hidden @endif
                                        >
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                @endforeach
                            </div>

                            <button
                                type="button"
                                class="lml-fp__add-commodity lml-focus-ring"
                                data-fp-commodity-add
                            >
                                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                <span>Add Another Commodity</span>
                            </button>
                        </fieldset>
                        @else
                        <div class="lml-fp__info-notice" role="note">
                            <p>{{ FamilyPlanningErdMode::COMMODITIES_UI_MESSAGE }}</p>
                        </div>
                        @endif
                    </div>

                    <div class="lml-fp__actions">
                        <a
                            href="{{ $cancelUrl }}"
                            class="lml-fp__btn lml-fp__btn--cancel lml-focus-ring"
                            data-fp-cancel
                        >
                            Cancel
                        </a>
                        <button
                            type="submit"
                            class="lml-fp__btn lml-fp__btn--save lml-focus-ring"
                            data-fp-save
                        >
                            Save
                        </button>
                    </div>
                </form>

                @if ($commoditiesSupported)
                <template data-fp-commodity-template>
                    <div class="lml-fp__commodity-row" data-fp-commodity-row>
                        <div class="lml-fp__field">
                            <label>Commodity</label>
                            <select
                                name="commodities[__INDEX__][name]"
                                class="lml-fp__input lml-focus-ring"
                                data-fp-commodity-name
                            >
                                <option value="">Select commodity</option>
                                @foreach ($commodityOptions as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="lml-fp__field lml-fp__field--qty">
                            <label>Quantity</label>
                            <input
                                type="number"
                                name="commodities[__INDEX__][quantity]"
                                class="lml-fp__input lml-focus-ring"
                                min="0"
                                step="1"
                                value=""
                                data-fp-commodity-qty
                                inputmode="numeric"
                            >
                        </div>
                        <button
                            type="button"
                            class="lml-fp__commodity-remove lml-focus-ring"
                            data-fp-commodity-remove
                            aria-label="Remove commodity row"
                        >
                            <i class="bi bi-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                </template>
                @endif
            </section>
        @endif
    </div>
@endsection
