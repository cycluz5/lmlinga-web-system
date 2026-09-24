{{--
    Card — content container with optional header, body, and footer.
    Usage:
        <x-lml.card title="Resident Info" level="h3">
            <p>Card content here.</p>
        </x-lml.card>
--}}
@props([
    'title' => null,
    'level' => 'h3',
    'elevated' => false,
    'flat' => false,
    'padding' => true,
])

@php
    $surfaceClass = 'lml-surface';

    if ($elevated) {
        $surfaceClass .= ' lml-surface--elevated';
    }

    if ($flat) {
        $surfaceClass .= ' lml-surface--flat';
    }

    $headingSize = match ($level) {
        'h1' => 'h4',
        'h2' => 'h5',
        'h3' => 'h6',
        'h4' => 'h6',
        default => 'h6',
    };
@endphp

<div {{ $attributes->class(['card', $surfaceClass]) }}>
    @if ($title || isset($header))
        <div class="card-header bg-white border-bottom border-lml-soft-green py-3">
            @if (isset($header))
                {{ $header }}
            @else
                <{{ $level }} class="card-title mb-0 text-lml-deep-green fw-semibold {{ $headingSize }}">
                    {{ $title }}
                </{{ $level }}>
            @endif
        </div>
    @endif

    <div @class(['card-body', 'p-0' => ! $padding])>
        {{ $slot }}
    </div>

    @if (isset($footer))
        <div class="card-footer bg-white border-top border-lml-soft-green py-3">
            {{ $footer }}
        </div>
    @endif
</div>
