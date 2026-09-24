{{--
    Select Input — dropdown field.
    Usage:
        <x-lml.select-input name="barangay" :options="['brgy-1' => 'Barangay 1']" placeholder="Choose..." />
    Or pass custom <option> tags via the slot.
    When nested in <x-lml.form-group>, aria-describedby is wired automatically.
--}}
@props([
    'name' => null,
    'id' => null,
    'options' => [],
    'selected' => null,
    'placeholder' => null,
    'required' => false,
    'disabled' => false,
    'error' => null,
    'describedby' => null,
])

@aware(['helpId' => null, 'errorId' => null])

@php
    $inputId = $id ?? $name;
    $errorMessage = $error ?? ($name ? $errors->first($name) : null);
    $hasError = filled($errorMessage);
    $currentValue = $name ? old($name, $selected) : $selected;
    $resolvedErrorId = $errorId ?? ($hasError && $inputId ? "{$inputId}-error" : null);
    $resolvedHelpId = $helpId;
    $autoDescribedBy = collect([$describedby, $resolvedHelpId, $resolvedErrorId])->filter()->unique()->implode(' ');
@endphp

<select
    @if ($name) name="{{ $name }}" @endif
    @if ($inputId) id="{{ $inputId }}" @endif
    @if ($required) required aria-required="true" @endif
    @if ($disabled) disabled @endif
    @if ($hasError) aria-invalid="true" @endif
    @if (filled($autoDescribedBy)) aria-describedby="{{ $autoDescribedBy }}" @endif
    {{ $attributes->class([
        'form-select lml-form-control',
        'is-invalid' => $hasError,
    ]) }}
>
    @if ($placeholder)
        <option value="" @selected($currentValue === null || $currentValue === '')>
            {{ $placeholder }}
        </option>
    @endif

    @if (! empty($options))
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected((string) $currentValue === (string) $optionValue)>
                {{ $optionLabel }}
            </option>
        @endforeach
    @else
        {{ $slot }}
    @endif
</select>
