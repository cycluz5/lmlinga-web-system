@php
    use App\Support\DemoMaternalCare;

    $canPersist = (bool) ($canPersist ?? false);
    $readOnly = (bool) ($readOnly ?? false);
    $updateUrl = route('household-profiling.members.maternal-care.update', $routeParams + [
        'section' => 'laboratory',
    ]);
    $lab = is_array($pregnancy['laboratory'] ?? null) ? $pregnancy['laboratory'] : [];
    $screens = [
        [
            'key' => 'hepatitis_b',
            'label' => 'Hepatitis B',
            'kind' => 'date_result',
            'results' => DemoMaternalCare::HEPATITIS_B_RESULTS,
        ],
        [
            'key' => 'cbc',
            'label' => 'CBC / Hgb & Hct Count',
            'kind' => 'date_result',
            'results' => DemoMaternalCare::CBC_RESULTS,
        ],
        [
            'key' => 'gdm',
            'label' => 'Gestational Diabetes Mellitus',
            'kind' => 'date_result',
            'results' => DemoMaternalCare::GDM_RESULTS,
        ],
        [
            'key' => 'urinalysis',
            'label' => 'Urinalysis',
            'kind' => 'date',
        ],
        [
            'key' => 'ultrasound',
            'label' => 'Ultrasound',
            'kind' => 'date',
        ],
        [
            'key' => 'syphilis',
            'label' => 'Syphilis',
            'kind' => 'date_result',
            'results' => DemoMaternalCare::SYPHILIS_RESULTS,
        ],
        [
            'key' => 'hiv',
            'label' => 'HIV',
            'kind' => 'date_result',
            'results' => DemoMaternalCare::HIV_RESULTS,
        ],
        [
            'key' => 'cvc',
            'label' => 'CVC',
            'kind' => 'numeric',
        ],
        [
            'key' => 'gestational',
            'label' => 'Gestational',
            'kind' => 'numeric',
        ],
    ];
@endphp

<section class="lml-mc__panel" aria-labelledby="lml-mc-lab-title" data-mc-laboratory>
    <header class="lml-mc__panel-head">
        <div class="lml-mc__panel-titles">
            <h2 id="lml-mc-lab-title" class="lml-mc__panel-title">Laboratory Screening</h2>
            <p class="lml-mc__panel-subtitle">
                Track essential lab tests to ensure maternal safety and early risk detection.
            </p>
        </div>
        <div class="lml-mc__panel-controls">
            @unless ($readOnly)
            <button
                type="button"
                class="lml-mc__btn lml-mc__btn--primary lml-focus-ring"
                data-mc-edit
                data-mc-edit-for="laboratory"
            >
                <i class="bi bi-pencil" aria-hidden="true"></i>
                <span>Edit</span>
            </button>
            <button
                type="submit"
                form="lml-mc-lab-form"
                class="lml-mc__btn lml-mc__btn--primary lml-focus-ring"
                data-mc-save
                data-mc-save-for="laboratory"
                hidden
            >
                Save
            </button>
            @endunless
        </div>
    </header>

    <form
        id="lml-mc-lab-form"
        method="post"
        action="{{ $updateUrl }}"
        class="lml-mc__form"
        data-mc-section-form="laboratory"
        data-editing="false"
        @if ($readOnly) data-mc-readonly="true" onsubmit="return false;" @endif
        novalidate
        @include('offline.maternal-section-attrs', ['section' => 'laboratory'])
    >
        @csrf
        @method('PUT')

        <div class="lml-mc__lab-grid">
            @foreach ($screens as $screen)
                @php
                    $row = is_array($lab[$screen['key']] ?? null) ? $lab[$screen['key']] : [];
                    $panelId = 'lml-mc-lab-'.$screen['key'];
                    $triggerId = $panelId.'-trigger';
                    $kind = $screen['kind'] ?? 'date_result';
                @endphp
                <div class="lml-mc__lab-card" data-mc-lab="{{ $screen['key'] }}" data-mc-accordion>
                    <h3 class="lml-mc__accordion-heading">
                        <button
                            type="button"
                            id="{{ $triggerId }}"
                            class="lml-mc__accordion-trigger lml-mc__lab-trigger lml-focus-ring"
                            aria-expanded="true"
                            aria-controls="{{ $panelId }}"
                            data-mc-accordion-trigger
                        >
                            <span class="lml-mc__accordion-title">{{ $screen['label'] }}</span>
                            <i class="bi bi-chevron-down lml-mc__accordion-chevron" aria-hidden="true"></i>
                        </button>
                    </h3>
                    <div
                        id="{{ $panelId }}"
                        class="lml-mc__accordion-panel lml-mc__lab-panel"
                        role="region"
                        aria-labelledby="{{ $triggerId }}"
                        data-mc-accordion-panel
                    >
                        @if ($kind === 'numeric')
                            <div class="lml-mc__field">
                                <label class="lml-mc__label" for="mc-lab-{{ $screen['key'] }}-value">
                                    Value
                                </label>
                                <input
                                    type="number"
                                    step="any"
                                    id="mc-lab-{{ $screen['key'] }}-value"
                                    name="{{ $screen['key'] }}[value]"
                                    class="lml-mc__input lml-focus-ring"
                                    value="{{ $row['value'] ?? '' }}"
                                    data-mc-field
                                    disabled
                                >
                            </div>
                        @else
                            <div class="lml-mc__field">
                                <label class="lml-mc__label" for="mc-lab-{{ $screen['key'] }}-date">
                                    Date Screened
                                </label>
                                <input
                                    type="date"
                                    id="mc-lab-{{ $screen['key'] }}-date"
                                    name="{{ $screen['key'] }}[date]"
                                    class="lml-mc__input lml-focus-ring"
                                    value="{{ $row['date'] ?? '' }}"
                                    data-mc-field
                                    max="{{ now()->toDateString() }}"
                                    disabled
                                >
                            </div>
                            @if ($kind === 'date_result')
                                <div class="lml-mc__field">
                                    <label class="lml-mc__label" for="mc-lab-{{ $screen['key'] }}-result">
                                        Result
                                    </label>
                                    <select
                                        id="mc-lab-{{ $screen['key'] }}-result"
                                        name="{{ $screen['key'] }}[result]"
                                        class="lml-mc__input lml-focus-ring"
                                        data-mc-field
                                        data-mc-lab-result="{{ $screen['key'] }}"
                                        disabled
                                    >
                                        <option value="">Select result</option>
                                        @foreach ($screen['results'] as $result)
                                            <option value="{{ $result }}" @selected(($row['result'] ?? '') === $result)>
                                                {{ $result }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </form>
</section>
