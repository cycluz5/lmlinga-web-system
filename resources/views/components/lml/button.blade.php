{{--
    Base Button — internal component used by primary, secondary, and outline buttons.
    Prefer using <x-lml.primary-button>, <x-lml.secondary-button>, or <x-lml.outline-button> directly.
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'disabled' => false,
])

@php
    $variants = [
        'primary' => 'btn-primary',
        'secondary' => 'btn-secondary',
        'outline' => 'btn-outline-primary',
    ];

    $sizes = [
        'sm' => 'btn-sm',
        'md' => '',
        'lg' => 'btn-lg',
    ];

    $classes = trim('btn ' . ($variants[$variant] ?? $variants['primary']) . ' ' . ($sizes[$size] ?? '') . ' lml-focus-ring fw-medium');
@endphp

@if ($href && ! $disabled)
    <a href="{{ $href }}" {{ $attributes->class([$classes]) }}>
        {{ $slot }}
    </a>
@elseif ($href && $disabled)
    <a
        href="{{ $href }}"
        role="link"
        aria-disabled="true"
        tabindex="-1"
        {{ $attributes->class([$classes, 'disabled', 'pointer-events-none']) }}
    >
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" @disabled($disabled) {{ $attributes->class([$classes]) }}>
        {{ $slot }}
    </button>
@endif
