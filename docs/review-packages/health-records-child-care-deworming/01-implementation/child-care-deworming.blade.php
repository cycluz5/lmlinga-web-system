{{--
    Health Records — Child Care → Deworming monitoring summary (Figma-aligned).

    Data: App\Support\HealthRecordsDeworming — UI-phase Figma preview/demo display
    values only (not persisted; not authoritative production aggregates).
    Filters operate client-side on displayed preview rows. Export matches
    Vitamin A / Child Care UI-phase toast.
--}}
@extends('layouts.dashboard')

@section('title', 'Child Care — Deworming - LMLinga')

@section('content')
    @php
        $rows = $rows ?? [];
        $zones = $zones ?? [];
        $summary = $summary ?? [
            'first_round' => '0',
            'second_round' => '0',
            'received_1_dose_pct' => '0%',
            'received_2_dose_pct' => '0%',
        ];
        $statusFilterOptions = $statusFilterOptions ?? ['all' => 'Status'];
        $vitaminAUrl = route('health-records.child-care.vitamin-a');
        $dewormingUrl = route('health-records.child-care.deworming');
        $operationTimbangUrl = route('health-records.child-care.operation-timbang');
        $pageDescription = 'Record and management of deworming details for monitoring and tracking treatment status.';
        $totalRows = count($rows);
    @endphp

    <div
        class="lml-hr-child-care lml-hr-child-care--deworming"
        data-lml-hr-deworming
        data-dw-data-mode="figma-preview"
        data-total="{{ $totalRows }}"
    >
        <div class="lml-hr-child-care__panel">
            <header class="lml-hr-child-care__top">
                <div class="lml-hr-child-care__title-row">
                    <h2 class="lml-hr-child-care__title" id="lml-hr-deworming-heading">Child Care</h2>
                    <nav class="lml-hr-child-care__nav-pills" aria-label="Child Care related summaries">
                        <a
                            href="{{ $vitaminAUrl }}"
                            class="lml-hr-child-care__pill lml-focus-ring"
                        >
                            Vitamin A
                        </a>
                        <a
                            href="{{ $dewormingUrl }}"
                            class="lml-hr-child-care__pill lml-hr-child-care__pill--active lml-focus-ring"
                            aria-current="page"
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

                <div class="lml-hr-child-care__actions" role="group" aria-label="Deworming actions">
                    <button
                        type="button"
                        class="lml-hr-child-care__add-btn lml-focus-ring"
                        data-hr-dw-add
                        aria-label="Add deworming record"
                    >
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        <span>Add</span>
                    </button>
                    <button
                        type="button"
                        class="lml-hr-child-care__export-btn lml-focus-ring"
                        data-hr-dw-export
                        aria-label="Export Deworming data"
                    >
                        <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                        <span>Export Data</span>
                    </button>
                </div>
            </header>

            <p class="lml-hr-child-care__description" id="lml-hr-deworming-desc">
                {{ $pageDescription }}
            </p>

            <div
                class="lml-hr-child-care__toast"
                data-hr-dw-toast
                role="status"
                aria-live="polite"
                hidden
            ></div>

            <div
                class="lml-hr-child-care__stats lml-hr-child-care__stats--deworming"
                role="group"
                aria-label="Deworming monitoring summary"
            >
                <article class="lml-hr-dw-card lml-hr-dw-card--first-round">
                    <div class="lml-hr-dw-card__body">
                        <p class="lml-hr-dw-card__label">First Round (July)</p>
                        <p class="lml-hr-dw-card__value" data-dw-stat="first-round">{{ $summary['first_round'] }}</p>
                    </div>
                </article>

                <article class="lml-hr-dw-card lml-hr-dw-card--second-round">
                    <div class="lml-hr-dw-card__body">
                        <p class="lml-hr-dw-card__label">Second Round (January)</p>
                        <p class="lml-hr-dw-card__value" data-dw-stat="second-round">{{ $summary['second_round'] }}</p>
                    </div>
                </article>

                <article class="lml-hr-dw-card lml-hr-dw-card--status">
                    <div class="lml-hr-dw-card__body">
                        <p class="lml-hr-dw-card__label">Status</p>
                        <dl class="lml-hr-dw-card__status-list">
                            <div class="lml-hr-dw-card__status-row">
                                <dt>Received 1 dose/year</dt>
                                <dd data-dw-stat="received-1-dose">{{ $summary['received_1_dose_pct'] }}</dd>
                            </div>
                            <div class="lml-hr-dw-card__status-row">
                                <dt>Received 2 dose/year</dt>
                                <dd data-dw-stat="received-2-dose">{{ $summary['received_2_dose_pct'] }}</dd>
                            </div>
                        </dl>
                    </div>
                </article>
            </div>

            <div
                class="lml-hr-child-care__filters lml-hr-child-care__filters--deworming"
                role="toolbar"
                aria-label="Deworming search and filters"
            >
                <div class="lml-hr-child-care__search">
                    <label class="visually-hidden" for="lml-hr-dw-search">Name of Child</label>
                    <i class="bi bi-search lml-hr-child-care__search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="lml-hr-dw-search"
                        class="lml-hr-child-care__search-input lml-focus-ring"
                        data-hr-dw-search
                        placeholder="Name of Child"
                        autocomplete="off"
                    >
                </div>

                <div class="lml-hr-child-care__select-wrap">
                    <label class="visually-hidden" for="lml-hr-dw-zone">Filter by zone</label>
                    <select
                        id="lml-hr-dw-zone"
                        class="lml-hr-child-care__select lml-focus-ring"
                        data-hr-dw-zone
                    >
                        <option value="all">All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}">{{ $zone }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-child-care__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-child-care__select-wrap lml-hr-child-care__select-wrap--sex">
                    <label class="visually-hidden" for="lml-hr-dw-sex">Filter by sex</label>
                    <select
                        id="lml-hr-dw-sex"
                        class="lml-hr-child-care__select lml-focus-ring"
                        data-hr-dw-sex
                    >
                        <option value="all">Sex</option>
                        <option value="female">Female</option>
                        <option value="male">Male</option>
                    </select>
                    <i class="bi bi-chevron-down lml-hr-child-care__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-child-care__select-wrap">
                    <label class="visually-hidden" for="lml-hr-dw-status">Filter by status</label>
                    <select
                        id="lml-hr-dw-status"
                        class="lml-hr-child-care__select lml-focus-ring"
                        data-hr-dw-status
                    >
                        @foreach ($statusFilterOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-child-care__select-icon" aria-hidden="true"></i>
                </div>
            </div>

            <p class="lml-hr-child-care__results visually-hidden" data-hr-dw-results aria-live="polite">
                Showing {{ $totalRows }} of {{ $totalRows }} children
            </p>

            <div class="lml-hr-child-care__table-card lml-hr-child-care__table-card--deworming">
                <div
                    class="lml-hr-child-care__table-scroll lml-hr-child-care__table-scroll--deworming"
                    tabindex="0"
                    aria-labelledby="lml-hr-deworming-heading"
                    aria-describedby="lml-hr-deworming-desc"
                >
                    <table class="lml-hr-child-care__table lml-hr-child-care__table--deworming">
                        <caption class="visually-hidden">
                            Barangay-wide Deworming monitoring by child name, age,
                            July round date, and January round date.
                            Figures and rows are UI-phase Figma preview/demo values
                            pending authoritative backend integration.
                        </caption>
                        <colgroup>
                            <col class="lml-hr-dw-col lml-hr-dw-col--name">
                            <col class="lml-hr-dw-col lml-hr-dw-col--age">
                            <col class="lml-hr-dw-col lml-hr-dw-col--july">
                            <col class="lml-hr-dw-col lml-hr-dw-col--january">
                            <col class="lml-hr-dw-col lml-hr-dw-col--action">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Full Name</th>
                                <th scope="col">Age</th>
                                <th scope="col">July Round (Date)</th>
                                <th scope="col">January Round (Date)</th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody data-hr-dw-tbody>
                            @foreach ($rows as $row)
                                <tr
                                    data-hr-dw-row
                                    data-name="{{ strtolower($row['full_name']) }}"
                                    data-zone="{{ $row['zone'] }}"
                                    data-sex="{{ $row['sex'] }}"
                                    data-status="{{ $row['status'] }}"
                                    data-child-key="{{ $row['key'] }}"
                                >
                                    <th scope="row" class="lml-hr-dw-cell lml-hr-dw-cell--name">
                                        {{ $row['full_name'] }}
                                    </th>
                                    <td class="lml-hr-dw-cell lml-hr-dw-cell--age">
                                        {{ $row['age_label'] }}
                                    </td>
                                    <td class="lml-hr-dw-cell lml-hr-dw-cell--date">
                                        {{ $row['july_round'] }}
                                    </td>
                                    <td class="lml-hr-dw-cell lml-hr-dw-cell--date">
                                        {{ $row['january_round'] }}
                                    </td>
                                    <td class="lml-hr-dw-cell lml-hr-dw-cell--action">
                                        @if (! empty($row['view_url']))
                                            <a
                                                href="{{ $row['view_url'] }}"
                                                class="lml-hr-child-care__view-btn lml-focus-ring"
                                                data-hr-dw-view
                                                aria-label="View Deworming record for {{ $row['full_name'] }}"
                                            >
                                                <i class="bi bi-eye" aria-hidden="true"></i>
                                                <span>View</span>
                                            </a>
                                        @else
                                            <span class="lml-hr-dw-cell__na" aria-label="No resident Deworming record for {{ $row['full_name'] }}">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div
                    class="lml-hr-child-care__empty"
                    data-hr-dw-empty
                    hidden
                >
                    <div class="lml-hr-child-care__empty-icon" aria-hidden="true">
                        <i class="bi bi-search"></i>
                    </div>
                    <p class="lml-hr-child-care__empty-title">No matching children</p>
                    <p class="lml-hr-child-care__empty-hint">Try adjusting search or filters.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
