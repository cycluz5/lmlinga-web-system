{{--
    Upcoming Announcements — frontend demo list (event date ascending).
    Demo fixtures: App\Support\AnnouncementDemoCatalog
--}}
@extends('layouts.dashboard')

@section('title', 'Upcoming Announcements - LMLinga')

@section('content')
    <div
        class="lml-announce lml-announce--list"
        data-lml-announce-list
        data-announce-list-mode="upcoming"
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
                        <i class="bi bi-calendar-event"></i>
                    </span>
                    <div class="lml-announce__hero-copy">
                        <h1 class="lml-announce__title">Upcoming Announcements</h1>
                        <p class="lml-announce__subtitle">
                            View scheduled health activities and notices for residents.
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

            <div class="lml-announce__filters" role="group" aria-label="Upcoming filters">
                @foreach ([
                    'all' => 'All',
                    'week' => 'This Week',
                    'month' => 'This Month',
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
            aria-labelledby="lml-announce-upcoming-list-heading"
        >
            <h2 id="lml-announce-upcoming-list-heading" class="visually-hidden">
                Upcoming announcement list
            </h2>

            <ul class="lml-announce__feed" data-announce-feed>
                @forelse ($announcements as $item)
                    <li
                        class="lml-announce__upcoming-item"
                        data-announce-item
                        data-search="{{ e($item['search_text']) }}"
                        data-filter-week="{{ $item['is_this_week'] ? '1' : '0' }}"
                        data-filter-month="{{ $item['is_this_month'] ? '1' : '0' }}"
                        data-timing="{{ e($item['timing_key']) }}"
                    >
                        <div class="lml-announce__dateblock" aria-hidden="true">
                            <span class="lml-announce__dateblock-month">{{ $item['month'] }}</span>
                            <span class="lml-announce__dateblock-day">{{ $item['day'] }}</span>
                            <span class="lml-announce__dateblock-year">{{ $item['year'] }}</span>
                        </div>
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
                        No upcoming announcements right now.
                    </li>
                @endforelse
            </ul>

            <p class="lml-announce__empty" data-announce-empty hidden>
                No announcements match your search or filter.
            </p>
        </section>
    </div>
@endsection
