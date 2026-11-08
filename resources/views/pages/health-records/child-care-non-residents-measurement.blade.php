{{-- Health Records → Child Care → Non-Residents measurement form (UI-phase preview). --}}
@extends('layouts.dashboard')

@section('title', ($pageTitle ?? 'Measurement') . ' — Child Care - LMLinga')

@section('content')
    @php
        $mode = $mode ?? 'create';
        $isEdit = $mode === 'edit';
        $measurement = $measurement ?? [];
        $statusOptions = $statusOptions ?? [];
        $historyUrl = isset($child['key'])
            ? route('health-records.child-care.non-residents.nutrition', ['childKey' => $child['key']])
            : route('health-records.child-care.non-residents.index');
        $formTitle = $isEdit ? 'Edit Measurement for Child' : 'Add Measurement for Child';
    @endphp

    <div class="lml-hr-cc-nr" data-lml-hr-cc-nr data-lml-hr-cc-nr-mode="measurement">
        <div class="lml-hr-cc-nr__toast" data-hr-cc-nr-toast role="status" aria-live="polite" hidden></div>

        <a href="{{ $historyUrl }}" class="lml-hr-cc-nr__page-back lml-focus-ring" aria-label="Back to nutritional status">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </a>

        @include('pages.health-records.partials.child-care-non-residents-profile', ['child' => $child])

        @if ($child)
            <section class="lml-hr-cc-nr__form-panel lml-hr-cc-nr__form-panel--measure" aria-labelledby="lml-hr-cc-nr-measure-title">
                <div class="lml-hr-cc-nr__measure-head">
                    <h2 class="lml-hr-cc-nr__measure-title" id="lml-hr-cc-nr-measure-title">
                        <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                        {{ $formTitle }}
                    </h2>
                    <p class="lml-hr-cc-nr__form-lead">Track the growth of the child</p>
                </div>

                <form
                    class="lml-hr-cc-nr__form"
                    data-hr-cc-nr-measure-form
                    action="#"
                    method="post"
                    novalidate
                    data-hr-cc-nr-return="{{ $historyUrl }}"
                    data-hr-cc-nr-preview-save="Preview only: this measurement was not saved to the database."
                >
                    @csrf

                    <fieldset class="lml-hr-cc-nr__fieldset">
                        <legend class="lml-hr-cc-nr__section-title">MEASUREMENT</legend>

                        <div class="lml-hr-cc-nr__field-grid lml-hr-cc-nr__field-grid--4">
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-m-date">Date</label>
                                <input id="lml-hr-cc-nr-m-date" name="date" type="date" class="lml-hr-cc-nr__input lml-focus-ring" value="{{ $measurement['date'] ?? '' }}">
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-m-weight">Weight (kg)</label>
                                <input id="lml-hr-cc-nr-m-weight" name="weight_kg" type="number" inputmode="decimal" step="0.1" min="0" class="lml-hr-cc-nr__input lml-focus-ring" value="{{ $measurement['weight_kg'] ?? '' }}">
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-m-height">Height (cm)</label>
                                <input id="lml-hr-cc-nr-m-height" name="height_cm" type="number" inputmode="decimal" step="0.1" min="0" class="lml-hr-cc-nr__input lml-focus-ring" value="{{ $measurement['height_cm'] ?? '' }}">
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-m-muac">MUAC (cm)</label>
                                <input id="lml-hr-cc-nr-m-muac" name="muac_cm" type="number" inputmode="decimal" step="0.1" min="0" class="lml-hr-cc-nr__input lml-focus-ring" value="{{ $measurement['muac_cm'] ?? '' }}">
                            </div>
                        </div>

                        <div class="lml-hr-cc-nr__field-grid lml-hr-cc-nr__field-grid--3">
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-m-wfa">Weight-for-age</label>
                                <input id="lml-hr-cc-nr-m-wfa" name="weight_for_age" type="text" class="lml-hr-cc-nr__input lml-focus-ring" value="{{ $measurement['weight_for_age'] ?? '' }}">
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-m-hfa">Height-for-age</label>
                                <input id="lml-hr-cc-nr-m-hfa" name="height_for_age" type="text" class="lml-hr-cc-nr__input lml-focus-ring" value="{{ $measurement['height_for_age'] ?? '' }}">
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-m-status">Nutrition Status</label>
                                <select id="lml-hr-cc-nr-m-status" name="status" class="lml-hr-cc-nr__input lml-focus-ring">
                                    <option value="">Select</option>
                                    @foreach ($statusOptions as $option)
                                        <option value="{{ $option }}" @selected(($measurement['status'] ?? '') === $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hr-cc-nr-m-remarks">Remarks</label>
                            <textarea id="lml-hr-cc-nr-m-remarks" name="remarks" class="lml-hr-cc-nr__input lml-hr-cc-nr__textarea lml-focus-ring" rows="3">{{ $measurement['remarks'] ?? '' }}</textarea>
                        </div>
                    </fieldset>

                    <div class="lml-hr-cc-nr__form-actions">
                        <a href="{{ $historyUrl }}" class="lml-hr-cc-nr__cancel-btn lml-focus-ring" data-hr-cc-nr-cancel>Cancel</a>
                        <button type="submit" class="lml-hr-cc-nr__save-btn lml-focus-ring" data-hr-cc-nr-save>Save</button>
                    </div>
                </form>
            </section>
        @endif
    </div>
@endsection
