{{--
    SR-05 term details (Admin only): version in use, version being worked on, actions and full history.
--}}
@extends('layouts.dashboard')

@section('title', 'Language Management - LMLinga')

@section('content')
    <div class="lml-language-management" data-lml-language-management-show>
        @if (session('status'))
            <div class="alert alert-success mb-0" role="status">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger mb-0" role="alert" data-lml-term-error>{{ session('error') }}</div>
        @endif

        <header class="lml-language-management__header">
            <div>
                <h1 class="lml-language-management__title">{{ $term['term'] }}</h1>
                <p class="lml-language-management__subtitle">
                    <span class="lml-term-status lml-term-status--{{ $term['status'] }}" data-lml-term-status="{{ $term['status'] }}">{{ $term['status_label'] }}</span>
                    @if ($term['revision_label'])
                        <span class="lml-term-status lml-term-status--revision">{{ $term['revision_label'] }}</span>
                    @endif
                    @if ($term['in_use'])
                        <span class="lml-language-management__muted">Available for chatbot use.</span>
                    @else
                        <span class="lml-language-management__muted">Not available for chatbot use.</span>
                    @endif
                </p>
            </div>
            <a href="{{ route('language-management.index') }}" class="btn btn-outline-secondary lml-focus-ring fw-medium">Back to terms</a>
        </header>

        <section class="lml-language-management__actions" aria-label="Term actions">
            @if ($term['can_edit'])
                <a href="{{ route('language-management.edit', ['termId' => $term['id']]) }}" class="btn btn-outline-primary lml-focus-ring fw-medium">Edit</a>
            @endif
            @if ($term['can_submit'])
                <form method="POST" action="{{ route('language-management.submit', ['termId' => $term['id']]) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary lml-focus-ring fw-medium">Submit for review</button>
                </form>
            @endif
            @if ($term['can_retire'])
                <form method="POST" action="{{ route('language-management.retire', ['termId' => $term['id']]) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary lml-focus-ring fw-medium">Retire</button>
                </form>
            @endif
            @if ($term['can_restore'])
                <form method="POST" action="{{ route('language-management.restore', ['termId' => $term['id']]) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary lml-focus-ring fw-medium">Restore as draft</button>
                </form>
            @endif
            @if ($term['can_delete'])
                <form method="POST" action="{{ route('language-management.destroy', ['termId' => $term['id']]) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger lml-focus-ring fw-medium">
                        {{ $term['deletes_whole_term'] ? 'Delete draft term' : 'Delete unsubmitted draft' }}
                    </button>
                </form>
            @endif
            @if (! $term['can_edit'] && $term['edit_blocked_reason'])
                <p class="lml-language-management__muted mb-0">{{ $term['edit_blocked_reason'] }}</p>
            @endif
        </section>

        @if ($term['approved'])
            <section class="lml-language-management__panel" aria-labelledby="term-approved-heading" data-lml-term-approved>
                <h2 id="term-approved-heading" class="lml-language-management__panel-title">
                    Approved version {{ $term['approved']['version_no'] }} (in use)
                </h2>
                @include('pages.language-management.partials.version', ['version' => $term['approved']])
            </section>
        @endif

        @if ($term['open'])
            <section class="lml-language-management__panel" aria-labelledby="term-open-heading" data-lml-term-open>
                <h2 id="term-open-heading" class="lml-language-management__panel-title">
                    Version {{ $term['open']['version_no'] }} — {{ $term['open']['status_label'] }}
                </h2>
                @include('pages.language-management.partials.version', ['version' => $term['open']])
            </section>
        @endif

        <section class="lml-language-management__panel" aria-labelledby="term-history-heading">
            <h2 id="term-history-heading" class="lml-language-management__panel-title">History</h2>
            @include('pages.language-management.partials.history', ['history' => $term['history']])
        </section>
    </div>
@endsection
