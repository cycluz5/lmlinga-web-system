@props([
    'tag' => 'span',
    'size' => 'md',
])

@php
    $sizes = [
        'sm' => 'text-xl',
        'md' => 'text-2xl',
        'lg' => 'text-3xl',
        'xl' => 'text-4xl',
    ];
    $sizeClass = $sizes[$size] ?? $sizes['md'];
@endphp

<{{ $tag }} {{ $attributes->merge(['class' => "lml-brand lml-logo {$sizeClass} text-lml-deep-green"]) }}>
    {{ $slot }}
</{{ $tag }}>
