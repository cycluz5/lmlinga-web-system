{{--
    Household Profiling — Birth History dedicated edit page.
    Continuous vertical scroll. Demo fallback remains preview-safe (sessionStorage).
    DB-backed residents persist via POST when a persisted resident is resolved.
--}}
@extends('layouts.dashboard')

@section('title', ($demoMember['name'] ?? 'Member') . ' — Birth History - LMLinga')

@section('content')
    @php
        $emptyRecord = 'No record';
        $persistenceSource = $persistenceSource ?? 'preview';
        $isDbPersisted = in_array($persistenceSource, ['db', 'local'], true);
        $memberName = (string) ($demoMember['name'] ?? 'Member');
        $memberSex = (string) ($demoMember['sex'] ?? '');
        $memberAge = $demoMember['age'] ?? null;
        $dateBirth = $demoMember
            ? lml_demo_member_display($demoMember, 'birthday')
            : '';
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

        $memberLastName = (string) ($demoMember['last_name'] ?? '');
        $memberFirstName = (string) ($demoMember['first_name'] ?? '');
        $memberMiddleName = (string) ($demoMember['middle_name'] ?? '');
        $memberDobDisplay = $dateBirth !== '' ? $dateBirth : '';

        $birthWeightForm = filled(data_get($birthHistoryForm ?? null, 'weight'))
            ? (string) data_get($birthHistoryForm, 'weight')
            : (filled(data_get($demoMember, 'birth_history.weight'))
                ? (string) data_get($demoMember, 'birth_history.weight')
                : '');
        $birthLengthForm = filled(data_get($birthHistoryForm ?? null, 'length'))
            ? (string) data_get($birthHistoryForm, 'length')
            : (filled(data_get($demoMember, 'birth_history.length'))
                ? (string) data_get($demoMember, 'birth_history.length')
                : '');
        $birthPcabForm = filled(data_get($birthHistoryForm ?? null, 'pcab'))
            ? (string) data_get($birthHistoryForm, 'pcab')
            : (filled(data_get($demoMember, 'birth_history.pcab'))
                ? (string) data_get($demoMember, 'birth_history.pcab')
                : '');
        $birthBfDateForm = filled(data_get($birthHistoryForm ?? null, 'breastfeeding_date'))
            ? (string) data_get($birthHistoryForm, 'breastfeeding_date')
            : (filled(data_get($demoMember, 'birth_history.breastfeeding_date'))
                ? (string) data_get($demoMember, 'birth_history.breastfeeding_date')
                : '');

        $pcabOptions = [
            'at_least_2_doses_1_month_prior' => 'At least 2 doses received at least 1 month prior to delivery',
            'tt3_td3_to_tt5_td5_prior' => 'TT3/TD3 – TT5/TD5 given to the mother anytime prior to delivery',
        ];

        $sexBadgeClass = strtolower($memberSex) === 'female'
            ? 'lml-child-imm__sex-badge--female'
            : 'lml-child-imm__sex-badge--male';

        $backUrl = route('household-profiling.members.child-immunization', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);

        $storeUrl = $isDbPersisted
            ? route('household-profiling.members.child-immunization.birth-history.store', [
                'householdNo' => $householdNo,
                'memberId' => $memberId,
            ])
            : null;
    @endphp

    <div
        class="lml-bh-edit"
        data-lml-bh-edit
        data-demo="{{ $isDbPersisted ? 'false' : 'true' }}"
        data-persistence="{{ $persistenceSource }}"
        data-household-no="{{ $householdNo }}"
        data-member-id="{{ $memberId }}"
        data-return-url="{{ $backUrl }}"
        @if ($storeUrl)
            data-store-url="{{ $storeUrl }}"
        @endif
    >
        <a
            href="{{ $backUrl }}"
            class="lml-bh-edit__back lml-focus-ring"
            aria-label="Back to Child Immunization for {{ $memberName }}"
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span>Back</span>
        </a>

        @if (! $demoHousehold || ! $demoMember)
            <section class="lml-bh-edit__not-found" aria-labelledby="lml-bh-edit-nf-title">
                <h2 id="lml-bh-edit-nf-title" class="lml-bh-edit__not-found-title">
                    Member not found
                </h2>
                <p class="lml-bh-edit__not-found-message">
                    @if (! $demoHousehold)
                        No record found.
                    @else
                        No record found.
                    @endif
                </p>
                <a
                    href="{{ $demoHousehold ? route('household-profiling.view', ['householdNo' => $householdNo]) : route('household-profiling.index') }}"
                    class="lml-bh-edit__not-found-link lml-focus-ring"
                >
                    {{ $demoHousehold ? 'Back to Household' : 'Return to Household List' }}
                </a>
            </section>
        @else
            {{-- Informational member context only; no Edit control on this dedicated page. --}}
            <article
                class="lml-child-imm__summary lml-bh-edit__summary"
                data-bh-edit-member-summary
                aria-labelledby="lml-bh-edit-member-name"
            >
                <div class="lml-child-imm__summary-profile">
                    <span class="lml-child-imm__avatar" aria-hidden="true">
                        <i class="bi bi-person-fill"></i>
                    </span>
                    <div class="lml-child-imm__summary-identity">
                        <p id="lml-bh-edit-member-name" class="lml-child-imm__member-name">
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

                <div
                    class="lml-child-imm__birth-history"
                    aria-labelledby="lml-bh-edit-birth-summary-heading"
                >
                    <div class="lml-child-imm__birth-head">
                        <h2
                            id="lml-bh-edit-birth-summary-heading"
                            class="lml-child-imm__birth-title"
                        >
                            <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                            <span aria-hidden="true">Birth History</span>
                            <span class="visually-hidden">Birth History summary</span>
                        </h2>
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

            <section
                id="lml-child-imm-birth-editor"
                class="lml-child-imm__birth-editor"
                data-child-imm-birth-editor
                data-persistence="{{ $persistenceSource }}"
                aria-labelledby="lml-child-imm-birth-editor-heading"
            >
                <form
                    class="lml-child-imm__birth-editor-form"
                    data-child-imm-birth-form
                    data-persistence="{{ $persistenceSource }}"
                    @if ($isDbPersisted && $storeUrl)
                        method="post"
                        action="{{ $storeUrl }}"
                        @include('offline.form-attrs', [
                            'offlineOperation' => 'HEALTH_SERVICE_WRITE',
                            'offlineHealthAction' => 'child_birth_history_store',
                            'offlineHouseholdNo' => $householdNo,
                            'offlineMemberNo' => $memberId,
                        ])
                    @else
                        novalidate
                    @endif
                >
                    @if ($isDbPersisted && $storeUrl)
                        @csrf
                    @endif
                    <header class="lml-child-imm__birth-editor-head">
                        <h2
                            id="lml-child-imm-birth-editor-heading"
                            class="lml-child-imm__birth-editor-title"
                            tabindex="-1"
                        >
                            <span class="lml-child-imm__birth-editor-icon" aria-hidden="true">
                                <i class="bi bi-person"></i>
                            </span>
                            <span aria-hidden="true">Birth History</span>
                            <span class="visually-hidden">Edit Birth History form</span>
                        </h2>
                    </header>

                    <div class="lml-child-imm__birth-editor-body">
                        <div class="lml-child-imm__birth-editor-grid">
                            <div class="lml-child-imm__birth-field lml-child-imm__birth-field--third">
                                <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-last-name">
                                    Last Name
                                </label>
                                <input
                                    type="text"
                                    id="lml-child-imm-bh-last-name"
                                    class="lml-child-imm__birth-input lml-child-imm__birth-input--readonly lml-focus-ring"
                                    value="{{ $memberLastName }}"
                                    readonly
                                    autocomplete="off"
                                    aria-readonly="true"
                                >
                            </div>
                            <div class="lml-child-imm__birth-field lml-child-imm__birth-field--third">
                                <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-first-name">
                                    First Name
                                </label>
                                <input
                                    type="text"
                                    id="lml-child-imm-bh-first-name"
                                    class="lml-child-imm__birth-input lml-child-imm__birth-input--readonly lml-focus-ring"
                                    value="{{ $memberFirstName }}"
                                    readonly
                                    autocomplete="off"
                                    aria-readonly="true"
                                >
                            </div>
                            <div class="lml-child-imm__birth-field lml-child-imm__birth-field--third">
                                <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-middle-name">
                                    Middle Name
                                </label>
                                <input
                                    type="text"
                                    id="lml-child-imm-bh-middle-name"
                                    class="lml-child-imm__birth-input lml-child-imm__birth-input--readonly lml-focus-ring"
                                    value="{{ $memberMiddleName }}"
                                    readonly
                                    autocomplete="off"
                                    aria-readonly="true"
                                >
                            </div>

                            <div class="lml-child-imm__birth-field lml-child-imm__birth-field--half">
                                <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-dob">
                                    Date of Birth
                                </label>
                                <input
                                    type="text"
                                    id="lml-child-imm-bh-dob"
                                    class="lml-child-imm__birth-input lml-child-imm__birth-input--readonly lml-focus-ring"
                                    value="{{ $memberDobDisplay }}"
                                    readonly
                                    autocomplete="off"
                                    aria-readonly="true"
                                >
                            </div>
                            <div class="lml-child-imm__birth-field lml-child-imm__birth-field--half">
                                <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-sex">
                                    Sex
                                </label>
                                <input
                                    type="text"
                                    id="lml-child-imm-bh-sex"
                                    class="lml-child-imm__birth-input lml-child-imm__birth-input--readonly lml-focus-ring"
                                    value="{{ $memberSex }}"
                                    readonly
                                    autocomplete="off"
                                    aria-readonly="true"
                                >
                            </div>

                            <div class="lml-child-imm__birth-field lml-child-imm__birth-field--half">
                                <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-weight">
                                    Birth Weight
                                </label>
                                <input
                                    type="number"
                                    id="lml-child-imm-bh-weight"
                                    name="birth_weight"
                                    class="lml-child-imm__birth-input lml-focus-ring"
                                    data-child-imm-birth-field="weight"
                                    value="{{ $birthWeightForm }}"
                                    placeholder="Enter Weight in kg"
                                    inputmode="decimal"
                                    min="0"
                                    step="0.01"
                                    autocomplete="off"
                                >
                            </div>
                            <div class="lml-child-imm__birth-field lml-child-imm__birth-field--half">
                                <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-length">
                                    Birth Length
                                </label>
                                <input
                                    type="number"
                                    id="lml-child-imm-bh-length"
                                    name="birth_length"
                                    class="lml-child-imm__birth-input lml-focus-ring"
                                    data-child-imm-birth-field="length"
                                    value="{{ $birthLengthForm }}"
                                    placeholder="Enter length in cm"
                                    inputmode="decimal"
                                    min="0"
                                    step="0.01"
                                    autocomplete="off"
                                >
                            </div>

                            <div class="lml-child-imm__birth-field lml-child-imm__birth-field--half">
                                <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-pcab">
                                    CPAB from Neonatal Tetanus
                                </label>
                                <select
                                    id="lml-child-imm-bh-pcab"
                                    name="pcab"
                                    class="lml-child-imm__birth-select lml-focus-ring"
                                    data-child-imm-birth-field="pcab"
                                >
                                    <option value="" @selected($birthPcabForm === '')>Select</option>
                                    @foreach ($pcabOptions as $pcabValue => $pcabLabel)
                                        <option
                                            value="{{ $pcabValue }}"
                                            @selected($birthPcabForm === $pcabValue)
                                        >
                                            {{ $pcabLabel }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="lml-child-imm__birth-field lml-child-imm__birth-field--half">
                                <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-breastfeeding">
                                    Initiated Breast Feeding Date
                                </label>
                                <input
                                    type="date"
                                    id="lml-child-imm-bh-breastfeeding"
                                    name="breastfeeding_date"
                                    class="lml-child-imm__birth-input lml-focus-ring"
                                    data-child-imm-birth-field="breastfeeding_date"
                                    value="{{ $birthBfDateForm }}"
                                    max="{{ now()->toDateString() }}"
                                    autocomplete="off"
                                >
                            </div>
                        </div>

                        <div class="lml-child-imm__birth-editor-actions">
                            <a
                                href="{{ $backUrl }}"
                                class="lml-child-imm__birth-btn lml-child-imm__birth-btn--close lml-focus-ring"
                                data-child-imm-birth-close
                                aria-label="Close birth history editor"
                            >
                                Close
                            </a>
                            <button
                                type="submit"
                                class="lml-child-imm__birth-btn lml-child-imm__birth-btn--save lml-focus-ring"
                                data-child-imm-birth-save
                                aria-label="Save birth history"
                            >
                                Save
                            </button>
                        </div>
                    </div>
                </form>
            </section>
        @endif
    </div>
@endsection
