{{--
    Household Profiling — Child Nutrition destination.
    Continuous vertical scroll. Persisted residents use DB-backed nutrition saves.
    Demo-only members remain preview-safe.

    New Born status is derived in-page from member sex + Weight/Length at Birth
    (approved age-0 thresholds). Iron / Vitamin A / MNP / LNS-SQ chips stay empty
    ("No record") until a real completion contract exists. Overall Status and
    Latest Assessment remain stubs without a Nutritional Status / Timbang source.
    Nutrition Program Iron / Vitamin A dates read from hydrated $nutritionState.
    Birth History editing lives on the dedicated Child Immunization Birth
    History page. ERD-supported Birth History fields persist on child_nutrition
    (legacy: child_nutritions newborn_* columns). PCAB is not an ERD column.
--}}
@extends('layouts.dashboard')

@section('title', ($demoMember['name'] ?? 'Member') . ' — Child Nutrition - LMLinga')

@section('content')
    @php
        $emptyRecord = 'No record';
        $memberName = (string) ($demoMember['name'] ?? 'Member');
        $memberSex = (string) ($demoMember['sex'] ?? '');
        $memberAge = $demoMember['age'] ?? null;
        $childDobIso = '';
        $rawBirthday = data_get($demoMember, 'birthday');
        if (filled($rawBirthday)) {
            try {
                $childDobIso = \Carbon\Carbon::parse($rawBirthday)->format('Y-m-d');
            } catch (\Throwable) {
                $childDobIso = '';
            }
        }
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

        $storeUrl = route('household-profiling.members.child-nutrition.store', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);

        $persistenceSource = $persistenceSource ?? 'preview';
        $isDbPersisted = in_array($persistenceSource, ['db', 'local'], true);
        $nutritionState = is_array($nutritionState ?? null) ? $nutritionState : null;

        $sfpOutcomes = [
            ['key' => 'identified', 'label' => 'Identified'],
            ['key' => 'enrolled', 'label' => 'Enrolled'],
            ['key' => 'cured', 'label' => 'Cured'],
            ['key' => 'non-cured', 'label' => 'Non-Cured'],
            ['key' => 'default', 'label' => 'Default'],
            ['key' => 'died', 'label' => 'Died'],
        ];

        $vitaminAFields = [
            [
                'key' => 'va-6-11',
                'label' => '100,000 IU (6–11 Months)',
                'caption' => 'Date',
            ],
            [
                'key' => 'va-12-59-1',
                'label' => '200,000 IU (12–59 Months)',
                'caption' => 'Date (1st Dose)',
            ],
            [
                'key' => 'va-12-59-2',
                'label' => '200,000 IU (12–59 Months)',
                'caption' => 'Date (2nd Dose)',
            ],
        ];

        $mnpFields = [
            ['key' => 'mnp-6-11', 'label' => '6–11 Months'],
            ['key' => 'mnp-12-23', 'label' => '12–23 Months'],
        ];

        $lnsFields = [
            ['key' => 'lns-6-11', 'label' => '6–11 Months'],
            ['key' => 'lns-12-23', 'label' => '12–23 Months'],
        ];

        $formValues = [
            'newborn' => [
                'length' => (string) data_get($nutritionState, 'newborn.length', ''),
                'weight' => (string) data_get($nutritionState, 'newborn.weight', ''),
                'breastfeeding_date' => (string) data_get($nutritionState, 'newborn.breastfeeding_date', ''),
            ],
            'iron' => [
                '1st' => (string) data_get($nutritionState, 'iron.1st', ''),
                '2nd' => (string) data_get($nutritionState, 'iron.2nd', ''),
                '3rd' => (string) data_get($nutritionState, 'iron.3rd', ''),
            ],
            'vitamin_a' => [
                'va-6-11' => (string) data_get($nutritionState, 'vitamin_a.va-6-11', ''),
                'va-12-59-1' => (string) data_get($nutritionState, 'vitamin_a.va-12-59-1', ''),
                'va-12-59-2' => (string) data_get($nutritionState, 'vitamin_a.va-12-59-2', ''),
            ],
            'mnp' => [
                'mnp-6-11' => (string) data_get($nutritionState, 'mnp.mnp-6-11', ''),
                'mnp-12-23' => (string) data_get($nutritionState, 'mnp.mnp-12-23', ''),
            ],
            'lns_sq' => [
                'lns-6-11' => (string) data_get($nutritionState, 'lns_sq.lns-6-11', ''),
                'lns-12-23' => (string) data_get($nutritionState, 'lns_sq.lns-12-23', ''),
            ],
            'mam' => [],
            'sam' => [],
        ];

        foreach (['mam', 'sam'] as $programKey) {
            foreach ($sfpOutcomes as $outcome) {
                $formValues[$programKey][$outcome['key']] = [
                    'date' => (string) data_get($nutritionState, $programKey.'.'.$outcome['key'].'.date', ''),
                    'action' => (string) data_get($nutritionState, $programKey.'.'.$outcome['key'].'.action', ''),
                ];
            }
        }

        if (
            old('newborn') !== null
            || old('iron') !== null
            || old('vitamin_a') !== null
            || old('mnp') !== null
            || old('lns_sq') !== null
            || old('mam') !== null
            || old('sam') !== null
        ) {
            foreach (['length', 'weight', 'breastfeeding_date'] as $nbKey) {
                if (old('newborn.'.$nbKey) !== null) {
                    $formValues['newborn'][$nbKey] = (string) old('newborn.'.$nbKey);
                }
            }
            foreach (['1st', '2nd', '3rd'] as $ironKey) {
                if (old('iron.'.$ironKey) !== null) {
                    $formValues['iron'][$ironKey] = (string) old('iron.'.$ironKey);
                }
            }
            foreach (['va-6-11', 'va-12-59-1', 'va-12-59-2'] as $vaKey) {
                if (old('vitamin_a.'.$vaKey) !== null) {
                    $formValues['vitamin_a'][$vaKey] = (string) old('vitamin_a.'.$vaKey);
                }
            }
            foreach (['mnp-6-11', 'mnp-12-23'] as $mnpKey) {
                if (old('mnp.'.$mnpKey) !== null) {
                    $formValues['mnp'][$mnpKey] = (string) old('mnp.'.$mnpKey);
                }
            }
            foreach (['lns-6-11', 'lns-12-23'] as $lnsKey) {
                if (old('lns_sq.'.$lnsKey) !== null) {
                    $formValues['lns_sq'][$lnsKey] = (string) old('lns_sq.'.$lnsKey);
                }
            }
            foreach (['mam', 'sam'] as $programKey) {
                foreach ($sfpOutcomes as $outcome) {
                    $dateOld = old($programKey.'.'.$outcome['key'].'.date');
                    if ($dateOld !== null) {
                        $formValues[$programKey][$outcome['key']]['date'] = (string) $dateOld;
                    }
                    $actionOld = old($programKey.'_'.$outcome['key']);
                    if ($actionOld !== null) {
                        $formValues[$programKey][$outcome['key']]['action'] = (string) $actionOld;
                    }
                }
            }
        }

        $programIronIso = \App\Support\ChildNutritionService::latestMeaningfulDate($formValues['iron']);
        $programVa611Iso = \App\Support\ChildNutritionService::latestMeaningfulDate([
            'va-6-11' => $formValues['vitamin_a']['va-6-11'] ?? '',
        ]);
        $programVa1259Iso = \App\Support\ChildNutritionService::latestMeaningfulDate([
            'va-12-59-1' => $formValues['vitamin_a']['va-12-59-1'] ?? '',
            'va-12-59-2' => $formValues['vitamin_a']['va-12-59-2'] ?? '',
        ]);
        $programIronDisplay = $programIronIso !== null
            ? \App\Support\ChildNutritionService::formatPanelDate($programIronIso)
            : $emptyRecord;
        $programVa611Display = $programVa611Iso !== null
            ? \App\Support\ChildNutritionService::formatPanelDate($programVa611Iso)
            : $emptyRecord;
        $programVa1259Display = $programVa1259Iso !== null
            ? \App\Support\ChildNutritionService::formatPanelDate($programVa1259Iso)
            : $emptyRecord;
    @endphp

    <div
        class="lml-child-nut"
        data-lml-child-nut
        data-demo="{{ $isDbPersisted ? 'false' : 'true' }}"
        data-household-no="{{ $householdNo }}"
        data-member-id="{{ $memberId }}"
        data-member-sex="{{ $memberSex }}"
        @if ($demoMember)
            data-member-name="{{ $demoMember['name'] }}"
        @endif
        @if (session('status'))
            data-saved-message="{{ session('status') }}"
        @endif
    >
        <div
            class="lml-child-nut__toast"
            data-child-nut-toast
            role="status"
            aria-live="polite"
            hidden
        ></div>

        <a
            href="{{ $backUrl }}"
            class="lml-child-nut__back lml-focus-ring"
            aria-label="Back to Health Summary Records for {{ $memberName }}"
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span>Back</span>
        </a>

        @if (! $demoHousehold || ! $demoMember)
            <section class="lml-child-nut__not-found" aria-labelledby="lml-child-nut-nf-title">
                <h2 id="lml-child-nut-nf-title" class="lml-child-nut__not-found-title">
                    Member not found
                </h2>
                <p class="lml-child-nut__not-found-message">
                    @if (! $demoHousehold)
                        No record found.
                    @else
                        No record found.
                    @endif
                </p>
                <a
                    href="{{ $demoHousehold ? route('household-profiling.view', ['householdNo' => $householdNo]) : route('household-profiling.index') }}"
                    class="lml-child-nut__not-found-link lml-focus-ring"
                >
                    {{ $demoHousehold ? 'Back to Household' : 'Return to Household List' }}
                </a>
            </section>
        @else
            <article class="lml-child-imm__summary lml-child-nut__summary" aria-labelledby="lml-child-nut-member-name">
                <div class="lml-child-imm__summary-profile">
                    <span class="lml-child-imm__avatar" aria-hidden="true">
                        <i class="bi bi-person-fill"></i>
                    </span>
                    <div class="lml-child-imm__summary-identity">
                        <p id="lml-child-nut-member-name" class="lml-child-imm__member-name">
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

                <div class="lml-child-imm__birth-history" aria-labelledby="lml-child-nut-birth-heading">
                    <div class="lml-child-imm__birth-head">
                        <h2 id="lml-child-nut-birth-heading" class="lml-child-imm__birth-title">
                            <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                            <span>Birth History</span>
                        </h2>
                        <a
                            href="{{ $birthHistoryEditUrl }}"
                            class="lml-child-imm__birth-edit-link lml-focus-ring"
                            data-child-nut-birth-edit-link
                            aria-label="Edit birth history"
                        >
                            <i class="bi bi-pencil-square" aria-hidden="true"></i>
                            <span>Edit</span>
                        </a>
                    </div>
                    <dl class="lml-child-imm__birth-dl" data-child-nut-birth-summary>
                        @foreach ($birthHistory as $key => $item)
                            <div class="lml-child-imm__birth-item">
                                <dt>{{ $item['label'] }}</dt>
                                <dd data-birth-summary="{{ $key }}">{{ $item['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </article>

            {{-- Inline Child Nutrition edit form: DB-backed for persisted residents; preview-only otherwise. --}}
            <form
                class="lml-child-nut__records"
                data-child-nut-records
                data-editing="false"
                data-persistence="{{ $persistenceSource }}"
                @if ($isDbPersisted)
                    action="{{ $storeUrl }}"
                    method="post"
                    @include('offline.form-attrs', [
                        'offlineOperation' => 'HEALTH_SERVICE_WRITE',
                        'offlineHealthAction' => 'child_nutrition_store',
                        'offlineHouseholdNo' => $householdNo,
                        'offlineMemberNo' => $memberId,
                    ])
                @else
                    method="get"
                @endif
                aria-labelledby="lml-child-nut-heading"
                novalidate
            >
                @if ($isDbPersisted)
                    @csrf
                @endif

                @if ($errors->any())
                    <div class="lml-child-nut__errors" role="alert">
                        <p class="lml-child-nut__errors-title">Please correct the following:</p>
                        <ul class="lml-child-nut__errors-list">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <div class="lml-child-nut__records-head">
                    <div class="lml-child-nut__records-intro">
                        <h2 id="lml-child-nut-heading" class="lml-child-nut__records-title">
                            <i class="bi bi-heart-pulse" aria-hidden="true"></i>
                            <span>Child Nutrition</span>
                        </h2>
                        <p class="lml-child-nut__records-desc">
                            Monitor child growth, nutrition, and supplementation records.
                        </p>
                    </div>
                    <div class="lml-child-nut__records-actions">
                        <button
                            type="button"
                            class="lml-child-nut__edit lml-focus-ring"
                            data-child-nut-edit
                            aria-label="Edit child nutrition"
                        >
                            <i class="bi bi-pencil-square" aria-hidden="true"></i>
                            <span>Edit</span>
                        </button>
                        <button
                            type="submit"
                            class="lml-child-nut__save lml-focus-ring"
                            data-child-nut-save
                            aria-label="Save child nutrition"
                            hidden
                        >
                            <span>Save</span>
                        </button>
                    </div>
                </div>

                <div class="lml-child-nut__body">
                    <div class="lml-child-nut__main">
                        {{-- Capstone Figma: New Born and Iron are separate bordered cards. --}}
                        <section
                            class="lml-child-nut__card"
                            id="lml-child-nut-newborn"
                            aria-labelledby="lml-child-nut-newborn-heading"
                        >
                            <div class="lml-child-nut__section-head">
                                <h3 id="lml-child-nut-newborn-heading" class="lml-child-nut__section-title">
                                    New Born (0–28 Days Old)
                                </h3>
                            </div>
                            {{--
                                Figma Capstone layout: Length + Weight + Status on one row;
                                Initiated Breastfeeding Date on the row below.
                                Status remains JS-derived (not hardcoded NORMAL).
                            --}}
                            <div class="lml-child-nut__newborn-top">
                                <div class="lml-child-nut__field">
                                    <label class="lml-child-nut__label" for="lml-child-nut-nb-length">
                                        Length at Birth (cm)
                                    </label>
                                    <input
                                        type="number"
                                        id="lml-child-nut-nb-length"
                                        name="newborn[length]"
                                        value="{{ $formValues['newborn']['length'] }}"
                                        class="lml-child-nut__input lml-focus-ring"
                                        data-child-nut-field
                                        data-child-nut-newborn-metric="height"
                                        inputmode="decimal"
                                        min="0"
                                        step="0.01"
                                        autocomplete="off"
                                        disabled
                                    >
                                </div>
                                <div class="lml-child-nut__field">
                                    <label class="lml-child-nut__label" for="lml-child-nut-nb-weight">
                                        Weight at Birth (kg)
                                    </label>
                                    <input
                                        type="number"
                                        id="lml-child-nut-nb-weight"
                                        name="newborn[weight]"
                                        value="{{ $formValues['newborn']['weight'] }}"
                                        class="lml-child-nut__input lml-focus-ring"
                                        data-child-nut-field
                                        data-child-nut-newborn-metric="weight"
                                        inputmode="decimal"
                                        min="0"
                                        step="0.01"
                                        autocomplete="off"
                                        disabled
                                    >
                                </div>
                                <aside class="lml-child-nut__mini-status" aria-label="New born status">
                                    <span class="lml-child-nut__mini-status-title">Status</span>
                                    <span
                                        class="lml-child-nut__demo-status lml-child-nut__demo-status--empty"
                                        data-child-nut-newborn-status
                                        data-result="no_record"
                                    >
                                        <i class="bi bi-dash-lg" aria-hidden="true"></i>
                                        <span data-child-nut-newborn-status-label>No record</span>
                                    </span>
                                </aside>
                            </div>
                            <div class="lml-child-nut__field lml-child-nut__field--breastfeeding">
                                <label class="lml-child-nut__label" for="lml-child-nut-nb-breastfeeding">
                                    Initiated Breastfeeding Date
                                </label>
                                <input
                                    type="date"
                                    id="lml-child-nut-nb-breastfeeding"
                                    name="newborn[breastfeeding_date]"
                                    value="{{ $formValues['newborn']['breastfeeding_date'] }}"
                                    class="lml-child-nut__input lml-focus-ring"
                                    data-child-nut-field
                                    @if ($childDobIso !== '') min="{{ $childDobIso }}" @endif
                                    max="{{ now()->toDateString() }}"
                                    autocomplete="off"
                                    readonly
                                    disabled
                                >
                            </div>
                        </section>

                        <section
                            class="lml-child-nut__card"
                            id="lml-child-nut-iron"
                            aria-labelledby="lml-child-nut-iron-heading"
                        >
                            <div class="lml-child-nut__section-head">
                                <div class="lml-child-nut__iron-title-wrap">
                                    <h3 id="lml-child-nut-iron-heading" class="lml-child-nut__section-title">
                                        Iron
                                    </h3>
                                    <p class="lml-child-nut__section-note lml-child-nut__section-note--iron">
                                        <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                                        <span>For Low Birth Only</span>
                                    </p>
                                </div>
                                <span
                                    class="lml-child-nut__demo-status lml-child-nut__demo-status--empty"
                                    data-child-nut-program-status="iron"
                                >
                                    <i class="bi bi-dash-lg" aria-hidden="true"></i>
                                    <span>No record</span>
                                </span>
                            </div>
                            <div class="lml-child-nut__field-grid lml-child-nut__field-grid--thirds">
                                @foreach (['1st Month' => '1st', '2nd Month' => '2nd', '3rd Month' => '3rd'] as $ironLabel => $ironKey)
                                    @php $ironId = 'lml-child-nut-iron-'.$ironKey; @endphp
                                    <div class="lml-child-nut__field">
                                        <label class="lml-child-nut__label" for="{{ $ironId }}">
                                            {{ $ironLabel }}
                                        </label>
                                        <span class="lml-child-nut__date-caption" id="{{ $ironId }}-caption">
                                            Date
                                        </span>
                                        <input
                                            type="date"
                                            id="{{ $ironId }}"
                                            name="iron[{{ $ironKey }}]"
                                            value="{{ $formValues['iron'][$ironKey] }}"
                                            class="lml-child-nut__input lml-focus-ring"
                                            data-child-nut-field
                                            aria-describedby="{{ $ironId }}-caption"
                                            @if ($childDobIso !== '') min="{{ $childDobIso }}" @endif
                                            autocomplete="off"
                                            readonly
                                            disabled
                                        >
                                    </div>
                                @endforeach
                            </div>
                        </section>

                        {{-- Supplementation --}}
                        <section
                            class="lml-child-nut__card"
                            id="lml-child-nut-supplementation"
                            aria-labelledby="lml-child-nut-supp-heading"
                        >
                            <h3 id="lml-child-nut-supp-heading" class="lml-child-nut__card-title">
                                Supplementation
                            </h3>

                            <div class="lml-child-nut__section" aria-labelledby="lml-child-nut-vita-heading">
                                <div class="lml-child-nut__section-head">
                                    <h4 id="lml-child-nut-vita-heading" class="lml-child-nut__subsection-title">
                                        Vitamin A
                                    </h4>
                                    <span
                                        class="lml-child-nut__demo-status lml-child-nut__demo-status--empty"
                                        data-child-nut-program-status="vitamin-a"
                                    >
                                        <i class="bi bi-dash-lg" aria-hidden="true"></i>
                                        <span>No record</span>
                                    </span>
                                </div>
                                <div class="lml-child-nut__field-grid lml-child-nut__field-grid--thirds">
                                    @foreach ($vitaminAFields as $field)
                                        @php $fieldId = 'lml-child-nut-'.$field['key']; @endphp
                                        <div class="lml-child-nut__field">
                                            <label class="lml-child-nut__label" for="{{ $fieldId }}">
                                                {{ $field['label'] }}
                                            </label>
                                            <span class="lml-child-nut__date-caption" id="{{ $fieldId }}-caption">
                                                {{ $field['caption'] }}
                                            </span>
                                            <input
                                                type="date"
                                                id="{{ $fieldId }}"
                                                name="vitamin_a[{{ $field['key'] }}]"
                                                value="{{ $formValues['vitamin_a'][$field['key']] }}"
                                                class="lml-child-nut__input lml-focus-ring"
                                                data-child-nut-field
                                                aria-describedby="{{ $fieldId }}-caption"
                                                @if ($childDobIso !== '') min="{{ $childDobIso }}" @endif
                                                autocomplete="off"
                                                readonly
                                                disabled
                                            >
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="lml-child-nut__section" aria-labelledby="lml-child-nut-mnp-heading">
                                <div class="lml-child-nut__section-head">
                                    <h4 id="lml-child-nut-mnp-heading" class="lml-child-nut__subsection-title">
                                        MNP
                                    </h4>
                                    <span
                                        class="lml-child-nut__demo-status lml-child-nut__demo-status--empty"
                                        data-child-nut-program-status="mnp"
                                    >
                                        <i class="bi bi-dash-lg" aria-hidden="true"></i>
                                        <span>No record</span>
                                    </span>
                                </div>
                                <div class="lml-child-nut__field-grid">
                                    @foreach ($mnpFields as $field)
                                        @php $fieldId = 'lml-child-nut-'.$field['key']; @endphp
                                        <div class="lml-child-nut__field">
                                            <label class="lml-child-nut__label" for="{{ $fieldId }}">
                                                {{ $field['label'] }}
                                            </label>
                                            <span class="lml-child-nut__date-caption" id="{{ $fieldId }}-caption">
                                                Date
                                            </span>
                                            <input
                                                type="date"
                                                id="{{ $fieldId }}"
                                                name="mnp[{{ $field['key'] }}]"
                                                value="{{ $formValues['mnp'][$field['key']] }}"
                                                class="lml-child-nut__input lml-focus-ring"
                                                data-child-nut-field
                                                aria-describedby="{{ $fieldId }}-caption"
                                                @if ($childDobIso !== '') min="{{ $childDobIso }}" @endif
                                                autocomplete="off"
                                                readonly
                                                disabled
                                            >
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="lml-child-nut__section" aria-labelledby="lml-child-nut-lns-heading">
                                <div class="lml-child-nut__section-head">
                                    <h4 id="lml-child-nut-lns-heading" class="lml-child-nut__subsection-title">
                                        LNS-SQ
                                    </h4>
                                    <span
                                        class="lml-child-nut__demo-status lml-child-nut__demo-status--empty"
                                        data-child-nut-program-status="lns"
                                    >
                                        <i class="bi bi-dash-lg" aria-hidden="true"></i>
                                        <span>No record</span>
                                    </span>
                                </div>
                                <div class="lml-child-nut__field-grid">
                                    @foreach ($lnsFields as $field)
                                        @php $fieldId = 'lml-child-nut-'.$field['key']; @endphp
                                        <div class="lml-child-nut__field">
                                            <label class="lml-child-nut__label" for="{{ $fieldId }}">
                                                {{ $field['label'] }}
                                            </label>
                                            <span class="lml-child-nut__date-caption" id="{{ $fieldId }}-caption">
                                                Date
                                            </span>
                                            <input
                                                type="date"
                                                id="{{ $fieldId }}"
                                                name="lns_sq[{{ $field['key'] }}]"
                                                value="{{ $formValues['lns_sq'][$field['key']] }}"
                                                class="lml-child-nut__input lml-focus-ring"
                                                data-child-nut-field
                                                aria-describedby="{{ $fieldId }}-caption"
                                                @if ($childDobIso !== '') min="{{ $childDobIso }}" @endif
                                                autocomplete="off"
                                                readonly
                                                disabled
                                            >
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </section>

                        {{-- Supplementary Feeding Program --}}
                        <section
                            class="lml-child-nut__card"
                            id="lml-child-nut-sfp"
                            aria-labelledby="lml-child-nut-sfp-heading"
                        >
                            <h3 id="lml-child-nut-sfp-heading" class="lml-child-nut__card-title">
                                Supplementary Feeding Program
                            </h3>

                            @foreach ([
                                'mam' => [
                                    'title' => 'MAM',
                                    'subtitle' => 'Moderate Acute Malnutrition',
                                    'modifier' => 'mam',
                                ],
                                'sam' => [
                                    'title' => 'SAM',
                                    'subtitle' => 'Severe Acute Malnutrition',
                                    'modifier' => 'sam',
                                ],
                            ] as $programKey => $program)
                                <div
                                    class="lml-child-nut__sfp lml-child-nut__sfp--{{ $program['modifier'] }}"
                                    aria-labelledby="lml-child-nut-{{ $programKey }}-heading"
                                >
                                    <h4 id="lml-child-nut-{{ $programKey }}-heading" class="lml-child-nut__sfp-title">
                                        <span class="lml-child-nut__sfp-abbr">{{ $program['title'] }}</span>
                                        <span class="lml-child-nut__sfp-full">({{ $program['subtitle'] }})</span>
                                    </h4>

                                    <div class="lml-child-nut__sfp-table" role="table" aria-label="{{ $program['title'] }} program statuses">
                                        <div class="lml-child-nut__sfp-head" role="row">
                                            <span class="lml-child-nut__sfp-col lml-child-nut__sfp-col--status" role="columnheader">
                                                Program Status
                                            </span>
                                            <span class="lml-child-nut__sfp-col lml-child-nut__sfp-col--date" role="columnheader">
                                                Date
                                            </span>
                                            <span class="lml-child-nut__sfp-col lml-child-nut__sfp-col--action" role="columnheader">
                                                Action (Yes / No)
                                            </span>
                                        </div>

                                        @foreach ($sfpOutcomes as $outcome)
                                            @php
                                                $rowId = 'lml-child-nut-'.$programKey.'-'.$outcome['key'];
                                                $dateId = $rowId.'-date';
                                                $yesId = $rowId.'-yes';
                                                $noId = $rowId.'-no';
                                                $groupName = $programKey.'_'.$outcome['key'];
                                                $outcomeDate = (string) ($formValues[$programKey][$outcome['key']]['date'] ?? '');
                                                $outcomeAction = (string) ($formValues[$programKey][$outcome['key']]['action'] ?? '');
                                            @endphp
                                            <div class="lml-child-nut__sfp-row" role="row">
                                                <div class="lml-child-nut__sfp-col lml-child-nut__sfp-col--status" role="cell">
                                                    <span class="lml-child-nut__sfp-status-label">
                                                        {{ $outcome['label'] }}
                                                    </span>
                                                </div>
                                                <div class="lml-child-nut__sfp-col lml-child-nut__sfp-col--date" role="cell">
                                                    <label class="visually-hidden" for="{{ $dateId }}">
                                                        {{ $program['title'] }} {{ $outcome['label'] }} date
                                                    </label>
                                                    <input
                                                        type="date"
                                                        id="{{ $dateId }}"
                                                        name="{{ $programKey }}[{{ $outcome['key'] }}][date]"
                                                        value="{{ $outcomeDate }}"
                                                        class="lml-child-nut__input lml-focus-ring"
                                                        data-child-nut-field
                                                        @if ($childDobIso !== '') min="{{ $childDobIso }}" @endif
                                                        autocomplete="off"
                                                        readonly
                                                        disabled
                                                    >
                                                </div>
                                                <div class="lml-child-nut__sfp-col lml-child-nut__sfp-col--action" role="cell">
                                                    <fieldset class="lml-child-nut__yn" id="{{ $rowId }}-action">
                                                        <legend class="visually-hidden">
                                                            {{ $program['title'] }} {{ $outcome['label'] }} action
                                                        </legend>
                                                        <label class="lml-child-nut__yn-option" for="{{ $yesId }}">
                                                            <input
                                                                type="radio"
                                                                id="{{ $yesId }}"
                                                                name="{{ $groupName }}"
                                                                value="yes"
                                                                class="lml-child-nut__yn-input lml-focus-ring"
                                                                data-child-nut-field
                                                                @checked($outcomeAction === 'yes')
                                                                disabled
                                                            >
                                                            <span>Yes</span>
                                                        </label>
                                                        <label class="lml-child-nut__yn-option" for="{{ $noId }}">
                                                            <input
                                                                type="radio"
                                                                id="{{ $noId }}"
                                                                name="{{ $groupName }}"
                                                                value="no"
                                                                class="lml-child-nut__yn-input lml-focus-ring"
                                                                data-child-nut-field
                                                                @checked($outcomeAction === 'no')
                                                                disabled
                                                            >
                                                            <span>No</span>
                                                        </label>
                                                    </fieldset>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </section>
                    </div>

                    <aside
                        class="lml-child-nut__aside"
                        id="lml-child-nut-status-panel"
                        aria-labelledby="lml-child-nut-status-heading"
                    >
                        <div class="lml-child-nut__status-card">
                            <h3 id="lml-child-nut-status-heading" class="lml-child-nut__status-title">
                                <i class="bi bi-heart-fill" aria-hidden="true"></i>
                                <span>Child Nutrition Status</span>
                            </h3>

                            <dl class="lml-child-nut__status-dl">
                                <div class="lml-child-nut__status-block">
                                    <dt>Overall Status</dt>
                                    <dd data-child-nut-status-overall>
                                        <span class="lml-child-nut__demo-status lml-child-nut__demo-status--empty">
                                            <i class="bi bi-dash-lg" aria-hidden="true"></i>
                                            <span>No record</span>
                                        </span>
                                    </dd>
                                </div>

                                <div class="lml-child-nut__status-block">
                                    <dt>Latest Assessment</dt>
                                    <dd>
                                        <dl class="lml-child-nut__status-sub">
                                            <div>
                                                <dt>Date</dt>
                                                <dd>{{ $emptyRecord }}</dd>
                                            </div>
                                            <div>
                                                <dt>Weight</dt>
                                                <dd>{{ $emptyRecord }}</dd>
                                            </div>
                                            <div>
                                                <dt>Height</dt>
                                                <dd>{{ $emptyRecord }}</dd>
                                            </div>
                                            <div>
                                                <dt>MUAC</dt>
                                                <dd>{{ $emptyRecord }}</dd>
                                            </div>
                                        </dl>
                                    </dd>
                                </div>

                                <div class="lml-child-nut__status-block">
                                    <dt>Nutrition Program</dt>
                                    <dd>
                                        <dl class="lml-child-nut__status-sub">
                                            <div>
                                                <dt>Iron</dt>
                                                <dd data-child-nut-program-iron>{{ $programIronDisplay }}</dd>
                                            </div>
                                            <div>
                                                <dt>Vitamin A (6–11)</dt>
                                                <dd data-child-nut-program-va-6-11>{{ $programVa611Display }}</dd>
                                            </div>
                                            <div>
                                                <dt>Vitamin A (12–59)</dt>
                                                <dd data-child-nut-program-va-12-59>{{ $programVa1259Display }}</dd>
                                            </div>
                                        </dl>
                                    </dd>
                                </div>
                            </dl>

                            <p class="lml-child-nut__status-footer">--- Nothing Follows ---</p>
                        </div>
                    </aside>
                </div>
            </form>

            @include('pages.household-profiling.partials.child-nutrition-deworming')
        @endif
    </div>
@endsection
