{{--
    Health Records → Child Care → Non-Residents individual view (Figma).
    UI-phase fixture. Child Immunization, School-Based Immunization, Child Nutrition, and Deworming are child-scoped.
--}}
@extends('layouts.dashboard')

@section('title', ($child['full_name'] ?? 'Child') . ' — Non-Residents Child Care - LMLinga')

@section('content')
    @php
        $listingUrl = route('health-records.child-care.non-residents.index');
        $child = $child ?? null;
        $recordItems = $recordItems ?? \App\Support\HealthRecordsNonResidentChildCare::childCareRecordItems();
        $first = is_array($child['first_measurement'] ?? null) ? $child['first_measurement'] : null;
        $nutritionUrl = $child['nutrition_url'] ?? $listingUrl;
    @endphp

    <div class="lml-hr-cc-nr" data-lml-hr-cc-nr data-lml-hr-cc-nr-mode="show">
        <div
            class="lml-hr-cc-nr__toast"
            data-hr-cc-nr-toast
            role="status"
            aria-live="polite"
            hidden
        ></div>

        <a
            href="{{ $listingUrl }}"
            class="lml-hr-cc-nr__page-back lml-focus-ring"
            aria-label="Back to Non-Residents"
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </a>

        @include('pages.health-records.partials.child-care-non-residents-profile', ['child' => $child])

        @if ($child)
            <div class="lml-hr-cc-nr__dash">
                <section class="lml-hr-cc-nr__dash-card" aria-labelledby="lml-hr-cc-nr-record-title">
                    <h3 class="lml-hr-cc-nr__dash-title" id="lml-hr-cc-nr-record-title">
                        <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                        CHILD CARE RECORD
                    </h3>
                    <ul class="lml-hr-cc-nr__service-list">
                        @foreach ($recordItems as $item)
                            @php
                                $icon = match ($item['label']) {
                                    'Child Immunization' => 'bi-shield-plus',
                                    'School Based Immunization' => 'bi-hospital',
                                    'Child Nutrition' => 'bi-apple',
                                    'Deworming' => 'bi-capsule',
                                    default => 'bi-heart-pulse',
                                };
                            @endphp
                            <li class="lml-hr-cc-nr__service-item">
                                <span class="lml-hr-cc-nr__service-label">
                                    <i class="bi {{ $icon }}" aria-hidden="true"></i>
                                    {{ $item['label'] }}
                                </span>
                                @if (! empty($item['available']) && filled($item['url'] ?? null))
                                    <a
                                        href="{{ $item['url'] }}"
                                        class="lml-hr-cc-nr__view-btn lml-focus-ring"
                                        aria-label="View {{ $item['label'] }} for {{ $child['full_name'] }}"
                                    >
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                        <span>View →</span>
                                    </a>
                                @else
                                    <span class="lml-hr-cc-nr__service-meta" aria-disabled="true">Unavailable</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="lml-hr-cc-nr__dash-card" aria-labelledby="lml-hr-cc-nr-nutrition-title">
                    <div class="lml-hr-cc-nr__dash-head">
                        <div>
                            <h3 class="lml-hr-cc-nr__dash-title" id="lml-hr-cc-nr-nutrition-title">
                                <i class="bi bi-activity" aria-hidden="true"></i>
                                Nutritional Status
                            </h3>
                            <p class="lml-hr-cc-nr__dash-sub">Track the growth of the child</p>
                        </div>
                        <a
                            href="{{ $nutritionUrl }}"
                            class="lml-hr-cc-nr__view-btn lml-focus-ring"
                            aria-label="View nutritional status for {{ $child['full_name'] }}"
                        >
                            <i class="bi bi-eye" aria-hidden="true"></i>
                            <span>View →</span>
                        </a>
                    </div>
                    <dl class="lml-hr-cc-nr__metrics" data-hr-cc-nr-nutrition-summary="first">
                        <div>
                            <dt>Weight</dt>
                            <dd>{{ $first && $first['weight_kg'] !== null ? number_format($first['weight_kg'], 1).' kg' : '—' }}</dd>
                        </div>
                        <div>
                            <dt>Height</dt>
                            <dd>{{ $first && $first['height_cm'] !== null ? number_format($first['height_cm'], 1).' cm' : '—' }}</dd>
                        </div>
                        <div>
                            <dt>MUAC</dt>
                            <dd>{{ $first && $first['muac_cm'] !== null ? number_format($first['muac_cm'], 1).' cm' : '—' }}</dd>
                        </div>
                        <div>
                            <dt>Status</dt>
                            <dd>
                                @if ($first && filled($first['status'] ?? null))
                                    <span class="lml-hr-cc-nr__status-pill @switch($first['status'])
                                        @case('Needs Monitoring') lml-hr-cc-nr__status-pill--watch @break
                                        @case('Below Normal') lml-hr-cc-nr__status-pill--below @break
                                        @case('Above Normal') lml-hr-cc-nr__status-pill--above @break
                                    @endswitch">{{ $first['status'] }}</span>
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>
        @endif
    </div>
@endsection
