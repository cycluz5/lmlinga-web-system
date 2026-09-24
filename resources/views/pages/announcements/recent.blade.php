{{--
    Recent Announcements — frontend demo list (posted date descending).
    Demo fixtures: App\Support\AnnouncementDemoCatalog
--}}
@extends('layouts.dashboard')

@section('title', 'Recent Announcements - LMLinga')

@section('content')
    <div
        class="lml-announce lml-announce--list"
        data-lml-announce-list
        data-announce-list-mode="recent"
    >
        <header class="lml-announce__header">
            <a
                href="{{ route('announcements.index') }}"
                class="lml-announce__back lml-focus-ring"
            >
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Back to Announcement</span>
            </a>

            <div class="lml-announce__hero lml-announce__hero--list">
                <div class="lml-announce__hero-start">
                    <span class="lml-announce__hero-icon" aria-hidden="true">
                        <i class="bi bi-clock-history"></i>
                    </span>
                    <div class="lml-announce__hero-copy">
                        <h1 class="lml-announce__title">Recent Announcements</h1>
                        <p class="lml-announce__subtitle">
                            View recently posted health notices and announcements.
                        </p>
                    </div>
                </div>
                <a
                    href="{{ route('announcements.create') }}"
                    class="lml-announce__btn lml-announce__btn--primary lml-announce__add-btn lml-focus-ring"
                >
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    <span>Add Announcement</span>
                </a>
            </div>
        </header>

        <div class="lml-announce__toolbar">
            <label class="lml-announce__search">
                <span class="visually-hidden">Search announcements</span>
                <i class="bi bi-search" aria-hidden="true"></i>
                <input
                    type="search"
                    class="form-control lml-form-control"
                    data-announce-search
                    placeholder="Search announcements..."
                    autocomplete="off"
                >
            </label>

            <div class="lml-announce__filters" role="group" aria-label="Recent filters">
                @foreach ([
                    'all' => 'All',
                    'upcoming' => 'Upcoming',
                    'today' => 'Today',
                    'past' => 'Past',
                ] as $value => $label)
                    <button
                        type="button"
                        class="lml-announce__filter-btn lml-focus-ring{{ $value === 'all' ? ' is-active' : '' }}"
                        data-announce-filter="{{ $value }}"
                        aria-pressed="{{ $value === 'all' ? 'true' : 'false' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        <section
            class="lml-announce__panel lml-announce__panel--list lml-surface lml-surface--elevated"
            aria-labelledby="lml-announce-recent-list-heading"
        >
            <h2 id="lml-announce-recent-list-heading" class="visually-hidden">
                Recent announcement list
            </h2>

            <ul class="lml-announce__feed" data-announce-feed>
                @forelse ($announcements as $item)
                    <li
                        class="lml-announce__recent-item"
                        data-announce-item
                        data-search="{{ e($item['search_text']) }}"
                        data-timing="{{ e($item['timing_key']) }}"
                    >
                        <div class="lml-announce__feed-main">
                            <div class="lml-announce__feed-topline">
                                <p class="lml-announce__feed-title">{{ $item['title'] }}</p>
                                <span @class([
                                    'lml-announce__badge',
                                    'lml-announce__badge--upcoming' => $item['timing'] === 'Upcoming',
                                    'lml-announce__badge--today' => $item['timing'] === 'Today',
                                    'lml-announce__badge--past' => $item['timing'] === 'Past',
                                ])>
                                    {{ $item['timing'] }}
                                </span>
                            </div>
                            <p class="lml-announce__feed-line">
                                <i class="bi bi-calendar3" aria-hidden="true"></i>
                                <span>{{ $item['posted_label'] }}</span>
                            </p>
                            <p class="lml-announce__feed-line">
                                <i class="bi bi-calendar-event" aria-hidden="true"></i>
                                <span>{{ $item['scheduled_label'] }}</span>
                            </p>
                            @if (! empty($item['time']))
                                <p class="lml-announce__feed-line">
                                    <i class="bi bi-clock" aria-hidden="true"></i>
                                    <span>{{ $item['time'] }}</span>
                                </p>
                            @endif
                            @if (! empty($item['place']))
                                <p class="lml-announce__feed-line">
                                    <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                    <span>{{ $item['place'] }}</span>
                                </p>
                            @endif
                            <p class="lml-announce__feed-line">
                                <i class="bi bi-people" aria-hidden="true"></i>
                                <span>Audience: {{ $item['audience'] }}</span>
                            </p>
                        </div>
                    </li>
                @empty
                    <li class="lml-announce__empty" data-announce-empty-default>
                        No recent announcements yet.
                    </li>
                @endforelse
            </ul>

            <p class="lml-announce__empty" data-announce-empty hidden>
                No announcements match your search or filter.
            </p>
        </section>
    </div>
@endsection
