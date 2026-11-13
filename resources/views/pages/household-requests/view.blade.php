{{--
    Household Request Details — Admin monitoring / audit of household-record requests.
    Staff-verified linking actions (verify identity + mobile, decline, revoke link) appear only
    when HouseholdLinkingPolicy allows them; the server re-checks every action.
--}}
@extends('layouts.dashboard')

@section('title', 'Household Request Details - LMLinga')

@php
    $request = $demoRequest ?? null;
    $isApproved = strtolower((string) ($request['status'] ?? '')) === 'approved';
    $rejectionReasons = is_array($request['rejection_reasons'] ?? null)
        ? array_values(array_filter($request['rejection_reasons']))
        : [];
@endphp

@section('content')
    <div class="lml-hr-view">
        <div class="lml-hr-view__toolbar">
            <a
                href="{{ route('household-requests.index') }}"
                class="lml-hr-view__back lml-focus-ring"
            >
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Back to Household Requests</span>
            </a>
        </div>

        <article class="lml-hr-view__card" aria-labelledby="lml-hr-view-title">
            @if (! $request)
                <h1 id="lml-hr-view-title" class="lml-hr-view__title">Household request not found</h1>
                <p class="lml-hr-view__text">
                    The selected household request could not be loaded.
                </p>
            @else
                <header class="lml-hr-view__header">
                    <span class="lml-hr-view__header-icon" aria-hidden="true">
                        <i class="bi bi-house-check"></i>
                    </span>
                    <div>
                        <h1 id="lml-hr-view-title" class="lml-hr-view__title">
                            Household Request Details
                        </h1>
                        <p class="lml-hr-view__subtitle">
                            Verification result for {{ $request['name'] }}.
                        </p>
                    </div>
                </header>

                <section class="lml-hr-view__section" aria-labelledby="lml-hr-view-summary-heading">
                    <h2 id="lml-hr-view-summary-heading" class="lml-hr-view__section-title">
                        Request Summary
                    </h2>
                    <dl class="lml-hr-view__summary">
                        <div class="lml-hr-view__field">
                            <dt>Resident name</dt>
                            <dd>{{ $request['name'] }}</dd>
                        </div>
                        <div class="lml-hr-view__field">
                            <dt>Zone</dt>
                            <dd>{{ $request['zone'] ?? '—' }}</dd>
                        </div>
                        <div class="lml-hr-view__field">
                            <dt>Request status</dt>
                            <dd>
                                <span
                                    @class([
                                        'lml-hr-table__status',
                                        'lml-hr-table__status--approved' => $isApproved,
                                        'lml-hr-table__status--rejected' => ! $isApproved,
                                    ])
                                >
                                    {{ $request['status'] }}
                                </span>
                            </dd>
                        </div>
                        <div class="lml-hr-view__field">
                            <dt>Submitted</dt>
                            <dd>{{ $request['submitted_at'] ?? '—' }}</dd>
                        </div>
                        <div class="lml-hr-view__field">
                            <dt>Evaluated</dt>
                            <dd>{{ $request['evaluated_at'] ?? '—' }}</dd>
                        </div>
                        <div class="lml-hr-view__field">
                            <dt>Mobile</dt>
                            <dd>{{ $request['mobile'] ?? '—' }}</dd>
                        </div>
                        <div class="lml-hr-view__field lml-hr-view__field--full">
                            <dt>Household address</dt>
                            <dd>
                                {{ trim(implode(', ', array_filter([
                                    $request['house_no'] ?? null,
                                    $request['street'] ?? null,
                                    $request['barangay'] ?? null,
                                    $request['zone'] ?? null,
                                ]))) ?: '—' }}
                            </dd>
                        </div>
                        <div class="lml-hr-view__field lml-hr-view__field--full">
                            <dt>Household members</dt>
                            <dd>
                                @php
                                    $members = $request['household_members'] ?? [];
                                @endphp
                                @if (is_array($members) && count($members) > 0)
                                    <ul class="lml-hr-view__members">
                                        @foreach ($members as $member)
                                            <li>
                                                {{ $member['name'] ?? 'Member' }}
                                                @if (! empty($member['relationship']))
                                                    <span>({{ $member['relationship'] }})</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="lml-hr-view__section" aria-labelledby="lml-hr-view-decision-heading">
                    <h2 id="lml-hr-view-decision-heading" class="lml-hr-view__section-title">
                        Automatic verification result
                    </h2>
                    <dl class="lml-hr-view__decision">
                        <div class="lml-hr-view__field lml-hr-view__field--decision">
                            <dt>Verification Result</dt>
                            <dd>
                                <span
                                    @class([
                                        'lml-hr-table__status',
                                        'lml-hr-table__status--approved' => $isApproved,
                                        'lml-hr-table__status--rejected' => ! $isApproved,
                                    ])
                                >
                                    {{ $request['status'] }}
                                </span>
                            </dd>
                        </div>
                        <div class="lml-hr-view__field lml-hr-view__field--reason">
                            <dt>Reason</dt>
                            <dd>
                                @if (! $isApproved && count($rejectionReasons) > 1)
                                    <ul class="lml-hr-view__reasons">
                                        @foreach ($rejectionReasons as $reason)
                                            <li>{{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                @elseif (! $isApproved && count($rejectionReasons) === 1)
                                    {{ $rejectionReasons[0] }}
                                @else
                                    {{ $request['decision_reason'] ?? '—' }}
                                @endif
                            </dd>
                        </div>
                    </dl>
                </section>

                @if (($request['can_staff_verify'] ?? false) || ($request['can_staff_decline'] ?? false) || ($request['can_revoke_link'] ?? false))
                    <section class="lml-hr-view__section" aria-labelledby="lml-hr-view-staff-heading" data-hr-staff-verification>
                        <h2 id="lml-hr-view-staff-heading" class="lml-hr-view__section-title">
                            Staff identity verification
                        </h2>

                        @if (session('status'))
                            <p class="lml-hr-view__text" role="status">{{ session('status') }}</p>
                        @endif
                        @if (session('error'))
                            <p class="lml-hr-view__text" role="alert">{{ session('error') }}</p>
                        @endif
                        @if ($errors->any())
                            <ul class="lml-hr-view__reasons" role="alert">
                                @foreach ($errors->all() as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($request['can_staff_verify'] ?? false)
                            <p class="lml-hr-view__text">
                                A household link is created only after barangay staff verify the requester in person
                                (or by the barangay's official procedure) and confirm that the mobile number on this
                                request belongs to that resident. The verification code is then sent by SMS to that number only.
                            </p>
                            <form method="POST" action="{{ route('household-requests.verify-identity', ['id' => $requestId]) }}" data-hr-staff-verify-form>
                                @csrf
                                <label class="lml-hr-view__text">
                                    <input type="checkbox" name="identity_verified" value="1" required>
                                    I verified this person's identity against their valid ID and the household record.
                                </label>
                                <label class="lml-hr-view__text">
                                    <input type="checkbox" name="mobile_verified" value="1" required>
                                    I confirmed that the mobile number {{ $request['mobile'] ?? '—' }} belongs to this resident.
                                </label>
                                <button type="submit" class="lml-hr-view__btn lml-focus-ring" data-hr-staff-verify>
                                    Verify identity and mobile number
                                </button>
                            </form>
                        @endif

                        @if ($request['can_staff_decline'] ?? false)
                            <form method="POST" action="{{ route('household-requests.decline', ['id' => $requestId]) }}" data-hr-staff-decline-form>
                                @csrf
                                <button type="submit" class="lml-hr-view__btn lml-focus-ring" data-hr-staff-decline>
                                    Decline request
                                </button>
                            </form>
                        @endif

                        @if ($request['can_revoke_link'] ?? false)
                            <p class="lml-hr-view__text">
                                This resident account currently has household access. Revoke it only if staff determine
                                the link is not legitimate; the resident can verify again in person.
                            </p>
                            <form method="POST" action="{{ route('household-requests.revoke-link', ['id' => $requestId]) }}" data-hr-staff-revoke-form>
                                @csrf
                                <button type="submit" class="lml-hr-view__btn lml-focus-ring" data-hr-staff-revoke>
                                    Revoke household link
                                </button>
                            </form>
                        @endif
                    </section>
                @endif
            @endif

            <div class="lml-hr-view__actions">
                <a
                    href="{{ route('household-requests.index') }}"
                    class="lml-hr-view__btn lml-hr-view__btn--back lml-focus-ring"
                >
                    Back to Household Requests
                </a>
                <a
                    href="{{ route('dashboard') }}"
                    class="lml-hr-view__btn lml-hr-view__btn--exit"
                >
                    Exit
                </a>
            </div>
        </article>
    </div>
@endsection
