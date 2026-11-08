@php
    use App\Support\DemoMaternalCare;

    $canPersist = (bool) ($canPersist ?? false);
    $readOnly = (bool) ($readOnly ?? false);
    $updateUrl = route('household-profiling.members.maternal-care.update', $routeParams + [
        'section' => 'prenatal',
    ]);
    $schedule = DemoMaternalCare::prenatalSchedule();
    $prenatal = is_array($pregnancy['prenatal'] ?? null) ? $pregnancy['prenatal'] : [];
    $lmp = (string) ($pregnancy['lmp'] ?? '');
    $totalVisits = 8;
    $recorded = DemoMaternalCare::prenatalVisitCount($pregnancy);
@endphp

<section class="lml-mc__panel" aria-labelledby="lml-mc-prenatal-title" data-mc-prenatal>
    <header class="lml-mc__panel-head">
        <div class="lml-mc__panel-titles">
            <h2 id="lml-mc-prenatal-title" class="lml-mc__panel-title">Prenatal Visits</h2>
            <p class="lml-mc__panel-subtitle">
                Records of prenatal visits at various stages of pregnancy.
            </p>
        </div>
        <div class="lml-mc__panel-controls">
            @unless ($readOnly)
            <p class="lml-mc__count" data-mc-prenatal-count aria-live="polite">
                {{ $recorded }} of {{ $totalVisits }} Visits
            </p>
            <button
                type="button"
                class="lml-mc__btn lml-mc__btn--primary lml-focus-ring"
                data-mc-edit
                data-mc-edit-for="prenatal"
            >
                <i class="bi bi-pencil" aria-hidden="true"></i>
                <span>Edit</span>
            </button>
            <button
                type="submit"
                form="lml-mc-prenatal-form"
                class="lml-mc__btn lml-mc__btn--primary lml-focus-ring"
                data-mc-save
                data-mc-save-for="prenatal"
                hidden
            >
                Save
            </button>
            @else
            <p class="lml-mc__count" data-mc-prenatal-count>
                {{ $recorded }} of {{ $totalVisits }} Visits
            </p>
            @endunless
        </div>
    </header>

    <form
        id="lml-mc-prenatal-form"
        method="post"
        action="{{ $updateUrl }}"
        class="lml-mc__form"
        data-mc-section-form="prenatal"
        data-editing="false"
        @if ($readOnly) data-mc-readonly="true" onsubmit="return false;" @endif
        novalidate
        @if ($canPersist && ! $readOnly)
            @include('offline.form-attrs', [
                'offlineOperation' => 'HEALTH_SERVICE_WRITE',
                'offlineHealthAction' => 'maternal_section_update',
                'offlineHealthSection' => 'prenatal',
                'offlineHouseholdNo' => $householdNo ?? ($routeParams['householdNo'] ?? null),
                'offlineMemberNo' => $memberId ?? ($routeParams['memberId'] ?? null),
            ])
        @endif
    >
        @csrf
        @method('PUT')

        <div class="lml-mc__accordion-list" data-mc-accordion-group="prenatal">
            @foreach ($schedule as $trimesterKey => $trimester)
                @php
                    $panelId = 'lml-mc-prenatal-'.$trimesterKey;
                    $triggerId = $panelId.'-trigger';
                    $trimesterRecorded = 0;
                    foreach ($trimester['visits'] as $visit) {
                        $row = is_array($prenatal[$visit['key']] ?? null) ? $prenatal[$visit['key']] : [];
                        if (trim((string) ($row['date'] ?? '')) !== '') {
                            $trimesterRecorded++;
                        }
                    }
                    $trimesterTotal = count($trimester['visits']);
                    $trimesterOpen = DemoMaternalCare::allowsNewPrenatalSlot(
                        $lmp !== '' ? $lmp : null,
                        $trimester['visits'][0]['key'] ?? ''
                    );
                @endphp
                <div
                    class="lml-mc__accordion{{ $trimesterOpen ? '' : ' lml-mc__accordion--future' }}"
                    data-mc-accordion
                    data-mc-trimester="{{ $trimesterKey }}"
                    @unless ($trimesterOpen) data-mc-trimester-locked="true" @endunless
                >
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
                                <span class="lml-mc__accordion-title">{{ $trimester['label'] }}</span>
                                <span class="lml-mc__accordion-meta">{{ $trimester['weeks'] }}</span>
                            </span>
                            <span class="lml-mc__pill" data-mc-trimester-count="{{ $trimesterKey }}">
                                {{ $trimesterRecorded }} of {{ $trimesterTotal }} Visits
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
                        @unless ($trimesterOpen)
                            <p class="lml-mc__hint" role="status" data-mc-trimester-unavailable="{{ $trimesterKey }}">
                                {{ DemoMaternalCare::futurePrenatalUnavailableMessage($trimesterKey) }}
                            </p>
                        @endunless
                        <div class="lml-mc__visit-table" role="table" aria-label="{{ $trimester['label'] }} visits">
                            <div class="lml-mc__visit-head" role="row">
                                <span role="columnheader">Visit</span>
                                <span role="columnheader">Date</span>
                                <span role="columnheader">Height (cm)</span>
                                <span role="columnheader">Weight (kg)</span>
                                <span role="columnheader">BMI</span>
                                <span role="columnheader">Blood Pressure</span>
                            </div>
                            @foreach ($trimester['visits'] as $visit)
                                @php
                                    $row = is_array($prenatal[$visit['key']] ?? null) ? $prenatal[$visit['key']] : [];
                                    $visitHasData = DemoMaternalCare::prenatalSlotHasExistingData($row);
                                    $visitLocked = ! $readOnly && ! $trimesterOpen && ! $visitHasData;
                                @endphp
                                <div
                                    class="lml-mc__visit-row{{ $visitLocked ? ' lml-mc__visit-row--locked' : '' }}"
                                    role="row"
                                    data-mc-visit="{{ $visit['key'] }}"
                                    @if ($visitLocked) data-mc-visit-locked="true" @endif
                                >
                                    <span class="lml-mc__visit-label" role="cell">{{ $visit['label'] }}</span>
                                    <div class="lml-mc__field" role="cell">
                                        <label class="visually-hidden" for="mc-prenatal-{{ $visit['key'] }}-date">
                                            {{ $trimester['label'] }} {{ $visit['label'] }} Date
                                        </label>
                                        <input
                                            type="date"
                                            id="mc-prenatal-{{ $visit['key'] }}-date"
                                            name="visits[{{ $visit['key'] }}][date]"
                                            class="lml-mc__input lml-focus-ring"
                                            value="{{ $row['date'] ?? '' }}"
                                            data-mc-field
                                            max="{{ now()->toDateString() }}"
                                            disabled
                                        >
                                    </div>
                                    <div class="lml-mc__field" role="cell">
                                        <label class="visually-hidden" for="mc-prenatal-{{ $visit['key'] }}-height">
                                            {{ $trimester['label'] }} {{ $visit['label'] }} Height (cm)
                                        </label>
                                        <input
                                            type="number"
                                            id="mc-prenatal-{{ $visit['key'] }}-height"
                                            name="visits[{{ $visit['key'] }}][height]"
                                            min="0"
                                            step="0.1"
                                            class="lml-mc__input lml-focus-ring"
                                            value="{{ $row['height'] ?? '' }}"
                                            data-mc-field
                                            data-mc-prenatal-height
                                            disabled
                                        >
                                    </div>
                                    <div class="lml-mc__field" role="cell">
                                        <label class="visually-hidden" for="mc-prenatal-{{ $visit['key'] }}-weight">
                                            {{ $trimester['label'] }} {{ $visit['label'] }} Weight (kg)
                                        </label>
                                        <input
                                            type="number"
                                            id="mc-prenatal-{{ $visit['key'] }}-weight"
                                            name="visits[{{ $visit['key'] }}][weight]"
                                            min="0"
                                            step="0.1"
                                            class="lml-mc__input lml-focus-ring"
                                            value="{{ $row['weight'] ?? '' }}"
                                            data-mc-field
                                            data-mc-prenatal-weight
                                            disabled
                                        >
                                    </div>
                                    <div class="lml-mc__field" role="cell">
                                        @php
                                            $visitBmiValue = $row['bmi'] ?? '';
                                            $visitBmiStatus = \App\Support\RiskAssessmentClinicalValues::bmiStatusFromValue($visitBmiValue) ?? '';
                                        @endphp
                                        <label
                                            id="mc-prenatal-{{ $visit['key'] }}-bmi-label"
                                            class="visually-hidden"
                                        >
                                            {{ $trimester['label'] }} {{ $visit['label'] }} BMI
                                        </label>
                                        <div
                                            class="lml-mc__bmi-display"
                                            data-mc-field
                                            data-mc-bmi
                                            data-mc-derived="bmi"
                                            role="status"
                                            aria-labelledby="mc-prenatal-{{ $visit['key'] }}-bmi-label"
                                        >
                                            <span data-mc-bmi-value>{{ $visitBmiValue }}</span>
                                            <span
                                                class="lml-mc__bmi-status"
                                                data-mc-bmi-status
                                                data-bmi-status="{{ strtolower($visitBmiStatus) }}"
                                            >{{ $visitBmiStatus !== '' ? '('.$visitBmiStatus.')' : '' }}</span>
                                        </div>
                                    </div>
                                    <div class="lml-mc__field" role="cell">
                                        <label class="visually-hidden" for="mc-prenatal-{{ $visit['key'] }}-bp">
                                            {{ $trimester['label'] }} {{ $visit['label'] }} Blood Pressure
                                        </label>
                                        <input
                                            type="text"
                                            id="mc-prenatal-{{ $visit['key'] }}-bp"
                                            name="visits[{{ $visit['key'] }}][bp]"
                                            class="lml-mc__input lml-focus-ring"
                                            value="{{ $row['bp'] ?? '' }}"
                                            data-mc-field
                                            disabled
                                            autocomplete="off"
                                        >
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </form>
</section>
