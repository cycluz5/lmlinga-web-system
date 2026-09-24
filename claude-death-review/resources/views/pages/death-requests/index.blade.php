{{--
    Admin Death Requests queue. Separate from Household Requests.
--}}
@extends('layouts.dashboard')

@section('title', 'Death Requests - LMLinga')

@php
    $requests = $requests ?? collect();
    $statusOptions = [
        'all' => 'All Statuses',
        'pending' => 'Pending verification',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];
@endphp

@section('content')
    <div class="lml-dr" data-lml-death-requests>
        <div class="lml-hr__toolbar" role="search" aria-label="Filter death requests">
            <div class="lml-hr__search">
                <i class="bi bi-search lml-hr__search-icon" aria-hidden="true"></i>
                <label class="visually-hidden" for="lml-dr-search">Search resident</label>
                <input
                    type="search"
                    id="lml-dr-search"
                    class="lml-hr__search-input"
                    placeholder="Search Resident"
                    autocomplete="off"
                    data-dr-search
                >
            </div>

            <div class="lml-hr__toolbar-end">
                <div class="lml-hr__select-wrap">
                    <label class="visually-hidden" for="lml-dr-status">Status</label>
                    <select id="lml-dr-status" class="lml-hr__select" data-dr-status aria-label="Status">
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($value === 'all')>{{ $label }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr__select-icon" aria-hidden="true"></i>
                </div>
            </div>
        </div>

        <div class="lml-hr-table-wrap lml-dr__table-wrap" data-dr-table-wrap @if ($requests->isEmpty()) hidden @endif>
            <table class="lml-hr-table lml-dr__table" data-dr-table>
                <caption class="visually-hidden">
                    Death verification requests by resident name, status, and review action.
                </caption>
                <colgroup>
                    <col class="lml-dr__col lml-dr__col--name">
                    <col class="lml-dr__col lml-dr__col--status">
                    <col class="lml-dr__col lml-dr__col--action">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Resident Name</th>
                        <th scope="col">Status</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($requests as $request)
                        <tr
                            class="lml-hr-table__row"
                            data-dr-row
                            data-dr-name="{{ trim($request->resident_name.' '.$request->member_id.' '.$request->resident_sex) }}"
                            data-dr-status="{{ $request->status }}"
                        >
                            <td
                                class="lml-hr-table__cell lml-hr-table__cell--name lml-dr__cell--name"
                                data-label="Resident Name"
                            >
                                <span class="lml-dr__identity-name">{{ $request->resident_name }}</span>
                            </td>
                            <td class="lml-hr-table__cell lml-dr__cell--status" data-label="Status">
                                <span class="lml-hr-table__status lml-dr__status--{{ $request->status }}">
                                    {{ $request->statusLabel() }}
                                </span>
                            </td>
                            <td class="lml-hr-table__cell lml-hr-table__cell--actions lml-dr__cell--action" data-label="Action">
                                <a
                                    href="{{ route('death-requests.show', $request) }}"
                                    class="lml-hr-table__view-btn lml-dr__review-btn lml-focus-ring"
                                    aria-label="Review death request for {{ $request->resident_name }}{{ $request->member_id ? ', '.$request->member_id : '' }}"
                                >
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                    <span>Review</span>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p
            class="lml-hr__empty"
            role="status"
            aria-live="polite"
            @if ($requests->isNotEmpty()) hidden @endif
            data-dr-empty
        >
            No death requests match your search or status filters.
        </p>
    </div>
@endsection
