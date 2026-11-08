@extends('layouts.app')

@section('title', 'LMLinga - Welcome')

@php
    $roles = [
        [
            'code' => 'BHW',
            'title' => 'Barangay Health Worker',
            'description' => 'BHWs provide basic health care services and support to individuals and families in the community.',
            'responsibilities' => [
                'Monitor household health status',
                'Assist in immunization and health programs',
                'Record patient data and health visits',
                'Support maternal and child care',
            ],
        ],
        [
            'code' => 'BNS',
            'title' => 'Barangay Nutrition Scholar',
            'description' => 'BHNs focus on improving the nutrition and well-being of children and families in the barangay.',
            'responsibilities' => [
                'Conduct Operational Timbang (weighing)',
                'Monitor child nutritional status',
                'Organize feeding programs',
                'Promote healthy eating habits',
            ],
        ],
        [
            'code' => 'BSPO',
            'title' => 'Barangay Service Point Officer',
            'description' => 'BSPOs provide family planning services and education on responsible parenthood and population programs.',
            'responsibilities' => [
                'Provide family planning counseling',
                'Distribute contraceptives',
                'Educate on reproductive health',
                'Support population programs',
            ],
        ],
    ];
@endphp

@section('body')
    <header class="border-bottom bg-white lml-public-header">
        <div class="lml-header-inner">
            <a href="{{ route('landing') }}" class="logo text-decoration-none lml-focus-ring rounded-2">
                <img src="{{ asset('assets/images/logo/logo.png') }}" alt="">
                <span>LMLinga</span>
            </a>

            <nav class="d-flex align-items-center lml-header-actions" aria-label="Public navigation">
                <x-lml.primary-button href="{{ route('login') }}">Login</x-lml.primary-button>
            </nav>
        </div>
    </header>

    <main class="lml-landing-main" id="main-content">
        <section class="hero">
            <div class="hero-content">
                <p class="mb-0 lml-hero-kicker">Welcome to</p>
                <h1 class="lml-hero-title">LMLinga</h1>
                <p class="mb-0 lml-hero-description">
                    LMLinga is a community health information system that supports the Barangay Health Team
                    in delivering better services to every resident.
                </p>
            </div>

            <div class="hero-illustration">
                <img
                    src="{{ asset('assets/images/illustrations/landing.png') }}"
                    alt=""
                >
            </div>
        </section>

        <section class="lml-team-section">
            <x-lml.page-container>
                <div class="text-center lml-team-heading">
                    <h2 class="lml-team-heading__title">Our Barangay Health Team</h2>
                    <p class="lml-team-heading__subtitle">
                        Working together for a healthier and stronger community.
                    </p>
                </div>

                <div class="row g-3 g-xl-4">
                    @foreach ($roles as $role)
                        <div class="col-12 col-md-6 col-xl-4 d-flex">
                            <x-lml.role-info-card
                                role-code="{{ $role['code'] }}"
                                role-title="{{ $role['title'] }}"
                                description="{{ $role['description'] }}"
                                :responsibilities="$role['responsibilities']"
                            />
                        </div>
                    @endforeach
                </div>
            </x-lml.page-container>
        </section>
    </main>
@endsection
