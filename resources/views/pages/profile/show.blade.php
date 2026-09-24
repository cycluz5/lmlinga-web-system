{{--
    Authenticated worker User Profile — read-only current-user data.
    Binds to HealthWorkerUiCatalog::presentUser (database staff only).
    Empty fields render as "—" — values are never invented.
--}}
@extends('layouts.dashboard')

@section('title', 'User Profile - LMLinga')

@php
    $profile = $profile ?? [];

    $roleTitles = [
        'BHW' => 'Barangay Health Worker (BHW)',
        'BNS' => 'Barangay Nutrition Scholar (BNS)',
        'BSPO' => 'Barangay Service Point Officer (BSPO)',
        'Admin' => 'Administrator (Admin)',
    ];

    $formatDate = static function (?string $value): string {
        $formatted = \App\Support\DisplayDate::format($value);

        return $formatted !== '' ? $formatted : '—';
    };

    $display = static function (?string $value): string {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : '—';
    };

    $roleCode = $profile['role'] ?? '';
    $roleLabel = $roleTitles[$roleCode] ?? ($roleCode !== '' ? $roleCode : '—');
    $statusNormalized = \App\Support\StaffAccountStatus::normalize($profile['status'] ?? null);
    $statusLabel = $statusNormalized ?? '—';
    $isActive = $statusNormalized === \App\Support\StaffAccountStatus::ACTIVE;
    $isInactive = $statusNormalized === \App\Support\StaffAccountStatus::INACTIVE;
    $fullName = trim((string) ($profile['name'] ?? ''));
    if ($fullName === '') {
        $fullName = trim(implode(' ', array_filter([
            $profile['first_name'] ?? null,
            $profile['middle_name'] ?? null,
            $profile['last_name'] ?? null,
        ])));
    }
    $photoAlt = $fullName !== ''
        ? 'Profile photo of '.$fullName
        : 'User profile photo';
@endphp

@section('content')
    <div class="lml-hw-view" data-lml-user-profile-page>
        <div class="lml-hw-view__toolbar">
            <a
                href="{{ route('dashboard') }}"
                class="lml-hw-view__back lml-focus-ring"
                data-profile-back
            >
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Back</span>
            </a>
        </div>

        <article class="lml-hw-view__card" aria-labelledby="lml-user-profile-title">
            @if (session('status'))
                <p class="lml-hw-wizard__toast" role="status">
                    {{ session('status') }}
                </p>
            @endif

            <header class="lml-hw-view__header">
                <span class="lml-hw-view__header-icon" aria-hidden="true">
                    <i class="bi bi-person-circle"></i>
                </span>
                <div>
                    <h1 class="lml-hw-view__title" id="lml-user-profile-title">
                        User Profile
                    </h1>
                    <p class="lml-hw-view__subtitle">
                        Your current account details from the staff registry.
                    </p>
                </div>
            </header>

            <section class="lml-hw-view__section" aria-labelledby="lml-user-profile-identity">
                <h2 id="lml-user-profile-identity" class="lml-hw-view__section-title">
                    <i class="bi bi-person-fill lml-hw-view__section-icon" aria-hidden="true"></i>
                    <span>Identity</span>
                </h2>

                <div class="lml-hw-view__personal">
                    <div class="lml-hw-view__profile">
                        <div class="lml-hw-view__avatar">
                            @if (! empty($profile['photo']))
                                <img
                                    src="{{ $profile['photo'] }}"
                                    alt="{{ $photoAlt }}"
                                    class="lml-hw-view__avatar-img"
                                >
                            @else
                                <i class="bi bi-person" aria-hidden="true"></i>
                                <span class="visually-hidden">{{ $photoAlt }} (default avatar)</span>
                            @endif
                        </div>
                        <p class="lml-hw-view__role-label">{{ $roleLabel }}</p>
                    </div>

                    <dl class="lml-hw-view__fields lml-hw-view__fields--personal">
                        <div class="lml-hw-view__field lml-hw-view__field--full">
                            <dt>Full Name</dt>
                            <dd data-profile-full-name>{{ $display($fullName) }}</dd>
                        </div>
                        <div class="lml-hw-view__field">
                            <dt>Role</dt>
                            <dd data-profile-role>{{ $display($roleCode) }}</dd>
                        </div>
                        <div class="lml-hw-view__field">
                            <dt>Username</dt>
                            <dd data-profile-username>{{ $display($profile['username'] ?? null) }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            <section class="lml-hw-view__section" aria-labelledby="lml-user-profile-contact">
                <h2 id="lml-user-profile-contact" class="lml-hw-view__section-title">
                    <i class="bi bi-telephone-fill lml-hw-view__section-icon" aria-hidden="true"></i>
                    <span>Contact</span>
                </h2>

                <dl class="lml-hw-view__fields lml-hw-view__fields--2">
                    <div class="lml-hw-view__field">
                        <dt>Email</dt>
                        <dd data-profile-email>{{ $display($profile['email'] ?? null) }}</dd>
                    </div>
                    <div class="lml-hw-view__field">
                        <dt>Mobile Number</dt>
                        <dd data-profile-mobile>{{ $display($profile['mobile'] ?? null) }}</dd>
                    </div>
                </dl>
            </section>

            <section class="lml-hw-view__section" aria-labelledby="lml-user-profile-assignment">
                <h2 id="lml-user-profile-assignment" class="lml-hw-view__section-title">
                    <i class="bi bi-briefcase-fill lml-hw-view__section-icon" aria-hidden="true"></i>
                    <span>Assignment</span>
                </h2>

                <dl class="lml-hw-view__fields lml-hw-view__fields--employment">
                    <div class="lml-hw-view__field">
                        <dt>Assigned Barangay</dt>
                        <dd data-profile-assigned-barangay>{{ $display($profile['assigned_barangay'] ?? null) }}</dd>
                    </div>
                    <div class="lml-hw-view__field">
                        <dt>Assigned Zones</dt>
                        <dd data-profile-assigned-zone>{{ $display($profile['assigned_zones_display'] ?? ($profile['zone'] ?? ($profile['assigned_zone'] ?? null))) }}</dd>
                    </div>
                    <div class="lml-hw-view__field">
                        <dt>Date Appointed</dt>
                        <dd>{{ $formatDate($profile['date_appointed'] ?? null) }}</dd>
                    </div>
                    <div class="lml-hw-view__field">
                        <dt>End of Appointment</dt>
                        <dd>{{ $formatDate($profile['end_of_appointment'] ?? null) }}</dd>
                    </div>
                    <div class="lml-hw-view__field">
                        <dt>Status</dt>
                        <dd>
                            <span
                                data-profile-status
                                @class([
                                    'lml-hw-view__status',
                                    'lml-hw-view__status--active' => $isActive,
                                    'lml-hw-view__status--inactive' => $isInactive,
                                ])
                            >
                                {{ $statusLabel }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </section>

            <div class="lml-hw-view__actions">
                <a
                    href="{{ route('password.change.required') }}"
                    class="lml-hw-view__btn lml-hw-view__btn--edit lml-focus-ring"
                    data-profile-change-password
                >
                    Change Password
                </a>
                <a
                    href="{{ route('dashboard') }}"
                    class="lml-hw-view__btn lml-hw-view__btn--exit lml-focus-ring"
                >
                    Back to Dashboard
                </a>
            </div>
        </article>
    </div>
@endsection
