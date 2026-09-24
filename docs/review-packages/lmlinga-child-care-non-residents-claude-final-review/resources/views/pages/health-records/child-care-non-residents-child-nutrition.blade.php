{{--
    Health Records → Child Care → Non-Residents Child Nutrition.
    Isolated equivalent of the frozen Household Profiling Child Nutrition form.
    Distinct from Nutritional Status / Operation Timbang measurement history.
--}}
@extends('layouts.dashboard')

@section('title', ($child['full_name'] ?? 'Child') . ' — Child Nutrition - LMLinga')

@section('content')
    @php
        $showUrl = isset($child['key'])
            ? route('health-records.child-care.non-residents.show', ['childKey' => $child['key']])
            : route('health-records.child-care.non-residents.index');
        $birthHistoryEditUrl = isset($child['key'])
            ? route('health-records.child-care.non-residents.immunization.birth-history', [
                'childKey' => $child['key'],
                'from' => 'child-nutrition',
            ])
            : $showUrl;
        $emptyRecord = 'No record';
        $latest = is_array($child['latest_measurement'] ?? null) ? $child['latest_measurement'] : null;
        $sfpOutcomes = [
            ['key' => 'identified', 'label' => 'Identified'],
            ['key' => 'enrolled', 'label' => 'Enrolled'],
            ['key' => 'cured', 'label' => 'Cured'],
            ['key' => 'non-cured', 'label' => 'Non-Cured'],
            ['key' => 'default', 'label' => 'Default'],
            ['key' => 'died', 'label' => 'Died'],
        ];
        $vitaminAFields = [
            ['key' => 'va-6-11', 'label' => '100,000 IU (6–11 Months)', 'caption' => 'Date'],
            ['key' => 'va-12-59-1', 'label' => '200,000 IU (12–59 Months)', 'caption' => 'Date (1st Dose)'],
            ['key' => 'va-12-59-2', 'label' => '200,000 IU (12–59 Months)', 'caption' => 'Date (2nd Dose)'],
        ];
        $mnpFields = [
            ['key' => 'mnp-6-11', 'label' => '6–11 Months'],
            ['key' => 'mnp-12-23', 'label' => '12–23 Months'],
        ];
        $lnsFields = [
            ['key' => 'lns-6-11', 'label' => '6–11 Months'],
            ['key' => 'lns-12-23', 'label' => '12–23 Months'],
        ];
    @endphp

    <div class="lml-hr-cc-nr" data-lml-hr-cc-nr data-lml-hr-cc-nr-mode="child-nutrition">
        <div class="lml-hr-cc-nr__toast" data-hr-cc-nr-toast role="status" aria-live="polite" hidden></div>

        <a href="{{ $showUrl }}" class="lml-hr-cc-nr__page-back lml-focus-ring" aria-label="Back to child record">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </a>

        @include('pages.health-records.partials.child-care-non-residents-profile', ['child' => $child])

        @if ($child)
            <div
                class="lml-child-nut lml-hr-cc-nr__module"
                data-lml-child-nut
                data-demo="true"
                data-household-no="nr"
                data-member-id="{{ $child['key'] }}"
                data-member-sex="{{ $child['sex'] ?? '' }}"
                data-member-name="{{ $child['full_name'] }}"
            >
                <div class="lml-child-nut__toast" data-child-nut-toast role="status" aria-live="polite" hidden></div>

                <article class="lml-child-imm__summary lml-child-imm__summary--birth-only">
                    @include('pages.health-records.partials.child-care-non-residents-birth-history', [
                        'editUrl' => $birthHistoryEditUrl,
                        'emptyRecord' => $emptyRecord,
                        'headingId' => 'lml-nr-cn-birth-heading',
                        'summaryAttr' => 'data-child-nut-birth-summary',
                        'editLinkAttr' => 'data-child-nut-birth-edit-link',
                    ])
                </article>

                <form class="lml-child-nut__records" data-child-nut-records data-editing="false" data-persistence="preview" aria-labelledby="lml-child-nut-heading" novalidate>
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
                            <button type="button" class="lml-child-nut__edit lml-focus-ring" data-child-nut-edit aria-label="Edit child nutrition">
                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                <span>Edit</span>
                            </button>
                            <button type="submit" class="lml-child-nut__save lml-focus-ring" data-child-nut-save aria-label="Save child nutrition" hidden>
                                <span>Save</span>
                            </button>
                        </div>
                    </div>

                    <div class="lml-child-nut__body">
                        <div class="lml-child-nut__main">
                            <section class="lml-child-nut__card" id="lml-child-nut-newborn" aria-labelledby="lml-child-nut-newborn-heading">
                                <div class="lml-child-nut__section-head">
                                    <h3 id="lml-child-nut-newborn-heading" class="lml-child-nut__section-title">New Born (0–28 Days Old)</h3>
                                </div>
                                <div class="lml-child-nut__newborn-top">
                                    <div class="lml-child-nut__field">
                                        <label class="lml-child-nut__label" for="lml-child-nut-nb-length">Length at Birth (cm)</label>
                                        <input type="number" id="lml-child-nut-nb-length" name="newborn[length]" class="lml-child-nut__input lml-focus-ring" data-child-nut-field data-child-nut-newborn-metric="height" inputmode="decimal" min="0" step="0.01" autocomplete="off" disabled>
                                    </div>
                                    <div class="lml-child-nut__field">
                                        <label class="lml-child-nut__label" for="lml-child-nut-nb-weight">Weight at Birth (kg)</label>
                                        <input type="number" id="lml-child-nut-nb-weight" name="newborn[weight]" class="lml-child-nut__input lml-focus-ring" data-child-nut-field data-child-nut-newborn-metric="weight" inputmode="decimal" min="0" step="0.01" autocomplete="off" disabled>
                                    </div>
                                    <aside class="lml-child-nut__mini-status" aria-label="New born status">
                                        <span class="lml-child-nut__mini-status-title">Status</span>
                                        <span class="lml-child-nut__demo-status lml-child-nut__demo-status--empty" data-child-nut-newborn-status data-result="no_record">
                                            <i class="bi bi-dash-lg" aria-hidden="true"></i>
                                            <span data-child-nut-newborn-status-label>No record</span>
                                        </span>
                                    </aside>
                                </div>
                                <div class="lml-child-nut__field lml-child-nut__field--breastfeeding">
                                    <label class="lml-child-nut__label" for="lml-child-nut-nb-breastfeeding">Initiated Breastfeeding Date</label>
                                    <input type="date" id="lml-child-nut-nb-breastfeeding" name="newborn[breastfeeding_date]" class="lml-child-nut__input lml-focus-ring" data-child-nut-field autocomplete="off" readonly disabled>
                                </div>
                            </section>

                            <section class="lml-child-nut__card" id="lml-child-nut-iron" aria-labelledby="lml-child-nut-iron-heading">
                                <div class="lml-child-nut__section-head">
                                    <div class="lml-child-nut__iron-title-wrap">
                                        <h3 id="lml-child-nut-iron-heading" class="lml-child-nut__section-title">Iron</h3>
                                        <p class="lml-child-nut__section-note lml-child-nut__section-note--iron">
                                            <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                                            <span>For Low Birth Only</span>
                                        </p>
                                    </div>
                                </div>
                                <div class="lml-child-nut__field-grid lml-child-nut__field-grid--thirds">
                                    @foreach (['1st Month' => '1st', '2nd Month' => '2nd', '3rd Month' => '3rd'] as $ironLabel => $ironKey)
                                        @php $ironId = 'lml-child-nut-iron-'.$ironKey; @endphp
                                        <div class="lml-child-nut__field">
                                            <label class="lml-child-nut__label" for="{{ $ironId }}">{{ $ironLabel }}</label>
                                            <span class="lml-child-nut__date-caption" id="{{ $ironId }}-caption">Date</span>
                                            <input type="date" id="{{ $ironId }}" name="iron[{{ $ironKey }}]" class="lml-child-nut__input lml-focus-ring" data-child-nut-field aria-describedby="{{ $ironId }}-caption" autocomplete="off" readonly disabled>
                                        </div>
                                    @endforeach
                                </div>
                            </section>

                            <section class="lml-child-nut__card" id="lml-child-nut-supplementation" aria-labelledby="lml-child-nut-supp-heading">
                                <h3 id="lml-child-nut-supp-heading" class="lml-child-nut__card-title">Supplementation</h3>

                                <div class="lml-child-nut__section" aria-labelledby="lml-child-nut-vita-heading">
                                    <div class="lml-child-nut__section-head">
                                        <h4 id="lml-child-nut-vita-heading" class="lml-child-nut__subsection-title">Vitamin A</h4>
                                    </div>
                                    <div class="lml-child-nut__field-grid lml-child-nut__field-grid--thirds">
                                        @foreach ($vitaminAFields as $field)
                                            @php $fieldId = 'lml-child-nut-'.$field['key']; @endphp
                                            <div class="lml-child-nut__field">
                                                <label class="lml-child-nut__label" for="{{ $fieldId }}">{{ $field['label'] }}</label>
                                                <span class="lml-child-nut__date-caption" id="{{ $fieldId }}-caption">{{ $field['caption'] }}</span>
                                                <input type="date" id="{{ $fieldId }}" name="vitamin_a[{{ $field['key'] }}]" class="lml-child-nut__input lml-focus-ring" data-child-nut-field aria-describedby="{{ $fieldId }}-caption" autocomplete="off" readonly disabled>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="lml-child-nut__section" aria-labelledby="lml-child-nut-mnp-heading">
                                    <div class="lml-child-nut__section-head">
                                        <h4 id="lml-child-nut-mnp-heading" class="lml-child-nut__subsection-title">MNP</h4>
                                    </div>
                                    <div class="lml-child-nut__field-grid">
                                        @foreach ($mnpFields as $field)
                                            @php $fieldId = 'lml-child-nut-'.$field['key']; @endphp
                                            <div class="lml-child-nut__field">
                                                <label class="lml-child-nut__label" for="{{ $fieldId }}">{{ $field['label'] }}</label>
                                                <span class="lml-child-nut__date-caption" id="{{ $fieldId }}-caption">Date</span>
                                                <input type="date" id="{{ $fieldId }}" name="mnp[{{ $field['key'] }}]" class="lml-child-nut__input lml-focus-ring" data-child-nut-field aria-describedby="{{ $fieldId }}-caption" autocomplete="off" readonly disabled>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="lml-child-nut__section" aria-labelledby="lml-child-nut-lns-heading">
                                    <div class="lml-child-nut__section-head">
                                        <h4 id="lml-child-nut-lns-heading" class="lml-child-nut__subsection-title">LNS-SQ</h4>
                                    </div>
                                    <div class="lml-child-nut__field-grid">
                                        @foreach ($lnsFields as $field)
                                            @php $fieldId = 'lml-child-nut-'.$field['key']; @endphp
                                            <div class="lml-child-nut__field">
                                                <label class="lml-child-nut__label" for="{{ $fieldId }}">{{ $field['label'] }}</label>
                                                <span class="lml-child-nut__date-caption" id="{{ $fieldId }}-caption">Date</span>
                                                <input type="date" id="{{ $fieldId }}" name="lns_sq[{{ $field['key'] }}]" class="lml-child-nut__input lml-focus-ring" data-child-nut-field aria-describedby="{{ $fieldId }}-caption" autocomplete="off" readonly disabled>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </section>

                            <section class="lml-child-nut__card" id="lml-child-nut-sfp" aria-labelledby="lml-child-nut-sfp-heading">
                                <h3 id="lml-child-nut-sfp-heading" class="lml-child-nut__card-title">Supplementary Feeding Program</h3>
                                @foreach ([
                                    'mam' => ['title' => 'MAM', 'subtitle' => 'Moderate Acute Malnutrition', 'modifier' => 'mam'],
                                    'sam' => ['title' => 'SAM', 'subtitle' => 'Severe Acute Malnutrition', 'modifier' => 'sam'],
                                ] as $programKey => $program)
                                    <div class="lml-child-nut__sfp lml-child-nut__sfp--{{ $program['modifier'] }}" aria-labelledby="lml-child-nut-{{ $programKey }}-heading">
                                        <h4 id="lml-child-nut-{{ $programKey }}-heading" class="lml-child-nut__sfp-title">
                                            <span class="lml-child-nut__sfp-abbr">{{ $program['title'] }}</span>
                                            <span class="lml-child-nut__sfp-full">({{ $program['subtitle'] }})</span>
                                        </h4>
                                        <div class="lml-child-nut__sfp-table" role="table" aria-label="{{ $program['title'] }} program statuses">
                                            <div class="lml-child-nut__sfp-head" role="row">
                                                <span class="lml-child-nut__sfp-col lml-child-nut__sfp-col--status" role="columnheader">Program Status</span>
                                                <span class="lml-child-nut__sfp-col lml-child-nut__sfp-col--date" role="columnheader">Date</span>
                                                <span class="lml-child-nut__sfp-col lml-child-nut__sfp-col--action" role="columnheader">Action (Yes / No)</span>
                                            </div>
                                            @foreach ($sfpOutcomes as $outcome)
                                                @php
                                                    $rowId = 'lml-child-nut-'.$programKey.'-'.$outcome['key'];
                                                    $dateId = $rowId.'-date';
                                                    $yesId = $rowId.'-yes';
                                                    $noId = $rowId.'-no';
                                                    $groupName = $programKey.'_'.$outcome['key'];
                                                @endphp
                                                <div class="lml-child-nut__sfp-row" role="row">
                                                    <div class="lml-child-nut__sfp-col lml-child-nut__sfp-col--status" role="cell">
                                                        <span class="lml-child-nut__sfp-status-label">{{ $outcome['label'] }}</span>
                                                    </div>
                                                    <div class="lml-child-nut__sfp-col lml-child-nut__sfp-col--date" role="cell">
                                                        <label class="visually-hidden" for="{{ $dateId }}">{{ $program['title'] }} {{ $outcome['label'] }} date</label>
                                                        <input type="date" id="{{ $dateId }}" name="{{ $programKey }}[{{ $outcome['key'] }}][date]" class="lml-child-nut__input lml-focus-ring" data-child-nut-field autocomplete="off" readonly disabled>
                                                    </div>
                                                    <div class="lml-child-nut__sfp-col lml-child-nut__sfp-col--action" role="cell">
                                                        <fieldset class="lml-child-nut__yn" id="{{ $rowId }}-action">
                                                            <legend class="visually-hidden">{{ $program['title'] }} {{ $outcome['label'] }} action</legend>
                                                            <label class="lml-child-nut__yn-option" for="{{ $yesId }}">
                                                                <input type="radio" id="{{ $yesId }}" name="{{ $groupName }}" value="yes" class="lml-child-nut__yn-input lml-focus-ring" data-child-nut-field disabled>
                                                                <span>Yes</span>
                                                            </label>
                                                            <label class="lml-child-nut__yn-option" for="{{ $noId }}">
                                                                <input type="radio" id="{{ $noId }}" name="{{ $groupName }}" value="no" class="lml-child-nut__yn-input lml-focus-ring" data-child-nut-field disabled>
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

                        <aside class="lml-child-nut__aside" id="lml-child-nut-status-panel" aria-labelledby="lml-child-nut-status-heading">
                            <div class="lml-child-nut__status-card">
                                <h3 id="lml-child-nut-status-heading" class="lml-child-nut__status-title">
                                    <i class="bi bi-heart-fill" aria-hidden="true"></i>
                                    <span>Child Nutrition Status</span>
                                </h3>
                                <dl class="lml-child-nut__status-dl">
                                    <div class="lml-child-nut__status-block">
                                        <dt>Overall Status</dt>
                                        <dd data-child-nut-status-overall>
                                            @if ($latest && filled($latest['status'] ?? null))
                                                <span class="lml-child-nut__status-pill"><span>{{ $latest['status'] }}</span></span>
                                            @else
                                                {{ $emptyRecord }}
                                            @endif
                                        </dd>
                                    </div>
                                    <div class="lml-child-nut__status-block">
                                        <dt>Latest Assessment</dt>
                                        <dd>
                                            <dl class="lml-child-nut__status-sub">
                                                <div>
                                                    <dt>Date</dt>
                                                    <dd>{{ $latest['date_label'] ?? $emptyRecord }}</dd>
                                                </div>
                                                <div>
                                                    <dt>Weight</dt>
                                                    <dd>{{ $latest && $latest['weight_kg'] !== null ? number_format($latest['weight_kg'], 1).' kg' : $emptyRecord }}</dd>
                                                </div>
                                                <div>
                                                    <dt>Height</dt>
                                                    <dd>{{ $latest && $latest['height_cm'] !== null ? number_format($latest['height_cm'], 1).' cm' : $emptyRecord }}</dd>
                                                </div>
                                                <div>
                                                    <dt>MUAC</dt>
                                                    <dd>{{ $latest && $latest['muac_cm'] !== null ? number_format($latest['muac_cm'], 1).' cm' : $emptyRecord }}</dd>
                                                </div>
                                            </dl>
                                        </dd>
                                    </div>
                                    <div class="lml-child-nut__status-block">
                                        <dt>Nutrition Program</dt>
                                        <dd>
                                            <dl class="lml-child-nut__status-sub">
                                                <div><dt>Iron</dt><dd>{{ $emptyRecord }}</dd></div>
                                                <div><dt>Vitamin A (6–11)</dt><dd>{{ $emptyRecord }}</dd></div>
                                                <div><dt>Vitamin A (12–59)</dt><dd>{{ $emptyRecord }}</dd></div>
                                            </dl>
                                        </dd>
                                    </div>
                                </dl>
                                <p class="lml-child-nut__demo-note lml-child-nut__demo-note--panel">
                                    Preview/demo presentation only; no clinical derivation or persistence yet.
                                </p>
                                <p class="lml-child-nut__status-footer">--- Nothing Follows ---</p>
                            </div>
                        </aside>
                    </div>
                </form>
            </div>
        @endif
    </div>
@endsection
