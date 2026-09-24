{{--
    Form Group — wraps a label, input field, help text, and error message.
    Child inputs (text-input, password-input, etc.) resolve matching
    {name}-error / {name}-help IDs for aria-describedby.
    Usage:
        <x-lml.form-group label="Email" name="email" :required="true" help="We never share your email.">
            <x-lml.text-input name="email" :required="true" />
        </x-lml.form-group>
        <x-lml.form-group label="Full Name" name="full_name" icon="bi-person-fill" :required="true">
            <x-lml.text-input name="full_name" :required="true" />
        </x-lml.form-group>
--}}
@props([
    'label' => null,
    'name' => null,
    'for' => null,
    'required' => false,
    'optional' => false,
    'icon' => null,
    'error' => null,
    'help' => null,
])

@php
    $fieldId = $for ?? $name ?? $attributes->get('id');
    $errorMessage = $error ?? ($name ? $errors->first($name) : null);
    $helpId = ($fieldId && filled($help)) ? "{$fieldId}-help" : null;
    $errorId = ($fieldId && filled($errorMessage)) ? "{$fieldId}-error" : null;
    $iconClass = filled($icon) ? trim((string) $icon) : null;
@endphp

<div {{ $attributes->class(['mb-3']) }}>
    @if ($label)
        <label
            for="{{ $fieldId }}"
            @class([
                'form-label lml-form-label',
                'lml-form-label--required' => $required,
                'lml-form-label--with-icon' => filled($iconClass),
            ])
        >
            @if ($iconClass)
                <i class="bi {{ $iconClass }}" aria-hidden="true"></i>
            @endif
            <span>{{ $label }}</span>@if ($optional)<span class="lml-form-label__optional"> (Optional)</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if ($errorMessage)
        <div id="{{ $errorId }}" class="lml-form-error" role="alert">{{ $errorMessage }}</div>
    @endif

    @if ($help)
        <div id="{{ $helpId }}" class="lml-form-help">{{ $help }}</div>
    @endif
</div>
