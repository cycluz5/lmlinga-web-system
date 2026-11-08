{{--
    Household Profiling — operational list.
    Table and summary cards both use persisted Household/Resident records only.
    Export remains a UI demonstration. Delete is available only when the schema
    supports household archival (deleted_at); disabled on authoritative ERD.
--}}
@extends('layouts.dashboard')

@section('title', 'Household Profiling - LMLinga')

@php
    $demoHouseholds = $demoHouseholds ?? [];
    $demoTotal = $demoTotal ?? count($demoHouseholds);
    $profilingSummary = $profilingSummary ?? [
        'households' => 0,
        'respondents' => 0,
        'male' => 0,
        'female' => 0,
    ];

    $demoZones = ['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5'];
    $householdDeletionEnabled = $householdDeletionEnabled ?? false;
@endphp

@section('content')
    <div
        class="lml-hh-profiling"
        data-lml-hh-profiling
        data-total="{{ $demoTotal }}"
        data-hh-export-url="{{ route('household-profiling.report-builder') }}"
        data-hh-destroy-template="{{ url('/household-profiling') }}/__HH__"
        data-offline-hp-bootstrap-url="{{ route('offline.household-profiling-bootstrap') }}"
    >
        <div class="lml-hh-profiling__header">
            <p
                class="lml-hh-profiling__offline-ready"
                data-hp-offline-ready
                role="status"
                aria-live="polite"
                hidden
            ></p>
            <div class="lml-hh-profiling__header-actions">
                <a
                    href="{{ route('household-profiling.create') }}"
                    class="lml-hh-profiling__add-btn lml-focus-ring"
                    data-hh-add-information
                >
                    <i class="bi bi-house-add-fill" aria-hidden="true"></i>
                    <span>Add Household Information</span>
                </a>
                <button
                    type="button"
                    class="lml-hh-profiling__export-btn lml-focus-ring"
                    data-hh-export
                >
                    <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                    <span>Export Data</span>
                </button>
            </div>
        </div>

        <p class="lml-hh-profiling__info-hint">
            Household information can be added even if GPS or map access is unavailable. Its location can be plotted later from Pending Households.
        </p>

        <div
            class="lml-hh-profiling__stats"
            role="group"
            aria-label="Registered household profiling summary"
        >
            <article class="lml-hh-profiling__card">
                <div class="lml-hh-profiling__card-body">
                    <p class="lml-hh-profiling__card-label">Total Households</p>
                    <p class="lml-hh-profiling__card-value" data-stat="households">{{ $profilingSummary['households'] }}</p>
                </div>
                <span class="lml-hh-profiling__card-icon" aria-hidden="true">
                    <i class="bi bi-house-door-fill"></i>
                </span>
            </article>
            <article class="lml-hh-profiling__card">
                <div class="lml-hh-profiling__card-body">
                    <p class="lml-hh-profiling__card-label">Total Respondents</p>
                    <p class="lml-hh-profiling__card-value" data-stat="respondents">{{ $profilingSummary['respondents'] }}</p>
                </div>
                <span class="lml-hh-profiling__card-icon" aria-hidden="true">
                    <i class="bi bi-people-fill"></i>
                </span>
            </article>
            <article class="lml-hh-profiling__card">
                <div class="lml-hh-profiling__card-body">
                    <p class="lml-hh-profiling__card-label">Male</p>
                    <p class="lml-hh-profiling__card-value" data-stat="male">{{ $profilingSummary['male'] }}</p>
                </div>
                <span class="lml-hh-profiling__card-icon" aria-hidden="true">
                    <i class="bi bi-gender-male"></i>
                </span>
            </article>
            <article class="lml-hh-profiling__card">
                <div class="lml-hh-profiling__card-body">
                    <p class="lml-hh-profiling__card-label">Female</p>
                    <p class="lml-hh-profiling__card-value" data-stat="female">{{ $profilingSummary['female'] }}</p>
                </div>
                <span class="lml-hh-profiling__card-icon" aria-hidden="true">
                    <i class="bi bi-gender-female"></i>
                </span>
            </article>
        </div>

        <div class="lml-hh-profiling__toolbar" role="toolbar" aria-label="Household search and filters">
            <div class="lml-hh-profiling__search">
                <label class="visually-hidden" for="lml-hh-search">Search Household Head</label>
                <i class="bi bi-search lml-hh-profiling__search-icon" aria-hidden="true"></i>
                <input
                    type="search"
                    id="lml-hh-search"
                    class="lml-hh-profiling__search-input lml-focus-ring"
                    data-hh-search
                    placeholder="Search Household Head"
                    autocomplete="off"
                >
            </div>

            <div class="lml-hh-profiling__select-wrap">
                <label class="visually-hidden" for="lml-hh-zone">Filter by zone</label>
                <select
                    id="lml-hh-zone"
                    class="lml-hh-profiling__select lml-focus-ring"
                    data-hh-zone
                >
                    <option value="all">All Zones</option>
                    @foreach ($demoZones as $zone)
                        <option value="{{ $zone }}">{{ $zone }}</option>
                    @endforeach
                </select>
                <i class="bi bi-chevron-down lml-hh-profiling__select-icon" aria-hidden="true"></i>
            </div>
        </div>

        <div
            class="lml-hh-profiling__toast"
            data-hh-toast
            role="status"
            aria-live="polite"
            hidden
        ></div>

        <p class="lml-hh-profiling__results" data-hh-results aria-live="polite">
            Showing {{ $demoTotal }} of {{ $demoTotal }} households
        </p>

        <div class="lml-hh-profiling__table-card">
            <div class="lml-hh-profiling__table-scroll" tabindex="0" role="region" aria-label="Household records table">
                <table class="lml-hh-profiling__table">
                    <caption class="visually-hidden">
                        Household records for Barangay La Medalla. Rows are persisted database households.
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">HH No.</th>
                            <th scope="col">HH Head</th>
                            <th scope="col">Zone</th>
                            <th scope="col">No. of Members</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody data-hh-tbody>
                        @foreach ($demoHouseholds as $household)
                            @php
                                $rowSource = $household['source'] ?? 'db';
                            @endphp
                            <tr
                                data-hh-row
                                data-id="{{ $household['id'] }}"
                                data-household-no="{{ $household['householdNo'] }}"
                                data-house-head="{{ $household['houseHead'] }}"
                                data-zone="{{ $household['zone'] }}"
                                data-street="{{ $household['street'] ?? '' }}"
                                data-members="{{ $household['members'] }}"
                                data-male="{{ $household['male'] ?? 0 }}"
                                data-female="{{ $household['female'] ?? 0 }}"
                                data-source="{{ $rowSource }}"
                            >
                                <td data-label="HH No.">
                                    <span class="lml-hh-profiling__hh-no">{{ $household['displayNo'] ?? \App\Support\HouseholdNumber::forDisplay($household['householdNo']) }}</span>
                                </td>
                                <td data-label="HH Head">{{ $household['houseHead'] }}</td>
                                <td data-label="Zone">{{ $household['zone'] }}</td>
                                <td data-label="No. of Members">{{ $household['members'] }}</td>
                                <td data-label="Actions">
                                    <div class="lml-hh-profiling__actions" role="group" aria-label="Actions for {{ $household['householdNo'] }}">
                                        <a
                                            href="{{ route('household-profiling.view', ['householdNo' => $household['householdNo']]) }}"
                                            class="lml-hh-profiling__action-btn lml-hh-profiling__action-btn--view lml-focus-ring"
                                            data-hh-view
                                            aria-label="View {{ $household['householdNo'] }}"
                                        >
                                            <i class="bi bi-eye-fill" aria-hidden="true"></i>
                                            <span>View</span>
                                        </a>
                                        <a
                                            href="{{ route('household-profiling.members.create', ['householdNo' => $household['householdNo']]) }}"
                                            class="lml-hh-profiling__action-btn lml-hh-profiling__action-btn--add lml-focus-ring"
                                            data-hh-nav="add-member"
                                            aria-label="Add member to {{ $household['householdNo'] }}"
                                        >
                                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                            <span>Add</span>
                                        </a>
                                        @if ($householdDeletionEnabled)
                                            <button
                                                type="button"
                                                class="lml-hh-profiling__action-btn lml-hh-profiling__action-btn--delete lml-focus-ring"
                                                data-hh-action="delete"
                                                data-household-no="{{ $household['householdNo'] }}"
                                                data-household-key="{{ \App\Support\OpaqueId::forUrl('h', (string) $household['householdNo']) }}"
                                                aria-label="Delete {{ $household['householdNo'] }}"
                                            >
                                                <i class="bi bi-trash3" aria-hidden="true"></i>
                                                <span>Delete</span>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="lml-hh-profiling__empty" data-hh-empty hidden>
                <span class="lml-hh-profiling__empty-icon" aria-hidden="true">
                    <i class="bi bi-search"></i>
                </span>
                <p class="lml-hh-profiling__empty-title">No household records match your current filters.</p>
                <p class="lml-hh-profiling__empty-hint">Try clearing search or selecting All Zones.</p>
            </div>
        </div>

        <p class="lml-hh-profiling__demo-note">
            Summary cards count registered database households and residents.
        </p>

        @if ($householdDeletionEnabled)
        <div
            class="lml-hh-profiling__dialog-backdrop"
            data-hh-dialog
            hidden
        >
            <div
                class="lml-hh-profiling__dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="lml-hh-delete-title"
                aria-describedby="lml-hh-delete-message"
                tabindex="-1"
                data-hh-dialog-panel
            >
                <h2 id="lml-hh-delete-title" class="lml-hh-profiling__dialog-title">
                    Delete Household?
                </h2>
                <p id="lml-hh-delete-message" class="lml-hh-profiling__dialog-message">
                    Are you sure you want to delete this household record?
                    <br><br>
                    It will be removed from active Household Profiling and Spot Mapping views.
                    Related member and health records are kept for audit and are not permanently erased.
                </p>
                <form
                    method="post"
                    action="#"
                    class="lml-hh-profiling__dialog-actions"
                    data-hh-delete-form
                >
                    @csrf
                    @method('DELETE')
                    <button
                        type="button"
                        class="lml-hh-profiling__dialog-btn lml-hh-profiling__dialog-btn--cancel lml-focus-ring"
                        data-hh-dialog-cancel
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="lml-hh-profiling__dialog-btn lml-hh-profiling__dialog-btn--delete lml-focus-ring"
                        data-hh-dialog-confirm
                    >
                        Delete
                    </button>
                </form>
            </div>
        </div>
        @endif
    </div>
@endsection
