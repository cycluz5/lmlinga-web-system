{{--
    Password Input — password field with optional show/hide toggle.
    Usage: <x-lml.password-input name="password" />
    When nested in <x-lml.form-group>, aria-describedby is wired automatically.
--}}
@props([
    'name' => 'password',
    'id' => null,
    'placeholder' => 'Enter your password',
    'required' => false,
    'disabled' => false,
    'toggle' => true,
    'error' => null,
    'describedby' => null,
])

@aware(['helpId' => null, 'errorId' => null])

@php
    $inputId = $id ?? $name;
    $toggleId = $inputId . '_toggle';
    $errorMessage = $error ?? ($name ? $errors->first($name) : null);
    $hasError = filled($errorMessage);
    $resolvedErrorId = $errorId ?? ($hasError && $inputId ? "{$inputId}-error" : null);
    $resolvedHelpId = $helpId;
    $autoDescribedBy = collect([$describedby, $resolvedHelpId, $resolvedErrorId])->filter()->unique()->implode(' ');
@endphp

<div @class(['input-group', 'lml-password-input' => $toggle])>
    <input
        type="password"
        name="{{ $name }}"
        id="{{ $inputId }}"
        placeholder="{{ $placeholder }}"
        @if ($required) required aria-required="true" @endif
        @if ($disabled) disabled @endif
        @if ($hasError) aria-invalid="true" @endif
        @if (filled($autoDescribedBy)) aria-describedby="{{ $autoDescribedBy }}" @endif
        {{ $attributes->class([
            'form-control lml-form-control',
            'is-invalid' => $hasError,
        ]) }}
    />

    @if ($toggle)
        <button
            class="btn lml-password-input__toggle lml-focus-ring"
            type="button"
            id="{{ $toggleId }}"
            aria-label="Show password"
            title="Show password"
            aria-pressed="false"
            aria-controls="{{ $inputId }}"
            data-lml-password-toggle="{{ $inputId }}"
        >
            <i class="bi bi-eye" aria-hidden="true" data-lml-password-icon></i>
        </button>
    @endif
</div>

@once
    @push('scripts')
        <script>
            document.addEventListener('click', function (event) {
                const button = event.target.closest('[data-lml-password-toggle]');
                if (!button) return;

                const input = document.getElementById(button.dataset.lmlPasswordToggle);
                if (!input) return;

                const icon = button.querySelector('[data-lml-password-icon]');
                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                button.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
                button.setAttribute('title', isHidden ? 'Hide password' : 'Show password');
                button.setAttribute('aria-pressed', isHidden ? 'true' : 'false');

                if (icon) {
                    icon.classList.toggle('bi-eye', !isHidden);
                    icon.classList.toggle('bi-eye-slash', isHidden);
                }
            });
        </script>
    @endpush
@endonce
