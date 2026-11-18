{{--
    Dataset details (Admin only): current version, actions and history.
--}}
@extends('layouts.dashboard')

@section('title', 'Chatbot Datasets - LMLinga')

@section('content')
    <div class="lml-language-management" data-lml-chatbot-dataset-show>
        @if (session('status'))
            <div class="alert alert-success mb-0" role="status">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger mb-0" role="alert" data-lml-dataset-error>{{ session('error') }}</div>
        @endif

        <header class="lml-language-management__header">
            <div>
                <h1 class="lml-language-management__title">{{ $dataset['title'] }}@if ($dataset['is_default']) <span class="badge text-bg-secondary fs-6 align-middle" data-lml-dataset-default>Default</span>@endif</h1>
                <p class="lml-language-management__subtitle">
                    <span class="lml-language-management__muted">
                        {{ $dataset['language_label'] }} · {{ $dataset['category_label'] }} · {{ $dataset['source_name'] }}
                            ·
                            @if ($dataset['retired'])
                                Removed from the chatbot health information.
                            @elseif ($dataset['indexed'])
                                <strong data-lml-dataset-indexed>In the chatbot health information (indexed).</strong>
                            @elseif ($dataset['in_chatbot'])
                                <strong data-lml-dataset-waiting>Saved for the chatbot, waiting to be indexed.</strong>
                            @endif
                    </span>
                </p>
            </div>
            <a href="{{ route('chatbot-datasets.index', ['language' => $dataset['language']]) }}" class="btn btn-outline-secondary lml-focus-ring fw-medium">Back to datasets</a>
        </header>

        <section class="lml-language-management__actions" aria-label="Dataset actions">
            @if ($dataset['can_edit'])
                <a href="{{ route('chatbot-datasets.edit', ['datasetId' => $dataset['id']]) }}" class="btn btn-outline-primary lml-focus-ring fw-medium">Change or replace file</a>
            @endif
            @if (! $dataset['retired'] && $dataset['in_chatbot'] && ! $dataset['indexed'])
                <form method="POST" action="{{ route('chatbot-datasets.reindex', ['datasetId' => $dataset['id']]) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary lml-focus-ring fw-medium" data-lml-dataset-reindex>Re-index</button>
                </form>
            @endif
            @if ($dataset['can_retire'])
                <form method="POST" action="{{ route('chatbot-datasets.retire', ['datasetId' => $dataset['id']]) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary lml-focus-ring fw-medium">Retire</button>
                </form>
            @endif
            @if ($dataset['can_restore'])
                <form method="POST" action="{{ route('chatbot-datasets.restore', ['datasetId' => $dataset['id']]) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary lml-focus-ring fw-medium">Restore and send to chatbot</button>
                </form>
            @endif
            @if (! $dataset['can_edit'] && $dataset['edit_blocked_reason'])
                <p class="lml-language-management__muted mb-0">{{ $dataset['edit_blocked_reason'] }}</p>
            @endif
        </section>

        @if ($dataset['approved_version'])
            <section class="lml-language-management__panel" aria-labelledby="dataset-approved-heading" data-lml-dataset-approved>
                <h2 id="dataset-approved-heading" class="lml-language-management__panel-title">
                    Current version {{ $dataset['approved_version']['version_no'] }}
                </h2>
                @include('pages.chatbot-datasets.partials.version', ['version' => $dataset['approved_version'], 'showScreening' => ! $dataset['is_default']])
            </section>
        @endif

        @unless ($dataset['is_default'])
            <section class="lml-language-management__panel" aria-labelledby="dataset-history-heading">
                <h2 id="dataset-history-heading" class="lml-language-management__panel-title">History</h2>
                @include('pages.chatbot-datasets.partials.history', ['history' => $dataset['history']])
            </section>
        @endunless
    </div>
@endsection
