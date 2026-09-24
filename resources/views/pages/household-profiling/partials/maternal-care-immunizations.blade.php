@php
    use App\Support\DemoMaternalCare;

    $canPersist = (bool) ($canPersist ?? false);
    $readOnly = (bool) ($readOnly ?? false);
    $updateUrl = route('household-profiling.members.maternal-care.update', $routeParams + [
        'section' => 'immunizations',
    ]);
    $imm = is_array($pregnancy['immunizations'] ?? null) ? $pregnancy['immunizations'] : [];
    $recorded = DemoMaternalCare::immunizationCount($pregnancy);
    $doses = [
        ['key' => 'td1', 'label' => 'TD1 / TT1'],
        ['key' => 'td2', 'label' => 'TD2 / TT2'],
        ['key' => 'td3', 'label' => 'TD3 / TT3'],
        ['key' => 'td4', 'label' => 'TD4 / TT4'],
        ['key' => 'td5', 'label' => 'TD5 / TT5'],
    ];
@endphp

<section class="lml-mc__panel" aria-labelledby="lml-mc-imm-title" data-mc-immunizations>
    <header class="lml-mc__panel-head">
        <div class="lml-mc__panel-titles">
            <h2 id="lml-mc-imm-title" class="lml-mc__panel-title">Immunizations</h2>
            <p class="lml-mc__panel-subtitle">
                Track Tetanus Diphtheria (TD) doses for maternal and child protection.
            </p>
        </div>
        <div class="lml-mc__panel-controls">
            @unless ($readOnly)
            <button
                type="button"
                class="lml-mc__btn lml-mc__btn--primary lml-focus-ring"
                data-mc-edit
                data-mc-edit-for="immunizations"
            >
                <i class="bi bi-pencil" aria-hidden="true"></i>
                <span>Edit</span>
            </button>
            <button
                type="submit"
                form="lml-mc-imm-form"
                class="lml-mc__btn lml-mc__btn--primary lml-focus-ring"
                data-mc-save
                data-mc-save-for="immunizations"
                hidden
            >
                Save
            </button>
            @endunless
        </div>
    </header>

    <div class="lml-mc__imm-card">
        <div class="lml-mc__imm-card-head">
            <h3 class="lml-mc__section-title">Tetanus Diphtheria (TD) Vaccine</h3>
            <p class="lml-mc__count" data-mc-imm-count aria-live="polite">
                {{ $recorded }} of 5 Vaccines
            </p>
        </div>

        <form
            id="lml-mc-imm-form"
            method="post"
            action="{{ $updateUrl }}"
            class="lml-mc__form"
            data-mc-section-form="immunizations"
            data-editing="false"
            @if ($readOnly) data-mc-readonly="true" onsubmit="return false;" @endif
            novalidate
            @include('offline.maternal-section-attrs', ['section' => 'immunizations'])
        >
            @csrf
            @method('PUT')

            <ul class="lml-mc__dose-list">
                @foreach ($doses as $dose)
                    @php
                        $date = (string) ($imm[$dose['key']] ?? '');
                        $complete = $date !== '';
                    @endphp
                    <li class="lml-mc__dose-row" data-mc-dose="{{ $dose['key'] }}">
                        <span
                            class="lml-mc__dose-status{{ $complete ? ' is-complete' : '' }}"
                            aria-hidden="true"
                        >
                            @if ($complete)
                                <i class="bi bi-check-lg"></i>
                            @endif
                        </span>
                        <span class="lml-mc__dose-label">
                            {{ $dose['label'] }}
                            <span class="visually-hidden">
                                {{ $complete ? 'recorded' : 'not recorded' }}
                            </span>
                        </span>
                        <div class="lml-mc__field lml-mc__field--grow">
                            <label class="visually-hidden" for="mc-imm-{{ $dose['key'] }}">
                                {{ $dose['label'] }} Date
                            </label>
                            <input
                                type="date"
                                id="mc-imm-{{ $dose['key'] }}"
                                name="{{ $dose['key'] }}"
                                class="lml-mc__input lml-focus-ring"
                                value="{{ $date }}"
                                data-mc-field
                                max="{{ now()->toDateString() }}"
                                disabled
                            >
                        </div>
                    </li>
                @endforeach
            </ul>
        </form>
    </div>
</section>
