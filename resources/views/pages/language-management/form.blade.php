{{--
    SR-05 Add / edit a Bikol-Iriga term (Admin only). Editing a reviewed term creates a new draft version;
    an approved version stays in use until the Linguist approves the new one.
--}}
@extends('layouts.dashboard')

@section('title', 'Language Management - LMLinga')

@php
    $value = static fn (string $field): string => (string) old($field, $values[$field] ?? '');
    $fields = [
        ['term', 'Bikol-Iriga term', true, 120, 'input'],
        ['english_meaning', 'English meaning', true, 255, 'input'],
        ['tagalog_meaning', 'Tagalog meaning', false, 255, 'input'],
    ];
@endphp

@section('content')
    <div class="lml-language-management" data-lml-language-management-form>
        <header class="lml-language-management__header">
            <div>
                <h1 class="lml-language-management__title">{{ $term ? 'Edit term' : 'Add term' }}</h1>
                <p class="lml-language-management__subtitle">
                    Enter the term exactly as it should be reviewed. A Linguist checks it before the chatbot may use it.
                </p>
            </div>
            <a href="{{ $term ? route('language-management.show', ['termId' => $term['id']]) : route('language-management.index') }}" class="btn btn-outline-secondary lml-focus-ring fw-medium">Cancel</a>
        </header>

        @if ($term && $term['edit_creates_revision'])
            <div class="alert alert-info mb-0" role="note" data-lml-term-revision-note>
                @if ($term['in_use'])
                    Saving creates a new draft version. The approved version stays in use until a Linguist approves the new one.
                @else
                    Saving creates a new draft version. The reviewed version and its history are kept.
                @endif
            </div>
        @endif

        <form method="POST" action="{{ $action }}" class="lml-language-management__form" novalidate>
            @csrf
            @if ($method !== 'POST')
                @method($method)
            @endif

            @foreach ($fields as [$name, $label, $required, $max])
                <div class="lml-language-management__field">
                    <label for="term-{{ $name }}" class="lml-form-label{{ $required ? ' lml-form-label--required' : '' }}">
                        {{ $label }}
                        @unless ($required)<span class="lml-form-label__optional">(optional)</span>@endunless
                    </label>
                    <input
                        id="term-{{ $name }}"
                        type="text"
                        name="{{ $name }}"
                        value="{{ $value($name) }}"
                        maxlength="{{ $max }}"
                        class="form-control lml-form-control @error($name) is-invalid @enderror"
                        @if ($required) required @endif
                        @error($name) aria-invalid="true" aria-describedby="term-{{ $name }}-error" @enderror
                    >
                    @error($name)
                        <p id="term-{{ $name }}-error" class="lml-form-error">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach

            <div class="lml-language-management__field">
                <label for="term-part_of_speech" class="lml-form-label">
                    Part of speech <span class="lml-form-label__optional">(optional)</span>
                </label>
                <select id="term-part_of_speech" name="part_of_speech" class="form-select lml-form-control @error('part_of_speech') is-invalid @enderror">
                    <option value="">Not specified</option>
                    @foreach ($partsOfSpeech as $key => $label)
                        <option value="{{ $key }}" @selected($value('part_of_speech') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('part_of_speech')
                    <p class="lml-form-error">{{ $message }}</p>
                @enderror
            </div>

            @foreach ([['example_usage', 'Example usage', 'A sentence that shows how the term is used.'], ['notes', 'Notes for the reviewer', 'Context or sources the Linguist should know about.']] as [$name, $label, $help])
                <div class="lml-language-management__field">
                    <label for="term-{{ $name }}" class="lml-form-label">
                        {{ $label }} <span class="lml-form-label__optional">(optional)</span>
                    </label>
                    <textarea
                        id="term-{{ $name }}"
                        name="{{ $name }}"
                        rows="3"
                        maxlength="1000"
                        class="form-control lml-form-control @error($name) is-invalid @enderror"
                        aria-describedby="term-{{ $name }}-help"
                    >{{ $value($name) }}</textarea>
                    <p id="term-{{ $name }}-help" class="lml-form-help">{{ $help }}</p>
                    @error($name)
                        <p class="lml-form-error">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach

            <div class="lml-language-management__actions">
                <button type="submit" class="btn btn-primary lml-focus-ring fw-medium">Save draft</button>
            </div>
        </form>
    </div>
@endsection
