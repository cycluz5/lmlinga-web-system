{{--
    Household Profiling — Child Immunization destination.
    Continuous vertical scroll. Persisted residents use DB-backed immunization saves.
    Demo-only members remain preview-safe.
--}}
@extends('layouts.dashboard')

@section('title', ($demoMember['name'] ?? 'Member') . ' — Child Immunization - LMLinga')

@section('content')
    @php
        $emptyRecord = 'No record';
        $memberName = (string) ($demoMember['name'] ?? 'Member');
        $memberSex = (string) ($demoMember['sex'] ?? '');
        $memberAge = $demoMember['age'] ?? null;
        $dateBirth = $demoMember
            ? lml_demo_member_display($demoMember, 'birthday')
            : $emptyRecord;
        $householdNoLabel = filled($householdNo) ? (string) $householdNo : $emptyRecord;
        $zoneLabel = filled(data_get($demoHousehold, 'zone'))
            ? (string) data_get($demoHousehold, 'zone')
            : $emptyRecord;

        $birthHistoryDisplay = static function (mixed $value) use ($emptyRecord): string {
            return filled($value) ? (string) $value : $emptyRecord;
        };

        $birthHistory = [
            'weight' => [
                'label' => 'Birth Weight',
                'value' => $birthHistoryDisplay(data_get($demoMember, 'birth_history.weight')),
            ],
            'length' => [
                'label' => 'Birth Length',
                'value' => $birthHistoryDisplay(data_get($demoMember, 'birth_history.length')),
            ],
            'status' => [
                'label' => 'Status',
                'value' => $birthHistoryDisplay(data_get($demoMember, 'birth_history.status')),
            ],
            'pcab' => [
                'label' => 'CPAB from Neonatal Tetanus',
                'value' => $birthHistoryDisplay(data_get($demoMember, 'birth_history.pcab')),
            ],
            'breastfeeding_date' => [
                'label' => 'Initiated Breast Feeding Date',
                'value' => $birthHistoryDisplay(data_get($demoMember, 'birth_history.breastfeeding_date_display')),
            ],
        ];

        $sexBadgeClass = strtolower($memberSex) === 'female'
            ? 'lml-child-imm__sex-badge--female'
            : 'lml-child-imm__sex-badge--male';

        $backUrl = route('household-profiling.members.show', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);

        $birthHistoryEditUrl = route('household-profiling.members.child-immunization.birth-history.edit', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);

        /*
         | Presentation-only dose status icons (Figma demo indicators).
         | Not derived from clinical scheduling or date-input values.
         */
        $vaccineCards = [
            [
                'key' => 'bcg',
                'title' => 'BCG',
                'layout' => 'pair',
                'doses' => [
                    ['label' => '0-28 days', 'status' => 'recorded'],
                    ['label' => '29 days - 1 year old', 'status' => null],
                ],
            ],
            [
                'key' => 'hepa-b',
                'title' => 'Hepa B',
                'layout' => 'pair',
                'doses' => [
                    ['label' => '24 hrs after birth', 'status' => 'recorded'],
                    ['label' => '24 hrs up to 14 days', 'status' => null],
                ],
            ],
            [
                'key' => 'dpt-hib-hepb',
                'title' => 'DPT-HIB-HepB',
                'layout' => 'stack',
                'doses' => [
                    ['label' => '1st Dose (1 1/2 months)', 'status' => null],
                    ['label' => '2nd Dose (2 1/2 months)', 'status' => 'attention'],
                    ['label' => '3rd Dose (3 1/2 months)', 'status' => null],
                ],
            ],
            [
                'key' => 'opv',
                'title' => 'OPV',
                'layout' => 'stack',
                'doses' => [
                    ['label' => '1st Dose (1 1/2 months)', 'status' => 'recorded'],
                    ['label' => '2nd Dose (2 1/2 months)', 'status' => null],
                    ['label' => '3rd Dose (3 1/2 months)', 'status' => null],
                ],
            ],
            [
                'key' => 'pcv',
                'title' => 'PCV',
                'layout' => 'stack',
                'doses' => [
                    ['label' => '1st Dose (1 1/2 months)', 'status' => 'recorded'],
                    ['label' => '2nd Dose (2 1/2 months)', 'status' => 'attention'],
                    ['label' => '3rd Dose (3 1/2 months)', 'status' => 'recorded'],
                ],
            ],
            [
                'key' => 'ipv',
                'title' => 'IPV',
                'layout' => 'stack',
                'doses' => [
                    ['label' => '1st Dose (3 1/2 months)', 'status' => null],
                    ['label' => '2nd Dose (9 months)', 'status' => null],
                ],
            ],
            [
                'key' => 'mmr',
                'title' => 'MMR',
                'layout' => 'stack',
                'doses' => [
                    ['label' => '1st Dose (9 months)', 'status' => null],
                    ['label' => '2nd Dose (12 months)', 'status' => null],
                ],
            ],
        ];

        /*
         | FIC/CIC completion lists (presentation-only):
         | - FIC intentionally requires MMR × 1 dose.
         | - CIC requires MMR × 2 doses.
         | Figma is a visual reference only; its duplicated two-dose FIC sample
         | is not authoritative for this approved requirement.
         */
        $completionCards = [
            [
                'key' => 'fic',
                'title' => 'FIC',
                'range' => '0–12 months',
                'items' => [
                    ['label' => 'BCG', 'doses' => '1 dose'],
                    ['label' => 'OPV', 'doses' => '3 doses'],
                    ['label' => 'DPT-HIB-HepB', 'doses' => '3 doses'],
                    ['label' => 'MMR', 'doses' => '1 dose'],
                ],
            ],
            [
                'key' => 'cic',
                'title' => 'CIC',
                'range' => '13–24 months',
                'items' => [
                    ['label' => 'BCG', 'doses' => '1 dose'],
                    ['label' => 'OPV', 'doses' => '3 doses'],
                    ['label' => 'DPT-HIB-HepB', 'doses' => '3 doses'],
                    ['label' => 'MMR', 'doses' => '2 doses'],
                ],
            ],
        ];

        $vaccineTypes = [
            ['key' => 'bcg', 'label' => 'BCG'],
            ['key' => 'hepa-b', 'label' => 'Hepa B'],
            ['key' => 'dpt-hib-hepb', 'label' => 'DPT-HIB-HepB'],
            ['key' => 'opv', 'label' => 'OPV'],
            ['key' => 'ipv', 'label' => 'IPV'],
            ['key' => 'pcv', 'label' => 'PCV'],
            ['key' => 'mmr', 'label' => 'MMR'],
            ['key' => 'fic', 'label' => 'FIC'],
            ['key' => 'cic', 'label' => 'CIC'],
        ];

        $persistenceSource = $persistenceSource ?? 'preview';
        $isDbPersisted = in_array($persistenceSource, ['db', 'local'], true);
        $storeUrl = $isDbPersisted
            ? route('household-profiling.members.child-immunization.store', [
                'householdNo' => $householdNo,
                'memberId' => $memberId,
            ])
            : null;

        $formVaccines = \App\Support\ChildImmunizationService::emptyVaccineForm();
        $selectedVaccineTypes = [];
        $ficCompleted = false;
        $cicCompleted = false;
        $ficCounts = array_fill_keys(array_keys(\App\Support\ChildImmunizationService::FIC_DOSE_REQUIREMENTS), 0);
        $cicCounts = $ficCounts;

        if (is_array($immunizationState ?? null)) {
            $formVaccines = $immunizationState['vaccines'] ?? $formVaccines;
            $selectedVaccineTypes = $immunizationState['selected_vaccine_types'] ?? [];
            $ficCompleted = (bool) ($immunizationState['fic']['completed'] ?? false);
            $cicCompleted = (bool) ($immunizationState['cic']['completed'] ?? false);
            $ficCounts = $immunizationState['fic']['counts'] ?? $ficCounts;
            $cicCounts = $immunizationState['cic']['counts'] ?? $cicCounts;
        }

        if (old('vaccines') !== null && is_array(old('vaccines'))) {
            foreach (old('vaccines') as $vaccineKey => $doses) {
                if (! is_array($doses) || ! isset($formVaccines[$vaccineKey])) {
                    continue;
                }

                foreach ($doses as $index => $value) {
                    if (isset($formVaccines[$vaccineKey][(int) $index])) {
                        $formVaccines[$vaccineKey][(int) $index] = (string) $value;
                    }
                }
            }
        }

        if (old('vaccine_types') !== null) {
            $selectedVaccineTypes = \App\Support\ChildImmunizationService::normalizeSelectedVaccineTypes(
                old('vaccine_types')
            );
        }

        $formDoseSlots = [];
        foreach ($formVaccines as $vaccineType => $dates) {
            foreach ($dates as $index => $date) {
                $formDoseSlots[] = [
                    'vaccine_type' => (string) $vaccineType,
                    'dose_index' => (int) $index,
                    'date_given' => $date !== '' ? (string) $date : null,
                ];
            }
        }

        $vaccineProgress = \App\Support\ChildImmunizationService::vaccineProgress($formDoseSlots);
        $formDatedCounts = \App\Support\ChildImmunizationService::countDatedDoses($formDoseSlots);
        $ficCompleted = \App\Support\ChildImmunizationService::ficCompleted($formDatedCounts);
        $cicCompleted = \App\Support\ChildImmunizationService::cicCompleted($formDatedCounts);
        $ficCounts = $formDatedCounts;
        $cicCounts = $formDatedCounts;

        if ($isDbPersisted) {
            $vaccineCards = array_map(
                static function (array $card): array {
                    $card['doses'] = array_map(
                        static fn (array $dose): array => array_merge($dose, ['status' => null]),
                        $card['doses']
                    );

                    return $card;
                },
                $vaccineCards
            );
        }

        $completionLabelKeys = [
            'BCG' => 'bcg',
            'OPV' => 'opv',
            'DPT-HIB-HepB' => 'dpt-hib-hepb',
            'MMR' => 'mmr',
        ];

        $completionItemMet = static function (
            string $cardKey,
            string $label,
            array $counts
        ) use ($isDbPersisted, $completionLabelKeys, $ficCounts, $cicCounts): bool {
            if (! $isDbPersisted) {
                return false;
            }

            $vaccineKey = $completionLabelKeys[$label] ?? null;
            if ($vaccineKey === null) {
                return false;
            }

            $requirements = $cardKey === 'fic'
                ? \App\Support\ChildImmunizationService::FIC_DOSE_REQUIREMENTS
                : \App\Support\ChildImmunizationService::CIC_DOSE_REQUIREMENTS;

            return ($counts[$vaccineKey] ?? 0) >= ($requirements[$vaccineKey] ?? PHP_INT_MAX);
        };
    @endphp

    <div
        class="lml-child-imm"
        data-lml-child-imm
        data-demo="{{ $isDbPersisted ? 'false' : 'true' }}"
        data-household-no="{{ $householdNo }}"
        data-member-id="{{ $memberId }}"
        @if ($demoMember)
            data-member-name="{{ $demoMember['name'] }}"
        @endif
        @if (session('status'))
            data-saved-message="{{ session('status') }}"
        @endif
    >
        <div
            class="lml-child-imm__toast"
            data-child-imm-toast
            role="status"
            aria-live="polite"
            hidden
        ></div>

        <a
            href="{{ $backUrl }}"
            class="lml-child-imm__back lml-focus-ring"
            aria-label="Back to Health Summary Records for {{ $memberName }}"
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span>Back</span>
        </a>

        @if (! $demoHousehold || ! $demoMember)
            <section class="lml-child-imm__not-found" aria-labelledby="lml-child-imm-nf-title">
                <h2 id="lml-child-imm-nf-title" class="lml-child-imm__not-found-title">
                    Member not found
                </h2>
                <p class="lml-child-imm__not-found-message">
                    @if (! $demoHousehold)
                        No record found.
                    @else
                        No record found.
                    @endif
                </p>
                <a
                    href="{{ $demoHousehold ? route('household-profiling.view', ['householdNo' => $householdNo]) : route('household-profiling.index') }}"
                    class="lml-child-imm__not-found-link lml-focus-ring"
                >
                    {{ $demoHousehold ? 'Back to Household' : 'Return to Household List' }}
                </a>
            </section>
        @else
            <article class="lml-child-imm__summary" aria-labelledby="lml-child-imm-member-name">
                    <div class="lml-child-imm__summary-profile">
                        <span class="lml-child-imm__avatar" aria-hidden="true">
                            <i class="bi bi-person-fill"></i>
                        </span>
                        <div class="lml-child-imm__summary-identity">
                            <p id="lml-child-imm-member-name" class="lml-child-imm__member-name">
                                {{ $demoMember['name'] }}
                            </p>
                            @if ($memberSex !== '')
                                <span class="lml-child-imm__sex-badge {{ $sexBadgeClass }}">
                                    {{ $memberSex }}
                                </span>
                            @endif
                            <dl class="lml-child-imm__profile-dl">
                                <div class="lml-child-imm__profile-item">
                                    <dt>Age:</dt>
                                    <dd>{{ $memberAge !== null && $memberAge !== '' ? $memberAge : $emptyRecord }}</dd>
                                </div>
                                <div class="lml-child-imm__profile-item">
                                    <dt>Date Birth:</dt>
                                    <dd>{{ $dateBirth !== '' ? $dateBirth : $emptyRecord }}</dd>
                                </div>
                                <div class="lml-child-imm__profile-item">
                                    <dt>Household No.:</dt>
                                    <dd>{{ $householdNoLabel }}</dd>
                                </div>
                                <div class="lml-child-imm__profile-item">
                                    <dt>Zone:</dt>
                                    <dd>{{ $zoneLabel }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <div class="lml-child-imm__birth-history" aria-labelledby="lml-child-imm-birth-heading">
                        <div class="lml-child-imm__birth-head">
                            <h2 id="lml-child-imm-birth-heading" class="lml-child-imm__birth-title">
                                <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                                <span>Birth History</span>
                            </h2>
                            <a
                                href="{{ $birthHistoryEditUrl }}"
                                class="lml-child-imm__birth-edit-link lml-focus-ring"
                                data-child-imm-birth-edit-link
                                aria-label="Edit birth history"
                            >
                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                <span>Edit</span>
                            </a>
                        </div>
                        <dl class="lml-child-imm__birth-dl" data-child-imm-birth-summary>
                            @foreach ($birthHistory as $key => $item)
                                <div class="lml-child-imm__birth-item">
                                    <dt>{{ $item['label'] }}</dt>
                                    <dd data-birth-summary="{{ $key }}">{{ $item['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </article>

                {{-- Inline Immunization edit form: DB-backed for persisted residents; preview-only otherwise. --}}
                <form
                    class="lml-child-imm__immunization"
                    data-child-imm-immunization
                    data-editing="false"
                    data-persistence="{{ $persistenceSource }}"
                    @if ($isDbPersisted)
                        action="{{ $storeUrl }}"
                        method="post"
                        @include('offline.form-attrs', [
                            'offlineOperation' => 'HEALTH_SERVICE_WRITE',
                            'offlineHealthAction' => 'child_immunization_store',
                            'offlineHouseholdNo' => $householdNo,
                            'offlineMemberNo' => $memberId,
                        ])
                    @else
                        method="get"
                    @endif
                    aria-labelledby="lml-child-imm-heading"
                    novalidate
                >
                    @if ($isDbPersisted)
                        @csrf
                    @endif

                    @if ($errors->any())
                        <div class="lml-child-imm__errors" role="alert">
                            <p class="lml-child-imm__errors-title">Please correct the following:</p>
                            <ul class="lml-child-imm__errors-list">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <div class="lml-child-imm__imm-head">
                        <div class="lml-child-imm__imm-intro">
                            <h2 id="lml-child-imm-heading" class="lml-child-imm__imm-title">
                                <i class="bi bi-syringe" aria-hidden="true"></i>
                                <span>Immunization</span>
                            </h2>
                            <p class="lml-child-imm__imm-desc">
                                Vaccination records that support immunity and protection against infectious diseases.
                            </p>
                        </div>
                        <div class="lml-child-imm__imm-actions">
                            <button
                                type="button"
                                class="lml-child-imm__edit lml-focus-ring"
                                data-child-imm-edit="immunization"
                                aria-label="Edit child immunization"
                            >
                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                <span>Edit</span>
                            </button>
                            <button
                                type="submit"
                                class="lml-child-imm__save lml-focus-ring"
                                data-child-imm-save
                                aria-label="Save child immunization"
                                hidden
                            >
                                <span>Save</span>
                            </button>
                        </div>
                    </div>

                    <div class="lml-child-imm__body">
                        <div class="lml-child-imm__main">
                            <div class="lml-child-imm__vaccine-grid">
                                @foreach ($vaccineCards as $card)
                                    <section
                                        class="lml-child-imm__vaccine-card lml-child-imm__vaccine-card--{{ $card['key'] }}{{ ($card['layout'] ?? '') === 'pair' ? ' lml-child-imm__vaccine-card--pair' : '' }}"
                                        aria-labelledby="lml-child-imm-vax-{{ $card['key'] }}"
                                    >
                                        <h3
                                            id="lml-child-imm-vax-{{ $card['key'] }}"
                                            class="lml-child-imm__vaccine-title"
                                        >
                                            {{ $card['title'] }}
                                        </h3>
                                        <div class="lml-child-imm__dose-list">
                                            @foreach ($card['doses'] as $index => $dose)
                                                @php
                                                    $fieldId = 'lml-child-imm-'.$card['key'].'-dose-'.$index;
                                                    $status = $dose['status'] ?? null;
                                                @endphp
                                                <div class="lml-child-imm__dose">
                                                    <div class="lml-child-imm__dose-label-row">
                                                        <label
                                                            class="lml-child-imm__dose-label"
                                                            for="{{ $fieldId }}"
                                                        >
                                                            {{ $dose['label'] }}
                                                        </label>
                                                        @if ($status === 'recorded')
                                                            <span class="lml-child-imm__status lml-child-imm__status--recorded">
                                                                <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                                                                <span class="visually-hidden">Demo status: recorded</span>
                                                            </span>
                                                        @elseif ($status === 'attention')
                                                            <span class="lml-child-imm__status lml-child-imm__status--attention">
                                                                <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                                                                <span class="visually-hidden">Demo status: needs attention</span>
                                                            </span>
                                                        @elseif ($status === 'pending')
                                                            <span class="lml-child-imm__status lml-child-imm__status--pending">
                                                                <i class="bi bi-circle-fill" aria-hidden="true"></i>
                                                                <span class="visually-hidden">Demo status: pending</span>
                                                            </span>
                                                        @endif
                                                    </div>
                                                    <div class="lml-child-imm__date-wrap">
                                                        <span class="lml-child-imm__date-caption" id="{{ $fieldId }}-caption">
                                                            Date
                                                        </span>
                                                        <input
                                                            type="date"
                                                            id="{{ $fieldId }}"
                                                            name="vaccines[{{ $card['key'] }}][{{ $index }}]"
                                                            value="{{ $formVaccines[$card['key']][$index] ?? '' }}"
                                                            class="lml-child-imm__date-input lml-focus-ring"
                                                            data-child-imm-field
                                                            aria-describedby="{{ $fieldId }}-caption"
                                                            autocomplete="off"
                                                            readonly
                                                            disabled
                                                        >
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </section>
                                @endforeach

                                @foreach ($completionCards as $card)
                                    @php
                                        $cardCounts = $card['key'] === 'fic' ? $ficCounts : $cicCounts;
                                        $cardCompleted = $card['key'] === 'fic' ? $ficCompleted : $cicCompleted;
                                    @endphp
                                    <section
                                        class="lml-child-imm__vaccine-card lml-child-imm__vaccine-card--completion lml-child-imm__vaccine-card--{{ $card['key'] }}"
                                        aria-labelledby="lml-child-imm-vax-{{ $card['key'] }}"
                                        data-{{ $card['key'] }}-completed="{{ $cardCompleted ? 'true' : 'false' }}"
                                    >
                                        <h3
                                            id="lml-child-imm-vax-{{ $card['key'] }}"
                                            class="lml-child-imm__vaccine-title"
                                        >
                                            {{ $card['title'] }}
                                            <span class="lml-child-imm__vaccine-range">({{ $card['range'] }})</span>
                                            <span
                                                class="lml-child-imm__derived-status {{ $cardCompleted ? 'is-complete' : 'is-incomplete' }}"
                                                data-{{ $card['key'] }}-display-status="{{ $cardCompleted ? 'completed' : 'incomplete' }}"
                                            >
                                                {{ $cardCompleted ? 'Completed' : 'Incomplete' }}
                                            </span>
                                        </h3>
                                        <p class="visually-hidden">
                                            @if ($isDbPersisted)
                                                Derived completion from persisted dose dates.
                                            @else
                                                Presentation-only completion checklist. Not calculated from date inputs.
                                            @endif
                                        </p>
                                        <ul class="lml-child-imm__completion-list">
                                            @foreach ($card['items'] as $item)
                                                @php
                                                    $itemMet = $completionItemMet(
                                                        $card['key'],
                                                        $item['label'],
                                                        $cardCounts
                                                    );
                                                @endphp
                                                <li class="lml-child-imm__completion-item">
                                                    <span class="lml-child-imm__completion-mark" aria-hidden="true"></span>
                                                    <span class="lml-child-imm__completion-text">
                                                        <span class="lml-child-imm__completion-label">{{ $item['label'] }}</span>
                                                        <span class="lml-child-imm__completion-doses">{{ $item['doses'] }}</span>
                                                        @if ($itemMet)
                                                            <span class="visually-hidden">Completed</span>
                                                        @endif
                                                    </span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </section>
                                @endforeach
                            </div>
                        </div>

                        <aside class="lml-child-imm__aside" aria-labelledby="lml-child-imm-types-heading">
                            <div class="lml-child-imm__types-card">
                                <h3 id="lml-child-imm-types-heading" class="lml-child-imm__types-title">
                                    <i class="bi bi-shield-plus" aria-hidden="true"></i>
                                    <span>Vaccines Type</span>
                                </h3>
                                <ul class="lml-child-imm__types-list">
                                    @foreach ($vaccineTypes as $type)
                                        @php
                                            $checkboxId = 'lml-child-imm-type-'.$type['key'];
                                            $isFicCic = in_array($type['key'], ['fic', 'cic'], true);
                                            $progress = $vaccineProgress[$type['key']] ?? null;
                                            $rowComplete = $isFicCic
                                                ? ($type['key'] === 'fic' ? $ficCompleted : $cicCompleted)
                                                : (bool) ($progress['complete'] ?? false);
                                        @endphp
                                        <li>
                                            <label
                                                class="lml-child-imm__type-row{{ $rowComplete ? ' lml-child-imm__type-row--complete' : '' }}"
                                                for="{{ $checkboxId }}"
                                            >
                                                <input
                                                    type="checkbox"
                                                    id="{{ $checkboxId }}"
                                                    value="{{ $type['key'] }}"
                                                    class="lml-child-imm__type-checkbox lml-focus-ring"
                                                    data-child-imm-type-status="{{ $type['key'] }}"
                                                    aria-label="{{ $type['label'] }} completion status"
                                                    @checked($rowComplete)
                                                    disabled
                                                    tabindex="-1"
                                                >
                                                <span class="lml-child-imm__type-label">{{ $type['label'] }}</span>
                                                @if ($progress !== null)
                                                    <span
                                                        class="lml-child-imm__type-progress"
                                                        data-vaccine-progress="{{ $type['key'] }}"
                                                        data-vaccine-progress-display="{{ $progress['display'] }}"
                                                    >{{ $progress['display'] }}</span>
                                                @else
                                                    <span
                                                        class="lml-child-imm__type-status {{ $rowComplete ? 'is-complete' : 'is-incomplete' }}"
                                                        data-{{ $type['key'] }}-type-status="{{ $rowComplete ? 'completed' : 'incomplete' }}"
                                                    >{{ $rowComplete ? 'Completed' : 'Incomplete' }}</span>
                                                @endif
                                            </label>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </aside>
                    </div>
                </form>
        @endif
    </div>
@endsection