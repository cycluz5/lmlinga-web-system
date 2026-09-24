{{--
    Section Header — title block for a page section with optional subtitle and actions.
    Usage:
        <x-lml.section-header title="Household Records" subtitle="Manage resident profiles">
            <x-slot:actions>
                <x-lml.primary-button>Add Record</x-lml.primary-button>
            </x-slot:actions>
        </x-lml.section-header>
--}}
@props([
    'title' => null,
    'subtitle' => null,
    'level' => 'h2',
])

@php
    $headingText = $title ?? $slot;
    $headingSize = match ($level) {
        'h1' => 'h3',
        'h2' => 'h4',
        'h3' => 'h5',
        default => 'h4',
    };
@endphp

<div {{ $attributes->class(['lml-section-header d-flex flex-wrap justify-content-between align-items-start gap-3']) }}>
    <div>
        <{{ $level }} class="lml-section-header__title {{ $headingSize }}">
            {{ $headingText }}
        </{{ $level }}>

        @if ($subtitle)
            <p class="lml-section-header__subtitle">{{ $subtitle }}</p>
        @endif
    </div>

    @if (isset($actions))
        <div class="d-flex flex-wrap align-items-center gap-2">
            {{ $actions }}
        </div>
    @endif
</div>
