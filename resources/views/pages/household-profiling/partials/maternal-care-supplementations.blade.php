@php
    use App\Support\DemoMaternalCare;

    $canPersist = (bool) ($canPersist ?? false);
    $readOnly = (bool) ($readOnly ?? false);
    $updateUrl = route('household-profiling.members.maternal-care.update', $routeParams + [
        'section' => 'supplementations',
    ]);
    $schedule = DemoMaternalCare::supplementationSchedule();
    $supp = is_array($pregnancy['supplementations'] ?? null) ? $pregnancy['supplementations'] : [];
    $counts = DemoMaternalCare::supplementationCounts($pregnancy);
    $lmp = (string) ($pregnancy['lmp'] ?? '');
@endphp

<section class="lml-mc__panel" aria-labelledby="lml-mc-supp-title" data-mc-supplementations>
    <header class="lml-mc__panel-head">
        <div class="lml-mc__panel-titles">
            <h2 id="lml-mc-supp-title" class="lml-mc__panel-title">Supplementations</h2>
            <p class="lml-mc__panel-subtitle">
                Record all supplement intakes for the healthy pregnancy.
            </p>
        </div>
        <div class="lml-mc__panel-controls">
            @unless ($readOnly)
            <button
                type="button"
                class="lml-mc__btn lml-mc__btn--primary lml-focus-ring"
                data-mc-edit
                data-mc-edit-for="supplementations"
            >
                <i class="bi bi-pencil" aria-hidden="true"></i>
                <span>Edit</span>
            </button>
            <button
                type="submit"
                form="lml-mc-supp-form"
                class="lml-mc__btn lml-mc__btn--primary lml-focus-ring"
                data-mc-save
                data-mc-save-for="supplementations"
                hidden
            >
                Save
            </button>
            @endunless
        </div>
    </header>

    <form
        id="lml-mc-supp-form"
        method="post"
        action="{{ $updateUrl }}"
        class="lml-mc__form"
        data-mc-section-form="supplementations"
        data-editing="false"
        @if ($readOnly) data-mc-readonly="true" onsubmit="return false;" @endif
        novalidate
        @include('offline.maternal-section-attrs', ['section' => 'supplementations'])
    >
        @csrf
        @method('PUT')

        <div class="lml-mc__accordion-list" data-mc-accordion-group="supplementations">
            {{-- Deworming --}}
            @php
                $dewPanel = 'lml-mc-supp-deworming';
                $dewTrigger = $dewPanel.'-trigger';
                $dewDate = (string) ($supp['deworming_date'] ?? '');
            @endphp
            <div class="lml-mc__accordion" data-mc-accordion data-mc-supp="deworming">
                <h3 class="lml-mc__accordion-heading">
                    <button
                        type="button"
                        id="{{ $dewTrigger }}"
                        class="lml-mc__accordion-trigger lml-focus-ring"
                        aria-expanded="false"
                        aria-controls="{{ $dewPanel }}"
                        data-mc-accordion-trigger
                    >
                        <span class="lml-mc__accordion-title-wrap">
                            <span class="lml-mc__accordion-title">{{ $schedule['deworming']['label'] }}</span>
                            <span class="lml-mc__accordion-meta">1 Dose</span>
                        </span>
                        <span class="lml-mc__pill" data-mc-supp-count="deworming">
                            {{ $counts['deworming'] }} of 1 dose
                        </span>
                        <i class="bi bi-chevron-down lml-mc__accordion-chevron" aria-hidden="true"></i>
                    </button>
                </h3>
                <div
                    id="{{ $dewPanel }}"
                    class="lml-mc__accordion-panel"
                    role="region"
                    aria-labelledby="{{ $dewTrigger }}"
                    hidden
                    data-mc-accordion-panel
                >
                    <div class="lml-mc__field lml-mc__field--narrow">
                        <label for="mc-supp-deworming-date" class="lml-mc__label">Date</label>
                        <input
                            type="date"
                            id="mc-supp-deworming-date"
                            name="deworming_date"
                            class="lml-mc__input lml-focus-ring"
                            value="{{ $dewDate }}"
                            data-mc-field
                            max="{{ now()->toDateString() }}"
                            disabled
                        >
                    </div>
                </div>
            </div>

            {{-- IFA / MMS / Calcium --}}
            @foreach (['ifa' => 'Iron with Folic Acid Supplementation', 'mms' => 'Multiple Micronutrient Supplementation', 'calcium' => 'Calcium Carbonate Supplementation'] as $groupKey => $groupLabel)
                @php
                    $meta = $schedule[$groupKey];
                    $panelId = 'lml-mc-supp-'.$groupKey;
                    $triggerId = $panelId.'-trigger';
                    $rows = is_array($supp[$groupKey] ?? null) ? $supp[$groupKey] : [];
                    $max = (int) $meta['max'];
                    $count = (int) ($counts[$groupKey] ?? 0);
                    $highRisk = ! empty($meta['high_risk_only']);
                @endphp
                <div class="lml-mc__accordion" data-mc-accordion data-mc-supp="{{ $groupKey }}">
                    <h3 class="lml-mc__accordion-heading">
                        <button
                            type="button"
                            id="{{ $triggerId }}"
                            class="lml-mc__accordion-trigger lml-focus-ring"
                            aria-expanded="false"
                            aria-controls="{{ $panelId }}"
                            data-mc-accordion-trigger
                        >
                            <span class="lml-mc__accordion-title-wrap">
                                <span class="lml-mc__accordion-title">{{ $groupLabel }}</span>
                                @if ($highRisk)
                                    <span class="lml-mc__warning-text">For High Risk Only</span>
                                @endif
                            </span>
                            <span class="lml-mc__pill" data-mc-supp-count="{{ $groupKey }}">
                                {{ $count }} of {{ $max }} Visits
                            </span>
                            <i class="bi bi-chevron-down lml-mc__accordion-chevron" aria-hidden="true"></i>
                        </button>
                    </h3>
                    <div
                        id="{{ $panelId }}"
                        class="lml-mc__accordion-panel"
                        role="region"
                        aria-labelledby="{{ $triggerId }}"
                        hidden
                        data-mc-accordion-panel
                    >
                        @if ($highRisk)
                            <p class="lml-mc__warning-banner" role="note">
                                For High Risk Only — Calcium Carbonate supplementation is indicated for high-risk pregnancies.
                            </p>
                        @endif
                        <ul class="lml-mc__supp-visit-list">
                            @foreach ($meta['visits'] as $visit)
                                @php
                                    $row = is_array($rows[$visit['key']] ?? null) ? $rows[$visit['key']] : [];
                                    $slotTrimester = DemoMaternalCare::supplementationVisitTrimesterKey($groupKey, $visit['key']);
                                    $slotOpen = DemoMaternalCare::allowsNewSupplementationSlot(
                                        $lmp !== '' ? $lmp : null,
                                        $groupKey,
                                        $visit['key']
                                    );
                                    $visitHasData = DemoMaternalCare::supplementationSlotHasExistingData($row);
                                    $visitLocked = ! $readOnly && ! $slotOpen && ! $visitHasData;
                                @endphp
                                <li
                                    class="lml-mc__supp-visit{{ $visitLocked ? ' lml-mc__supp-visit--locked' : '' }}"
                                    data-mc-supp-visit="{{ $groupKey }}-{{ $visit['key'] }}"
                                    @if ($visitLocked) data-mc-visit-locked="true" @endif
                                >
                                    <p class="lml-mc__supp-visit-label">{{ $visit['label'] }}</p>
                                    @if ($visitLocked && $slotTrimester !== '')
                                        <p class="lml-mc__hint" role="status" data-mc-supp-unavailable="{{ $groupKey }}-{{ $visit['key'] }}">
                                            {{ DemoMaternalCare::futurePrenatalUnavailableMessage($slotTrimester) }}
                                        </p>
                                    @endif
                                    <div class="lml-mc__grid lml-mc__grid--2">
                                        <div class="lml-mc__field">
                                            <label
                                                class="lml-mc__label"
                                                for="mc-supp-{{ $groupKey }}-{{ $visit['key'] }}-date"
                                            >
                                                Date
                                            </label>
                                            <input
                                                type="date"
                                                id="mc-supp-{{ $groupKey }}-{{ $visit['key'] }}-date"
                                                name="{{ $groupKey }}[{{ $visit['key'] }}][date]"
                                                class="lml-mc__input lml-focus-ring"
                                                value="{{ $row['date'] ?? '' }}"
                                                data-mc-field
                                                max="{{ now()->toDateString() }}"
                                                disabled
                                            >
                                        </div>
                                        <div class="lml-mc__field">
                                            <label
                                                class="lml-mc__label"
                                                for="mc-supp-{{ $groupKey }}-{{ $visit['key'] }}-tablets"
                                            >
                                                No. of Tablets
                                            </label>
                                            <input
                                                type="number"
                                                id="mc-supp-{{ $groupKey }}-{{ $visit['key'] }}-tablets"
                                                name="{{ $groupKey }}[{{ $visit['key'] }}][tablets]"
                                                min="0"
                                                step="1"
                                                inputmode="numeric"
                                                class="lml-mc__input lml-focus-ring"
                                                value="{{ $row['tablets'] ?? '' }}"
                                                data-mc-field
                                                disabled
                                            >
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- RUSF --}}
        @php
            $rusfLabel = (string) (($schedule['rusf']['label'] ?? 'Ready-to-Use Supplementary Food (RUSF)'));
            $rusfRows = [];
            foreach (is_array($supp['rusf'] ?? null) ? $supp['rusf'] : [] as $rusfItem) {
                if (is_array($rusfItem)) {
                    $rusfRows[] = $rusfItem;
                }
            }
            $rusfCount = (int) ($counts['rusf'] ?? 0);
            $rusfPanel = 'lml-mc-supp-rusf';
            $rusfTrigger = $rusfPanel.'-trigger';
            $rusfEmptyIndex = count($rusfRows);
        @endphp
        <div class="lml-mc__accordion" data-mc-accordion data-mc-supp="rusf">
            <h3 class="lml-mc__accordion-heading">
                <button
                    type="button"
                    id="{{ $rusfTrigger }}"
                    class="lml-mc__accordion-trigger lml-focus-ring"
                    aria-expanded="false"
                    aria-controls="{{ $rusfPanel }}"
                    data-mc-accordion-trigger
                >
                    <span class="lml-mc__accordion-title-wrap">
                        <span class="lml-mc__accordion-title">{{ $rusfLabel }}</span>
                    </span>
                    <span class="lml-mc__pill" data-mc-supp-count="rusf">
                        {{ $rusfCount }} given
                    </span>
                    <i class="bi bi-chevron-down lml-mc__accordion-chevron" aria-hidden="true"></i>
                </button>
            </h3>
            <div
                id="{{ $rusfPanel }}"
                class="lml-mc__accordion-panel"
                role="region"
                aria-labelledby="{{ $rusfTrigger }}"
                hidden
                data-mc-accordion-panel
            >
                <ul class="lml-mc__supp-visit-list">
                    @foreach ($rusfRows as $rusfIndex => $rusfRow)
                        <li class="lml-mc__supp-visit" data-mc-supp-visit="rusf-{{ $rusfIndex }}">
                            @if (trim((string) ($rusfRow['id'] ?? '')) !== '')
                                <input
                                    type="hidden"
                                    name="rusf[{{ $rusfIndex }}][id]"
                                    value="{{ (int) $rusfRow['id'] }}"
                                    data-mc-field
                                    disabled
                                >
                            @endif
                            <div class="lml-mc__field lml-mc__field--narrow">
                                <label
                                    class="lml-mc__label"
                                    for="mc-supp-rusf-{{ $rusfIndex }}-date"
                                >
                                    Date given
                                </label>
                                <input
                                    type="date"
                                    id="mc-supp-rusf-{{ $rusfIndex }}-date"
                                    name="rusf[{{ $rusfIndex }}][date]"
                                    class="lml-mc__input lml-focus-ring"
                                    value="{{ $rusfRow['date'] ?? '' }}"
                                    data-mc-field
                                    max="{{ now()->toDateString() }}"
                                    disabled
                                >
                            </div>
                        </li>
                    @endforeach
                    <li class="lml-mc__supp-visit" data-mc-supp-visit="rusf-new">
                        <div class="lml-mc__field lml-mc__field--narrow">
                            <label
                                class="lml-mc__label"
                                for="mc-supp-rusf-{{ $rusfEmptyIndex }}-date"
                            >
                                Date given
                            </label>
                            <input
                                type="date"
                                id="mc-supp-rusf-{{ $rusfEmptyIndex }}-date"
                                name="rusf[{{ $rusfEmptyIndex }}][date]"
                                class="lml-mc__input lml-focus-ring"
                                value=""
                                data-mc-field
                                max="{{ now()->toDateString() }}"
                                disabled
                            >
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </form>
</section>
