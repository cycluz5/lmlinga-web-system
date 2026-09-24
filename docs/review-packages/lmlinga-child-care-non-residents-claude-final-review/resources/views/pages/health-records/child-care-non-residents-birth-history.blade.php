{{-- Non-Resident Birth History edit (isolated). Reuses frozen Birth History JS via data-lml-bh-edit. --}}
@extends('layouts.dashboard')

@section('title', ($child['full_name'] ?? 'Child') . ' — Birth History - LMLinga')

@section('content')
    @php
        $from = $returnTo ?? 'immunization';
        $showUrl = isset($child['key'])
            ? route('health-records.child-care.non-residents.show', ['childKey' => $child['key']])
            : route('health-records.child-care.non-residents.index');
        $backUrl = match ($from) {
            'sbi' => isset($child['key'])
                ? route('health-records.child-care.non-residents.school-based-immunization', ['childKey' => $child['key']])
                : $showUrl,
            'child-nutrition' => isset($child['key'])
                ? route('health-records.child-care.non-residents.child-nutrition', ['childKey' => $child['key']])
                : $showUrl,
            default => isset($child['key'])
                ? route('health-records.child-care.non-residents.immunization', ['childKey' => $child['key']])
                : $showUrl,
        };
        $emptyRecord = 'No record';
        $pcabOptions = [
            'at_least_2_doses_1_month_prior' => 'At least 2 doses received at least 1 month prior to delivery',
            'tt3_td3_to_tt5_td5_prior' => 'TT3/TD3 – TT5/TD5 given to the mother anytime prior to delivery',
        ];
    @endphp

    <div class="lml-hr-cc-nr" data-lml-hr-cc-nr data-lml-hr-cc-nr-mode="birth-history">
        <div class="lml-hr-cc-nr__toast" data-hr-cc-nr-toast role="status" aria-live="polite" hidden></div>

        <a href="{{ $backUrl }}" class="lml-hr-cc-nr__page-back lml-focus-ring" aria-label="Back to child record module">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </a>

        @include('pages.health-records.partials.child-care-non-residents-profile', ['child' => $child])

        @if ($child)
            <div
                class="lml-bh-edit lml-hr-cc-nr__module"
                data-lml-bh-edit
                data-demo="true"
                data-persistence="preview"
                data-household-no="nr"
                data-member-id="{{ $child['key'] }}"
                data-return-url="{{ $backUrl }}"
            >
                <section
                    id="lml-child-imm-birth-editor"
                    class="lml-child-imm__birth-editor"
                    data-child-imm-birth-editor
                    data-persistence="preview"
                    aria-labelledby="lml-child-imm-birth-editor-heading"
                >
                    <form class="lml-child-imm__birth-editor-form" data-child-imm-birth-form data-persistence="preview" novalidate>
                        <header class="lml-child-imm__birth-editor-head">
                            <h2 id="lml-child-imm-birth-editor-heading" class="lml-child-imm__birth-editor-title" tabindex="-1">
                                <span class="lml-child-imm__birth-editor-icon" aria-hidden="true"><i class="bi bi-person"></i></span>
                                <span aria-hidden="true">Birth History</span>
                                <span class="visually-hidden">Edit Birth History form</span>
                            </h2>
                        </header>

                        <div class="lml-child-imm__birth-editor-body">
                            <div class="lml-child-imm__birth-editor-grid">
                                <div class="lml-child-imm__birth-field lml-child-imm__birth-field--third">
                                    <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-last-name">Last Name</label>
                                    <input type="text" id="lml-child-imm-bh-last-name" class="lml-child-imm__birth-input lml-child-imm__birth-input--readonly lml-focus-ring" value="{{ $child['last_name'] }}" readonly autocomplete="off" aria-readonly="true">
                                </div>
                                <div class="lml-child-imm__birth-field lml-child-imm__birth-field--third">
                                    <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-first-name">First Name</label>
                                    <input type="text" id="lml-child-imm-bh-first-name" class="lml-child-imm__birth-input lml-child-imm__birth-input--readonly lml-focus-ring" value="{{ $child['first_name'] }}" readonly autocomplete="off" aria-readonly="true">
                                </div>
                                <div class="lml-child-imm__birth-field lml-child-imm__birth-field--third">
                                    <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-middle-name">Middle Name</label>
                                    <input type="text" id="lml-child-imm-bh-middle-name" class="lml-child-imm__birth-input lml-child-imm__birth-input--readonly lml-focus-ring" value="{{ $child['middle_name'] }}" readonly autocomplete="off" aria-readonly="true">
                                </div>
                                <div class="lml-child-imm__birth-field lml-child-imm__birth-field--half">
                                    <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-dob">Date of Birth</label>
                                    <input type="text" id="lml-child-imm-bh-dob" class="lml-child-imm__birth-input lml-child-imm__birth-input--readonly lml-focus-ring" value="{{ $child['birthday_label'] }}" readonly autocomplete="off" aria-readonly="true">
                                </div>
                                <div class="lml-child-imm__birth-field lml-child-imm__birth-field--half">
                                    <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-sex">Sex</label>
                                    <input type="text" id="lml-child-imm-bh-sex" class="lml-child-imm__birth-input lml-child-imm__birth-input--readonly lml-focus-ring" value="{{ $child['sex'] }}" readonly autocomplete="off" aria-readonly="true">
                                </div>
                                <div class="lml-child-imm__birth-field lml-child-imm__birth-field--half">
                                    <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-weight">Birth Weight</label>
                                    <input type="number" id="lml-child-imm-bh-weight" name="birth_weight" class="lml-child-imm__birth-input lml-focus-ring" data-child-imm-birth-field="weight" placeholder="Enter Weight in kg" inputmode="decimal" min="0" step="0.01" autocomplete="off">
                                </div>
                                <div class="lml-child-imm__birth-field lml-child-imm__birth-field--half">
                                    <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-length">Birth Length</label>
                                    <input type="number" id="lml-child-imm-bh-length" name="birth_length" class="lml-child-imm__birth-input lml-focus-ring" data-child-imm-birth-field="length" placeholder="Enter length in cm" inputmode="decimal" min="0" step="0.01" autocomplete="off">
                                </div>
                                <div class="lml-child-imm__birth-field lml-child-imm__birth-field--half">
                                    <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-pcab">PCAB from Neonatal Tetanus</label>
                                    <select id="lml-child-imm-bh-pcab" name="pcab" class="lml-child-imm__birth-select lml-focus-ring" data-child-imm-birth-field="pcab">
                                        <option value="">Select</option>
                                        @foreach ($pcabOptions as $pcabValue => $pcabLabel)
                                            <option value="{{ $pcabValue }}">{{ $pcabLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="lml-child-imm__birth-field lml-child-imm__birth-field--half">
                                    <label class="lml-child-imm__birth-label" for="lml-child-imm-bh-breastfeeding">Initiated Breast Feeding Date</label>
                                    <input type="date" id="lml-child-imm-bh-breastfeeding" name="breastfeeding_date" class="lml-child-imm__birth-input lml-focus-ring" data-child-imm-birth-field="breastfeeding_date" autocomplete="off">
                                </div>
                            </div>

                            <div class="lml-child-imm__birth-editor-actions">
                                <a href="{{ $backUrl }}" class="lml-child-imm__birth-btn lml-child-imm__birth-btn--close lml-focus-ring" data-child-imm-birth-close aria-label="Close birth history editor">Close</a>
                                <button type="submit" class="lml-child-imm__birth-btn lml-child-imm__birth-btn--save lml-focus-ring" data-child-imm-birth-save aria-label="Save birth history">Save</button>
                            </div>
                        </div>
                    </form>
                </section>
            </div>
        @endif
    </div>
@endsection
