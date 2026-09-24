{{--
    Health Records → Child Care → Deworming → Add Record (UI-phase).
    GET form only — Save uses preview toast until a persistence route is approved.
--}}
@extends('layouts.dashboard')

@section('title', 'Child Care | Deworming - LMLinga')

@section('content')
    @php
        $summaryUrl = route('health-records.child-care.deworming');
        $showUrl = isset($child['view_url']) ? $child['view_url'] : $summaryUrl;
        $roundOptions = $roundOptions ?? [];
        $seStatusOptions = $seStatusOptions ?? [];
    @endphp

    <div
        class="lml-hr-cc-nr lml-hr-dw-record"
        data-lml-hr-dw-record
        data-lml-hr-dw-mode="create"
        @if (isset($householdNo, $memberId))
            data-household-no="{{ $householdNo }}"
            data-member-id="{{ $memberId }}"
        @endif
    >
        <div class="lml-hr-dw-record__toast" data-hr-dw-record-toast role="status" aria-live="polite" hidden></div>

        <a
            href="{{ $showUrl }}"
            class="lml-hr-cc-nr__page-back lml-focus-ring"
            aria-label="Back to Deworming record"
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </a>

        @include('pages.health-records.partials.child-care-deworming-profile', ['child' => $child])

        @if ($child)
            <section class="lml-hr-cc-nr__form-panel lml-hr-cc-nr__form-panel--measure" aria-labelledby="lml-hr-dw-add-title">
                <div class="lml-hr-cc-nr__measure-head">
                    <h2 class="lml-hr-cc-nr__measure-title" id="lml-hr-dw-add-title">
                        <i class="bi bi-journal-medical" aria-hidden="true"></i>
                        <span>Add Deworming Record</span>
                    </h2>
                </div>

                <form
                    class="lml-hr-cc-nr__form"
                    data-hr-dw-deworming-form
                    action="#"
                    method="post"
                    novalidate
                    data-hr-dw-return="{{ $showUrl }}"
                    data-hr-dw-preview-save="Deworming record preview saved for this UI phase. Persistence is not yet implemented."
                    data-persistence="preview"
                >
                    @csrf

                    <fieldset class="lml-hr-cc-nr__fieldset">
                        <legend class="lml-hr-cc-nr__section-title">ROUND INFORMATION</legend>

                        <div class="lml-hr-cc-nr__field-grid lml-hr-cc-nr__field-grid--3">
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-dw-year">Year</label>
                                <input
                                    id="lml-hr-dw-year"
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
                                <label for="lml-hr-dw-round">Deworming Round</label>
                                <select id="lml-hr-dw-round" name="round" class="lml-hr-cc-nr__input lml-focus-ring">
                                    <option value="">Select</option>
                                    @foreach ($roundOptions as $option)
                                        <option value="{{ $option }}">{{ $option }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-dw-se">SE Status</label>
                                <select id="lml-hr-dw-se" name="se_status" class="lml-hr-cc-nr__input lml-focus-ring">
                                    <option value="">Select</option>
                                    @foreach ($seStatusOptions as $option)
                                        <option value="{{ $option }}">{{ $option }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="lml-hr-cc-nr__field-grid lml-hr-cc-nr__field-grid--3">
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-dw-date">Date Given</label>
                                <input
                                    id="lml-hr-dw-date"
                                    name="date_given"
                                    type="date"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                >
                            </div>
                        </div>

                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hr-dw-remarks">Remarks</label>
                            <textarea
                                id="lml-hr-dw-remarks"
                                name="remarks"
                                class="lml-hr-cc-nr__input lml-hr-cc-nr__textarea lml-focus-ring"
                                rows="3"
                            ></textarea>
                        </div>
                    </fieldset>

                    <div class="lml-hr-cc-nr__form-actions">
                        <a href="{{ $showUrl }}" class="lml-hr-cc-nr__cancel-btn lml-focus-ring" data-hr-dw-cancel>Cancel</a>
                        <button type="submit" class="lml-hr-cc-nr__save-btn lml-focus-ring" data-hr-dw-save>Save</button>
                    </div>
                </form>
            </section>
        @endif
    </div>
@endsection
