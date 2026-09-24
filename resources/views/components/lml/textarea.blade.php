{{--
    Textarea — multi-line text field.
    Usage: <x-lml.textarea name="notes" rows="4" placeholder="Add notes..." />
    When nested in <x-lml.form-group>, aria-describedby is wired automatically.
--}}
@props([
    'name' => null,
    'id' => null,
    'value' => null,
    'placeholder' => null,
    'rows' => 4,
    'required' => false,
    'disabled' => false,
    'readonly' => false,
    'error' => null,
    'describedby' => null,
])

@aware(['helpId' => null, 'errorId' => null])

@php
    $inputId = $id ?? $name;
    $errorMessage = $error ?? ($name ? $errors->first($name) : null);
    $hasError = filled($errorMessage);
    $content = $name ? old($name, $value) : $value;
    $resolvedErrorId = $errorId ?? ($hasError && $inputId ? "{$inputId}-error" : null);
    $resolvedHelpId = $helpId;
    $autoDescribedBy = collect([$describedby, $resolvedHelpId, $resolvedErrorId])->filter()->unique()->implode(' ');
@endphp

<textarea
    @if ($name) name="{{ $name }}" @endif
    @if ($inputId) id="{{ $inputId }}" @endif
    rows="{{ $rows }}"
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
>{{ $content }}</textarea>
