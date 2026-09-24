{{--
    Household Profiling — View Household.
    Database-backed via HouseholdMemberResolver. Unknown households render not-found.
--}}
@extends('layouts.dashboard')

@section('title', ($demoHousehold['displayNo'] ?? $householdNo ?? 'Household') . ' - LMLinga')

@php
    $householdSource = $householdSource ?? null;
    $isDbHousehold = $householdSource === 'db';
@endphp

@section('content')
    <div
        class="lml-hh-view"
        data-lml-hh-view
        data-source="{{ $householdSource ?? 'none' }}"
        @if ($demoHousehold)
            data-household-no="{{ $demoHousehold['householdNo'] }}"
        @endif
    >
        <a
            href="{{ route('household-profiling.index') }}"
            class="lml-hh-view__back lml-focus-ring"
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span>Back to Household List</span>
        </a>

        <div
            class="lml-hh-view__toast"
            data-hh-view-toast
            role="status"
            aria-live="polite"
            hidden
        ></div>

        @if (! $demoHousehold)
            <section class="lml-hh-view__not-found" aria-labelledby="lml-hh-view-not-found-title">
                <span class="lml-hh-view__not-found-icon" aria-hidden="true">
                    <i class="bi bi-house-x"></i>
                </span>
                <h2 id="lml-hh-view-not-found-title" class="lml-hh-view__not-found-title">
                    Household not found
                </h2>
                <p class="lml-hh-view__not-found-message">
                    No registered household matches
                    <strong>{{ $householdNo }}</strong>.
                </p>
                <a
                    href="{{ route('household-profiling.index') }}"
                    class="lml-hh-view__not-found-link lml-focus-ring"
                >
                    Return to Household List
                </a>
            </section>
        @else
            {{-- Section 1: Household Summary --}}
            <section class="lml-hh-view__card" aria-labelledby="lml-hh-view-title">
                <div class="lml-hh-view__card-top">
                    <div class="lml-hh-view__identity">
                        <span class="lml-hh-view__house-icon" aria-hidden="true">
                            <i class="bi bi-house-door-fill"></i>
                        </span>
                        <div class="lml-hh-view__identity-text">
                            <h2 id="lml-hh-view-title" class="lml-hh-view__hh-no">
                                {{ $demoHousehold['displayNo'] }}
                            </h2>
                            <p class="lml-hh-view__head">
                                <span class="lml-hh-view__head-label">Head of Household</span>
                                <span class="lml-hh-view__head-name">{{ $demoHousehold['houseHead'] }}</span>
                            </p>
                        </div>
                    </div>
                    <span class="lml-hh-view__members-badge" aria-label="{{ $demoHousehold['members'] }} members">
                        <i class="bi bi-people-fill" aria-hidden="true"></i>
                        <span>{{ $demoHousehold['members'] }} members</span>
                    </span>
                </div>

                <h3 class="lml-hh-view__section-label">Household Information</h3>

                <dl class="lml-hh-view__meta">
                    <div class="lml-hh-view__meta-item">
                        <dt>
                            <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                            <span>Zone</span>
                        </dt>
                        <dd>{{ $demoHousehold['zone'] }}</dd>
                    </div>
                    <div class="lml-hh-view__meta-item">
                        <dt>
                            <i class="bi bi-calendar3" aria-hidden="true"></i>
                            <span>Accomplished Date</span>
                        </dt>
                        <dd>{{ $demoHousehold['accomplishedDate'] }}</dd>
                    </div>
                </dl>

                <hr class="lml-hh-view__divider" aria-hidden="true">

                {{-- Section 2: Household Amenities Overview --}}
                <div class="lml-hh-view__amenities-header">
                    <h3 id="lml-hh-view-amenities-title" class="lml-hh-view__section-label lml-hh-view__section-label--amenities">
                        Household Amenities
                    </h3>
                    <a
                        href="{{ route('household-profiling.amenities.show', ['householdNo' => $demoHousehold['householdNo']]) }}"
                        class="lml-hh-view__details-btn lml-focus-ring"
                        data-hh-nav="amenities"
                        aria-label="View household amenities details"
                    >
                        <span>Details</span>
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </a>
                </div>

                <div
                    class="lml-hh-view__amenities"
                    role="group"
                    aria-labelledby="lml-hh-view-amenities-title"
                >
                    <article class="lml-hh-view__amenity lml-hh-view__amenity--water">
                        <span class="lml-hh-view__amenity-icon" aria-hidden="true">
                            <i class="bi bi-droplet-fill"></i>
                        </span>
                        <div class="lml-hh-view__amenity-body">
                            <h4 class="lml-hh-view__amenity-title">{{ $demoHousehold['water']['title'] }}</h4>
                            <p class="lml-hh-view__amenity-detail">{{ $demoHousehold['water']['level'] }}</p>
                            <span class="lml-hh-view__amenity-badge lml-hh-view__amenity-badge--water">
                                {{ $demoHousehold['water']['status'] }}
                            </span>
                        </div>
                    </article>

                    <article class="lml-hh-view__amenity lml-hh-view__amenity--sanitation">
                        <span class="lml-hh-view__amenity-icon" aria-hidden="true">
                            <i class="bi bi-moisture"></i>
                        </span>
                        <div class="lml-hh-view__amenity-body">
                            <h4 class="lml-hh-view__amenity-title">{{ $demoHousehold['sanitation']['title'] }}</h4>
                            <p class="lml-hh-view__amenity-detail">{{ $demoHousehold['sanitation']['facility'] }}</p>
                            <span class="lml-hh-view__amenity-badge lml-hh-view__amenity-badge--sanitation">
                                {{ $demoHousehold['sanitation']['status'] }}
                            </span>
                        </div>
                    </article>
                </div>
            </section>

            {{-- Section 3: Household Members --}}
            @php
                $memberCount = count($demoHousehold['memberList'] ?? []);
                if ($memberCount === 0 && isset($demoHousehold['members'])) {
                    $memberCount = (int) $demoHousehold['members'];
                }
            @endphp
            <section class="lml-hh-view__members" aria-labelledby="lml-hh-view-members-title">
                <div class="lml-hh-view__members-header">
                    <h2 id="lml-hh-view-members-title" class="lml-hh-view__members-title">
                        Household Members ({{ $memberCount }})
                    </h2>
                    <a
                        href="{{ route('household-profiling.members.create', ['householdNo' => $demoHousehold['householdNo']]) }}"
                        class="lml-hh-view__add-member lml-focus-ring"
                        data-hh-nav="add-member"
                        aria-label="Add Household Member"
                    >
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        <span class="lml-hh-view__add-member-label lml-hh-view__add-member-label--full">
                            Add Household Member
                        </span>
                        <span class="lml-hh-view__add-member-label lml-hh-view__add-member-label--short" aria-hidden="true">
                            Add Member
                        </span>
                    </a>
                </div>

                @if (count($demoHousehold['memberList'] ?? []) === 0)
                    <div class="lml-hh-view__members-empty" role="status">
                        <p class="lml-hh-view__members-empty-text">
                            No household members are recorded for this household yet.
                        </p>
                    </div>
                @else
                    <div class="lml-hh-view__table-scroll" tabindex="0" role="region" aria-label="Household members table">
                        <table class="lml-hh-view__table">
                            <caption class="visually-hidden">
                                Members for {{ $demoHousehold['householdNo'] }}.
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">Name</th>
                                    <th scope="col">Relationship</th>
                                    <th scope="col">Age</th>
                                    <th scope="col">Sex</th>
                                    <th scope="col">Occupation</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($demoHousehold['memberList'] as $member)
                                    @php
                                        $isHead = strcasecmp((string) ($member['relationship'] ?? ''), 'Head') === 0;
                                    @endphp
                                    <tr>
                                        <td data-label="Name">
                                            <span class="lml-hh-view__member-name-wrap">
                                                <span class="lml-hh-view__member-name">{{ $member['name'] }}</span>
                                                @if ($isHead)
                                                    <span class="lml-hh-view__head-badge">Head</span>
                                                @endif
                                            </span>
                                        </td>
                                        <td data-label="Relationship">{{ $member['relationship'] }}</td>
                                        <td data-label="Age">{{ $member['age'] }}</td>
                                        <td data-label="Sex">{{ $member['sex'] }}</td>
                                        <td data-label="Occupation">
                                            @php
                                                $occupation = (string) ($member['occupation'] ?? '');
                                            @endphp
                                            {{ $occupation === 'None / N/A' ? 'N/A' : $occupation }}
                                        </td>
                                        <td data-label="Actions">
                                            <div
                                                class="lml-hh-view__actions"
                                                role="group"
                                                aria-label="Actions for {{ $member['name'] }}"
                                            >
                                                <a
                                                    href="{{ route('household-profiling.members.show', [
                                                        'householdNo' => $demoHousehold['householdNo'],
                                                        'memberId' => $member['id'],
                                                    ]) }}"
                                                    class="lml-hh-view__action-btn lml-hh-view__action-btn--view lml-focus-ring"
                                                    data-hh-nav="view-member"
                                                    aria-label="View {{ $member['name'] }}"
                                                >
                                                    <i class="bi bi-eye-fill" aria-hidden="true"></i>
                                                    <span>View</span>
                                                </a>
                                                <a
                                                    href="{{ route('household-profiling.members.edit', [
                                                        'householdNo' => $demoHousehold['householdNo'],
                                                        'memberId' => $member['id'],
                                                    ]) }}"
                                                    class="lml-hh-view__action-btn lml-hh-view__action-btn--edit lml-focus-ring"
                                                    data-hh-nav="edit-member"
                                                    aria-label="Edit {{ $member['name'] }}"
                                                >
                                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                                    <span>Edit</span>
                                                </a>
                                                <button
                                                    type="button"
                                                    class="lml-hh-view__action-btn lml-hh-view__action-btn--delete lml-focus-ring"
                                                    data-hh-view-action="delete"
                                                    data-member-name="{{ $member['name'] }}"
                                                    aria-label="Delete {{ $member['name'] }}"
                                                >
                                                    <i class="bi bi-trash3" aria-hidden="true"></i>
                                                    <span>Delete</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            @if ($isDbHousehold)
                <p class="lml-hh-view__demo-note">
                    Registered household {{ $demoHousehold['householdNo'] }}.
                    Member add and edit save to the database.
                </p>
            @endif

            <div
                class="lml-hh-view__dialog-backdrop"
                data-hh-view-dialog
                hidden
            >
                <div
                    class="lml-hh-view__dialog"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="lml-hh-view-delete-title"
                    aria-describedby="lml-hh-view-delete-message"
                    tabindex="-1"
                    data-hh-view-dialog-panel
                >
                    <h2 id="lml-hh-view-delete-title" class="lml-hh-view__dialog-title">
                        Delete Household Member?
                    </h2>
                    <p id="lml-hh-view-delete-message" class="lml-hh-view__dialog-message">
                        Are you sure you want to delete this household member record?
                        <br><br>
                        This is currently a UI demonstration.
                        No information will actually be removed.
                    </p>
                    <div class="lml-hh-view__dialog-actions">
                        <button
                            type="button"
                            class="lml-hh-view__dialog-btn lml-hh-view__dialog-btn--cancel lml-focus-ring"
                            data-hh-view-dialog-cancel
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="lml-hh-view__dialog-btn lml-hh-view__dialog-btn--delete lml-focus-ring"
                            data-hh-view-dialog-confirm
                        >
                            Delete
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
