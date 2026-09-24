{{--
    Alert — feedback and status messages.
    Usage: <x-lml.alert variant="success" :dismissible="true">Saved successfully.</x-lml.alert>
--}}
@props([
    'variant' => 'primary',
    'dismissible' => false,
    'title' => null,
])

@php
    $variants = [
        'primary' => 'alert-primary',
        'success' => 'alert-success',
        'warning' => 'alert-warning',
        'danger' => 'alert-danger',
        'info' => 'alert-info',
    ];
@endphp

<div
    role="alert"
    {{ $attributes->class([
        'alert',
        $variants[$variant] ?? $variants['primary'],
        'alert-dismissible fade show' => $dismissible,
    ]) }}
>
    @if ($title)
        <h6 class="alert-heading fw-semibold mb-1">{{ $title }}</h6>
    @endif

    <div>{{ $slot }}</div>

    @if ($dismissible)
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    @endif
</div>
