{{--
    Health Records — Death resident selection.
    Dedicated page opened from + Record death on the Death listing.
--}}
@extends('layouts.dashboard')

@section('title', 'Select a resident - Death - LMLinga')

@section('content')
    @php
        $residents = $residents ?? [];
        $zones = $zones ?? \App\Support\HealthRecordsDeath::zones();
    @endphp

    <div
        class="lml-hr-death"
        data-lml-hr-death
        data-death-data-mode="persisted"
    >
        <section
            class="lml-hr-death__panel"
            id="lml-hr-death-residents"
            aria-label="Select a resident"
            data-hr-death-residents
        >
            <a
                href="{{ route('health-records.death.index') }}"
                class="lml-hr-death__page-back lml-focus-ring"
                aria-label="Back to Death records page"
            >
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Back to Death records page</span>
            </a>

            <div
                class="lml-hr-death__filters lml-hr-death__filters--residents"
                role="search"
                aria-label="Resident search and filters"
                data-hr-death-resident-filters
            >
                <div class="lml-hr-death__search lml-hr-death__search--residents">
                    <label class="visually-hidden" for="lml-hr-death-resident-search">Search resident name</label>
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

                <div class="lml-hr-death__select-wrap">
                    <label class="visually-hidden" for="lml-hr-death-resident-zone">Filter by zone</label>
                    <select
                        id="lml-hr-death-resident-zone"
                        class="lml-hr-death__select lml-focus-ring"
                        data-hr-death-resident-zone
                    >
                        <option value="all" selected>All Zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone }}">{{ $zone }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr-death__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr-death__select-wrap">
                    <label class="visually-hidden" for="lml-hr-death-resident-status">Filter by status</label>
                    <select
                        id="lml-hr-death-resident-status"
                        class="lml-hr-death__select lml-focus-ring"
                        data-hr-death-resident-status
                    >
                        <option value="all" selected>All Statuses</option>
                        <option value="Active">Active</option>
                        <option value="Pending verification">Pending verification</option>
                    </select>
                    <i class="bi bi-chevron-down lml-hr-death__select-icon" aria-hidden="true"></i>
                </div>
            </div>

            <div class="lml-hr-death__table-scroll" tabindex="0">
                <table class="lml-hr-death__table">
                    <caption class="visually-hidden">
                        Residents available for death record submission.
                    </caption>
                    <colgroup>
                        <col class="lml-hr-death__col lml-hr-death__col--resident">
                        <col class="lml-hr-death__col lml-hr-death__col--household">
                        <col class="lml-hr-death__col lml-hr-death__col--zone">
                        <col class="lml-hr-death__col lml-hr-death__col--resident-status">
                        <col class="lml-hr-death__col lml-hr-death__col--action">
                    </colgroup>
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
                                $actionVerb = $resident['can_submit'] ? 'Record death for' : 'Open death record for';
                                $ariaIdentity = implode(', ', array_filter([
                                    (string) $resident['full_name'],
                                    (string) ($resident['member_id'] ?? ''),
                                ]));
                            @endphp
                            <tr
                                class="lml-hr-death__record-row"
                                data-hr-death-resident-row
                                data-name="{{ strtolower($resident['full_name']) }}"
                                data-zone="{{ $resident['zone'] }}"
                                data-status-label="{{ $resident['vital_label'] }}"
                            >
                                <th scope="row" class="lml-hr-death__cell lml-hr-death__cell--name">
                                    <a
                                        href="{{ $resident['open_url'] }}"
                                        class="lml-hr-death__name-link lml-focus-ring"
                                        aria-label="{{ $actionVerb }} {{ $ariaIdentity }}"
                                    >
                                        {{ $resident['full_name'] }}
                                    </a>
                                </th>
                                <td class="lml-hr-death__cell">{{ $resident['household_display'] }}</td>
                                <td class="lml-hr-death__cell">{{ $resident['zone'] }}</td>
                                <td class="lml-hr-death__cell">
                                    <span class="lml-hr-death__status lml-hr-death__status--{{ $resident['status'] }}">
                                        {{ $resident['vital_label'] }}
                                    </span>
                                </td>
                                <td class="lml-hr-death__cell lml-hr-death__cell--action">
                                    <a
                                        href="{{ $resident['open_url'] }}"
                                        class="lml-hr-death__open-btn lml-focus-ring"
                                        data-hr-death-resident-action="{{ $resident['can_submit'] ? 'record' : 'open' }}"
                                        aria-label="{{ $actionVerb }} {{ $ariaIdentity }}"
                                    >
                                        {{ $resident['can_submit'] ? 'Record Death' : 'Open' }}
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
