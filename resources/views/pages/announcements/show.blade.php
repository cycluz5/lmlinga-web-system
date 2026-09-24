{{--
    Announcement detail — persisted fields only.
--}}
@extends('layouts.dashboard')

@section('title', $announcement->title.' - LMLinga')

@section('content')
    <div class="lml-announce lml-announce--create">
        <header class="lml-announce__header">
            <a
                href="{{ route('announcements.index') }}"
                class="lml-announce__back lml-focus-ring"
            >
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Back to Announcement</span>
            </a>
            <h1 class="lml-announce__title">View Announcement</h1>
            <p class="lml-announce__subtitle">
                Review this health notice as stored in the database.
            </p>
        </header>

        <div class="lml-announce__layout">
            <section
                class="lml-announce__composer lml-surface lml-surface--elevated"
                aria-labelledby="lml-announce-view-heading"
            >
                <h2 id="lml-announce-view-heading" class="lml-announce__card-title">
                    Announcement
                </h2>

                <article class="lml-announce-post">
                    <header class="lml-announce-post__header">
                        <span class="lml-announce-post__avatar" aria-hidden="true">
                            <i class="bi bi-hospital"></i>
                        </span>
                        <div class="lml-announce-post__identity">
                            <p class="lml-announce-post__name">{{ $announcement->posted_by_name }}</p>
                            <p class="lml-announce-post__meta">
                                {{ $item['posted_label'] }}
                            </p>
                        </div>
                    </header>

                    <p class="lml-announce-post__kicker">Health Announcement</p>

                    <h3 class="lml-announce-post__title">{{ $announcement->title }}</h3>
                    <p class="lml-announce-post__message">{{ $announcement->message }}</p>

                    <ul class="lml-announce-post__details">
                        <li>
                            <i class="bi bi-calendar-event" aria-hidden="true"></i>
                            <span>{{ $item['event_label'] }}</span>
                        </li>
                        @if ($item['time'])
                            <li>
                                <i class="bi bi-clock" aria-hidden="true"></i>
                                <span>{{ $item['time'] }}</span>
                            </li>
                        @endif
                        @if (filled($announcement->place))
                            <li>
                                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                <span>{{ $announcement->place }}</span>
                            </li>
                        @endif
                    </ul>

                    <div class="lml-announce-post__targeting">
                        <p class="lml-announce-post__audience">
                            <span class="lml-announce-post__badge">
                                Audience: {{ $announcement->audience_label }}
                            </span>
                        </p>
                        <p class="lml-announce-post__audience">
                            <span class="lml-announce-post__badge lml-announce-post__badge--coverage">
                                Coverage: {{ $coverageLabel }}
                            </span>
                        </p>
                    </div>
                </article>

                <div class="lml-announce__actions mt-4">
                    <a
                        href="{{ route('announcements.edit', $announcement) }}"
                        class="lml-announce__btn lml-announce__btn--primary lml-focus-ring"
                    >
                        Edit
                    </a>
                    <form
                        method="POST"
                        action="{{ route('announcements.destroy', $announcement) }}"
                        onsubmit="return confirm('Delete this announcement? This cannot be undone.');"
                    >
                        @csrf
                        @method('DELETE')
                        <button
                            type="submit"
                            class="lml-announce__btn lml-announce__btn--secondary lml-focus-ring"
                        >
                            Delete
                        </button>
                    </form>
                </div>
            </section>
        </div>
    </div>
@endsection
