@php
    $canPersist = (bool) ($canPersist ?? false);
    $readOnly = (bool) ($readOnly ?? false);
    $updateUrl = route('household-profiling.members.maternal-care.update', $routeParams + [
        'section' => 'trans-out',
    ]);
    $trans = is_array($pregnancy['trans_out'] ?? null) ? $pregnancy['trans_out'] : [];
@endphp

<section class="lml-mc__panel" aria-labelledby="lml-mc-transout-title" data-mc-trans-out>
    <header class="lml-mc__panel-head">
        <div class="lml-mc__panel-titles">
            <h2 id="lml-mc-transout-title" class="lml-mc__panel-title">Trans-Out</h2>
            <p class="lml-mc__panel-subtitle">
                {{ $readOnly ? 'Transfer-out details for this pregnancy.' : 'Manually transfer out her profile.' }}
            </p>
        </div>
    </header>

    <form
        method="post"
        action="{{ $updateUrl }}"
        class="lml-mc__form"
        data-mc-section-form="trans-out"
        data-editing="{{ $readOnly ? 'false' : 'true' }}"
        @if ($readOnly) data-mc-readonly="true" onsubmit="return false;" @endif
        novalidate
        @include('offline.maternal-section-attrs', ['section' => 'trans-out'])
    >
        @csrf
        @method('PUT')

        <div class="lml-mc__grid lml-mc__grid--2">
            <div class="lml-mc__field">
                <label for="lml-mc-to-facility" class="lml-mc__label">To Facility</label>
                <input
                    type="text"
                    id="lml-mc-to-facility"
                    name="to_facility"
                    class="lml-mc__input lml-focus-ring"
                    value="{{ $trans['to_facility'] ?? '' }}"
                    autocomplete="organization"
                    @disabled($readOnly)
                >
            </div>
            <div class="lml-mc__field">
                <label for="lml-mc-occurred-stage" class="lml-mc__label">Occurred at Stage</label>
                <select
                    id="lml-mc-occurred-stage"
                    name="occurred_at_stage"
                    class="lml-mc__input lml-focus-ring"
                    @disabled($readOnly)
                >
                    <option value="">Select stage</option>
                    @foreach ([
                        'Prenatal',
                        'Delivery',
                        'Postnatal',
                        'Active - completing prenatal visits',
                    ] as $stage)
                        <option
                            value="{{ $stage }}"
                            @selected(($trans['occurred_at_stage'] ?? '') === $stage)
                        >
                            {{ $stage }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="lml-mc__field">
                <label for="lml-mc-trans-reason" class="lml-mc__label">Reason</label>
                <select
                    id="lml-mc-trans-reason"
                    name="reason"
                    class="lml-mc__input lml-focus-ring"
                    @disabled($readOnly)
                >
                    <option value="">Select reason</option>
                    @foreach ([
                        'Non-resident/Moved',
                        'Referral',
                        'Facility transfer',
                        'Other',
                    ] as $reason)
                        <option
                            value="{{ $reason }}"
                            @selected(($trans['reason'] ?? '') === $reason)
                        >
                            {{ $reason }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="lml-mc__field">
                <label for="lml-mc-trans-date" class="lml-mc__label">Date Transferred Out</label>
                <input
                    type="date"
                    id="lml-mc-trans-date"
                    name="date_transferred_out"
                    class="lml-mc__input lml-focus-ring"
                    value="{{ $trans['date_transferred_out'] ?? '' }}"
                    max="{{ now()->toDateString() }}"
                    @disabled($readOnly)
                >
            </div>
        </div>

        @unless ($readOnly)
        <div class="lml-mc__form-actions">
            <a
                href="{{ route('household-profiling.members.maternal-care.index', $routeParams) }}"
                class="lml-mc__btn lml-mc__btn--ghost lml-focus-ring"
            >
                Cancel
            </a>
            <button type="submit" class="lml-mc__btn lml-mc__btn--primary lml-focus-ring" data-mc-save>
                Save
            </button>
        </div>
        @endunless
    </form>
</section>
