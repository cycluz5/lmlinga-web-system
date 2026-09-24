{{--
    Page Container — consistent max-width and horizontal padding for page content.
    Usage:
        <x-lml.page-container>
            <x-lml.section-header title="Residents" />
            ...
        </x-lml.page-container>
--}}
@props([
    'fluid' => false,
])

@php
    $containerClass = $fluid
        ? 'container-fluid'
        : 'container lml-page-container';
@endphp

<div {{ $attributes->class([$containerClass, 'py-4']) }}>
    {{ $slot }}
</div>
