{{--
    Secondary Button — supporting actions (Cancel, Back, etc.).
    Usage: <x-lml.secondary-button>Cancel</x-lml.secondary-button>
--}}
@props([
    'type' => 'button',
    'href' => null,
    'size' => 'md',
    'disabled' => false,
])

<x-lml.button variant="secondary" :type="$type" :href="$href" :size="$size" :disabled="$disabled" {{ $attributes }}>
    {{ $slot }}
</x-lml.button>
