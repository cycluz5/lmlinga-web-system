{{--
    Health Records — Death.
    Persisted death_requests listing + DemoCatalog resident picker for submissions.
    Independent of Household Profiling DemoDeath session preview.
--}}
@extends('layouts.dashboard')

@section('title', 'Death - LMLinga')

@section('content')
    @php
        $rows = $rows ?? [];
        $zones = $zones ?? [];
        $causes = $causes ?? [];
        $years = $years ?? [];
        $residents = $residents ?? [];
        $summary = $summary ?? ['total' => 0, 'female' => 0, 'male' => 0];
        $totalUnfiltered = $totalUnfiltered ?? count($rows);
        $pageDescription = 'Submit a death record for a selected resident. Admin verification is required before the resident is marked deceased.';
        $hasRows = $totalUnfiltered > 0;
    @endphp

    <div
        class="lml-hr-death"
        data-lml-hr-death
        data-death-data-mode="persisted"
        data-total="{{ $totalUnfiltered }}"
    >
        <div class="lml-hr-death__panel">
            <header class="lml-hr-death__top">
                <div class="lml-hr-death__title-block">
                    <h2 class="lml-hr-death__title" id="lml-hr-death-heading">Death</h2>
                    <p class="lml-hr-death__description" id="lml-hr-death-desc">
                        {{ $pageDescription }}
                    </p>
                </div>

                <div class="lml-hr-death__actions">
                    <a
                        href="#lml-hr-death-residents"
                        class="lml-hr-death__record-btn lml-focus-ring"
                        data-hr-death-record
                    >
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        <span>Record death</span>
                    </a>
                    <button
                        type="button"
                        class="lml-hr-death__export-btn"
                        data-hr-death-export
                        disabled
                        aria-disabled="true"
                        aria-label="Export Death data"
                        aria-describedby="lml-hr-death-export-note"
                    >
                        <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                        <span>Export Data</span>
                    </button>
                    <p id="lml-hr-death-export-note" class="visually-hidden">
                        Export is not available. No export destination is implemented for Death records.
                    </p>
                </div>
            </header>

            <div
                class="lml-hr-death__stats"
                role="group"
                aria-label="Approved death barangay summary"
            >
                <article class="lml-hr-death__card lml-hr-death__card--total">
                    <div class="lml-hr-death__card-body">
                        <p class="lml-hr-death__card-label">Total Deaths</p>
                        <p class="lml-hr-death__card-value" data-death-stat="total">{{ $summary['total'] }}</p>
                    </div>
                    <span class="lml-hr-death__card-icon" aria-hidden="true">
                        <i class="bi bi-archive"></i>
                    </span>
                </article>

                <article class="lml-hr-death__card lml-hr-death__card--female">
                    <div class="lml-hr-death__card-body">
                        <p class="lml-hr-death__card-label">Female</p>
                        <p class="lml-hr-death__card-value" data-death-stat="female">{{ $summary['female'] }}</p>
                    </div>
                    <span class="lml-hr-death__card-icon" aria-hidden="true">
                        <i class="bi bi-gender-female"></i>
                    </span>
                </article>

                <article class="lml-hr-death__card lml-hr-death__card--male">
                    <div class="lml-hr-death__card-body">
                        <p class="lml-hr-death__card-label">Male</p>
                        <p class="lml-hr-death__card-value" data-death-stat="male">{{ $summary['male'] }}</p>
                    </div>
                    <span class="lml-hr-death__card-icon" aria-hidden="true">
                        <i class="bi bi-gender-male"></i>
                    </span>
                </article>
            </div>

            <div
                class="lml-hr-death__filters"
                role="search"
                aria-label="Death search and filters"
            >
                <div class="lml-hr-death__search">
                    <label class="visually-hidden" for="lml-hr-death-search">Search Name</label>
                    <i class="bi bi-search lml-hr-death__search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="lml-hr-death-search"
                        class="lml-hr-death__search-input lml-focus-ring"
                        data-hr-death-search
                        placeholder="Search Name"
                        autocomplete="off"
                    >
                </div>

                <div class="lml-hr-death__select-wrap">
                    <label class="visually-hidden" for="lml-hr-death-zone">Filter by zone</label>
                    <select
                        id="lml-hr-death-zone"
                        class="lml-hr-death__select lml-focus-ring"
                        data-hr-death-zone
                    >
                        <option value="all">All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}">{{ $zone }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-death__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-death__select-wrap">
                    <label class="visually-hidden" for="lml-hr-death-cause">Filter by cause of death</label>
                    <select
                        id="lml-hr-death-cause"
                        class="lml-hr-death__select lml-focus-ring"
                        data-hr-death-cause
                    >
                        <option value="all">Cause of Death</option>
                        @foreach ($causes as $cause)
                            <option value="{{ $cause }}">{{ $cause }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-death__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-death__select-wrap">
                    <label class="visually-hidden" for="lml-hr-death-sex">Filter by sex</label>
                    <select
                        id="lml-hr-death-sex"
                        class="lml-hr-death__select lml-focus-ring"
                        data-hr-death-sex
                    >
                        <option value="all">Sex</option>
                        <option value="female">Female</option>
                        <option value="male">Male</option>
                    </select>
                    <i class="bi bi-chevron-down lml-hr-death__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-death__select-wrap">
                    <label class="visually-hidden" for="lml-hr-death-year">Filter by year</label>
                    <select
                        id="lml-hr-death-year"
                        class="lml-hr-death__select lml-focus-ring"
                        data-hr-death-year
                    >
                        <option value="all">Year</option>
                        @foreach ($years as $year)
                            <option value="{{ $year }}">{{ $year }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-death__select-icon" aria-hidden="true"></i>
                </div>
            </div>

            <p class="lml-hr-death__results visually-hidden" data-hr-death-results aria-live="polite">
                Showing {{ $totalUnfiltered }} of {{ $totalUnfiltered }} death records
            </p>

            <div class="lml-hr-death__table-card">
                <div
                    class="lml-hr-death__table-scroll"
                    tabindex="0"
                    aria-labelledby="lml-hr-death-heading"
                    aria-describedby="lml-hr-death-desc"
                    @if (! $hasRows) hidden @endif
                >
                    <table class="lml-hr-death__table">
                        <caption class="visually-hidden">
                            Submitted death records by full name, age, cause of death, date of death, and status.
                        </caption>
                        <colgroup>
                            <col class="lml-hr-death__col lml-hr-death__col--name">
                            <col class="lml-hr-death__col lml-hr-death__col--age">
                            <col class="lml-hr-death__col lml-hr-death__col--cause">
                            <col class="lml-hr-death__col lml-hr-death__col--date">
                            <col class="lml-hr-death__col lml-hr-death__col--status">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Full Name</th>
                                <th scope="col">Age</th>
                                <th scope="col">Cause of Death</th>
                                <th scope="col">Date of Death</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody data-hr-death-tbody>
                            @foreach ($rows as $row)
                                <tr
                                    data-hr-death-row
                                    data-name="{{ strtolower(trim($row['full_name'].' '.($row['member_id'] ?? '').' '.($row['sex'] ?? ''))) }}"
                                    data-zone="{{ $row['zone'] }}"
                                    data-cause="{{ $row['cause_of_death'] }}"
                                    data-sex="{{ $row['sex_filter'] }}"
                                    data-year="{{ $row['year'] }}"
                                    data-row-key="{{ $row['key'] }}"
                                >
                                    <th scope="row" class="lml-hr-death__cell lml-hr-death__cell--name">
                                        <a href="{{ $row['open_url'] }}" class="lml-hr-death__name-link lml-focus-ring">
                                            {{ $row['full_name'] }}
                                        </a>
                                        @if (($row['member_id'] ?? '') !== '' || ($row['sex'] ?? '') !== '')
                                            <span class="lml-hr-death__resident-meta">
                                                {{ implode(' · ', array_filter([
                                                    (string) ($row['sex'] ?? ''),
                                                    (string) ($row['member_id'] ?? ''),
                                                ])) }}
                                            </span>
                                        @endif
                                    </th>
                                    <td class="lml-hr-death__cell lml-hr-death__cell--age">
                                        {{ $row['age'] }}
                                    </td>
                                    <td class="lml-hr-death__cell lml-hr-death__cell--cause">
                                        {{ $row['cause_of_death'] }}
                                    </td>
                                    <td class="lml-hr-death__cell lml-hr-death__cell--date">
                                        {{ $row['date_of_death'] }}
                                    </td>
                                    <td class="lml-hr-death__cell lml-hr-death__cell--status">
                                        <span
                                            class="lml-hr-death__status lml-hr-death__status--{{ $row['status'] }}"
                                        >
                                            {{ $row['status_label'] }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div
                    class="lml-hr-death__empty"
                    data-hr-death-empty
                    role="status"
                    @if ($hasRows) hidden @endif
                >
                    <div class="lml-hr-death__empty-icon" aria-hidden="true">
                        <i class="bi bi-journal-x"></i>
                    </div>
                    <p class="lml-hr-death__empty-title" data-hr-death-empty-title>
                        @if ($hasRows)
                            No death records match the selected filters.
                        @else
                            No death records have been recorded yet.
                        @endif
                    </p>
                    <p class="lml-hr-death__empty-hint" data-hr-death-empty-hint>
                        @if ($hasRows)
                            Try adjusting search, zone, cause, sex, or year.
                        @else
                            Select a resident below to submit a death record for Admin verification.
                        @endif
                    </p>
                </div>
            </div>
        </div>

        <section
            class="lml-hr-death__panel lml-hr-death__panel--residents"
            id="lml-hr-death-residents"
            aria-labelledby="lml-hr-death-residents-heading"
            data-hr-death-residents
        >
            <header class="lml-hr-death__residents-head">
                <div>
                    <h3 class="lml-hr-death__residents-title" id="lml-hr-death-residents-heading">
                        Select a resident
                    </h3>
                    <p class="lml-hr-death__residents-hint">
                        A death submission must identify a specific resident. Open a resident to begin.
                    </p>
                </div>
                <div class="lml-hr-death__search lml-hr-death__search--residents">
                    <label class="visually-hidden" for="lml-hr-death-resident-search">Search residents</label>
                    <i class="bi bi-search lml-hr-death__search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="lml-hr-death-resident-search"
                        class="lml-hr-death__search-input lml-focus-ring"
                        data-hr-death-resident-search
                        placeholder="Search resident name"
                        autocomplete="off"
                    >
                </div>
            </header>

            <div class="lml-hr-death__table-scroll" tabindex="0">
                <table class="lml-hr-death__table">
                    <caption class="visually-hidden">
                        Residents available for death record submission.
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Resident</th>
                            <th scope="col">Household</th>
                            <th scope="col">Zone</th>
                            <th scope="col">Status</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody data-hr-death-resident-tbody>
                        @foreach ($residents as $resident)
                            @php
                                $identityParts = array_values(array_filter([
                                    (string) ($resident['sex'] ?? ''),
                                    (string) ($resident['age'] ?? ''),
                                    (string) ($resident['relationship'] ?? ''),
                                ], static fn (string $part): bool => $part !== '' && $part !== '—'));
                                $idParts = array_values(array_filter([
                                    (string) ($resident['member_id'] ?? ''),
                                    filled($resident['birthday_display'] ?? null)
                                        ? 'Born '.$resident['birthday_display']
                                        : '',
                                ]));
                                $actionVerb = $resident['can_submit'] ? 'Record death for' : 'Open death record for';
                                $ariaIdentity = implode(', ', array_filter([
                                    (string) $resident['full_name'],
                                    (string) ($resident['sex'] ?? ''),
                                    (string) ($resident['relationship'] ?? ''),
                                    (string) ($resident['member_id'] ?? ''),
                                ]));
                            @endphp
                            <tr
                                data-hr-death-resident-row
                                data-name="{{ strtolower($resident['full_name']) }}"
                                data-search="{{ $resident['identity_search'] ?? strtolower($resident['full_name']) }}"
                            >
                                <th scope="row" class="lml-hr-death__cell">
                                    {{ $resident['full_name'] }}
                                    @if ($identityParts !== [])
                                        <span class="lml-hr-death__resident-meta">
                                            {{ implode(' · ', $identityParts) }}
                                        </span>
                                    @endif
                                    @if ($idParts !== [])
                                        <span class="lml-hr-death__resident-meta">
                                            {{ implode(' · ', $idParts) }}
                                        </span>
                                    @endif
                                </th>
                                <td class="lml-hr-death__cell">{{ $resident['household_display'] }}</td>
                                <td class="lml-hr-death__cell">{{ $resident['zone'] }}</td>
                                <td class="lml-hr-death__cell">
                                    <span class="lml-hr-death__status lml-hr-death__status--{{ $resident['status'] }}">
                                        {{ $resident['vital_label'] }}
                                    </span>
                                </td>
                                <td class="lml-hr-death__cell">
                                    <a
                                        href="{{ $resident['open_url'] }}"
                                        class="lml-hr-death__open-btn lml-focus-ring"
                                        aria-label="{{ $actionVerb }} {{ $ariaIdentity }}"
                                    >
                                        {{ $resident['can_submit'] ? 'Record death' : 'Open' }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
