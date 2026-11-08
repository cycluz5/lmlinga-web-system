{{--
    Announcement dashboard — summary + bulk management list (frontend demo).
    Create form: announcements.create
    View-all: announcements.upcoming / announcements.recent
--}}
@extends('layouts.dashboard')

@section('title', 'Announcements - LMLinga')

@section('content')
    <div
        class="lml-announce lml-announce--dashboard"
        data-lml-announce-manage
        data-announce-page-size="10"
    >
        @if (session('status'))
            <div class="alert alert-success mb-3" role="status">
                {{ session('status') }}
            </div>
        @endif

        <header class="lml-announce__hero">
            <div class="lml-announce__hero-start">
                <span class="lml-announce__hero-icon" aria-hidden="true">
                    <i class="bi bi-megaphone"></i>
                </span>
                <div class="lml-announce__hero-copy">
                    <h1 class="lml-announce__title">Announcements</h1>
                    <p class="lml-announce__subtitle">
                        Manage and monitor health notices for residents.
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
        </header>

        <section class="lml-announce__summary" aria-label="Announcement summary">
            <div class="lml-announce__summary-grid lml-announce__summary-grid--three">
                @foreach ($summaryCards as $card)
                    <article
                        class="lml-announce__stat lml-announce__stat--{{ $card['tone'] }}"
                        data-announce-stat="{{ $card['key'] }}"
                    >
                        <span class="lml-announce__stat-icon" aria-hidden="true">
                            <i class="bi {{ $card['icon'] }}"></i>
                        </span>
                        <div class="lml-announce__stat-body">
                            <h2 class="lml-announce__stat-label">{{ $card['label'] }}</h2>
                            <p class="lml-announce__stat-value">{{ number_format($card['value']) }}</p>
                            <p class="lml-announce__stat-hint">{{ $card['hint'] }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <div class="lml-announce__lists">
            <section
                class="lml-announce__panel lml-surface lml-surface--elevated"
                aria-labelledby="lml-announce-upcoming-heading"
            >
                <div class="lml-announce__panel-head">
                    <h2 id="lml-announce-upcoming-heading" class="lml-announce__panel-title">
                        <i class="bi bi-calendar-event" aria-hidden="true"></i>
                        <span>Upcoming Announcements</span>
                    </h2>
                    <a
                        href="{{ route('announcements.upcoming') }}"
                        class="lml-announce__view-all lml-focus-ring"
                    >
                        View all
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </a>
                </div>

                <ul class="lml-announce__feed" id="upcoming">
                    @foreach ($upcomingAnnouncements as $item)
                        <li class="lml-announce__upcoming-item">
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
                    @endforeach
                </ul>
            </section>

            <section
                class="lml-announce__panel lml-surface lml-surface--elevated"
                aria-labelledby="lml-announce-recent-heading"
            >
                <div class="lml-announce__panel-head">
                    <h2 id="lml-announce-recent-heading" class="lml-announce__panel-title">
                        <i class="bi bi-clock-history" aria-hidden="true"></i>
                        <span>Recent Announcements</span>
                    </h2>
                    <a
                        href="{{ route('announcements.recent') }}"
                        class="lml-announce__view-all lml-focus-ring"
                    >
                        View all
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </a>
                </div>

                <ul class="lml-announce__feed" id="recent">
                    @foreach ($recentAnnouncements as $item)
                        <li class="lml-announce__recent-item">
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
                                    <i class="bi bi-people" aria-hidden="true"></i>
                                    <span>Audience: {{ $item['audience'] }}</span>
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>

        <section
            class="lml-announce__manage lml-surface lml-surface--elevated"
            aria-labelledby="lml-announce-manage-heading"
        >
            <div class="lml-announce__manage-head">
                <h2 id="lml-announce-manage-heading" class="lml-announce__panel-title">
                    <i class="bi bi-list-ul" aria-hidden="true"></i>
                    <span>All Announcements</span>
                </h2>
                <p class="lml-announce__manage-hint">
                    Search, filter, and manage notices in one place.
                </p>
            </div>

            <div class="lml-announce__manage-toolbar">
                <label class="lml-announce__search">
                    <span class="visually-hidden">Search announcements</span>
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input
                        type="search"
                        class="form-control lml-form-control"
                        data-announce-manage-search
                        placeholder="Search announcements..."
                        autocomplete="off"
                    >
                </label>

                <div class="lml-announce__manage-filters">
                    <label class="lml-announce__filter-field">
                        <span class="lml-announce__filter-label">Status</span>
                        <select
                            class="form-select lml-form-control"
                            data-announce-manage-status
                            aria-label="Filter by status"
                        >
                            <option value="all">All</option>
                            <option value="upcoming">Upcoming</option>
                            <option value="published">Published</option>
                            <option value="today">Today</option>
                            <option value="past">Past</option>
                        </select>
                    </label>

                    <label class="lml-announce__filter-field">
                        <span class="lml-announce__filter-label">Audience</span>
                        <select
                            class="form-select lml-form-control"
                            data-announce-manage-audience
                            aria-label="Filter by audience"
                        >
                            <option value="all">All Audiences</option>
                            <option value="all_households">All Residents</option>
                            <option value="age">Specific Age Group</option>
                            <option value="active_maternal">Active Maternal</option>
                            <option value="active_fp_user">Active FP User</option>
                        </select>
                    </label>

                    <label class="lml-announce__filter-field">
                        <span class="lml-announce__filter-label">Date</span>
                        <select
                            class="form-select lml-form-control"
                            data-announce-manage-date
                            aria-label="Filter by date"
                        >
                            <option value="all">All Dates</option>
                            <option value="today">Today</option>
                            <option value="week">This Week</option>
                            <option value="month">This Month</option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="lml-announce__table-wrap d-none d-md-block">
                <table class="lml-announce__table">
                    <thead>
                        <tr>
                            <th scope="col">Title</th>
                            <th scope="col">Audience</th>
                            <th scope="col">Event Date</th>
                            <th scope="col">Posted Date</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="lml-announce__table-actions-col">
                                <span class="visually-hidden">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody data-announce-manage-table-body>
                        @foreach ($manageAnnouncements as $item)
                            <tr
                                data-announce-manage-row
                                data-search="{{ e($item['search_text']) }}"
                                data-status="{{ e($item['status_key']) }}"
                                data-timing="{{ e($item['timing_key']) }}"
                                data-audience="{{ e($item['audience_type']) }}"
                                data-date-today="{{ $item['is_today_event'] ? '1' : '0' }}"
                                data-date-week="{{ $item['is_this_week'] ? '1' : '0' }}"
                                data-date-month="{{ $item['is_this_month'] ? '1' : '0' }}"
                            >
                                <td>
                                    <span class="lml-announce__table-title">{{ $item['title'] }}</span>
                                </td>
                                <td>{{ $item['audience'] }}</td>
                                <td>{{ $item['event_label'] }}</td>
                                <td>{{ $item['posted_short'] }}</td>
                                <td>
                                    <span @class([
                                        'lml-announce__badge',
                                        'lml-announce__badge--upcoming' => $item['status_label'] === 'Upcoming',
                                        'lml-announce__badge--today' => $item['status_label'] === 'Today',
                                        'lml-announce__badge--published' => $item['status_label'] === 'Published',
                                        'lml-announce__badge--past' => $item['timing'] === 'Past' && $item['status_label'] !== 'Published',
                                    ])>
                                        {{ $item['status_label'] }}
                                    </span>
                                </td>
                                <td class="lml-announce__table-actions-col">
                                    @include('pages.announcements.partials.manage-actions', [
                                        'itemId' => $item['id'],
                                        'variant' => 'table',
                                    ])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <ul class="lml-announce__manage-cards d-md-none list-unstyled mb-0" data-announce-manage-cards>
                @foreach ($manageAnnouncements as $item)
                    <li
                        class="lml-announce__manage-card"
                        data-announce-manage-row
                        data-search="{{ e($item['search_text']) }}"
                        data-status="{{ e($item['status_key']) }}"
                        data-timing="{{ e($item['timing_key']) }}"
                        data-audience="{{ e($item['audience_type']) }}"
                        data-date-today="{{ $item['is_today_event'] ? '1' : '0' }}"
                        data-date-week="{{ $item['is_this_week'] ? '1' : '0' }}"
                        data-date-month="{{ $item['is_this_month'] ? '1' : '0' }}"
                    >
                        <div class="lml-announce__manage-card-top">
                            <p class="lml-announce__feed-title">{{ $item['title'] }}</p>
                            @include('pages.announcements.partials.manage-actions', [
                                'itemId' => $item['id'],
                                'variant' => 'card',
                            ])
                        </div>
                        <p class="lml-announce__feed-line">
                            <i class="bi bi-people" aria-hidden="true"></i>
                            <span>{{ $item['audience'] }}</span>
                        </p>
                        <p class="lml-announce__feed-line">
                            <i class="bi bi-calendar-event" aria-hidden="true"></i>
                            <span>{{ $item['event_label'] }}</span>
                        </p>
                        <div class="lml-announce__manage-card-meta">
                            <span class="lml-announce__manage-card-posted">{{ $item['posted_label'] }}</span>
                            <span @class([
                                'lml-announce__badge',
                                'lml-announce__badge--upcoming' => $item['status_label'] === 'Upcoming',
                                'lml-announce__badge--today' => $item['status_label'] === 'Today',
                                'lml-announce__badge--published' => $item['status_label'] === 'Published',
                            ])>
                                {{ $item['status_label'] }}
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="lml-announce__empty-state" data-announce-manage-empty hidden>
                <p class="lml-announce__empty-title">No announcements found.</p>
                <p class="lml-announce__empty-text">Try adjusting your search or filters.</p>
            </div>

            <div class="lml-announce__pagination" data-announce-manage-pagination>
                <p class="lml-announce__pagination-meta" data-announce-manage-meta>
                    Showing 1–10 of {{ count($manageAnnouncements) }} announcements
                </p>
                <nav class="lml-announce__pagination-nav" aria-label="Announcement pages">
                    <button
                        type="button"
                        class="lml-announce__page-btn lml-focus-ring"
                        data-announce-manage-prev
                        disabled
                    >
                        Previous
                    </button>
                    <div class="lml-announce__page-numbers" data-announce-manage-pages></div>
                    <button
                        type="button"
                        class="lml-announce__page-btn lml-focus-ring"
                        data-announce-manage-next
                    >
                        Next
                    </button>
                </nav>
            </div>
        </section>

    </div>
@endsection
