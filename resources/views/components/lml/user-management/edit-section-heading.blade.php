{{--
    Numbered section heading for Edit Health Worker modal.
--}}
@props([
    'number' => '1',
    'title' => '',
    'headingId' => null,
])

@php
    $resolvedHeadingId = $headingId ?? $attributes->get('id');
@endphp

<div {{ $attributes->except('id')->class(['lml-hw-edit__section-head']) }}>
    <span class="lml-hw-edit__section-num" aria-hidden="true">{{ $number }}</span>
    <h3
        @if ($resolvedHeadingId) id="{{ $resolvedHeadingId }}" @endif
        class="lml-hw-edit__section-title"
    >
        {{ $title }}
    </h3>
</div>
