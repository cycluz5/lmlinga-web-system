{{--
    User Management — Health Workers + Residents tabs.
    Health Workers: database staff + currentAppointment. Residents: resident_accounts.
--}}
@extends('layouts.dashboard')

@section('title', 'User Management - LMLinga')

@php
    $healthWorkers = $healthWorkers ?? [];
    $residentAccounts = $residentAccounts ?? [];
    $workerTotal = count($healthWorkers);
    $categoryOptions = [
        'all' => 'All',
        'BHW' => 'BHW',
        'BNS' => 'BNS',
        'BSPO' => 'BSPO',
        'Admin' => 'Admin',
    ];
    $zoneOptions = [
        'all' => 'All Zones',
        'Zone 1' => 'Zone 1',
        'Zone 2' => 'Zone 2',
        'Zone 3' => 'Zone 3',
        'Zone 4' => 'Zone 4',
        'Zone 5' => 'Zone 5',
    ];
    $umErrors = $errors ?? new \Illuminate\Support\ViewErrorBag();
@endphp

@section('content')
    <div
        class="lml-user-mgmt"
        data-lml-user-mgmt
        data-total="{{ $workerTotal }}"
        data-subtitle-workers="Manage accounts of the Barangay Health Workers"
        data-subtitle-residents="Manage user accounts and access permissions."
    >
        <div class="lml-user-mgmt__tabs" role="tablist" aria-label="User Management sections">
            <button
                type="button"
                class="lml-user-mgmt__tab lml-user-mgmt__tab--active lml-focus-ring"
                role="tab"
                id="lml-um-tab-workers"
                aria-selected="true"
                aria-controls="lml-um-panel-workers"
                data-um-tab="workers"
                tabindex="0"
            >
                Health Workers
            </button>
            <button
                type="button"
                class="lml-user-mgmt__tab lml-focus-ring"
                role="tab"
                id="lml-um-tab-residents"
                aria-selected="false"
                aria-controls="lml-um-panel-residents"
                data-um-tab="residents"
                tabindex="-1"
            >
                Residents
            </button>
        </div>

        <div
            class="lml-user-mgmt__panel"
            role="tabpanel"
            id="lml-um-panel-workers"
            aria-labelledby="lml-um-tab-workers"
            data-um-panel="workers"
        >
            <div class="lml-user-mgmt__toolbar">
                <div class="lml-user-mgmt__search">
                    <i class="bi bi-search lml-user-mgmt__search-icon" aria-hidden="true"></i>
                    <label class="visually-hidden" for="lml-um-search">Search Health Worker</label>
                    <input
                        type="search"
                        id="lml-um-search"
                        class="lml-user-mgmt__search-input"
                        placeholder="Search Health Worker"
                        autocomplete="off"
                        data-um-search
                    >
                </div>

                <div class="lml-user-mgmt__toolbar-end">
                    <div class="lml-user-mgmt__category">
                        <label class="visually-hidden" for="lml-um-category">Category</label>
                        <select
                            id="lml-um-category"
                            class="lml-user-mgmt__category-select"
                            data-um-category
                        >
                            <option value="" disabled selected hidden>Category</option>
                            @foreach ($categoryOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down lml-user-mgmt__category-icon" aria-hidden="true"></i>
                    </div>

                    <a
                        href="{{ route('user-management.health-workers.create') }}"
                        class="lml-user-mgmt__add-btn lml-focus-ring"
                        data-um-add
                        data-um-nav="add-worker"
                    >
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        <span>Add Health Worker</span>
                    </a>
                </div>
            </div>

            <p
                class="lml-user-mgmt__toast"
                role="status"
                aria-live="polite"
                @if (! session('status') || request()->query('tab') === 'residents') hidden @endif
                data-um-toast
            >{{ session('status') && request()->query('tab') !== 'residents' ? session('status') : '' }}</p>

            <p
                class="lml-user-mgmt__toast lml-user-mgmt__toast--error"
                role="alert"
                aria-live="assertive"
                @if (! $umErrors->any() || request()->query('tab') === 'residents') hidden @endif
                data-um-toast-error
            >{{ $umErrors->any() && request()->query('tab') !== 'residents' ? $umErrors->first() : '' }}</p>

            <div
                class="lml-user-mgmt__grid"
                role="list"
                aria-label="Health workers"
                data-um-grid
            >
                @foreach ($healthWorkers as $worker)
                    <x-lml.user-management.health-worker-card
                        :id="$worker['id']"
                        :name="$worker['name']"
                        :role="$worker['role']"
                        :zone="$worker['zone']"
                        :status="$worker['status']"
                        :photo="$worker['photo'] ?? null"
                    />
                @endforeach
            </div>

            <p
                class="lml-user-mgmt__empty"
                role="status"
                aria-live="polite"
                @if (count($healthWorkers) > 0) hidden @endif
                data-um-empty-seed
            >
                No registered accounts found.
            </p>

            <p
                class="lml-user-mgmt__empty"
                role="status"
                aria-live="polite"
                hidden
                data-um-empty
            >
                No health workers match your search or category filter.
            </p>
        </div>

        <div
            class="lml-user-mgmt__panel"
            role="tabpanel"
            id="lml-um-panel-residents"
            aria-labelledby="lml-um-tab-residents"
            data-um-panel="residents"
            data-lml-resident-management
            hidden
        >
            @if (session('status') && request()->query('tab') === 'residents')
                <p class="lml-ra-toast" role="status" aria-live="polite">
                    {{ session('status') }}
                </p>
            @endif

            <div class="lml-ra-toolbar" role="search" aria-label="Filter residents">
                <div class="lml-ra-toolbar__search">
                    <i class="bi bi-search lml-ra-toolbar__search-icon" aria-hidden="true"></i>
                    <label class="visually-hidden" for="lml-ra-search">Search residents</label>
                    <input
                        type="search"
                        id="lml-ra-search"
                        class="lml-ra-toolbar__search-input"
                        placeholder="Search residents"
                        autocomplete="off"
                        data-resident-search
                    >
                </div>

                <div class="lml-ra-toolbar__end">
                    <div class="lml-ra-toolbar__zone">
                        <label class="visually-hidden" for="lml-ra-zone">Zone</label>
                        <select
                            id="lml-ra-zone"
                            class="lml-ra-toolbar__select"
                            data-resident-zone
                        >
                            @foreach ($zoneOptions as $value => $label)
                                <option value="{{ $value }}" @selected($value === 'all')>{{ $label }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down lml-ra-toolbar__select-icon" aria-hidden="true"></i>
                    </div>
                </div>
            </div>

            <div class="lml-ra-table-wrap" data-resident-table-wrap>
                <table class="lml-ra-table">
                    <caption class="visually-hidden">Resident accounts by name, zone, and email address</caption>
                    <colgroup>
                        <col class="lml-ra-table__col--name">
                        <col class="lml-ra-table__col--zone">
                        <col class="lml-ra-table__col--email">
                        <col class="lml-ra-table__col--actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="lml-ra-table__th lml-ra-table__th--name">Name</th>
                            <th scope="col" class="lml-ra-table__th lml-ra-table__th--zone">Zone</th>
                            <th scope="col" class="lml-ra-table__th lml-ra-table__th--email">Email Address</th>
                            <th scope="col" class="lml-ra-table__th lml-ra-table__th--actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody data-resident-tbody>
                        @forelse ($residentAccounts as $resident)
                            <x-lml.user-management.resident-row
                                :id="$resident['id']"
                                :name="$resident['name']"
                                :first-name="$resident['first_name'] ?? ''"
                                :middle-name="$resident['middle_name'] ?? ''"
                                :last-name="$resident['last_name'] ?? ''"
                                :zone="$resident['zone']"
                                :email="$resident['email'] ?? ''"
                            />
                        @empty
                            <tr class="lml-ra-table__empty-row" data-resident-seed-empty>
                                <td colspan="4">No resident accounts are available.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <nav
                class="lml-ra-pagination"
                aria-label="Residents pagination"
                data-resident-pagination
                hidden
            >
                <p
                    class="lml-ra-pagination__summary"
                    role="status"
                    aria-live="polite"
                    data-resident-page-summary
                ></p>
                <div class="lml-ra-pagination__controls">
                    <button
                        type="button"
                        class="lml-ra-pagination__btn lml-focus-ring"
                        data-resident-page-prev
                        aria-label="Go to previous residents page"
                    >
                        Previous
                    </button>
                    <div
                        class="lml-ra-pagination__pages"
                        data-resident-page-numbers
                    ></div>
                    <button
                        type="button"
                        class="lml-ra-pagination__btn lml-focus-ring"
                        data-resident-page-next
                        aria-label="Go to next residents page"
                    >
                        Next
                    </button>
                </div>
            </nav>

            <div
                class="lml-ra-empty"
                role="status"
                aria-live="polite"
                hidden
                data-resident-empty
            >
                <p class="lml-ra-empty__title">No residents found</p>
                <p class="lml-ra-empty__text">Try changing your search keyword or selected zone.</p>
            </div>

            <x-lml.user-management.delete-resident-modal />
        </div>
    </div>

    <x-lml.user-management.deactivate-health-worker-modal />
    <x-lml.user-management.delete-health-worker-modal />
@endsection
