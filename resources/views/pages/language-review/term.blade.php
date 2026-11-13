{{--
    SR-05 Linguist review of one submitted Bikol-Iriga term version (Linguist only). Approve or reject with a
    note; the Linguist does not edit the text (D-T2).
--}}
@extends('layouts.dashboard')

@section('title', 'Language Review - LMLinga')

@section('content')
    <div class="lml-language-review" data-lml-language-review-term>
        @if (session('error'))
            <div class="alert alert-danger mb-0" role="alert" data-lml-term-error>{{ session('error') }}</div>
        @endif

        <section class="lml-language-review__section" aria-labelledby="review-term-heading">
            <header class="lml-language-review__section-header lml-language-review__term-header">
                <h2 id="review-term-heading" class="lml-language-review__section-title">
                    {{ $item['is_revision'] ? 'Revised term' : 'New term' }} — version {{ $item['version']['version_no'] }}
                </h2>
                <a href="{{ route('language-review.index') }}" class="btn btn-outline-secondary btn-sm lml-focus-ring">Back to queue</a>
            </header>
            @unless ($item['pending'])
                <p class="lml-language-review__section-description" data-lml-term-decided>
                    This version is no longer waiting for review ({{ $item['version']['status_label'] }}).
                </p>
            @endunless
            @include('pages.language-management.partials.version', ['version' => $item['version']])
        </section>

        @if ($item['approved'])
            <section class="lml-language-review__section" aria-labelledby="review-approved-heading" data-lml-term-approved>
                <h2 id="review-approved-heading" class="lml-language-review__section-title">
                    Currently approved version {{ $item['approved']['version_no'] }} (in use until you approve the revision)
                </h2>
                @include('pages.language-management.partials.version', ['version' => $item['approved']])
            </section>
        @endif

        @if ($item['pending'])
            <section class="lml-language-review__section" aria-labelledby="review-decision-heading">
                <h2 id="review-decision-heading" class="lml-language-review__section-title">Your decision</h2>
                <div class="lml-language-review__decisions">
                    <form method="POST" action="{{ route('language-review.terms.approve', ['versionId' => $item['version_id']]) }}" class="lml-language-review__decision">
                        @csrf
                        <label for="approve-note" class="lml-form-label">
                            Note <span class="lml-form-label__optional">(optional)</span>
                        </label>
                        <textarea id="approve-note" name="review_note" rows="2" maxlength="1000" class="form-control lml-form-control"></textarea>
                        <button type="submit" class="btn btn-primary lml-focus-ring fw-medium">Approve</button>
                    </form>
                    <form method="POST" action="{{ route('language-review.terms.reject', ['versionId' => $item['version_id']]) }}" class="lml-language-review__decision">
                        @csrf
                        <label for="reject-note" class="lml-form-label lml-form-label--required">What needs to change</label>
                        <textarea
                            id="reject-note"
                            name="review_note"
                            rows="2"
                            maxlength="1000"
                            required
                            class="form-control lml-form-control @error('review_note') is-invalid @enderror"
                        ></textarea>
                        @error('review_note')
                            <p class="lml-form-error">{{ $message }}</p>
                        @enderror
                        <button type="submit" class="btn btn-outline-secondary lml-focus-ring fw-medium">Reject</button>
                    </form>
                </div>
            </section>
        @endif

        <section class="lml-language-review__section" aria-labelledby="review-history-heading">
            <h2 id="review-history-heading" class="lml-language-review__section-title">Review history</h2>
            @include('pages.language-management.partials.history', ['history' => $item['history']])
        </section>
    </div>
@endsection
