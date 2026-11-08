{{--
    Health Records — Maternal Care resident listing (Figma-aligned).

    Data: App\Support\HealthRecordsMaternal — persisted maternal care records
    only. Independent of Household Profiling member Maternal Care routes.
    Filters are client-side. Export Data lands on the Report Builder's
    choices page (zone/year/month) before generating the PDF.
--}}
@extends('layouts.dashboard')

@section('title', 'Maternal Care - LMLinga')

@section('content')
    @php
        $rows = $rows ?? [];
        $zones = $zones ?? [];
        $years = $years ?? [];
        $months = $months ?? \App\Support\HealthRecordsMaternal::monthOptions();
        $summary = $summary ?? [
            'total' => 0,
            'delivered' => 0,
            'delivered_month_label' => \App\Support\HealthRecordsMaternal::currentDeliveredMonthLabel(),
        ];
        $totalUnfiltered = $totalUnfiltered ?? count($rows);
        $hasRecords = $totalUnfiltered > 0;
        $pageDescription = 'Record and management of maternal care details for monitoring and tracking maternal health status.';
    @endphp

    <div
        class="lml-hr-mc"
        data-lml-hr-mc
        data-lml-hr-mc-mode="resident"
        data-total="{{ $totalUnfiltered }}"
        data-has-records="{{ $hasRecords ? '1' : '0' }}"
        data-export-url="{{ route('health-records.maternal.report-builder') }}"
        data-delivered-month-label="{{ $summary['delivered_month_label'] }}"
    >
        <div class="lml-hr-mc__panel">
            <h2 class="visually-hidden" id="lml-hr-mc-heading">Maternal Care</h2>
            <p class="visually-hidden" id="lml-hr-mc-desc">
                {{ $pageDescription }}
            </p>

            <div class="lml-hr-mc__action-row" data-hr-mc-action-row>
                <div class="lml-hr-mc__action-right" role="group" aria-label="Maternal Care actions">
                    <button
                        type="button"
                        class="lml-hr-mc__export-btn lml-focus-ring"
                        data-hr-mc-export
                        aria-label="Export Maternal Care data"
                    >
                        <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                        <span>Export Data</span>
                    </button>
                </div>
            </div>

            <div
                class="lml-hr-mc__toast"
                data-hr-mc-toast
                role="status"
                aria-live="polite"
                hidden
            ></div>

            <div
                class="lml-hr-mc__stats lml-hr-mc__stats--resident"
                role="group"
                aria-label="Maternal care barangay summary"
            >
                <article class="lml-hr-mc__card lml-hr-mc__card--total">
                    <div class="lml-hr-mc__card-body">
                        <p class="lml-hr-mc__card-label">Total Pregnancy Clients</p>
                        <p class="lml-hr-mc__card-value" data-mc-stat="total">{{ $summary['total'] }}</p>
                    </div>
                </article>
                <article class="lml-hr-mc__card lml-hr-mc__card--delivered">
                    <div class="lml-hr-mc__card-body">
                        <p class="lml-hr-mc__card-label">
                            Delivered Cases
                            <span class="lml-hr-mc__card-sublabel" data-mc-delivered-month>
                                ({{ $summary['delivered_month_label'] }})
                            </span>
                        </p>
                        <p class="lml-hr-mc__card-value lml-hr-mc__card-value--delivered" data-mc-stat="delivered">{{ $summary['delivered'] }}</p>
                    </div>
                </article>
            </div>

            <div
                class="lml-hr-mc__filters"
                role="toolbar"
                aria-label="Maternal care search and filters"
            >
                <div class="lml-hr-mc__search">
                    <label class="visually-hidden" for="lml-hr-mc-search">Search Name</label>
                    <i class="bi bi-search lml-hr-mc__search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="lml-hr-mc-search"
                        class="lml-hr-mc__search-input lml-focus-ring"
                        data-hr-mc-search
                        placeholder="Search Name"
                        autocomplete="off"
                    >
                </div>

                <div class="lml-hr-mc__select-wrap">
                    <label class="visually-hidden" for="lml-hr-mc-zone">Filter by zone</label>
                    <select
                        id="lml-hr-mc-zone"
                        class="lml-hr-mc__select lml-focus-ring"
                        data-hr-mc-zone
                    >
                        <option value="all">Zone</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}">{{ $zone }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-mc__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-mc__select-wrap">
                    <label class="visually-hidden" for="lml-hr-mc-year">Filter by year</label>
                    <select
                        id="lml-hr-mc-year"
                        class="lml-hr-mc__select lml-focus-ring"
                        data-hr-mc-year
                    >
                        <option value="all">Year</option>
                        @foreach ($years as $year)
                            <option value="{{ $year }}">{{ $year }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-mc__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-mc__select-wrap">
                    <label class="visually-hidden" for="lml-hr-mc-month">Filter by month</label>
                    <select
                        id="lml-hr-mc-month"
                        class="lml-hr-mc__select lml-focus-ring"
                        data-hr-mc-month
                    >
                        <option value="all">Month</option>
                        @foreach ($months as $monthOption)
                            <option value="{{ $monthOption['value'] }}">{{ $monthOption['label'] }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-mc__select-icon" aria-hidden="true"></i>
                </div>
            </div>

            <p class="lml-hr-mc__results visually-hidden" data-hr-mc-results aria-live="polite">
                Showing {{ $totalUnfiltered }} of {{ $totalUnfiltered }} maternal care clients
            </p>

            @include('pages.health-records.partials.maternal-listing-table', [
                'rows' => $rows,
                'filterAttr' => 'zone',
                'emptyHint' => 'Try adjusting search, zone, year, or month.',
                'hasRecords' => $hasRecords,
                'tableVariant' => 'compact',
                'showClientView' => true,
            ])
        </div>
    </div>
@endsection
