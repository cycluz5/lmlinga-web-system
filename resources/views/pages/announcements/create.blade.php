{{--
    Add / Edit Announcement — same composer layout.
    Create posts to announcements.store; edit updates the same row.
--}}
@extends('layouts.dashboard')

@section('title', ($formMode ?? 'create') === 'edit' ? 'Edit Announcement - LMLinga' : 'Add Announcement - LMLinga')

@section('content')
    @php
        $isEdit = ($formMode ?? 'create') === 'edit';
        $formValues = $formValues ?? [];
        $audienceType = (string) old('audience_type', $formValues['audience_type'] ?? 'all');
        $zoneCoverage = (string) old('zone_coverage', $formValues['zone_coverage'] ?? 'all');
        $ageGroups = collect(old('age_groups', $formValues['age_groups'] ?? []))->map(fn ($value) => (string) $value)->all();
        $selectedZones = collect(old('zones', $formValues['zones'] ?? []))->map(fn ($value) => (string) $value)->all();
        $customZones = collect(old('custom_zones', $formValues['custom_zones'] ?? []))->map(fn ($value) => (string) $value)->values()->all();
        $formAction = $isEdit
            ? route('announcements.update', $announcement)
            : route('announcements.store');
    @endphp

    <div
        class="lml-announce lml-announce--create"
        data-lml-announcement
        data-reach-preview-url="{{ route('announcements.reach-preview') }}"
        data-custom-zones='@json($customZones)'
    >
        <header class="lml-announce__header">
            <a
                href="{{ route('announcements.index') }}"
                class="lml-announce__back lml-focus-ring"
            >
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Back to Announcement</span>
            </a>
            <h1 class="lml-announce__title">{{ $isEdit ? 'Edit Announcement' : 'Add Announcement' }}</h1>
            <p class="lml-announce__subtitle">
                {{ $isEdit ? 'Update this health notice for residents.' : 'Create and publish a health notice for residents.' }}
            </p>
        </header>

        <div class="lml-announce__layout">
            <section
                class="lml-announce__composer lml-surface lml-surface--elevated"
                aria-labelledby="lml-announce-form-heading"
            >
                <h2 id="lml-announce-form-heading" class="lml-announce__card-title">
                    {{ $isEdit ? 'Edit Announcement' : 'Create Announcement' }}
                </h2>

                <form
                    class="lml-announce__form"
                    data-announce-form
                    method="POST"
                    action="{{ $formAction }}"
                    novalidate
                >
                    @csrf
                    @if ($isEdit)
                        @method('PUT')
                    @endif
                    @if ($errors->any())
                        <div class="alert alert-danger" role="alert">
                            {{ $errors->first() }}
                        </div>
                    @endif
                    <div data-announce-custom-zones-holder>
                        @foreach ($customZones as $customZone)
                            <input type="hidden" name="custom_zones[]" value="{{ $customZone }}">
                        @endforeach
                    </div>
                    <div class="lml-announce__field">
                        <label class="lml-form-label lml-form-label--required" for="announce-title">
                            Title
                        </label>
                        <input
                            type="text"
                            id="announce-title"
                            name="title"
                            class="form-control lml-form-control"
                            data-announce-title
                            placeholder="e.g. Free Deworming Program — August 30"
                            maxlength="120"
                            autocomplete="off"
                            required
                            value="{{ old('title', $formValues['title'] ?? '') }}"
                            aria-describedby="announce-title-error"
                        >
                        <p
                            id="announce-title-error"
                            class="lml-announce__error"
                            data-announce-error="title"
                            hidden
                        ></p>
                    </div>

                    <div class="lml-announce__field">
                        <div class="lml-announce__label-row">
                            <label class="lml-form-label lml-form-label--required" for="announce-message">
                                Message
                            </label>
                            <span class="lml-announce__counter" data-announce-counter aria-live="polite">
                                0 / 500
                            </span>
                        </div>
                        <textarea
                            id="announce-message"
                            name="message"
                            class="form-control lml-form-control lml-announce__textarea"
                            data-announce-message
                            rows="5"
                            maxlength="500"
                            placeholder="Write your announcement message for residents here..."
                            required
                            aria-describedby="announce-message-help announce-message-error"
                        >{{ old('message', $formValues['message'] ?? '') }}</textarea>
                        <p id="announce-message-help" class="lml-form-help">
                            You may write the announcement in Filipino, Bikol, or English.
                        </p>
                        <p
                            id="announce-message-error"
                            class="lml-announce__error"
                            data-announce-error="message"
                            hidden
                        ></p>
                    </div>

                    <div class="lml-announce__row">
                        <div class="lml-announce__field">
                            <label class="lml-form-label lml-form-label--required" for="announce-date">
                                Date
                            </label>
                            <input
                                type="date"
                                id="announce-date"
                                name="date"
                                class="form-control lml-form-control @error('date') is-invalid @enderror"
                                data-announce-date
                                min="{{ now()->toDateString() }}"
                                value="{{ old('date', $formValues['date'] ?? '') }}"
                                required
                                aria-describedby="announce-date-error"
                                aria-invalid="{{ $errors->has('date') ? 'true' : 'false' }}"
                            >
                            <p
                                id="announce-date-error"
                                class="lml-announce__error"
                                data-announce-error="date"
                                @if (! $errors->has('date')) hidden @endif
                            >{{ $errors->first('date') }}</p>
                        </div>

                        <div class="lml-announce__field">
                            <label class="lml-form-label" for="announce-time">
                                Time
                                <span class="lml-form-label__optional">(optional)</span>
                            </label>
                            <input
                                type="time"
                                id="announce-time"
                                name="time"
                                class="form-control lml-form-control"
                                data-announce-time
                                value="{{ old('time', $formValues['time'] ?? '') }}"
                                placeholder="e.g. 8:00 AM"
                            >
                        </div>
                    </div>

                    <div class="lml-announce__field">
                        <label class="lml-form-label" for="announce-place">
                            Place
                            <span class="lml-form-label__optional">(optional)</span>
                        </label>
                        <input
                            type="text"
                            id="announce-place"
                            name="place"
                            class="form-control lml-form-control"
                            data-announce-place
                            placeholder="e.g. Barangay Health Center"
                            maxlength="120"
                            value="{{ old('place', $formValues['place'] ?? '') }}"
                            autocomplete="off"
                        >
                    </div>

                    <fieldset class="lml-announce__audience">
                        <legend class="lml-announce__legend">Who needs to see this?</legend>
                        <p class="lml-form-help lml-announce__audience-help">
                            Choose a target group and zone coverage. These work together.
                        </p>

                        <div class="lml-announce__targeting-block">
                            <p class="lml-announce__section-label" id="announce-target-group-label">
                                Target Group
                            </p>
                            <div
                                class="lml-announce__audience-types"
                                role="radiogroup"
                                aria-labelledby="announce-target-group-label"
                            >
                                <label class="lml-announce__type-card">
                                    <input
                                        type="radio"
                                        name="audience_type"
                                        value="all"
                                        data-announce-audience-type
                                        @checked($audienceType === 'all')
                                    >
                                    <span class="lml-announce__type-card-body">
                                        <span class="lml-announce__type-card-title">All Residents</span>
                                        <span class="lml-announce__type-card-sub">Everyone in the barangay</span>
                                    </span>
                                </label>

                                <label class="lml-announce__type-card">
                                    <input
                                        type="radio"
                                        name="audience_type"
                                        value="age"
                                        data-announce-audience-type
                                        @checked($audienceType === 'age')
                                    >
                                    <span class="lml-announce__type-card-body">
                                        <span class="lml-announce__type-card-title">Specific Age Group</span>
                                        <span class="lml-announce__type-card-sub">Target residents by age</span>
                                    </span>
                                </label>

                                <label class="lml-announce__type-card">
                                    <input
                                        type="radio"
                                        name="audience_type"
                                        value="active_maternal"
                                        data-announce-audience-type
                                        @checked($audienceType === 'active_maternal')
                                    >
                                    <span class="lml-announce__type-card-body">
                                        <span class="lml-announce__type-card-title">Active Maternal</span>
                                        <span class="lml-announce__type-card-sub">Target active maternal clients</span>
                                    </span>
                                </label>

                                <label class="lml-announce__type-card">
                                    <input
                                        type="radio"
                                        name="audience_type"
                                        value="active_fp_user"
                                        data-announce-audience-type
                                        @checked($audienceType === 'active_fp_user')
                                    >
                                    <span class="lml-announce__type-card-body">
                                        <span class="lml-announce__type-card-title">Active FP User</span>
                                        <span class="lml-announce__type-card-sub">Target active family planning users</span>
                                    </span>
                                </label>
                            </div>

                            <div
                                class="lml-announce__audience-panel"
                                data-announce-panel="age"
                                hidden
                            >
                                <p class="lml-announce__panel-label" id="announce-age-chips-label">
                                    Select age groups
                                </p>
                                <div
                                    class="lml-announce__chips"
                                    role="group"
                                    aria-labelledby="announce-age-chips-label"
                                >
                                    @foreach ([
                                        'infants_0_6' => 'Infants 0–6 months',
                                        'infants_7_11' => 'Infants 7–11 months',
                                        'young_children' => 'Young Children 1–5 years',
                                        'school_age' => 'School Age 6–12 years',
                                        'teens' => 'Teens 13–17 years',
                                        'adults' => 'Adults 18–59 years',
                                        'seniors' => 'Senior Citizens 60+ years',
                                    ] as $value => $label)
                                        <label class="lml-announce__chip">
                                            <input
                                                type="checkbox"
                                                name="age_groups[]"
                                                value="{{ $value }}"
                                                data-announce-chip="age"
                                                data-announce-label="{{ $label }}"
                                                @checked(in_array($value, $ageGroups, true))
                                            >
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>

                                <div class="lml-announce__custom-age">
                                    <p class="lml-announce__panel-label" id="announce-custom-age-label">
                                        Custom Age Range
                                    </p>
                                    <div
                                        class="lml-announce__custom-age-row"
                                        role="group"
                                        aria-labelledby="announce-custom-age-label"
                                    >
                                        <div class="lml-announce__custom-age-side">
                                            <label class="lml-form-label" for="announce-age-from">From</label>
                                            <div class="lml-announce__custom-age-controls">
                                                <input
                                                    type="number"
                                                    id="announce-age-from"
                                                    name="age_from"
                                                    class="form-control lml-form-control"
                                                    data-announce-age-from
                                                    min="0"
                                                    max="1200"
                                                    inputmode="numeric"
                                                    placeholder="e.g. 0"
                                                    value="{{ old('age_from', $formValues['age_from'] ?? '') }}"
                                                    aria-describedby="announce-custom-age-error"
                                                >
                                                <select
                                                    id="announce-age-from-unit"
                                                    name="age_from_unit"
                                                    class="form-select lml-form-control"
                                                    data-announce-age-from-unit
                                                    aria-label="From age unit"
                                                >
                                                    <option value="months" @selected(old('age_from_unit', $formValues['age_from_unit'] ?? 'months') === 'months')>Months</option>
                                                    <option value="years" @selected(old('age_from_unit', $formValues['age_from_unit'] ?? 'months') === 'years')>Years</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="lml-announce__custom-age-side">
                                            <label class="lml-form-label" for="announce-age-to">To</label>
                                            <div class="lml-announce__custom-age-controls">
                                                <input
                                                    type="number"
                                                    id="announce-age-to"
                                                    name="age_to"
                                                    class="form-control lml-form-control"
                                                    data-announce-age-to
                                                    min="0"
                                                    max="1200"
                                                    inputmode="numeric"
                                                    placeholder="e.g. 6"
                                                    value="{{ old('age_to', $formValues['age_to'] ?? '') }}"
                                                    aria-describedby="announce-custom-age-error"
                                                >
                                                <select
                                                    id="announce-age-to-unit"
                                                    name="age_to_unit"
                                                    class="form-select lml-form-control"
                                                    data-announce-age-to-unit
                                                    aria-label="To age unit"
                                                >
                                                    <option value="months" @selected(old('age_to_unit', $formValues['age_to_unit'] ?? 'months') === 'months')>Months</option>
                                                    <option value="years" @selected(old('age_to_unit', $formValues['age_to_unit'] ?? 'months') === 'years')>Years</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <p
                                        id="announce-custom-age-error"
                                        class="lml-announce__error"
                                        data-announce-error="custom-age"
                                        hidden
                                    ></p>
                                </div>
                            </div>

                            <p
                                class="lml-announce__error"
                                data-announce-error="audience"
                                hidden
                            ></p>
                        </div>

                        <div class="lml-announce__targeting-block">
                            <p class="lml-announce__section-label" id="announce-zone-coverage-label">
                                Zone Coverage
                            </p>
                            <div
                                class="lml-announce__audience-types lml-announce__audience-types--two"
                                role="radiogroup"
                                aria-labelledby="announce-zone-coverage-label"
                            >
                                <label class="lml-announce__type-card">
                                    <input
                                        type="radio"
                                        name="zone_coverage"
                                        value="all"
                                        data-announce-zone-coverage
                                        @checked($zoneCoverage === 'all')
                                    >
                                    <span class="lml-announce__type-card-body">
                                        <span class="lml-announce__type-card-title">All Zones</span>
                                        <span class="lml-announce__type-card-sub">Include every zone</span>
                                    </span>
                                </label>

                                <label class="lml-announce__type-card">
                                    <input
                                        type="radio"
                                        name="zone_coverage"
                                        value="specific"
                                        data-announce-zone-coverage
                                        @checked($zoneCoverage === 'specific')
                                    >
                                    <span class="lml-announce__type-card-body">
                                        <span class="lml-announce__type-card-title">Specific Zones</span>
                                        <span class="lml-announce__type-card-sub">Limit to selected zones</span>
                                    </span>
                                </label>
                            </div>

                            <div
                                class="lml-announce__audience-panel"
                                data-announce-zone-panel
                                hidden
                            >
                                <p class="lml-announce__panel-label" id="announce-zone-chips-label">
                                    Select zones
                                </p>
                                <div
                                    class="lml-announce__chips"
                                    role="group"
                                    aria-labelledby="announce-zone-chips-label"
                                >
                                    @foreach ([1, 2, 3, 4, 5] as $zone)
                                        <label class="lml-announce__chip">
                                            <input
                                                type="checkbox"
                                                name="zones[]"
                                                value="{{ $zone }}"
                                                data-announce-chip="zone"
                                                data-announce-label="Zone {{ $zone }}"
                                                @checked(in_array((string) $zone, $selectedZones, true))
                                            >
                                            <span>Zone {{ $zone }}</span>
                                        </label>
                                    @endforeach
                                </div>

                                <div class="lml-announce__custom-zone" data-announce-custom-zone>
                                    <button
                                        type="button"
                                        class="lml-announce__custom-zone-toggle lml-focus-ring"
                                        data-announce-custom-zone-toggle
                                        aria-expanded="false"
                                        aria-controls="announce-custom-zone-form"
                                    >
                                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                        <span>Add Custom Zone</span>
                                    </button>

                                    <div
                                        id="announce-custom-zone-form"
                                        class="lml-announce__custom-zone-form"
                                        data-announce-custom-zone-form
                                        hidden
                                    >
                                        <label class="lml-form-label" for="announce-custom-zone-input">
                                            Custom Zone / Purok
                                        </label>
                                        <div class="lml-announce__custom-zone-controls">
                                            <input
                                                type="text"
                                                id="announce-custom-zone-input"
                                                class="form-control lml-form-control"
                                                data-announce-custom-zone-input
                                                placeholder="e.g. Purok 5"
                                                maxlength="80"
                                                autocomplete="off"
                                            >
                                            <button
                                                type="button"
                                                class="lml-announce__btn lml-announce__btn--secondary lml-focus-ring"
                                                data-announce-custom-zone-add
                                            >
                                                Add
                                            </button>
                                        </div>
                                        <p
                                            class="lml-announce__error"
                                            data-announce-error="custom-zone"
                                            hidden
                                        ></p>
                                    </div>

                                    <ul
                                        class="lml-announce__custom-zone-list"
                                        data-announce-custom-zone-list
                                        aria-label="Custom zones"
                                    ></ul>
                                </div>
                            </div>

                            <p
                                class="lml-announce__error"
                                data-announce-error="zones"
                                hidden
                            ></p>
                        </div>

                        <p class="lml-announce__reach" data-announce-reach aria-live="polite">
                            Estimated reach:
                            <strong data-announce-reach-count>—</strong>
                            <span data-announce-reach-unit>residents</span>
                        </p>
                    </fieldset>

                    <div class="lml-announce__actions">
                        <a
                            href="{{ route('announcements.index') }}"
                            class="lml-announce__btn lml-announce__btn--secondary lml-focus-ring"
                        >
                            Cancel
                        </a>
                        <button
                            type="submit"
                            class="lml-announce__btn lml-announce__btn--primary lml-focus-ring"
                            data-announce-submit="sent"
                        >
                            {{ $isEdit ? 'Save Changes' : 'Post Announcement' }}
                        </button>
                    </div>

                    <p
                        class="lml-announce__status"
                        data-announce-status
                        role="status"
                        aria-live="polite"
                        hidden
                    ></p>
                </form>
            </section>

            <aside
                class="lml-announce__preview-wrap"
                aria-labelledby="lml-announce-preview-heading"
            >
                <div class="lml-announce__preview-card lml-surface lml-surface--elevated">
                    <h2 id="lml-announce-preview-heading" class="lml-announce__card-title">
                        Announcement Preview
                    </h2>

                    <article class="lml-announce-post" data-announce-post aria-live="polite">
                        <header class="lml-announce-post__header">
                            <span class="lml-announce-post__avatar" aria-hidden="true">
                                <i class="bi bi-hospital"></i>
                            </span>
                            <div class="lml-announce-post__identity">
                                <p class="lml-announce-post__name">Barangay Health Center</p>
                                <p class="lml-announce-post__meta">
                                    LMLinga Official Notice ·
                                    <span data-announce-preview-stamp>Just now</span>
                                </p>
                            </div>
                        </header>

                        <p class="lml-announce-post__kicker">Health Announcement</p>

                        <h3 class="lml-announce-post__title" data-announce-preview-title>
                            Your announcement title
                        </h3>
                        <p class="lml-announce-post__message" data-announce-preview-message>
                            Your announcement message will appear here.
                        </p>

                        <ul class="lml-announce-post__details">
                            <li data-announce-preview-date-row>
                                <i class="bi bi-calendar-event" aria-hidden="true"></i>
                                <span data-announce-preview-date>Select a date</span>
                            </li>
                            <li data-announce-preview-time-row hidden>
                                <i class="bi bi-clock" aria-hidden="true"></i>
                                <span data-announce-preview-time></span>
                            </li>
                            <li data-announce-preview-place-row hidden>
                                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                <span data-announce-preview-place></span>
                            </li>
                        </ul>

                        <div class="lml-announce-post__targeting">
                            <p class="lml-announce-post__audience">
                                <span class="lml-announce-post__badge" data-announce-preview-audience>
                                    Audience: All Residents
                                </span>
                            </p>
                            <p class="lml-announce-post__audience">
                                <span class="lml-announce-post__badge lml-announce-post__badge--coverage" data-announce-preview-coverage>
                                    Coverage: All Zones
                                </span>
                            </p>
                        </div>
                    </article>
                </div>
            </aside>
        </div>
    </div>
@endsection
