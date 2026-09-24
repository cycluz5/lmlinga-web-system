{{--
    Health Records → Family Planning → Add / Edit visit (non-resident client).
    UI preview — Save and Delete Visit do not persist.
--}}
@extends('layouts.dashboard')

@section('title', ($mode ?? 'create') === 'edit' ? 'Edit Visit - LMLinga' : 'Add Visit - LMLinga')

@section('content')
    @php
        $mode = $mode ?? 'create';
        $isEdit = $mode === 'edit';
        $client = $client ?? null;
        $visit = $visit ?? [];
        $listingUrl = route('health-records.family-planning.non-residents.index');
        $showUrl = $client !== null
            ? route('health-records.family-planning.non-residents.show', ['clientKey' => $client['key']])
            : $listingUrl;
        $methodOptions = $methodOptions ?? [];
        $commodityOptions = $commodityOptions ?? [];
        $visitCommodities = is_array($visit['commodities'] ?? null) && count($visit['commodities']) > 0
            ? $visit['commodities']
            : [['name' => '', 'quantity' => '']];
        $heading = $isEdit ? 'EDIT VISIT' : 'ADD RECORD';
        $visitedAtValue = (string) ($visit['visited_at'] ?? '');
        $remarksValue = (string) ($visit['remarks'] ?? '');
        $idPrefix = $isEdit ? 'lml-hr-fp-nr-edit' : 'lml-hr-fp-nr-add';
    @endphp

    <div
        class="lml-hr-fp-nr"
        data-lml-hr-fp-nr
        data-lml-hr-fp-nr-mode="{{ $isEdit ? 'edit-visit' : 'add-visit' }}"
        @if ($isEdit && ! empty($visit['id'])) data-visit-id="{{ $visit['id'] }}" @endif
    >
        <div
            class="lml-hr-fp-nr__toast"
            data-hr-fp-nr-toast
            role="status"
            aria-live="polite"
            hidden
        ></div>

        @if ($client === null)
            <section class="lml-hr-fp-nr__not-found" aria-labelledby="lml-hr-fp-nr-nf-title">
                <h2 id="lml-hr-fp-nr-nf-title">Client not found</h2>
                <p>No demo non-resident client matches this record.</p>
                <a href="{{ $listingUrl }}" class="lml-hr-fp-nr__not-found-link lml-focus-ring">
                    Back to Non Residents listing
                </a>
            </section>
        @elseif ($isEdit && empty($visit))
            <section class="lml-hr-fp-nr__not-found" aria-labelledby="lml-hr-fp-nr-nf-title">
                <h2 id="lml-hr-fp-nr-nf-title">Visit not found</h2>
                <p>No demo visit matches this record for the selected client.</p>
                <a href="{{ $showUrl }}" class="lml-hr-fp-nr__not-found-link lml-focus-ring">
                    Back to client record
                </a>
            </section>
        @else
            @include('pages.health-records.non-resident-family-planning.partials.workflow-nav', [
                'backUrl' => $showUrl,
                'backLabel' => 'Back to client record for '.($client['full_name'] ?? 'client'),
            ])

            @include('pages.health-records.non-resident-family-planning.partials.client-identity-banner', [
                'client' => $client,
                'clientKey' => $client['key'],
                'backUrl' => $showUrl,
                'backLabel' => 'Back to client record for '.($client['full_name'] ?? 'client'),
                'hideBannerBack' => true,
                'showAddVisit' => false,
                'clientIconClass' => 'bi-person-vcard',
            ])

            <section class="lml-hr-fp-nr__form-panel lml-hr-fp-nr__form-panel--visit" aria-labelledby="lml-hr-fp-nr-visit-form-title">
                <h3 id="lml-hr-fp-nr-visit-form-title" class="lml-hr-fp-nr__visit-form-heading">
                    @if ($isEdit)
                        <i class="bi bi-pencil-square" aria-hidden="true"></i>
                    @else
                        <i class="bi bi-plus-circle" aria-hidden="true"></i>
                    @endif
                    <span>{{ $heading }}</span>
                </h3>

                <form
                    id="lml-hr-fp-nr-visit-form"
                    class="lml-hr-fp-nr__form"
                    data-hr-fp-nr-visit-form
                    action="#"
                    method="post"
                    novalidate
                >
                    @csrf

                    <div class="lml-hr-fp-nr__form-split lml-hr-fp-nr__form-split--visit">
                        <div
                            class="lml-hr-fp-nr__form-split-col lml-hr-fp-nr__section-box"
                            role="group"
                            aria-labelledby="{{ $idPrefix }}-visit-info-heading"
                        >
                            <h4 id="{{ $idPrefix }}-visit-info-heading" class="lml-hr-fp-nr__subheading">
                                Visit Information
                            </h4>

                            <div class="lml-hr-fp-nr__field">
                                <label for="{{ $idPrefix }}-visit-date">Date</label>
                                <div class="lml-hr-fp-nr__input-with-icon">
                                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                                    <input
                                        id="{{ $idPrefix }}-visit-date"
                                        name="visited_at"
                                        type="date"
                                        class="lml-hr-fp-nr__input lml-focus-ring"
                                        value="{{ $visitedAtValue }}"
                                    >
                                </div>
                            </div>
                            <div class="lml-hr-fp-nr__field">
                                <label for="{{ $idPrefix }}-remarks">Remarks</label>
                                <textarea
                                    id="{{ $idPrefix }}-remarks"
                                    name="remarks"
                                    class="lml-hr-fp-nr__textarea lml-focus-ring"
                                    rows="2"
                                    placeholder="Enter remarks"
                                >{{ $remarksValue }}</textarea>
                            </div>
                        </div>

                        <div
                            class="lml-hr-fp-nr__form-split-col lml-hr-fp-nr__section-box lml-hr-fp-nr__section-box--service"
                            role="group"
                            aria-labelledby="{{ $idPrefix }}-commodities-heading"
                            data-hr-fp-nr-commodities
                        >
                            <h4 id="{{ $idPrefix }}-commodities-heading" class="lml-hr-fp-nr__subheading">
                                Commodities Given
                            </h4>
                            @include('pages.health-records.non-resident-family-planning.partials.commodity-rows', [
                                'commodityOptions' => $commodityOptions,
                                'commodities' => $visitCommodities,
                                'idPrefix' => $idPrefix,
                            ])
                        </div>

                        <div class="lml-hr-fp-nr__form-actions lml-hr-fp-nr__form-actions--centered lml-hr-fp-nr__form-actions--visit-span">
                            <a
                                href="{{ $showUrl }}"
                                class="lml-hr-fp-nr__btn lml-hr-fp-nr__btn--cancel lml-focus-ring"
                            >
                                Cancel
                            </a>
                            <button
                                type="submit"
                                class="lml-hr-fp-nr__btn lml-hr-fp-nr__btn--save lml-focus-ring"
                            >
                                Save
                            </button>
                        </div>
                    </div>
                </form>
            </section>
        @endif
    </div>
@endsection
