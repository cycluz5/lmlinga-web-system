{{--
    Resident Information — read-only (Admin User Management).
--}}
@extends('layouts.dashboard')

@section('title', 'Resident Information - LMLinga')

@php
    $resident = $demoResident ?? null;
@endphp

@section('content')
    <div class="lml-ra-view">
        <div class="lml-ra-view__toolbar">
            <a
                href="{{ route('user-management.index', ['tab' => 'residents']) }}"
                class="lml-ra-view__back lml-focus-ring"
            >
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Back to Residents</span>
            </a>
        </div>

        @if (session('status'))
            <p class="lml-ra-view__toast" role="status" aria-live="polite">
                {{ session('status') }}
            </p>
        @endif

        <article class="lml-ra-view__card" aria-labelledby="lml-ra-view-title">
            @if (! $resident)
                <h1 id="lml-ra-view-title" class="lml-ra-view__title">Resident not found</h1>
                <p class="lml-ra-view__text">
                    The selected resident account could not be loaded.
                </p>
            @else
                <header class="lml-ra-view__header">
                    <span class="lml-ra-view__header-icon" aria-hidden="true">
                        <i class="bi bi-person-badge"></i>
                    </span>
                    <div>
                        <h1 id="lml-ra-view-title" class="lml-ra-view__title">
                            Resident Information
                        </h1>
                        <p class="lml-ra-view__subtitle">
                            Account profile for {{ $resident['name'] }}.
                        </p>
                    </div>
                </header>

                <dl class="lml-ra-view__summary">
                    <div class="lml-ra-view__field">
                        <dt>Resident Full Name</dt>
                        <dd>{{ $resident['name'] }}</dd>
                    </div>
                    <div class="lml-ra-view__field">
                        <dt>Email Address</dt>
                        <dd>{{ $resident['email'] ?? '—' }}</dd>
                    </div>
                    <div class="lml-ra-view__field">
                        <dt>Zone</dt>
                        <dd>{{ $resident['zone'] ?? '—' }}</dd>
                    </div>
                </dl>
            @endif

            <div class="lml-ra-view__actions">
                <a
                    href="{{ route('user-management.index', ['tab' => 'residents']) }}"
                    class="lml-ra-view__btn lml-ra-view__btn--back lml-focus-ring"
                >
                    Back to Residents
                </a>
                <a
                    href="{{ route('dashboard') }}"
                    class="lml-ra-view__btn lml-ra-view__btn--exit"
                >
                    Exit
                </a>
            </div>
        </article>
    </div>
@endsection
