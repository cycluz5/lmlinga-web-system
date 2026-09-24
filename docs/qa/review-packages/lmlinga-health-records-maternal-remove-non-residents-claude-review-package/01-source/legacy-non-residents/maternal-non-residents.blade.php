{{--
    Health Records → Maternal Care → Non-Residents listing (Figma-aligned).
    Female non-residents only. Filters are client-side.
--}}
@extends('layouts.dashboard')

@section('title', 'Maternal Care | Non Residents - LMLinga')

@section('content')
    @php
        $rows = $rows ?? [];
        $barangays = $barangays ?? [];
        $years = $years ?? [];
        $summary = $summary ?? [
            'total' => 0,
            'high_risk' => 0,
            'due_prenatal' => 0,
            'delivered' => 0,
            'incomplete_prenatal' => 0,
        ];
        $totalUnfiltered = $totalUnfiltered ?? count($rows);
        $pageDescription = 'Record and management of maternal care details for monitoring and tracking maternal health status.';
        $residentsUrl = route('health-records.maternal.index');
    @endphp

    <div
        class="lml-hr-mc lml-hr-mc--non-residents"
        data-lml-hr-mc
        data-lml-hr-mc-mode="non-resident"
        data-total="{{ $totalUnfiltered }}"
    >
        <div class="lml-hr-mc__panel">
            <h2 class="visually-hidden" id="lml-hr-mc-heading">Maternal Care | Non Residents</h2>
            <p class="visually-hidden" id="lml-hr-mc-desc">
                {{ $pageDescription }}
            </p>

            <div class="lml-hr-mc__action-row" data-hr-mc-action-row>
                <div class="lml-hr-mc__action-left">
                    <a
                        href="{{ $residentsUrl }}"
                        class="lml-hr-mc__back-btn lml-focus-ring"
                        data-hr-mc-back
                        aria-label="Back to resident Maternal Care listing"
                    >
                        <i class="bi bi-arrow-left" aria-hidden="true"></i>
                        <span>Back</span>
                    </a>
                </div>
                <div class="lml-hr-mc__action-right" role="group" aria-label="Maternal Care actions">
                    <a
                        href="{{ route('health-records.maternal.non-residents.create') }}"
                        class="lml-hr-mc__add-btn lml-focus-ring"
                        data-hr-mc-add
                        aria-label="Add non-resident maternal client"
                    >
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        <span>Add</span>
                    </a>
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
                class="lml-hr-mc__stats"
                role="group"
                aria-label="Non-resident maternal care summary"
            >
                <article class="lml-hr-mc__card lml-hr-mc__card--total">
                    <div class="lml-hr-mc__card-body">
                        <p class="lml-hr-mc__card-label">Total Pregnancy Clients</p>
                        <p class="lml-hr-mc__card-value" data-mc-stat="total">{{ $summary['total'] }}</p>
                    </div>
                </article>
                <article class="lml-hr-mc__card lml-hr-mc__card--risk">
                    <div class="lml-hr-mc__card-body">
                        <p class="lml-hr-mc__card-label">High Risk Pregnancies</p>
                        <p class="lml-hr-mc__card-value lml-hr-mc__card-value--risk" data-mc-stat="high-risk">{{ $summary['high_risk'] }}</p>
                    </div>
                </article>
                <article class="lml-hr-mc__card lml-hr-mc__card--due">
                    <div class="lml-hr-mc__card-body">
                        <p class="lml-hr-mc__card-label">Due for Prenatal Visit</p>
                        <p class="lml-hr-mc__card-value lml-hr-mc__card-value--due" data-mc-stat="due">{{ $summary['due_prenatal'] }}</p>
                    </div>
                </article>
                <article class="lml-hr-mc__card lml-hr-mc__card--delivered">
                    <div class="lml-hr-mc__card-body">
                        <p class="lml-hr-mc__card-label">Delivered Cases</p>
                        <p class="lml-hr-mc__card-value lml-hr-mc__card-value--delivered" data-mc-stat="delivered">{{ $summary['delivered'] }}</p>
                    </div>
                </article>
                <article class="lml-hr-mc__card lml-hr-mc__card--incomplete">
                    <div class="lml-hr-mc__card-body">
                        <p class="lml-hr-mc__card-label">Incomplete Prenatal</p>
                        <p class="lml-hr-mc__card-value lml-hr-mc__card-value--incomplete" data-mc-stat="incomplete">{{ $summary['incomplete_prenatal'] }}</p>
                    </div>
                </article>
            </div>

            <div
                class="lml-hr-mc__filters"
                role="toolbar"
                aria-label="Non-resident maternal care search and filters"
            >
                <div class="lml-hr-mc__search">
                    <label class="visually-hidden" for="lml-hr-mc-nr-search">Search Name</label>
                    <i class="bi bi-search lml-hr-mc__search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="lml-hr-mc-nr-search"
                        class="lml-hr-mc__search-input lml-focus-ring"
                        data-hr-mc-search
                        placeholder="Search Name"
                        autocomplete="off"
                    >
                </div>

                <div class="lml-hr-mc__select-wrap">
                    <label class="visually-hidden" for="lml-hr-mc-barangay">Filter by barangay</label>
                    <select
                        id="lml-hr-mc-barangay"
                        class="lml-hr-mc__select lml-focus-ring"
                        data-hr-mc-barangay
                    >
                        <option value="all">Barangay</option>
                        @foreach ($barangays as $barangay)
                            <option value="{{ $barangay }}">{{ $barangay }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-mc__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-mc__select-wrap">
                    <label class="visually-hidden" for="lml-hr-mc-nr-year">Filter by year</label>
                    <select
                        id="lml-hr-mc-nr-year"
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
            </div>

            <p class="lml-hr-mc__results visually-hidden" data-hr-mc-results aria-live="polite">
                Showing {{ $totalUnfiltered }} of {{ $totalUnfiltered }} non-resident maternal care clients
            </p>

            @include('pages.health-records.partials.maternal-listing-table', [
                'rows' => $rows,
                'filterAttr' => 'barangay',
                'emptyHint' => 'Try adjusting search, barangay, or year.',
                'showClientView' => true,
            ])
        </div>
    </div>
@endsection
