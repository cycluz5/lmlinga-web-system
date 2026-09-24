{{--
    Badge — small status label or count indicator.
    Usage: <x-lml.badge variant="soft">Active</x-lml.badge>
--}}
@props([
    'variant' => 'primary',
    'pill' => false,
])

@php
    $variants = [
        'primary' => 'lml-badge--primary',
        'secondary' => 'lml-badge--secondary',
        'soft' => 'lml-badge--soft',
        'outline' => 'lml-badge--outline',
    ];
@endphp

<span {{ $attributes->class([
    'lml-badge',
    $variants[$variant] ?? $variants['primary'],
    'rounded-pill' => $pill,
]) }}>
    {{ $slot }}
</span>
