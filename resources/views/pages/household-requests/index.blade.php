{{--
    Household Requests — Admin monitoring / history.
    Rows come from authoritative record_requests (HouseholdRecordRequestPresenter).
--}}
@extends('layouts.dashboard')

@section('title', 'Household Requests - LMLinga')

@php
    $requests = $requests ?? [];
    $hasRecords = $hasRecords ?? count($requests) > 0;
    $zoneFilterOptions = $zoneOptions ?? [];
    $zoneOptions = ['all' => 'All Zones'];
    foreach ($zoneFilterOptions as $zone) {
        $zoneOptions[$zone] = $zone;
    }
    $statusOptions = [
        'all' => 'All Statuses',
        'Approved' => 'Approved',
        'Rejected' => 'Rejected',
    ];
@endphp

@section('content')
    <div
        class="lml-hr"
        data-lml-household-requests
        data-has-records="{{ $hasRecords ? '1' : '0' }}"
    >
        <div class="lml-hr__toolbar" role="search" aria-label="Filter household requests">
            <div class="lml-hr__search">
                <i class="bi bi-search lml-hr__search-icon" aria-hidden="true"></i>
                <label class="visually-hidden" for="lml-hr-search">Search Requester</label>
                <input
                    type="search"
                    id="lml-hr-search"
                    class="lml-hr__search-input"
                    placeholder="Search Requester"
                    autocomplete="off"
                    data-hr-search
                >
            </div>

            <div class="lml-hr__toolbar-end">
                <div class="lml-hr__select-wrap">
                    <label class="visually-hidden" for="lml-hr-zone">Zone</label>
                    <select
                        id="lml-hr-zone"
                        class="lml-hr__select"
                        data-hr-zone
                        aria-label="Zone"
                    >
                        @foreach ($zoneOptions as $value => $label)
                            <option value="{{ $value }}" @selected($value === 'all')>{{ $label }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr__select-wrap">
                    <label class="visually-hidden" for="lml-hr-status">Status</label>
                    <select
                        id="lml-hr-status"
                        class="lml-hr__select"
                        data-hr-status
                        aria-label="Status"
                    >
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($value === 'all')>{{ $label }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr__select-icon" aria-hidden="true"></i>
                </div>
            </div>
        </div>

        <div class="lml-hr-table-wrap" data-hr-table-wrap @if (! $hasRecords) hidden @endif>
            <table class="lml-hr-table" data-hr-table>
                <caption class="visually-hidden">Household record access requests by name, zone, and status</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Zone</th>
                        <th scope="col">Status</th>
                        <th scope="col">View</th>
                    </tr>
                </thead>
                <tbody data-hr-tbody>
                    @foreach ($requests as $request)
                        <x-lml.household-requests.request-row
                            :id="$request['id']"
                            :name="$request['name']"
                            :first-name="$request['first_name'] ?? ''"
                            :middle-name="$request['middle_name'] ?? ''"
                            :last-name="$request['last_name'] ?? ''"
                            :zone="$request['zone']"
                            :status="$request['status']"
                        />
                    @endforeach
                </tbody>
            </table>
        </div>

        <p
            class="lml-hr__empty"
            role="status"
            aria-live="polite"
            @if ($hasRecords) hidden @endif
            data-hr-empty
        >
            @if ($hasRecords)
                No household requests match your search, zone, or status filters.
            @else
                No household record access requests are available.
            @endif
        </p>
    </div>
@endsection
