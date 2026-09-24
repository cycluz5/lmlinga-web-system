{{-- Household Profiling — Nutritional Status history (FR-12). Age-grouped
     card layout matches the existing Health Records → Child Care →
     Non-Residents nutrition history
     (resources/views/pages/health-records/child-care-non-residents-nutrition.blade.php)
     — same lml-hr-cc-nr__age-box/measure-row classes, already styled
     globally. Grouped by this feature's own age bands (0-5m/6-59m/5-19y/
     adult) instead of that page's 0-12mo/1-5y split, since Nutritional
     Status here covers every resident, not only young children. --}}
@extends('layouts.dashboard')

@section('title', ($demoMember['name'] ?? 'Member') . ' — Nutritional Status - LMLinga')

@section('content')
    @php
        $persistenceSource = $persistenceSource ?? 'preview';
        $isDbPersisted = $persistenceSource === 'db';
        $memberName = (string) ($demoMember['name'] ?? 'Member');
        $urlStyle = $urlStyle ?? 'member';
        // Always the real MB-xxx member_no — $memberId is the raw residentId
        // when entered via the canonical resident route, which does not
        // match household-profiling.members.show's MB-xxx route constraint.
        $backUrl = $memberProfileUrl ?? route('household-profiling.members.show', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);
        $createUrl = $urlStyle === 'resident'
            ? route('households.residents.nutritional-status.create', [
                'householdNo' => $householdNo,
                'residentId' => $residentId,
            ])
            : route('household-profiling.members.nutritional-status.create', [
                'householdNo' => $householdNo,
                'memberId' => $memberId,
            ]);
        $history = $history ?? [];
        $hasHistory = $history !== [];
        $formatMetric = static function (mixed $value, int $scale, string $suffix): string {
            if ($value === null || $value === '') {
                return '—';
            }

            $formatted = rtrim(rtrim(number_format((float) $value, $scale, '.', ''), '0'), '.');

            return ($formatted === '' ? '0' : $formatted).' '.$suffix;
        };
        $pillClass = static function (?string $status): string {
            return match (true) {
                $status === null => 'lml-hh-timbang__status-pill lml-hh-timbang__status-pill--pending',
                in_array($status, [
                    \App\Models\TimbangRecord::REFERENCE_UNAVAILABLE,
                    'N/A',
                ], true) => 'lml-hh-timbang__status-pill lml-hh-timbang__status-pill--pending',
                in_array($status, [
                    'Severely Underweight',
                    'Severely Stunted',
                    'Severely Wasted',
                    'Severe Acute Malnutrition (SAM)',
                    'Severely Malnourished',
                ], true) => 'lml-hh-timbang__status-pill lml-hh-timbang__status-pill--severe',
                in_array($status, [
                    'Underweight',
                    'Overweight',
                    'Obese',
                    'Stunted',
                    'Wasted',
                    'Possible Risk of Overweight',
                    'Moderate Acute Malnutrition (MAM)',
                    'At Risk',
                    'Malnourished',
                ], true) => 'lml-hh-timbang__status-pill lml-hh-timbang__status-pill--risk',
                default => 'lml-hh-timbang__status-pill',
            };
        };

        // Group history rows into this feature's own age bands — same
        // sectioned-card idea as the Non-Residents nutrition page, but this
        // page's bands (see NutritionAssessmentService) rather than that
        // page's fixed 0-12mo/1-5y split, since a resident here can be any age.
        $bandGroups = [
            '0-5m' => '0–5 Months Record',
            '6-59m' => '6–59 Months Record',
            '5-19y' => '5–19 Years Record',
            'adult' => 'Adult Record',
        ];
        $groupedHistory = array_fill_keys(array_keys($bandGroups), []);
        $unclassified = [];
        foreach ($history as $item) {
            $band = $item['assessment']['age_band'] ?? null;
            if ($band !== null && array_key_exists($band, $groupedHistory)) {
                $groupedHistory[$band][] = $item;
            } else {
                $unclassified[] = $item;
            }
        }
        if ($unclassified !== []) {
            $groupedHistory['__unclassified'] = $unclassified;
            $bandGroups['__unclassified'] = 'Other Records';
        }
    @endphp

    <div class="lml-hr-cc-nr lml-hh-timbang" data-lml-hh-timbang data-persistence="{{ $persistenceSource }}">
        <a href="{{ $backUrl }}" class="lml-hr-cc-nr__page-back lml-focus-ring" aria-label="Back to member record">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </a>

        <nav class="lml-hh-timbang__breadcrumb" aria-label="Breadcrumb">
            <a href="{{ $backUrl }}" class="lml-hh-timbang__breadcrumb-link lml-focus-ring">
                <i class="bi bi-person-lines-fill" aria-hidden="true"></i>
                Back to {{ $memberName }}'s profile
            </a>
        </nav>

        @if ($demoMember)
            <section class="lml-hr-cc-nr__history-panel" aria-labelledby="lml-hh-timbang-history-title">
                <div class="lml-hr-cc-nr__history-head">
                    <div>
                        <h3 class="lml-hr-cc-nr__dash-title" id="lml-hh-timbang-history-title">Nutritional Status</h3>
                        <p class="lml-hr-cc-nr__dash-sub">Track the growth of {{ $memberName }}</p>
                    </div>
                    @if ($isDbPersisted)
                        <a href="{{ $createUrl }}" class="lml-hr-cc-nr__save-btn lml-focus-ring" data-hr-cc-nr-add-record>
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                            Add Record
                        </a>
                    @endif
                </div>

                @if (session('status'))
                    <p class="lml-hh-timbang__status" role="status">{{ session('status') }}</p>
                @endif

                @if (! $hasHistory)
                    <div class="lml-hr-cc-nr__age-box" role="status">
                        <div class="lml-hr-cc-nr__empty">
                            <p class="lml-hr-cc-nr__empty-title">No nutritional measurements are recorded for this member.</p>
                            <p class="lml-hr-cc-nr__empty-hint">Add a record to start tracking growth.</p>
                        </div>
                    </div>
                @else
                    @foreach ($bandGroups as $bandKey => $bandTitle)
                        @continue ($groupedHistory[$bandKey] === [])
                        <article
                            class="lml-hr-cc-nr__age-box"
                            data-lml-hh-timbang-age-group="{{ $bandKey }}"
                            aria-labelledby="lml-hh-timbang-age-{{ $bandKey }}"
                        >
                            <div class="lml-hr-cc-nr__age-box-head">
                                <h4 class="lml-hr-cc-nr__history-group" id="lml-hh-timbang-age-{{ $bandKey }}">{{ $bandTitle }}</h4>
                            </div>
                            <ul class="lml-hr-cc-nr__measure-list">
                                @foreach ($groupedHistory[$bandKey] as $item)
                                    @php
                                        $row = $item['record'] ?? $item;
                                        $assessment = $item['assessment'] ?? [];
                                        $dateLabel = $row->measurement_date
                                            ? $row->measurement_date->format('M d, Y')
                                            : '—';
                                        $weightProgressLabel = $item['weight_change_label'] ?? '—';
                                        $heightProgressLabel = $item['height_change_label'] ?? '—';
                                        $ageLabel = $assessment['age_label'] ?? null;
                                        $muacApplicable = (bool) ($assessment['muac_applicable'] ?? false);
                                        $wfaApplicable = (bool) ($assessment['weight_for_age_applicable'] ?? false);
                                        $bmiApplicable = (bool) ($assessment['bmi_applicable'] ?? false);
                                        $bmiDisplay = $assessment['bmi_display'] ?? null;
                                    @endphp
                                    <li class="lml-hr-cc-nr__measure-row" data-timbang-id="{{ $row->timbang_id }}">
                                        <div class="lml-hr-cc-nr__measure-when">
                                            <h5 class="lml-hr-cc-nr__measure-date">{{ $dateLabel }}</h5>
                                            @if ($ageLabel)
                                                <p class="lml-hr-cc-nr__measure-age">{{ $ageLabel }} Old</p>
                                            @endif
                                        </div>
                                        <dl class="lml-hr-cc-nr__measure-metrics">
                                            <div>
                                                <dt>Weight</dt>
                                                <dd>{{ $formatMetric($row->weight_kg, 2, 'kg') }}</dd>
                                            </div>
                                            <div>
                                                <dt>Height</dt>
                                                <dd>{{ $formatMetric($row->height_cm, 2, 'cm') }}</dd>
                                            </div>
                                            @if ($muacApplicable)
                                                <div>
                                                    <dt>MUAC</dt>
                                                    <dd>{{ $formatMetric($row->muac_cm, 1, 'cm') }}</dd>
                                                </div>
                                            @endif
                                            <div>
                                                <dt>Weight Progress</dt>
                                                <dd
                                                    class="lml-hr-cc-nr__measure-progress"
                                                    data-weight-progress="{{ $weightProgressLabel }}"
                                                    @if (! empty($item['is_weight_baseline'])) data-weight-progress-baseline="true" @endif
                                                >{{ $weightProgressLabel }}</dd>
                                            </div>
                                            <div>
                                                <dt>Height Progress</dt>
                                                <dd
                                                    class="lml-hr-cc-nr__measure-progress"
                                                    data-height-progress="{{ $heightProgressLabel }}"
                                                    @if (! empty($item['is_height_baseline'])) data-height-progress-baseline="true" @endif
                                                >{{ $heightProgressLabel }}</dd>
                                            </div>

                                            @if ($wfaApplicable)
                                                <div>
                                                    <dt>Weight-for-age</dt>
                                                    <dd><span class="{{ $pillClass($row->weight_for_age) }}">{{ $row->weight_for_age ?? '—' }}</span></dd>
                                                </div>
                                                <div>
                                                    <dt>Height-for-age</dt>
                                                    <dd><span class="{{ $pillClass($row->height_for_age) }}">{{ $row->height_for_age ?? '—' }}</span></dd>
                                                </div>
                                                <div>
                                                    <dt>Weight-for-height</dt>
                                                    <dd><span class="{{ $pillClass($row->weight_for_height) }}">{{ $row->weight_for_height ?? '—' }}</span></dd>
                                                </div>
                                            @endif

                                            @if ($muacApplicable)
                                                <div>
                                                    <dt>MUAC Status</dt>
                                                    <dd><span class="{{ $pillClass($row->muac_status) }}">{{ $row->muac_status ?? '—' }}</span></dd>
                                                </div>
                                            @endif

                                            @if ($bmiApplicable)
                                                <div>
                                                    <dt>BMI</dt>
                                                    <dd>{{ $bmiDisplay !== null ? number_format((float) $bmiDisplay, 1) : '—' }}</dd>
                                                </div>
                                                <div>
                                                    <dt>BMI Status</dt>
                                                    <dd><span class="{{ $pillClass($row->bmi_status) }}">{{ $row->bmi_status ?? '—' }}</span></dd>
                                                </div>
                                            @endif

                                            <div>
                                                <dt>Overall Nutritional Status</dt>
                                                <dd><span class="{{ $pillClass($row->overall_nutritional_status) }}">{{ $row->overall_nutritional_status ?? '—' }}</span></dd>
                                            </div>

                                            @if (filled($row->remarks))
                                                <div>
                                                    <dt>Remarks</dt>
                                                    <dd>{{ $row->remarks }}</dd>
                                                </div>
                                            @endif
                                        </dl>
                                    </li>
                                @endforeach
                            </ul>
                        </article>
                    @endforeach
                @endif
            </section>
        @else
            <section class="lml-hr-cc-nr__age-box" role="status">
                <p class="lml-hr-cc-nr__empty-title">Member not found.</p>
            </section>
        @endif
    </div>
@endsection
