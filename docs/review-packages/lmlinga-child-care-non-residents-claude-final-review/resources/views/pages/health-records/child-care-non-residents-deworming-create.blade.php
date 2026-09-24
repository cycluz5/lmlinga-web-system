{{-- Health Records → Child Care → Non-Residents Add Deworming Record (UI-phase). --}}
@extends('layouts.dashboard')

@section('title', 'Add Deworming Record — Child Care - LMLinga')

@section('content')
    @php
        $listingUrl = route('health-records.child-care.non-residents.index');
        $indexUrl = isset($child['key'])
            ? route('health-records.child-care.non-residents.deworming', ['childKey' => $child['key']])
            : $listingUrl;
        $showUrl = isset($child['key'])
            ? route('health-records.child-care.non-residents.show', ['childKey' => $child['key']])
            : $listingUrl;
        $roundOptions = $roundOptions ?? [];
        $seStatusOptions = $seStatusOptions ?? [];
    @endphp

    <div class="lml-hr-cc-nr" data-lml-hr-cc-nr data-lml-hr-cc-nr-mode="deworming-create">
        <div class="lml-hr-cc-nr__toast" data-hr-cc-nr-toast role="status" aria-live="polite" hidden></div>

        <a href="{{ $showUrl }}" class="lml-hr-cc-nr__page-back lml-focus-ring" aria-label="Back to child record">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </a>

        @include('pages.health-records.partials.child-care-non-residents-profile', ['child' => $child])

        @if ($child)
            <section class="lml-hr-cc-nr__form-panel lml-hr-cc-nr__form-panel--measure" aria-labelledby="lml-hr-cc-nr-dw-title">
                <div class="lml-hr-cc-nr__measure-head">
                    <h2 class="lml-hr-cc-nr__measure-title" id="lml-hr-cc-nr-dw-title">Add Deworming Record</h2>
                </div>

                <form
                    class="lml-hr-cc-nr__form"
                    data-hr-cc-nr-deworming-form
                    action="#"
                    method="post"
                    novalidate
                    data-hr-cc-nr-return="{{ $indexUrl }}"
                    data-hr-cc-nr-preview-save="Deworming record preview saved for this UI phase."
                >
                    @csrf

                    <fieldset class="lml-hr-cc-nr__fieldset">
                        <legend class="lml-hr-cc-nr__section-title">ROUND INFORMATION</legend>

                        <div class="lml-hr-cc-nr__field-grid lml-hr-cc-nr__field-grid--3">
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-dw-year">Year</label>
                                <input
                                    id="lml-hr-cc-nr-dw-year"
                                    name="year"
                                    type="number"
                                    inputmode="numeric"
                                    min="2000"
                                    max="2100"
                                    step="1"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                >
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-dw-round">Deworming Round</label>
                                <select id="lml-hr-cc-nr-dw-round" name="round" class="lml-hr-cc-nr__input lml-focus-ring">
                                    <option value="">Select</option>
                                    @foreach ($roundOptions as $option)
                                        <option value="{{ $option }}">{{ $option }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-dw-se">SE Status</label>
                                <select id="lml-hr-cc-nr-dw-se" name="se_status" class="lml-hr-cc-nr__input lml-focus-ring">
                                    <option value="">Select</option>
                                    @foreach ($seStatusOptions as $option)
                                        <option value="{{ $option }}">{{ $option }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="lml-hr-cc-nr__field-grid lml-hr-cc-nr__field-grid--3">
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-dw-date">Date Given</label>
                                <input
                                    id="lml-hr-cc-nr-dw-date"
                                    name="date_given"
                                    type="date"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                >
                            </div>
                        </div>

                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hr-cc-nr-dw-remarks">Remarks</label>
                            <textarea
                                id="lml-hr-cc-nr-dw-remarks"
                                name="remarks"
                                class="lml-hr-cc-nr__input lml-hr-cc-nr__textarea lml-focus-ring"
                                rows="3"
                            ></textarea>
                        </div>
                    </fieldset>

                    <div class="lml-hr-cc-nr__form-actions">
                        <a href="{{ $indexUrl }}" class="lml-hr-cc-nr__cancel-btn lml-focus-ring" data-hr-cc-nr-cancel>Cancel</a>
                        <button type="submit" class="lml-hr-cc-nr__save-btn lml-focus-ring" data-hr-cc-nr-save>Save</button>
                    </div>
                </form>
            </section>
        @endif
    </div>
@endsection
