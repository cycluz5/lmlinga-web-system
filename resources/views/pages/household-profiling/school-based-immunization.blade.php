{{--
    Household Profiling — School-Based Immunization destination.
    Continuous vertical scroll. Persisted residents use DB-backed SBI saves.
    Demo-only members remain preview-safe.

    HPV dose labels: Vaccines Type and record fields use "1st Dose" / "2nd Dose"
    when Figma layer text appears duplicated or conflicts with that structure.

    HPV section uses a neutral heading (Human Papillomavirus (HPV)) — no age/sex
    claim and no false completion badge when dates/checkboxes are empty.

    Recorded indicators are derived only from non-empty date values.
    Phase 4 one-way sync: date exists => mapped checkbox checked; manual
    checkbox-only selection remains allowed and does not invent a date.
--}}
@extends('layouts.dashboard')

@section('title', ($demoMember['name'] ?? 'Member') . ' — School-Based Immunization - LMLinga')

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

        $gradeCards = [
            [
                'key' => 'grade-1',
                'title' => 'Grade 1',
                'doses' => [
                    [
                        'key' => 'td',
                        'label' => 'Tetanus Diphtheria (TD)',
                        'status' => null,
                    ],
                    [
                        'key' => 'mr',
                        'label' => 'Measles Rubella',
                        'status' => null,
                    ],
                ],
            ],
            [
                'key' => 'grade-7',
                'title' => 'Grade 7',
                'doses' => [
                    [
                        'key' => 'td',
                        'label' => 'Tetanus Diphtheria (TD)',
                        'status' => null,
                    ],
                    [
                        'key' => 'mr',
                        'label' => 'Measles Rubella',
                        'status' => null,
                    ],
                ],
            ],
        ];

        $hpvDoses = [
            [
                'key' => '1',
                'label' => 'Human Papillomavirus (1st Dose)',
                'status' => null,
            ],
            [
                'key' => '2',
                'label' => 'Human Papillomavirus (2nd Dose)',
                'status' => null,
            ],
        ];

        $vaccineTypeGroups = [
            [
                'key' => 'grade-1',
                'legend' => 'GRADE 1',
                'items' => [
                    ['key' => 'g1-td', 'label' => 'Tetanus Diphtheria (TD)', 'value' => 'grade1_td'],
                    ['key' => 'g1-mr', 'label' => 'Measles Rubella', 'value' => 'grade1_mr'],
                ],
            ],
            [
                'key' => 'grade-7',
                'legend' => 'GRADE 7',
                'items' => [
                    ['key' => 'g7-td', 'label' => 'Tetanus Diphtheria (TD)', 'value' => 'grade7_td'],
                    ['key' => 'g7-mr', 'label' => 'Measles Rubella', 'value' => 'grade7_mr'],
                ],
            ],
            [
                'key' => 'hpv',
                'legend' => 'Human Papillomavirus',
                'items' => [
                    ['key' => 'hpv-1', 'label' => '1st Dose', 'value' => 'hpv_1'],
                    ['key' => 'hpv-2', 'label' => '2nd Dose', 'value' => 'hpv_2'],
                ],
            ],
        ];

        $persistenceSource = $persistenceSource ?? 'preview';
        $sbiEligible = $sbiEligible ?? true;
        $sbiIneligibleDetail = $sbiIneligibleDetail
            ?? \App\Support\SchoolImmunizationService::INELIGIBLE_DETAIL;
        $isDbPersisted = in_array($persistenceSource, ['db', 'local'], true);
        $storeUrl = $isDbPersisted && $sbiEligible
            ? route('household-profiling.members.school-based-immunization.store', [
                'householdNo' => $householdNo,
                'memberId' => $memberId,
            ])
            : null;

        $formVaccines = \App\Support\SchoolImmunizationService::emptyVaccineForm();
        $selectedVaccineTypes = [];

        if (is_array($immunizationState ?? null)) {
            $formVaccines = $immunizationState['vaccines'] ?? $formVaccines;
            $selectedVaccineTypes = $immunizationState['selected_vaccine_types'] ?? [];
        }

        if (old('vaccines') !== null && is_array(old('vaccines'))) {
            foreach (old('vaccines') as $slotGroup => $doses) {
                if (! is_array($doses) || ! isset($formVaccines[$slotGroup])) {
                    continue;
                }

                foreach ($doses as $slotKey => $value) {
                    if (array_key_exists((string) $slotKey, $formVaccines[$slotGroup])) {
                        $formVaccines[$slotGroup][(string) $slotKey] = (string) $value;
                    }
                }
            }
        }

        if (old('vaccine_types') !== null) {
            $selectedVaccineTypes = \App\Support\SchoolImmunizationService::normalizeSelectedVaccineTypes(
                old('vaccine_types')
            );
        }

        /*
         | Phase 4: DATE EXISTS → checkbox selected (hydration + old() recovery).
         | Manual checkbox without a date remains allowed.
         */
        $derivedSlots = [];
        foreach ($formVaccines as $slotGroup => $doses) {
            foreach ($doses as $slotKey => $dateValue) {
                $derivedSlots[] = [
                    'slot_group' => (string) $slotGroup,
                    'slot_key' => (string) $slotKey,
                    'date_given' => filled($dateValue) ? (string) $dateValue : null,
                ];
            }
        }
        $selectedVaccineTypes = \App\Support\SchoolImmunizationService::applySubmittedDateSelectionRules(
            $selectedVaccineTypes,
            $derivedSlots
        );

        /*
         | Status must agree with displayed dates — derive recorded only from
         | non-empty values. Never invent completion for blank fields.
         */
        foreach ($gradeCards as &$card) {
            foreach ($card['doses'] as &$dose) {
                $dateValue = $formVaccines[$card['key']][$dose['key']] ?? '';
                $dose['status'] = filled($dateValue) ? 'recorded' : null;
            }
            unset($dose);
        }
        unset($card);

        foreach ($hpvDoses as &$dose) {
            $dateValue = $formVaccines['hpv'][$dose['key']] ?? '';
            $dose['status'] = filled($dateValue) ? 'recorded' : null;
        }
        unset($dose);
    @endphp

    <div
        class="lml-sbi"
        data-lml-sbi
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
            class="lml-sbi__toast"
            data-sbi-toast
            role="status"
            aria-live="polite"
            hidden
        ></div>

        <a
            href="{{ $backUrl }}"
            class="lml-sbi__back lml-focus-ring"
            aria-label="Back to Health Summary Records for {{ $memberName }}"
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span>Back</span>
        </a>

        @if (! $demoHousehold || ! $demoMember)
            <section class="lml-sbi__not-found" aria-labelledby="lml-sbi-nf-title">
                <h2 id="lml-sbi-nf-title" class="lml-sbi__not-found-title">
                    Member not found
                </h2>
                <p class="lml-sbi__not-found-message">
                    @if (! $demoHousehold)
                        No record found.
                    @else
                        No record found.
                    @endif
                </p>
                <a
                    href="{{ $demoHousehold ? route('household-profiling.view', ['householdNo' => $householdNo]) : route('household-profiling.index') }}"
                    class="lml-sbi__not-found-link lml-focus-ring"
                >
                    {{ $demoHousehold ? 'Back to Household' : 'Return to Household List' }}
                </a>
            </section>
        @else
            <article class="lml-child-imm__summary lml-sbi__summary" aria-labelledby="lml-sbi-member-name">
                <div class="lml-child-imm__summary-profile">
                    <span class="lml-child-imm__avatar" aria-hidden="true">
                        <i class="bi bi-person-fill"></i>
                    </span>
                    <div class="lml-child-imm__summary-identity">
                        <p id="lml-sbi-member-name" class="lml-child-imm__member-name">
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

                <div class="lml-child-imm__birth-history" aria-labelledby="lml-sbi-birth-heading">
                    <div class="lml-child-imm__birth-head">
                        <h2 id="lml-sbi-birth-heading" class="lml-child-imm__birth-title">
                            <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                            <span>Birth History</span>
                        </h2>
                        <a
                            href="{{ $birthHistoryEditUrl }}"
                            class="lml-child-imm__birth-edit-link lml-focus-ring"
                            data-sbi-birth-edit-link
                            aria-label="Edit birth history"
                        >
                            <i class="bi bi-pencil-square" aria-hidden="true"></i>
                            <span>Edit</span>
                        </a>
                    </div>
                    <dl class="lml-child-imm__birth-dl" data-sbi-birth-summary>
                        @foreach ($birthHistory as $key => $item)
                            <div class="lml-child-imm__birth-item">
                                <dt>{{ $item['label'] }}</dt>
                                <dd data-birth-summary="{{ $key }}">{{ $item['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </article>

            @if (! $sbiEligible)
                <section class="lml-sbi__ineligible" data-sbi-ineligible aria-labelledby="lml-sbi-ineligible-title">
                    <h2 id="lml-sbi-ineligible-title" class="lml-sbi__ineligible-title">
                        {{ \App\Support\SchoolImmunizationService::INELIGIBLE_TITLE }}
                    </h2>
                    <p class="lml-sbi__ineligible-message">
                        {{ $sbiIneligibleDetail }}
                    </p>
                </section>
            @else
            {{-- Inline SBI edit form: DB-backed for persisted residents; preview-only otherwise. --}}
            <form
                class="lml-sbi__records"
                data-sbi-records
                data-editing="false"
                data-persistence="{{ $persistenceSource }}"
                @if ($isDbPersisted)
                    action="{{ $storeUrl }}"
                    method="post"
                    @include('offline.form-attrs', [
                        'offlineOperation' => 'HEALTH_SERVICE_WRITE',
                        'offlineHealthAction' => 'school_immunization_store',
                        'offlineHouseholdNo' => $householdNo,
                        'offlineMemberNo' => $memberId,
                    ])
                @else
                    method="get"
                @endif
                aria-labelledby="lml-sbi-heading"
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

                <div class="lml-sbi__records-head">
                    <div class="lml-sbi__records-intro">
                        <h2 id="lml-sbi-heading" class="lml-sbi__records-title">
                            <i class="bi bi-syringe" aria-hidden="true"></i>
                            <span>School-Based Immunization</span>
                        </h2>
                        <p class="lml-sbi__records-desc">
                            Vaccination records that support immunity and protection against infectious diseases.
                        </p>
                    </div>
                    <div class="lml-sbi__records-actions">
                        <button
                            type="button"
                            class="lml-sbi__edit lml-focus-ring"
                            data-sbi-edit
                            aria-label="Edit school-based immunization"
                        >
                            <i class="bi bi-pencil-square" aria-hidden="true"></i>
                            <span>Edit</span>
                        </button>
                        <button
                            type="submit"
                            class="lml-sbi__save lml-focus-ring"
                            data-sbi-save
                            aria-label="Save school-based immunization"
                            hidden
                        >
                            <span>Save</span>
                        </button>
                    </div>
                </div>

                <div class="lml-sbi__body">
                    <div class="lml-sbi__main">
                        <div class="lml-sbi__grade-grid">
                            @foreach ($gradeCards as $card)
                                <section
                                    class="lml-sbi__grade-card lml-sbi__grade-card--{{ $card['key'] }}"
                                    aria-labelledby="lml-sbi-{{ $card['key'] }}"
                                >
                                    <h3
                                        id="lml-sbi-{{ $card['key'] }}"
                                        class="lml-sbi__grade-title"
                                    >
                                        {{ $card['title'] }}
                                    </h3>
                                    <div class="lml-sbi__dose-list">
                                        @foreach ($card['doses'] as $dose)
                                            @php
                                                $fieldId = 'lml-sbi-'.$card['key'].'-'.$dose['key'];
                                                $status = $dose['status'] ?? null;
                                            @endphp
                                            <div class="lml-sbi__dose">
                                                <div class="lml-sbi__dose-label-row">
                                                    <label
                                                        class="lml-sbi__dose-label"
                                                        for="{{ $fieldId }}"
                                                    >
                                                        {{ $dose['label'] }}
                                                    </label>
                                                    @if ($status === 'recorded')
                                                        <span class="lml-sbi__status lml-sbi__status--recorded">
                                                            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                                                            <span class="visually-hidden">Recorded</span>
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="lml-sbi__date-wrap">
                                                    <span class="lml-sbi__date-caption" id="{{ $fieldId }}-caption">
                                                        Date
                                                    </span>
                                                    <input
                                                        type="date"
                                                        id="{{ $fieldId }}"
                                                        name="vaccines[{{ $card['key'] }}][{{ $dose['key'] }}]"
                                                        value="{{ $formVaccines[$card['key']][$dose['key']] ?? '' }}"
                                                        class="lml-sbi__date-input lml-focus-ring"
                                                        data-sbi-field
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
                        </div>

                        <section
                            class="lml-sbi__hpv-card"
                            aria-labelledby="lml-sbi-hpv-heading"
                        >
                            <h3 id="lml-sbi-hpv-heading" class="lml-sbi__grade-title">
                                Human Papillomavirus (HPV)
                            </h3>
                            <div class="lml-sbi__dose-list lml-sbi__dose-list--hpv">
                                @foreach ($hpvDoses as $dose)
                                    @php
                                        $fieldId = 'lml-sbi-hpv-'.$dose['key'];
                                        $status = $dose['status'] ?? null;
                                    @endphp
                                    <div class="lml-sbi__dose">
                                        <div class="lml-sbi__dose-label-row">
                                            <label
                                                class="lml-sbi__dose-label"
                                                for="{{ $fieldId }}"
                                            >
                                                {{ $dose['label'] }}
                                            </label>
                                            @if ($status === 'recorded')
                                                <span class="lml-sbi__status lml-sbi__status--recorded">
                                                    <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                                                    <span class="visually-hidden">Recorded</span>
                                                </span>
                                            @endif
                                        </div>
                                        <div class="lml-sbi__date-wrap">
                                            <span class="lml-sbi__date-caption" id="{{ $fieldId }}-caption">
                                                Date
                                            </span>
                                            <input
                                                type="date"
                                                id="{{ $fieldId }}"
                                                name="vaccines[hpv][{{ $dose['key'] }}]"
                                                value="{{ $formVaccines['hpv'][$dose['key']] ?? '' }}"
                                                class="lml-sbi__date-input lml-focus-ring"
                                                data-sbi-field
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
                    </div>

                    <aside class="lml-sbi__aside" aria-labelledby="lml-sbi-types-heading">
                        <div class="lml-sbi__types-card">
                            <h3 id="lml-sbi-types-heading" class="lml-sbi__types-title">
                                <i class="bi bi-shield-plus" aria-hidden="true"></i>
                                <span>Vaccines Type</span>
                            </h3>
                            <div class="lml-sbi__types-groups">
                                @foreach ($vaccineTypeGroups as $group)
                                    <fieldset class="lml-sbi__types-fieldset">
                                        <legend class="lml-sbi__types-legend">
                                            {{ $group['legend'] }}
                                        </legend>
                                        <ul class="lml-sbi__types-list">
                                            @foreach ($group['items'] as $item)
                                                @php
                                                    $checkboxId = 'lml-sbi-type-'.$item['key'];
                                                @endphp
                                                <li>
                                                    <label class="lml-sbi__type-row" for="{{ $checkboxId }}">
                                                        <input
                                                            type="checkbox"
                                                            id="{{ $checkboxId }}"
                                                            name="vaccine_types[]"
                                                            value="{{ $item['value'] }}"
                                                            class="lml-sbi__type-checkbox lml-focus-ring"
                                                            data-sbi-field
                                                            @checked(in_array($item['value'], $selectedVaccineTypes, true))
                                                            disabled
                                                        >
                                                        <span class="lml-sbi__type-label">{{ $item['label'] }}</span>
                                                    </label>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </fieldset>
                                @endforeach
                            </div>
                        </div>
                    </aside>
                </div>
            </form>
            @endif
        @endif
    </div>
@endsection
