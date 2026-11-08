{{--
    Health Records → Child Care → Non-Residents Child Immunization.
    Isolated equivalent of the frozen Household Profiling destination.
    UI-preview only. Honest empty dose statuses (no fabricated recorded icons).
--}}
@extends('layouts.dashboard')

@section('title', ($child['full_name'] ?? 'Child') . ' — Child Immunization - LMLinga')

@section('content')
    @php
        $showUrl = isset($child['key'])
            ? route('health-records.child-care.non-residents.show', ['childKey' => $child['key']])
            : route('health-records.child-care.non-residents.index');
        $birthHistoryEditUrl = isset($child['key'])
            ? route('health-records.child-care.non-residents.immunization.birth-history', [
                'childKey' => $child['key'],
                'from' => 'immunization',
            ])
            : $showUrl;
        $emptyRecord = 'No record';
        $vaccineCards = [
            ['key' => 'bcg', 'title' => 'BCG', 'layout' => 'pair', 'doses' => [
                ['label' => '0-28 days', 'status' => null],
                ['label' => '29 days - 1 year old', 'status' => null],
            ]],
            ['key' => 'hepa-b', 'title' => 'Hepa B', 'layout' => 'pair', 'doses' => [
                ['label' => '24 hrs after birth', 'status' => null],
                ['label' => '24 hrs up to 14 days', 'status' => null],
            ]],
            ['key' => 'dpt-hib-hepb', 'title' => 'DPT-HIB-HepB', 'layout' => 'stack', 'doses' => [
                ['label' => '1st Dose (1 1/2 months)', 'status' => null],
                ['label' => '2nd Dose (2 1/2 months)', 'status' => null],
                ['label' => '3rd Dose (3 1/2 months)', 'status' => null],
            ]],
            ['key' => 'opv', 'title' => 'OPV', 'layout' => 'stack', 'doses' => [
                ['label' => '1st Dose (1 1/2 months)', 'status' => null],
                ['label' => '2nd Dose (2 1/2 months)', 'status' => null],
                ['label' => '3rd Dose (3 1/2 months)', 'status' => null],
            ]],
            ['key' => 'pcv', 'title' => 'PCV', 'layout' => 'stack', 'doses' => [
                ['label' => '1st Dose (1 1/2 months)', 'status' => null],
                ['label' => '2nd Dose (2 1/2 months)', 'status' => null],
                ['label' => '3rd Dose (3 1/2 months)', 'status' => null],
            ]],
            ['key' => 'ipv', 'title' => 'IPV', 'layout' => 'stack', 'doses' => [
                ['label' => '1st Dose (3 1/2 months)', 'status' => null],
                ['label' => '2nd Dose (9 months)', 'status' => null],
            ]],
            ['key' => 'mmr', 'title' => 'MMR', 'layout' => 'stack', 'doses' => [
                ['label' => '1st Dose (9 months)', 'status' => null],
                ['label' => '2nd Dose (12 months)', 'status' => null],
            ]],
        ];
        $completionCards = [
            ['key' => 'fic', 'title' => 'FIC', 'range' => '0–12 months', 'items' => [
                ['label' => 'BCG', 'doses' => '1 dose'],
                ['label' => 'OPV', 'doses' => '3 doses'],
                ['label' => 'DPT-HIB-HepB', 'doses' => '3 doses'],
                ['label' => 'MMR', 'doses' => '1 dose'],
            ]],
            ['key' => 'cic', 'title' => 'CIC', 'range' => '13–24 months', 'items' => [
                ['label' => 'BCG', 'doses' => '1 dose'],
                ['label' => 'OPV', 'doses' => '3 doses'],
                ['label' => 'DPT-HIB-HepB', 'doses' => '3 doses'],
                ['label' => 'MMR', 'doses' => '2 doses'],
            ]],
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
    @endphp

    <div class="lml-hr-cc-nr" data-lml-hr-cc-nr data-lml-hr-cc-nr-mode="immunization">
        <div class="lml-hr-cc-nr__toast" data-hr-cc-nr-toast role="status" aria-live="polite" hidden></div>

        <a href="{{ $showUrl }}" class="lml-hr-cc-nr__page-back lml-focus-ring" aria-label="Back to child record">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </a>

        @include('pages.health-records.partials.child-care-non-residents-profile', ['child' => $child])

        @if ($child)
            <div
                class="lml-child-imm lml-hr-cc-nr__module"
                data-lml-child-imm
                data-demo="true"
                data-household-no="nr"
                data-member-id="{{ $child['key'] }}"
                data-member-name="{{ $child['full_name'] }}"
            >
                <div class="lml-child-imm__toast" data-child-imm-toast role="status" aria-live="polite" hidden></div>

                <article class="lml-child-imm__summary lml-child-imm__summary--birth-only">
                    @include('pages.health-records.partials.child-care-non-residents-birth-history', [
                        'editUrl' => $birthHistoryEditUrl,
                        'emptyRecord' => $emptyRecord,
                    ])
                </article>

                <form
                    class="lml-child-imm__immunization"
                    data-child-imm-immunization
                    data-editing="false"
                    data-persistence="preview"
                    aria-labelledby="lml-child-imm-heading"
                    novalidate
                >
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
                            <button type="button" class="lml-child-imm__edit lml-focus-ring" data-child-imm-edit="immunization" aria-label="Edit child immunization">
                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                <span>Edit</span>
                            </button>
                            <button type="submit" class="lml-child-imm__save lml-focus-ring" data-child-imm-save aria-label="Save child immunization" hidden>
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
                                        <h3 id="lml-child-imm-vax-{{ $card['key'] }}" class="lml-child-imm__vaccine-title">{{ $card['title'] }}</h3>
                                        <div class="lml-child-imm__dose-list">
                                            @foreach ($card['doses'] as $index => $dose)
                                                @php $fieldId = 'lml-nr-ci-'.$card['key'].'-dose-'.$index; @endphp
                                                <div class="lml-child-imm__dose">
                                                    <div class="lml-child-imm__dose-label-row">
                                                        <label class="lml-child-imm__dose-label" for="{{ $fieldId }}">{{ $dose['label'] }}</label>
                                                    </div>
                                                    <div class="lml-child-imm__date-wrap">
                                                        <span class="lml-child-imm__date-caption" id="{{ $fieldId }}-caption">Date</span>
                                                        <input
                                                            type="date"
                                                            id="{{ $fieldId }}"
                                                            name="vaccines[{{ $card['key'] }}][{{ $index }}]"
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
                                    <section class="lml-child-imm__vaccine-card lml-child-imm__vaccine-card--completion lml-child-imm__vaccine-card--{{ $card['key'] }}" aria-labelledby="lml-child-imm-vax-{{ $card['key'] }}">
                                        <h3 id="lml-child-imm-vax-{{ $card['key'] }}" class="lml-child-imm__vaccine-title">
                                            {{ $card['title'] }}
                                            <span class="lml-child-imm__vaccine-range">({{ $card['range'] }})</span>
                                        </h3>
                                        <p class="visually-hidden">Presentation-only completion checklist. Not calculated from date inputs. FIC requires MMR × 1 dose. CIC requires MMR × 2 doses.</p>
                                        <ul class="lml-child-imm__completion-list">
                                            @foreach ($card['items'] as $item)
                                                <li class="lml-child-imm__completion-item">
                                                    <span class="lml-child-imm__completion-mark" aria-hidden="true"></span>
                                                    <span class="lml-child-imm__completion-text">
                                                        <span class="lml-child-imm__completion-label">{{ $item['label'] }}</span>
                                                        <span class="lml-child-imm__completion-doses">{{ $item['doses'] }}</span>
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
                                        @php $checkboxId = 'lml-nr-ci-type-'.$type['key']; @endphp
                                        <li>
                                            <label class="lml-child-imm__type-row" for="{{ $checkboxId }}">
                                                <input type="checkbox" id="{{ $checkboxId }}" name="vaccine_types[]" value="{{ $type['key'] }}" class="lml-child-imm__type-checkbox lml-focus-ring" data-child-imm-field disabled>
                                                <span class="lml-child-imm__type-label">{{ $type['label'] }}</span>
                                            </label>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </aside>
                    </div>
                </form>
            </div>
        @endif
    </div>
@endsection
