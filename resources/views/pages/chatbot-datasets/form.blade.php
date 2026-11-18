{{--
    Upload / change a chatbot dataset (Admin only). The file's real content, size, encoding and type
    are checked on the server; the file is read once and not kept (only the checked text is stored).
--}}
@extends('layouts.dashboard')

@section('title', 'Chatbot Datasets - LMLinga')

@php
    $value = static fn (string $field): string => (string) old($field, $values[$field] ?? '');
@endphp

@section('content')
    <div class="lml-language-management" data-lml-chatbot-dataset-form>
        <header class="lml-language-management__header">
            <div>
                <h1 class="lml-language-management__title">{{ $dataset ? 'Change dataset' : 'Upload dataset' }}</h1>
                <p class="lml-language-management__subtitle">
                    Plain text (.txt, UTF-8) up to {{ $maxKilobytes }} KB. Do not include resident or patient information.
                </p>
            </div>
            <a href="{{ $dataset ? route('chatbot-datasets.show', ['datasetId' => $dataset['id']]) : route('chatbot-datasets.index') }}" class="btn btn-outline-secondary lml-focus-ring fw-medium">Cancel</a>
        </header>

        @if ($dataset)
            <div class="alert alert-info mb-0" role="note" data-lml-dataset-revision-note>
                Saving replaces the live text in the chatbot's health information with this version. The earlier version and its history are kept.
            </div>
        @endif

        <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="lml-language-management__form" novalidate>
            @csrf
            @if ($method !== 'POST')
                @method($method)
            @endif

            <div class="lml-language-management__field">
                <label for="dataset-title" class="lml-form-label lml-form-label--required">Title</label>
                <input id="dataset-title" type="text" name="title" value="{{ $value('title') }}" maxlength="160" required
                    class="form-control lml-form-control @error('title') is-invalid @enderror">
                @if (! $dataset && ! $translationOf)
                    <p class="lml-form-help">The title also sets the dataset's source name, which cannot change after the dataset is saved.</p>
                @endif
                @error('title')<p class="lml-form-error">{{ $message }}</p>@enderror
            </div>

            @if ($dataset)
                <p class="lml-language-management__muted mb-0">
                    {{ $dataset['language_label'] }} · {{ $dataset['category_label'] }} · source name {{ $dataset['source_name'] }}
                </p>
            @else
                <div class="lml-language-management__field">
                    <label for="dataset-language" class="lml-form-label lml-form-label--required">Language</label>
                    <select id="dataset-language" name="language" required class="form-select lml-form-control @error('language') is-invalid @enderror">
                        <option value="">Choose a language</option>
                        @foreach ($languages as $key => $label)
                            <option value="{{ $key }}" @selected($value('language') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('language')<p class="lml-form-error">{{ $message }}</p>@enderror
                </div>
                @if ($translationOf)
                    <input type="hidden" name="translation_of" value="{{ $translationOf['id'] }}">
                    <p class="lml-language-management__muted mb-0" data-lml-dataset-translation-note>
                        Translation of “{{ $translationOf['title'] }}” ({{ $translationOf['language_label'] }}) ·
                        {{ $translationOf['category_label'] }} · source name {{ $translationOf['source_name'] }}.
                        Write this dataset in the language you chose; it is the same topic and is saved as its own dataset.
                    </p>
                @else
                    <div class="lml-language-management__field">
                        <label for="dataset-category" class="lml-form-label lml-form-label--required">Category</label>
                        <select id="dataset-category" name="category" required class="form-select lml-form-control @error('category') is-invalid @enderror">
                            <option value="">Choose a category</option>
                            @foreach ($categories as $key => $label)
                                <option value="{{ $key }}" @selected($value('category') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('category')<p class="lml-form-error">{{ $message }}</p>@enderror
                    </div>
                @endif
            @endif

            <div class="lml-language-management__field">
                <label for="dataset-source" class="lml-form-label lml-form-label--required">Source reference</label>
                <input id="dataset-source" type="text" name="source_reference" value="{{ $value('source_reference') }}" maxlength="255" required
                    class="form-control lml-form-control @error('source_reference') is-invalid @enderror">
                <p class="lml-form-help">Where the information comes from, e.g. publication, agency and date.</p>
                @error('source_reference')<p class="lml-form-error">{{ $message }}</p>@enderror
            </div>

            <div class="lml-language-management__field">
                <label for="dataset-file" class="lml-form-label{{ $dataset ? '' : ' lml-form-label--required' }}">
                    Dataset file (.txt)
                    @if ($dataset)<span class="lml-form-label__optional">(optional — leave empty to keep the current text)</span>@endif
                </label>
                <input id="dataset-file" type="file" name="dataset_file" accept=".txt,text/plain" @unless ($dataset) required @endunless
                    class="form-control lml-form-control @error('dataset_file') is-invalid @enderror">
                @error('dataset_file')<p class="lml-form-error" data-lml-dataset-file-error>{{ $message }}</p>@enderror
            </div>

            <div class="form-check">
                <input id="dataset-confirm" type="checkbox" name="no_personal_data_confirmed" value="1" required
                    class="form-check-input @error('no_personal_data_confirmed') is-invalid @enderror">
                <label for="dataset-confirm" class="form-check-label">
                    I confirm this file contains no resident or patient personal information.
                </label>
                @error('no_personal_data_confirmed')<p class="lml-form-error">{{ $message }}</p>@enderror
            </div>

            <div class="lml-language-management__actions">
                <button type="submit" class="btn btn-primary lml-focus-ring fw-medium">Save and send to chatbot</button>
            </div>
        </form>
    </div>
@endsection
