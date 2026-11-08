{{--
    Outline Button — low-emphasis actions with a bordered style.
    Usage: <x-lml.outline-button>Learn More</x-lml.outline-button>
--}}
@props([
    'type' => 'button',
    'href' => null,
    'size' => 'md',
    'disabled' => false,
])

<x-lml.button variant="outline" :type="$type" :href="$href" :size="$size" :disabled="$disabled" {{ $attributes }}>
    {{ $slot }}
</x-lml.button>
