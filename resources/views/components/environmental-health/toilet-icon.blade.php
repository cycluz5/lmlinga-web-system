{{--
    Decorative toilet status icon (SVG silhouette + Bootstrap check/cross).
    Usage: <x-environmental-health.toilet-icon variant="with" />
--}}
@props([
    'variant' => 'with', // with | without
    'size' => 'md', // sm | md | lg
])

@php
    $isWithout = $variant === 'without';
    $sizeClass = match ($size) {
        'sm' => 'lml-eh-toilet-icon--sm',
        'lg' => 'lml-eh-toilet-icon--lg',
        default => 'lml-eh-toilet-icon--md',
    };
    $variantClass = $isWithout
        ? 'lml-eh-toilet-icon--without'
        : 'lml-eh-toilet-icon--with';
@endphp

<span
    {{ $attributes->class(['lml-eh-toilet-icon', $variantClass, $sizeClass]) }}
    aria-hidden="true"
>
    <svg class="lml-eh-toilet-icon__toilet" viewBox="0 0 24 24" focusable="false">
        {{-- Tank --}}
        <rect x="6.2" y="2.2" width="9.2" height="6.2" rx="1.4" fill="currentColor"/>
        {{-- Seat / bowl --}}
        <path
            fill="currentColor"
            d="M4.5 10.2h12.6c.9 0 1.7.3 2.3.9.7.7 1.1 1.7 1.1 2.8 0 3.4-3.5 6.1-7.9 6.1S5 17.3 5 13.9c0-1.1.4-2.1 1.1-2.8.3-.3.6-.5 1-.6H4.5c-.4 0-.7-.3-.7-.7s.3-.7.7-.7Z"
        />
        {{-- Base --}}
        <path
            fill="currentColor"
            d="M9.1 19.5h3.4c.5 0 .9.4.9.9v.9c0 .3-.2.5-.5.5H8.7c-.3 0-.5-.2-.5-.5v-.9c0-.5.4-.9.9-.9Z"
        />
    </svg>
    <i class="bi {{ $isWithout ? 'bi-x-circle-fill' : 'bi-check-circle-fill' }} lml-eh-toilet-icon__mark"></i>
</span>
