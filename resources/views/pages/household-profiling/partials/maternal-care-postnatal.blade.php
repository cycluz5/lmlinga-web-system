@php
    use App\Support\DemoMaternalCare;

    $canPersist = (bool) ($canPersist ?? false);
    $readOnly = (bool) ($readOnly ?? false);
    $updateUrl = route('household-profiling.members.maternal-care.update', $routeParams + [
        'section' => 'postnatal',
    ]);
    $post = is_array($pregnancy['postnatal'] ?? null) ? $pregnancy['postnatal'] : [];
    $contacts = is_array($post['contacts'] ?? null) ? $post['contacts'] : [];
    $supp = is_array($post['supplementation'] ?? null) ? $post['supplementation'] : [];
    $contactCount = DemoMaternalCare::postnatalContactCount($pregnancy);
    $suppCount = DemoMaternalCare::postpartumSuppCount($pregnancy);
@endphp

<section class="lml-mc__panel" aria-labelledby="lml-mc-postnatal-title" data-mc-postnatal>
    <header class="lml-mc__panel-head">
        <div class="lml-mc__panel-titles">
            <h2 id="lml-mc-postnatal-title" class="lml-mc__panel-title">Postnatal Care</h2>
            <p class="lml-mc__panel-subtitle">
                Promoting the health and well-being of mothers and newborns after childbirth.
            </p>
        </div>
        <div class="lml-mc__panel-controls">
            @unless ($readOnly)
            <button
                type="button"
                class="lml-mc__btn lml-mc__btn--primary lml-focus-ring"
                data-mc-edit
                data-mc-edit-for="postnatal"
            >
                <i class="bi bi-pencil" aria-hidden="true"></i>
                <span>Edit</span>
            </button>
            <button
                type="submit"
                form="lml-mc-postnatal-form"
                class="lml-mc__btn lml-mc__btn--primary lml-focus-ring"
                data-mc-save
                data-mc-save-for="postnatal"
                hidden
            >
                Save
            </button>
            @endunless
        </div>
    </header>

    <form
        id="lml-mc-postnatal-form"
        method="post"
        action="{{ $updateUrl }}"
        class="lml-mc__form"
        data-mc-section-form="postnatal"
        data-editing="false"
        @if ($readOnly) data-mc-readonly="true" onsubmit="return false;" @endif
        novalidate
        @include('offline.maternal-section-attrs', ['section' => 'postnatal'])
    >
        @csrf
        @method('PUT')

        <div class="lml-mc__postnatal-grid">
            <section class="lml-mc__subpanel" aria-labelledby="lml-mc-pn-contacts-title">
                <div class="lml-mc__subpanel-head">
                    <h3 id="lml-mc-pn-contacts-title" class="lml-mc__section-title">
                        Postnatal Care Visits
                    </h3>
                    <p class="lml-mc__count" data-mc-postnatal-contact-count aria-live="polite">
                        {{ $contactCount }} of 4 Contact
                    </p>
                </div>
                <ul class="lml-mc__contact-list">
                    @foreach (DemoMaternalCare::postnatalContacts() as $contact)
                        @php
                            $date = (string) ($contacts[$contact['key']] ?? '');
                            $complete = $date !== '';
                        @endphp
                        <li class="lml-mc__contact-row" data-mc-contact="{{ $contact['key'] }}">
                            <span
                                class="lml-mc__dose-status{{ $complete ? ' is-complete' : '' }}"
                                aria-hidden="true"
                            >
                                @if ($complete)
                                    <i class="bi bi-check-lg"></i>
                                @endif
                            </span>
                            <div class="lml-mc__contact-copy">
                                <p class="lml-mc__contact-label">
                                    {{ $contact['label'] }}
                                    <span class="visually-hidden">
                                        {{ $complete ? 'recorded' : 'not recorded' }}
                                    </span>
                                </p>
                                <p class="lml-mc__contact-hint">{{ $contact['hint'] }}</p>
                            </div>
                            <div class="lml-mc__field lml-mc__field--grow">
                                <label class="visually-hidden" for="mc-pn-{{ $contact['key'] }}">
                                    {{ $contact['label'] }} Date — {{ $contact['hint'] }}
                                </label>
                                <input
                                    type="date"
                                    id="mc-pn-{{ $contact['key'] }}"
                                    name="contacts[{{ $contact['key'] }}]"
                                    class="lml-mc__input lml-focus-ring"
                                    value="{{ $date }}"
                                    data-mc-field
                                    max="{{ now()->toDateString() }}"
                                    disabled
                                >
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="lml-mc__subpanel" aria-labelledby="lml-mc-pn-supp-title">
                <div class="lml-mc__subpanel-head">
                    <h3 id="lml-mc-pn-supp-title" class="lml-mc__section-title">
                        Postpartum Supplementation
                    </h3>
                    <p class="lml-mc__count" data-mc-postpartum-supp-count aria-live="polite">
                        {{ $suppCount }} of 3 Visits
                    </p>
                </div>
                <p class="lml-mc__panel-subtitle">Iron with Folic Acid</p>
                <ul class="lml-mc__supp-visit-list">
                    @foreach (DemoMaternalCare::postpartumSupplementationVisits() as $visit)
                        @php
                            $row = is_array($supp[$visit['key']] ?? null) ? $supp[$visit['key']] : [];
                        @endphp
                        <li class="lml-mc__supp-visit" data-mc-pp-supp="{{ $visit['key'] }}">
                            <p class="lml-mc__supp-visit-label">{{ $visit['label'] }}</p>
                            <div class="lml-mc__grid lml-mc__grid--2">
                                <div class="lml-mc__field">
                                    <label class="lml-mc__label" for="mc-pp-{{ $visit['key'] }}-date">Date</label>
                                    <input
                                        type="date"
                                        id="mc-pp-{{ $visit['key'] }}-date"
                                        name="supplementation[{{ $visit['key'] }}][date]"
                                        class="lml-mc__input lml-focus-ring"
                                        value="{{ $row['date'] ?? '' }}"
                                        data-mc-field
                                        max="{{ now()->toDateString() }}"
                                        disabled
                                    >
                                </div>
                                <div class="lml-mc__field">
                                    <label class="lml-mc__label" for="mc-pp-{{ $visit['key'] }}-tablets">
                                        No. of Tablets
                                    </label>
                                    <input
                                        type="number"
                                        id="mc-pp-{{ $visit['key'] }}-tablets"
                                        name="supplementation[{{ $visit['key'] }}][tablets]"
                                        min="0"
                                        step="1"
                                        inputmode="numeric"
                                        class="lml-mc__input lml-focus-ring"
                                        value="{{ $row['tablets'] ?? '' }}"
                                        data-mc-field
                                        disabled
                                    >
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
                @php
                    $vitaminA = is_array($post['vitamin_a'] ?? null) ? $post['vitamin_a'] : [];
                @endphp
                <div class="lml-mc__field">
                    <label class="lml-mc__label" for="mc-pp-vitamin-a-date">Vitamin A</label>
                    <input
                        type="date"
                        id="mc-pp-vitamin-a-date"
                        name="vitamin_a[date]"
                        class="lml-mc__input lml-focus-ring"
                        value="{{ $vitaminA['date'] ?? '' }}"
                        data-mc-field
                        max="{{ now()->toDateString() }}"
                        disabled
                    >
                </div>
            </section>
        </div>
    </form>
</section>
