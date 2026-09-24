{{--
    Health Records — Child Care → Vitamin A monitoring summary (Figma-aligned).

    Data: App\Support\HealthRecordsVitaminA — UI-phase Figma preview/demo display
    values only (not persisted; not authoritative production aggregates).
    Zone filter is UI-only. Export matches Child Care UI-phase toast.
--}}
@extends('layouts.dashboard')

@section('title', 'Child Care — Vitamin A - LMLinga')

@section('content')
    @php
        $rows = $rows ?? [];
        $zones = $zones ?? [];
        $vitaminAUrl = route('health-records.child-care.vitamin-a');
        $dewormingUrl = route('health-records.child-care.deworming');
        $operationTimbangUrl = route('health-records.child-care.operation-timbang');
        $pageDescription = 'Record and management of Vitamin A supplementation details for monitoring and tracking nutritional status.';
    @endphp

    <div
        class="lml-hr-child-care lml-hr-child-care--vitamin-a"
        data-lml-hr-vitamin-a
        data-va-data-mode="figma-preview"
    >
        <div class="lml-hr-child-care__panel">
            <header class="lml-hr-child-care__top">
                <div class="lml-hr-child-care__title-row">
                    <h2 class="lml-hr-child-care__title" id="lml-hr-vitamin-a-heading">Child Care</h2>
                    <nav class="lml-hr-child-care__nav-pills" aria-label="Child Care related summaries">
                        <a
                            href="{{ $vitaminAUrl }}"
                            class="lml-hr-child-care__pill lml-hr-child-care__pill--active lml-focus-ring"
                            aria-current="page"
                        >
                            Vitamin A
                        </a>
                        <a
                            href="{{ $dewormingUrl }}"
                            class="lml-hr-child-care__pill lml-focus-ring"
                        >
                            Deworming
                        </a>
                        <a
                            href="{{ $operationTimbangUrl }}"
                            class="lml-hr-child-care__pill lml-focus-ring"
                        >
                            Operation Timbang
                        </a>
                    </nav>
                </div>

                <div class="lml-hr-child-care__actions" role="group" aria-label="Vitamin A actions">
                    <button
                        type="button"
                        class="lml-hr-child-care__export-btn lml-focus-ring"
                        data-hr-va-export
                        aria-label="Export Vitamin A data"
                    >
                        <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                        <span>Export Data</span>
                    </button>
                </div>
            </header>

            <p class="lml-hr-child-care__description" id="lml-hr-vitamin-a-desc">
                {{ $pageDescription }}
            </p>

            <div
                class="lml-hr-child-care__toast"
                data-hr-va-toast
                role="status"
                aria-live="polite"
                hidden
            ></div>

            <div class="lml-hr-child-care__filters lml-hr-child-care__filters--vitamin-a" role="toolbar" aria-label="Vitamin A filters">
                <div class="lml-hr-child-care__select-wrap lml-hr-child-care__select-wrap--zone-full">
                    <label class="visually-hidden" for="lml-hr-va-zone">Filter by zone</label>
                    <select
                        id="lml-hr-va-zone"
                        class="lml-hr-child-care__select lml-focus-ring"
                        data-hr-va-zone
                    >
                        <option value="all">All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}">{{ $zone }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-child-care__select-icon" aria-hidden="true"></i>
                </div>
            </div>

            <p class="visually-hidden" data-hr-va-zone-status aria-live="polite">
                Zone filter is preview-ready. Filtered aggregates await backend integration.
            </p>

            <div class="lml-hr-child-care__table-card lml-hr-child-care__table-card--vitamin-a">
                <div
                    class="lml-hr-child-care__table-scroll lml-hr-child-care__table-scroll--vitamin-a"
                    tabindex="0"
                    aria-labelledby="lml-hr-vitamin-a-heading"
                    aria-describedby="lml-hr-vitamin-a-desc"
                >
                    <table class="lml-hr-child-care__table lml-hr-child-care__table--vitamin-a">
                        <caption class="visually-hidden">
                            Barangay-wide Vitamin A monitoring summary by age group,
                            dose category, sex, and percentage accomplishment.
                            Numeric figures are UI-phase Figma preview/demo values
                            pending authoritative backend integration.
                        </caption>
                        <colgroup>
                            <col class="lml-hr-va-col lml-hr-va-col--age">
                            <col class="lml-hr-va-col lml-hr-va-col--target">
                            <col class="lml-hr-va-col lml-hr-va-col--metric" span="6">
                            <col class="lml-hr-va-col lml-hr-va-col--pct">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col" rowspan="3" class="lml-hr-va-th lml-hr-va-th--age">
                                    Age Group
                                </th>
                                <th scope="col" rowspan="3" class="lml-hr-va-th lml-hr-va-th--target">
                                    <span class="lml-hr-va-th__stack">
                                        <span>Target</span>
                                        <span>6mos</span>
                                        <span>to 6yrs old</span>
                                    </span>
                                </th>
                                <th
                                    scope="colgroup"
                                    colspan="6"
                                    class="lml-hr-va-th lml-hr-va-th--group"
                                >
                                    Total Number of children given vitamin A
                                </th>
                                <th scope="col" rowspan="3" class="lml-hr-va-th lml-hr-va-th--pct">
                                    <span class="lml-hr-va-th__stack">
                                        <span>Percentage</span>
                                        <span>Accomplishment</span>
                                        <span>(%)</span>
                                    </span>
                                </th>
                            </tr>
                            <tr>
                                <th
                                    scope="colgroup"
                                    colspan="3"
                                    class="lml-hr-va-th lml-hr-va-th--dose"
                                >
                                    Vitamin A 100,000 IU
                                </th>
                                <th
                                    scope="colgroup"
                                    colspan="3"
                                    class="lml-hr-va-th lml-hr-va-th--dose"
                                >
                                    Vitamin A 200,000 IU
                                </th>
                            </tr>
                            <tr>
                                <th scope="col" class="lml-hr-va-th lml-hr-va-th--sex">Male</th>
                                <th scope="col" class="lml-hr-va-th lml-hr-va-th--sex">Female</th>
                                <th scope="col" class="lml-hr-va-th lml-hr-va-th--sex">Total</th>
                                <th scope="col" class="lml-hr-va-th lml-hr-va-th--sex">Male</th>
                                <th scope="col" class="lml-hr-va-th lml-hr-va-th--sex">Female</th>
                                <th scope="col" class="lml-hr-va-th lml-hr-va-th--sex">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr
                                    @class([
                                        'lml-hr-va-row',
                                        'lml-hr-va-row--total' => ! empty($row['is_total']),
                                    ])
                                    data-hr-va-row
                                    data-age-group="{{ $row['key'] }}"
                                >
                                    <th scope="row" class="lml-hr-va-cell lml-hr-va-cell--age">
                                        {{ $row['label'] }}
                                    </th>
                                    <td class="lml-hr-va-cell lml-hr-va-cell--num">
                                        @if ($row['target'] === '' || $row['target'] === null)
                                            <span class="visually-hidden">No data</span>
                                        @else
                                            {{ $row['target'] }}
                                        @endif
                                    </td>
                                    <td class="lml-hr-va-cell lml-hr-va-cell--num">
                                        @if ($row['va_100k_male'] === '' || $row['va_100k_male'] === null)
                                            <span class="visually-hidden">No data</span>
                                        @else
                                            {{ $row['va_100k_male'] }}
                                        @endif
                                    </td>
                                    <td class="lml-hr-va-cell lml-hr-va-cell--num">
                                        @if ($row['va_100k_female'] === '' || $row['va_100k_female'] === null)
                                            <span class="visually-hidden">No data</span>
                                        @else
                                            {{ $row['va_100k_female'] }}
                                        @endif
                                    </td>
                                    <td class="lml-hr-va-cell lml-hr-va-cell--num">
                                        @if ($row['va_100k_total'] === '' || $row['va_100k_total'] === null)
                                            <span class="visually-hidden">No data</span>
                                        @else
                                            {{ $row['va_100k_total'] }}
                                        @endif
                                    </td>
                                    <td class="lml-hr-va-cell lml-hr-va-cell--num">
                                        @if ($row['va_200k_male'] === '' || $row['va_200k_male'] === null)
                                            <span class="visually-hidden">No data</span>
                                        @else
                                            {{ $row['va_200k_male'] }}
                                        @endif
                                    </td>
                                    <td class="lml-hr-va-cell lml-hr-va-cell--num">
                                        @if ($row['va_200k_female'] === '' || $row['va_200k_female'] === null)
                                            <span class="visually-hidden">No data</span>
                                        @else
                                            {{ $row['va_200k_female'] }}
                                        @endif
                                    </td>
                                    <td class="lml-hr-va-cell lml-hr-va-cell--num">
                                        @if ($row['va_200k_total'] === '' || $row['va_200k_total'] === null)
                                            <span class="visually-hidden">No data</span>
                                        @else
                                            {{ $row['va_200k_total'] }}
                                        @endif
                                    </td>
                                    <td class="lml-hr-va-cell lml-hr-va-cell--num lml-hr-va-cell--pct">
                                        @if ($row['percentage'] === '' || $row['percentage'] === null)
                                            <span class="visually-hidden">No data</span>
                                        @else
                                            {{ $row['percentage'] }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
