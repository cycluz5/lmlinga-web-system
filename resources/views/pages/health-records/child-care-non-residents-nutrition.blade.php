{{-- Health Records → Child Care → Non-Residents nutritional history (UI-phase). --}}
@extends('layouts.dashboard')

@section('title', 'Nutritional Status — Child Care - LMLinga')

@section('content')
    @php
        $listingUrl = route('health-records.child-care.non-residents.index');
        $showUrl = isset($child['key'])
            ? route('health-records.child-care.non-residents.show', ['childKey' => $child['key']])
            : $listingUrl;
        $createUrl = isset($child['key'])
            ? route('health-records.child-care.non-residents.nutrition.create', ['childKey' => $child['key']])
            : $listingUrl;
        $infantRecords = $infantRecords ?? [];
        $childRecords = $childRecords ?? [];
        $hasHistory = $infantRecords !== [] || $childRecords !== [];
    @endphp

    <div class="lml-hr-cc-nr" data-lml-hr-cc-nr data-lml-hr-cc-nr-mode="nutrition">
        <div class="lml-hr-cc-nr__toast" data-hr-cc-nr-toast role="status" aria-live="polite" hidden></div>

        <a href="{{ $showUrl }}" class="lml-hr-cc-nr__page-back lml-focus-ring" aria-label="Back to child record">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </a>

        @include('pages.health-records.partials.child-care-non-residents-profile', ['child' => $child])

        @if ($child)
            <section class="lml-hr-cc-nr__history-panel" aria-labelledby="lml-hr-cc-nr-history-title">
                <div class="lml-hr-cc-nr__history-head">
                    <div>
                        <h3 class="lml-hr-cc-nr__dash-title" id="lml-hr-cc-nr-history-title">Nutritional Status</h3>
                        <p class="lml-hr-cc-nr__dash-sub">Track the growth of the child</p>
                    </div>
                    <a href="{{ $createUrl }}" class="lml-hr-cc-nr__save-btn lml-focus-ring" data-hr-cc-nr-add-record>
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        Add Record
                    </a>
                </div>

                @if (! $hasHistory)
                    <div class="lml-hr-cc-nr__age-box" role="status">
                        <div class="lml-hr-cc-nr__empty">
                            <p class="lml-hr-cc-nr__empty-title">No nutritional measurements are recorded for this child.</p>
                            <p class="lml-hr-cc-nr__empty-hint">Add a record to start tracking growth.</p>
                        </div>
                    </div>
                @else
                    @foreach ([
                        ['title' => '0–12 Months Record', 'rows' => $infantRecords, 'key' => 'infant'],
                        ['title' => '1–5 Years Old Record', 'rows' => $childRecords, 'key' => 'child'],
                    ] as $group)
                        <article
                            class="lml-hr-cc-nr__age-box"
                            data-hr-cc-nr-age-group="{{ $group['key'] }}"
                            aria-labelledby="lml-hr-cc-nr-age-{{ $group['key'] }}"
                        >
                            <div class="lml-hr-cc-nr__age-box-head">
                                <h4 class="lml-hr-cc-nr__history-group" id="lml-hr-cc-nr-age-{{ $group['key'] }}">{{ $group['title'] }}</h4>
                            </div>
                            @if ($group['rows'] === [])
                                <p class="lml-hr-cc-nr__empty-hint">No records in this age group.</p>
                            @else
                                <ul class="lml-hr-cc-nr__measure-list">
                                    @foreach ($group['rows'] as $row)
                                        @php
                                            $ageDisplay = ($row['age_label'] ?? '—') === '—'
                                                ? '—'
                                                : $row['age_label'].' Old';
                                            $weightLabel = $row['weight_kg'] !== null ? number_format($row['weight_kg'], 1).' kg' : '—';
                                            $heightLabel = $row['height_cm'] !== null ? number_format($row['height_cm'], 1).' cm' : '—';
                                            $muacLabel = $row['muac_cm'] !== null ? number_format($row['muac_cm'], 1).' cm' : '—';
                                        @endphp
                                        <li
                                            class="lml-hr-cc-nr__measure-row"
                                            data-hr-cc-nr-measure-row
                                            data-hr-cc-nr-measure-id="{{ $row['id'] }}"
                                        >
                                            <div class="lml-hr-cc-nr__measure-when">
                                                <h5 class="lml-hr-cc-nr__measure-date">{{ $row['date_label'] }}</h5>
                                                <p class="lml-hr-cc-nr__measure-age">{{ $ageDisplay }}</p>
                                            </div>
                                            <dl class="lml-hr-cc-nr__measure-metrics">
                                                <div>
                                                    <dt>Weight</dt>
                                                    <dd>{{ $weightLabel }}</dd>
                                                </div>
                                                <div>
                                                    <dt>Height</dt>
                                                    <dd>{{ $heightLabel }}</dd>
                                                </div>
                                                <div>
                                                    <dt>MUAC</dt>
                                                    <dd>{{ $muacLabel }}</dd>
                                                </div>
                                                <div>
                                                    <dt>Weight Progress</dt>
                                                    <dd class="lml-hr-cc-nr__measure-progress">{{ $row['weight_progress'] ?? '—' }}</dd>
                                                </div>
                                                <div>
                                                    <dt>Height Progress</dt>
                                                    <dd class="lml-hr-cc-nr__measure-progress">{{ $row['height_progress'] ?? '—' }}</dd>
                                                </div>
                                                <div>
                                                    <dt>Status</dt>
                                                    <dd>
                                                        @if (filled($row['status']))
                                                            <span class="lml-hr-cc-nr__status-pill @switch($row['status'])
                                                                @case('Needs Monitoring') lml-hr-cc-nr__status-pill--watch @break
                                                                @case('Below Normal') lml-hr-cc-nr__status-pill--below @break
                                                                @case('Above Normal') lml-hr-cc-nr__status-pill--above @break
                                                            @endswitch">{{ $row['status'] }}</span>
                                                        @else
                                                            —
                                                        @endif
                                                    </dd>
                                                </div>
                                            </dl>
                                            <a
                                                href="{{ route('health-records.child-care.non-residents.nutrition.edit', ['childKey' => $child['key'], 'measurementId' => $row['id']]) }}"
                                                class="lml-hr-cc-nr__view-btn lml-focus-ring"
                                                data-hr-cc-nr-measure-edit
                                                aria-label="Edit measurement from {{ $row['date_label'] }} for {{ $child['full_name'] }}"
                                            >
                                                Edit
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </article>
                    @endforeach
                @endif
            </section>
        @endif
    </div>
@endsection
