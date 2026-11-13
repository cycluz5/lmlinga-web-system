{{--
    SR-07 Linguist review of one submitted chatbot dataset version (Linguist only).
    An explicit approval here is the only way a dataset version becomes Approved. Approval does not make
    the chatbot use the dataset; indexing needs a separate approval.
--}}
@extends('layouts.dashboard')

@section('title', 'Language Review - LMLinga')

@section('content')
    <div class="lml-language-review" data-lml-language-review-dataset>
        @if (session('error'))
            <div class="alert alert-danger mb-0" role="alert" data-lml-dataset-error>{{ session('error') }}</div>
        @endif

        <section class="lml-language-review__section" aria-labelledby="review-dataset-heading">
            <header class="lml-language-review__section-header lml-language-review__term-header">
                <h2 id="review-dataset-heading" class="lml-language-review__section-title">
                    {{ $item['is_revision'] ? 'Replacement dataset' : 'New dataset' }} — version {{ $item['version']['version_no'] }}
                    · {{ $item['dataset']['language_label'] }} · {{ $item['dataset']['category_label'] }}
                </h2>
                <a href="{{ route('language-review.index') }}" class="btn btn-outline-secondary btn-sm lml-focus-ring">Back to queue</a>
            </header>
            @unless ($item['pending'])
                <p class="lml-language-review__section-description" data-lml-dataset-decided>
                    This version is no longer waiting for review ({{ $item['version']['status_label'] }}).
                </p>
            @endunless
            @include('pages.chatbot-datasets.partials.version', ['version' => $item['version'], 'openContent' => true])
        </section>

        @if ($item['approved'])
            <section class="lml-language-review__section" aria-labelledby="review-dataset-approved-heading" data-lml-dataset-approved>
                <h2 id="review-dataset-approved-heading" class="lml-language-review__section-title">
                    Currently approved version {{ $item['approved']['version_no'] }} (stays current until you approve the replacement)
                </h2>
                @include('pages.chatbot-datasets.partials.version', ['version' => $item['approved']])
            </section>
        @endif

        @if ($item['pending'])
            <section class="lml-language-review__section" aria-labelledby="review-dataset-decision-heading">
                <h2 id="review-dataset-decision-heading" class="lml-language-review__section-title">Your decision</h2>
                <p class="lml-language-review__section-description">
                    Approve only after reading the full text. Automated screening is preliminary and can miss problems.
                </p>
                <div class="lml-language-review__decisions">
                    <form method="POST" action="{{ route('language-review.datasets.approve', ['datasetVersionId' => $item['version_id']]) }}" class="lml-language-review__decision">
                        @csrf
                        <label for="dataset-approve-note" class="lml-form-label">Note <span class="lml-form-label__optional">(optional)</span></label>
                        <textarea id="dataset-approve-note" name="review_note" rows="2" maxlength="1000" class="form-control lml-form-control"></textarea>
                        <button type="submit" class="btn btn-primary lml-focus-ring fw-medium">Approve</button>
                    </form>
                    <form method="POST" action="{{ route('language-review.datasets.reject', ['datasetVersionId' => $item['version_id']]) }}" class="lml-language-review__decision">
                        @csrf
                        <label for="dataset-reject-note" class="lml-form-label lml-form-label--required">What needs to change</label>
                        <textarea id="dataset-reject-note" name="review_note" rows="2" maxlength="1000" required
                            class="form-control lml-form-control @error('review_note') is-invalid @enderror"></textarea>
                        @error('review_note')<p class="lml-form-error">{{ $message }}</p>@enderror
                        <button type="submit" class="btn btn-outline-secondary lml-focus-ring fw-medium">Reject</button>
                    </form>
                </div>
            </section>
        @endif

        <section class="lml-language-review__section" aria-labelledby="review-dataset-history-heading">
            <h2 id="review-dataset-history-heading" class="lml-language-review__section-title">Review history</h2>
            @include('pages.language-management.partials.history', ['history' => $item['history']])
        </section>
    </div>
@endsection
