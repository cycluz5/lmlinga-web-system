{{--
    Text Input — single-line text field.
    Usage:
        <x-lml.text-input name="first_name" placeholder="Juan" />
        <x-lml.text-input type="email" name="email" placeholder="Email" autocomplete="email" />
        <x-lml.text-input type="date" name="date_of_birth" />
    When nested in <x-lml.form-group>, aria-describedby is wired automatically
    to the group's rendered {name}-error / {name}-help elements.
--}}
@props([
    'type' => 'text',
    'name' => null,
    'id' => null,
    'value' => null,
    'placeholder' => null,
    'required' => false,
    'disabled' => false,
    'readonly' => false,
    'error' => null,
    'describedby' => null,
])

@aware(['helpId' => null, 'errorId' => null])

@php
    $allowedTypes = ['text', 'email', 'search', 'tel', 'url', 'number', 'date'];
    $inputType = in_array($type, $allowedTypes, true) ? $type : 'text';
    $inputId = $id ?? $name;
    $errorMessage = $error ?? ($name ? $errors->first($name) : null);
    $hasError = filled($errorMessage);
    // Prefer the parent form-group error/help IDs when present; otherwise
    // fall back to the same {id}-error ID the form-group renders.
    $resolvedErrorId = $errorId ?? ($hasError && $inputId ? "{$inputId}-error" : null);
    $resolvedHelpId = $helpId;
    $autoDescribedBy = collect([$describedby, $resolvedHelpId, $resolvedErrorId])->filter()->unique()->implode(' ');
@endphp

<input
    type="{{ $inputType }}"
    @if ($name) name="{{ $name }}" @endif
    @if ($inputId) id="{{ $inputId }}" @endif
    value="{{ $name ? old($name, $value) : $value }}"
    @if ($placeholder) placeholder="{{ $placeholder }}" @endif
    @if ($required) required aria-required="true" @endif
    @if ($disabled) disabled @endif
    @if ($readonly) readonly @endif
    @if ($hasError) aria-invalid="true" @endif
    @if (filled($autoDescribedBy)) aria-describedby="{{ $autoDescribedBy }}" @endif
    {{ $attributes->class([
        'form-control lml-form-control',
        'is-invalid' => $hasError,
    ]) }}
/>
