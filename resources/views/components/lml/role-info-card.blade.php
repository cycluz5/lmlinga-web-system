{{--
    Role Info Card — reusable card for BHW/BNS/BSPO information blocks.
    Usage:
    <x-lml.role-info-card
        role-code="BHW"
        role-title="Barangay Health Worker"
        description="Short role description."
        :responsibilities="['Item 1', 'Item 2']"
    />
--}}
@props([
    'roleCode',
    'roleTitle',
    'description' => null,
    'responsibilities' => [],
])

@php
    $themeKey = strtoupper((string) $roleCode);

    $themes = [
        'BHW' => [
            'modifier' => 'bhw',
            'icon' => 'bi-house-add',
        ],
        'BNS' => [
            'modifier' => 'bns',
            'icon' => 'bi-fork-knife',
        ],
        'BSPO' => [
            'modifier' => 'bspo',
            'icon' => 'bi-people-fill',
        ],
    ];

    $theme = $themes[$themeKey] ?? $themes['BHW'];
@endphp

<div {{ $attributes->class(['card h-100 lml-role-card', 'lml-role-card--' . $theme['modifier']]) }}>
    <div class="card-body lml-role-card__body">
        <div class="d-flex align-items-center gap-3 lml-role-card__header">
            <span class="lml-role-card__badge" aria-hidden="true">
                <i class="bi {{ $theme['icon'] }}"></i>
            </span>

            <div class="min-w-0">
                <h3 class="lml-role-card__title mb-0">{{ $roleCode }}</h3>
                <p class="lml-role-card__subtitle mb-0">{{ $roleTitle }}</p>
            </div>
        </div>

        @if ($description)
            <p class="lml-role-card__description">{{ $description }}</p>
        @endif

        <hr class="lml-role-card__divider">

        <ul class="list-unstyled mb-0 lml-role-card__list">
            @foreach ($responsibilities as $responsibility)
                <li class="lml-role-card__item">
                    <i class="bi bi-check-circle-fill lml-role-card__check" aria-hidden="true"></i>
                    <span>{{ $responsibility }}</span>
                </li>
            @endforeach
        </ul>
    </div>
</div>
