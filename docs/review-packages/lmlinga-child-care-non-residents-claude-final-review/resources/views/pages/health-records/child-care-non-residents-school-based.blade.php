{{--
    Health Records → Child Care → Non-Residents School-Based Immunization.
    Isolated equivalent of the frozen Household Profiling destination.
    No age/school/grade eligibility gate (resident module has none).
--}}
@extends('layouts.dashboard')

@section('title', ($child['full_name'] ?? 'Child') . ' — School-Based Immunization - LMLinga')

@section('content')
    @php
        $showUrl = isset($child['key'])
            ? route('health-records.child-care.non-residents.show', ['childKey' => $child['key']])
            : route('health-records.child-care.non-residents.index');
        $birthHistoryEditUrl = isset($child['key'])
            ? route('health-records.child-care.non-residents.immunization.birth-history', [
                'childKey' => $child['key'],
                'from' => 'sbi',
            ])
            : $showUrl;
        $emptyRecord = 'No record';
        $gradeCards = [
            ['key' => 'grade-1', 'title' => 'Grade 1', 'doses' => [
                ['key' => 'td', 'label' => 'Tetanus Diphtheria (TD)', 'status' => null],
                ['key' => 'mr', 'label' => 'Measles Rubella', 'status' => null],
            ]],
            ['key' => 'grade-7', 'title' => 'Grade 7', 'doses' => [
                ['key' => 'td', 'label' => 'Tetanus Diphtheria (TD)', 'status' => null],
                ['key' => 'mr', 'label' => 'Measles Rubella', 'status' => null],
            ]],
        ];
        $hpvDoses = [
            ['key' => '1', 'label' => 'Human Papillomavirus (1st Dose)', 'status' => null],
            ['key' => '2', 'label' => 'Human Papillomavirus (2nd Dose)', 'status' => null],
        ];
        $vaccineTypeGroups = [
            ['key' => 'grade-1', 'legend' => 'GRADE 1', 'items' => [
                ['key' => 'g1-td', 'label' => 'Tetanus Diphtheria (TD)', 'value' => 'grade1_td'],
                ['key' => 'g1-mr', 'label' => 'Measles Rubella', 'value' => 'grade1_mr'],
            ]],
            ['key' => 'grade-7', 'legend' => 'GRADE 7', 'items' => [
                ['key' => 'g7-td', 'label' => 'Tetanus Diphtheria (TD)', 'value' => 'grade7_td'],
                ['key' => 'g7-mr', 'label' => 'Measles Rubella', 'value' => 'grade7_mr'],
            ]],
            ['key' => 'hpv', 'legend' => 'Human Papillomavirus', 'items' => [
                ['key' => 'hpv-1', 'label' => '1st Dose', 'value' => 'hpv_1'],
                ['key' => 'hpv-2', 'label' => '2nd Dose', 'value' => 'hpv_2'],
            ]],
        ];
    @endphp

    <div class="lml-hr-cc-nr" data-lml-hr-cc-nr data-lml-hr-cc-nr-mode="sbi">
        <div class="lml-hr-cc-nr__toast" data-hr-cc-nr-toast role="status" aria-live="polite" hidden></div>

        <a href="{{ $showUrl }}" class="lml-hr-cc-nr__page-back lml-focus-ring" aria-label="Back to child record">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </a>

        @include('pages.health-records.partials.child-care-non-residents-profile', ['child' => $child])

        @if ($child)
            <div
                class="lml-sbi lml-hr-cc-nr__module"
                data-lml-sbi
                data-demo="true"
                data-household-no="nr"
                data-member-id="{{ $child['key'] }}"
                data-member-name="{{ $child['full_name'] }}"
            >
                <div class="lml-sbi__toast" data-sbi-toast role="status" aria-live="polite" hidden></div>

                <article class="lml-child-imm__summary lml-child-imm__summary--birth-only">
                    @include('pages.health-records.partials.child-care-non-residents-birth-history', [
                        'editUrl' => $birthHistoryEditUrl,
                        'emptyRecord' => $emptyRecord,
                        'headingId' => 'lml-nr-sbi-birth-heading',
                        'summaryAttr' => 'data-sbi-birth-summary',
                        'editLinkAttr' => 'data-sbi-birth-edit-link',
                    ])
                </article>

                <form class="lml-sbi__records" data-sbi-records data-editing="false" data-persistence="preview" aria-labelledby="lml-sbi-heading" novalidate>
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
                            <button type="button" class="lml-sbi__edit lml-focus-ring" data-sbi-edit aria-label="Edit school-based immunization">
                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                <span>Edit</span>
                            </button>
                            <button type="submit" class="lml-sbi__save lml-focus-ring" data-sbi-save aria-label="Save school-based immunization" hidden>
                                <span>Save</span>
                            </button>
                        </div>
                    </div>

                    <div class="lml-sbi__body">
                        <div class="lml-sbi__main">
                            <div class="lml-sbi__grade-grid">
                                @foreach ($gradeCards as $card)
                                    <section class="lml-sbi__grade-card lml-sbi__grade-card--{{ $card['key'] }}" aria-labelledby="lml-sbi-{{ $card['key'] }}">
                                        <h3 id="lml-sbi-{{ $card['key'] }}" class="lml-sbi__grade-title">{{ $card['title'] }}</h3>
                                        <div class="lml-sbi__dose-list">
                                            @foreach ($card['doses'] as $dose)
                                                @php $fieldId = 'lml-nr-sbi-'.$card['key'].'-'.$dose['key']; @endphp
                                                <div class="lml-sbi__dose">
                                                    <div class="lml-sbi__dose-label-row">
                                                        <label class="lml-sbi__dose-label" for="{{ $fieldId }}">{{ $dose['label'] }}</label>
                                                    </div>
                                                    <div class="lml-sbi__date-wrap">
                                                        <span class="lml-sbi__date-caption" id="{{ $fieldId }}-caption">Date</span>
                                                        <input type="date" id="{{ $fieldId }}" name="vaccines[{{ $card['key'] }}][{{ $dose['key'] }}]" class="lml-sbi__date-input lml-focus-ring" data-sbi-field aria-describedby="{{ $fieldId }}-caption" autocomplete="off" readonly disabled>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </section>
                                @endforeach
                            </div>

                            <section class="lml-sbi__hpv-card" aria-labelledby="lml-sbi-hpv-heading">
                                <h3 id="lml-sbi-hpv-heading" class="lml-sbi__grade-title">Human Papillomavirus (HPV)</h3>
                                <div class="lml-sbi__dose-list lml-sbi__dose-list--hpv">
                                    @foreach ($hpvDoses as $dose)
                                        @php $fieldId = 'lml-nr-sbi-hpv-'.$dose['key']; @endphp
                                        <div class="lml-sbi__dose">
                                            <div class="lml-sbi__dose-label-row">
                                                <label class="lml-sbi__dose-label" for="{{ $fieldId }}">{{ $dose['label'] }}</label>
                                            </div>
                                            <div class="lml-sbi__date-wrap">
                                                <span class="lml-sbi__date-caption" id="{{ $fieldId }}-caption">Date</span>
                                                <input type="date" id="{{ $fieldId }}" name="vaccines[hpv][{{ $dose['key'] }}]" class="lml-sbi__date-input lml-focus-ring" data-sbi-field aria-describedby="{{ $fieldId }}-caption" autocomplete="off" readonly disabled>
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
                                            <legend class="lml-sbi__types-legend">{{ $group['legend'] }}</legend>
                                            <ul class="lml-sbi__types-list">
                                                @foreach ($group['items'] as $item)
                                                    @php $checkboxId = 'lml-nr-sbi-type-'.$item['key']; @endphp
                                                    <li>
                                                        <label class="lml-sbi__type-row" for="{{ $checkboxId }}">
                                                            <input type="checkbox" id="{{ $checkboxId }}" name="vaccine_types[]" value="{{ $item['value'] }}" class="lml-sbi__type-checkbox lml-focus-ring" data-sbi-field disabled>
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
            </div>
        @endif
    </div>
@endsection
