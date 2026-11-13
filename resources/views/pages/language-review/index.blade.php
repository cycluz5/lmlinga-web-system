{{--
    SR-06 Language Review — Linguist accounts only.
    Terms (SR-05) and datasets (SR-07) list versions an Admin submitted. Nothing is invented here.
--}}
@extends('layouts.dashboard')

@section('title', 'Language Review - LMLinga')

@section('content')
    <div class="lml-language-review" data-lml-language-review>
        @if (session('status'))
            <div class="alert alert-success mb-0" role="status">{{ session('status') }}</div>
        @endif

        <section class="lml-language-review__summary" aria-labelledby="language-review-summary-heading">
            <h2 id="language-review-summary-heading" class="lml-language-review__summary-title">
                <i class="bi bi-translate" aria-hidden="true"></i>
                Items waiting for your review
            </h2>
            <p class="lml-language-review__summary-count" data-lml-language-review-count>{{ (int) $pendingCount }}</p>
            <p class="lml-language-review__summary-note">
                Only items approved by a linguist may be used by the chatbot.
            </p>
        </section>

        @foreach ($sections as $key => $section)
            <section
                class="lml-language-review__section"
                aria-labelledby="language-review-{{ $key }}-heading"
                data-lml-language-review-section="{{ $key }}"
            >
                <header class="lml-language-review__section-header">
                    <h2 id="language-review-{{ $key }}-heading" class="lml-language-review__section-title">{{ $section['title'] }}</h2>
                    <p class="lml-language-review__section-description">{{ $section['description'] }}</p>
                </header>

                @if (count($section['items']) === 0)
                    <p class="lml-language-review__empty" data-lml-language-review-empty>
                        <i class="bi bi-inbox" aria-hidden="true"></i>
                        No items are waiting for review.
                    </p>
                @elseif ($key === \App\Support\LanguageReviewQueue::SECTION_TERMS)
                    <ul class="lml-language-review__items" data-lml-language-review-items="terms">
                        @foreach ($section['items'] as $item)
                            <li class="lml-language-review__item">
                                <div>
                                    <p class="lml-language-review__item-term">{{ $item['term'] }}</p>
                                    <p class="lml-language-review__item-meta">
                                        {{ $item['english_meaning'] }} · {{ $item['is_revision'] ? 'Revision' : 'New term' }} · submitted {{ $item['submitted_at'] }}
                                    </p>
                                </div>
                                <a href="{{ route('language-review.terms.show', ['versionId' => $item['version_id']]) }}" class="btn btn-primary btn-sm lml-focus-ring">Review</a>
                            </li>
                        @endforeach
                    </ul>
                @elseif ($key === \App\Support\LanguageReviewQueue::SECTION_DATASETS)
                    <ul class="lml-language-review__items" data-lml-language-review-items="datasets">
                        @foreach ($section['items'] as $item)
                            <li class="lml-language-review__item">
                                <div>
                                    <p class="lml-language-review__item-term">{{ $item['title'] }}</p>
                                    <p class="lml-language-review__item-meta">
                                        {{ $item['language_label'] }} · {{ $item['category_label'] }} · {{ $item['is_revision'] ? 'Replacement' : 'New dataset' }}
                                        · submitted {{ $item['submitted_at'] }}
                                        @if ($item['warning_count'] > 0)
                                            · {{ $item['warning_count'] }} screening {{ $item['warning_count'] === 1 ? 'warning' : 'warnings' }}
                                        @endif
                                    </p>
                                </div>
                                <a href="{{ route('language-review.datasets.show', ['datasetVersionId' => $item['version_id']]) }}" class="btn btn-primary btn-sm lml-focus-ring">Review</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>
@endsection
