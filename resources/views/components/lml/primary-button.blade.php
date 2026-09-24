{{--
    Primary Button — main call-to-action.
    Usage: <x-lml.primary-button>Save</x-lml.primary-button>
--}}
@props([
    'type' => 'button',
    'href' => null,
    'size' => 'md',
    'disabled' => false,
])

<x-lml.button variant="primary" :type="$type" :href="$href" :size="$size" :disabled="$disabled" {{ $attributes }}>
    {{ $slot }}
</x-lml.button>
