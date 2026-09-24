{{--
    Environmental Sanitation & Occupational Health Program
    Step 2 — Validation / Random Sampling / Testing (optional)
--}}
@extends('layouts.dashboard')

@section('title', 'Validation / Random Sampling / Testing - LMLinga')

@php
    $householdNo = $householdNo ?? '';
    $saved = is_array($savedRecord ?? null) ? $savedRecord : [];
    $spotMapUrl = route('spot-mapping.index');
    $step1Url = route('environmental-health.household-water-supply', ['household' => $householdNo]);
    $storeUrl = route('environmental-health.household-water-supply.step2.store', ['householdNo' => $householdNo]);

    $microDate = old('microbiological_test_date', $saved['microbiological_test_date'] ?? '');
    $microResult = old('microbiological_result', $saved['microbiological_result'] ?? '');
    $physicoDate = old('physicochemical_test_date', $saved['physicochemical_test_date'] ?? '');
    $physicoResult = old('physicochemical_result', $saved['physicochemical_result'] ?? '');
@endphp

@section('content')
    <div
        class="lml-hws"
        data-lml-hws
        data-hws-step="2"
        data-household-no="{{ $householdNo }}"
        data-spot-mapping-url="{{ $spotMapUrl }}"
        data-hws-back-url="{{ $step1Url }}"
    >
        <div class="lml-hws__body">
            <x-environmental-health.household-water-header
                :current-step="2"
                :step-labels="['1', '1.2', '2', '3']"
                page-heading="Validation / Random Sampling / Testing"
                page-heading-id="lml-hws-page-title"
                back-aria-label="Back to Step 1"
            />

            @if ($errors->any())
                <div class="lml-hws__server-alert" role="alert">
                    <p class="lml-hws__server-alert-text">
                        {{ $errors->first() }}
                    </p>
                </div>
            @endif

            <form
                class="lml-hws__form"
                data-hws-form
                data-hws-step2-form
                method="post"
                action="{{ $storeUrl }}"
                novalidate
                aria-labelledby="lml-hws-page-title"
                @include('offline.form-attrs', [
                    'offlineOperation' => 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
                    'offlineEhStep' => 2,
                    'offlineHouseholdPk' => $offlineHouseholdPk ?? null,
                    'offlineHouseholdNo' => $householdNo,
                    'offlineFieldHash' => $offlineFieldHash ?? null,
                ])
            >
                @csrf
                <p class="lml-offline-form-pending" data-lml-offline-form-pending hidden>Waiting to sync</p>
                <input type="hidden" name="household_no" value="{{ $householdNo }}" data-hws-household-no>

                <div class="lml-hws__test-grid">
                    <section
                        class="lml-hws__test-panel"
                        aria-labelledby="lml-hws-micro-heading"
                        data-hws-test-section="microbiological"
                    >
                        <h4 id="lml-hws-micro-heading" class="lml-hws__test-heading">
                            <i class="bi bi-virus" aria-hidden="true"></i>
                            <span>Microbiological Validation</span>
                        </h4>

                        <div class="lml-hws__field">
                            <label class="lml-hws__field-label" for="lml-hws-micro-date">Date</label>
                            <div class="lml-hws__date-wrap">
                                <input
                                    id="lml-hws-micro-date"
                                    type="date"
                                    class="form-control lml-form-control lml-hws__date-input @error('microbiological_test_date') is-invalid @enderror"
                                    name="microbiological_test_date"
                                    value="{{ $microDate }}"
                                    max="{{ now()->toDateString() }}"
                                    data-hws-micro-date
                                    aria-describedby="lml-hws-err-micro-date"
                                >
                            </div>
                            <p
                                id="lml-hws-err-micro-date"
                                class="lml-hws__error"
                                role="alert"
                                @if (! $errors->has('microbiological_test_date')) hidden @endif
                                data-hws-error="microbiological_test_date"
                            >{{ $errors->first('microbiological_test_date') }}</p>
                        </div>

                        <fieldset class="lml-hws__result-fieldset">
                            <legend class="lml-hws__field-label" id="lml-hws-micro-result-legend">
                                Micro Biological Result
                            </legend>
                            <div
                                class="lml-hws__radio-row"
                                role="radiogroup"
                                aria-labelledby="lml-hws-micro-result-legend"
                                aria-describedby="lml-hws-err-micro-result"
                            >
                                <label class="lml-hws__choice lml-focus-ring">
                                    <input
                                        type="radio"
                                        name="microbiological_result"
                                        value="passed"
                                        data-hws-micro-result
                                        data-hws-toggle-radio
                                        @checked($microResult === 'passed')
                                    >
                                    <span>Passed</span>
                                </label>
                                <label class="lml-hws__choice lml-focus-ring">
                                    <input
                                        type="radio"
                                        name="microbiological_result"
                                        value="failed"
                                        data-hws-micro-result
                                        data-hws-toggle-radio
                                        @checked($microResult === 'failed')
                                    >
                                    <span>Failed</span>
                                </label>
                            </div>
                            <p
                                id="lml-hws-err-micro-result"
                                class="lml-hws__error"
                                role="alert"
                                @if (! $errors->has('microbiological_result')) hidden @endif
                                data-hws-error="microbiological_result"
                            >{{ $errors->first('microbiological_result') }}</p>
                        </fieldset>
                    </section>

                    <div class="lml-hws__test-divider" aria-hidden="true"></div>

                    <section
                        class="lml-hws__test-panel"
                        aria-labelledby="lml-hws-physico-heading"
                        data-hws-test-section="physicochemical"
                    >
                        <h4 id="lml-hws-physico-heading" class="lml-hws__test-heading">
                            <i class="bi bi-eyedropper" aria-hidden="true"></i>
                            <span>Physico - Chemical Test</span>
                        </h4>

                        <div class="lml-hws__field">
                            <label class="lml-hws__field-label" for="lml-hws-physico-date">Date</label>
                            <div class="lml-hws__date-wrap">
                                <input
                                    id="lml-hws-physico-date"
                                    type="date"
                                    class="form-control lml-form-control lml-hws__date-input @error('physicochemical_test_date') is-invalid @enderror"
                                    name="physicochemical_test_date"
                                    value="{{ $physicoDate }}"
                                    max="{{ now()->toDateString() }}"
                                    data-hws-physico-date
                                    aria-describedby="lml-hws-err-physico-date"
                                >
                            </div>
                            <p
                                id="lml-hws-err-physico-date"
                                class="lml-hws__error"
                                role="alert"
                                @if (! $errors->has('physicochemical_test_date')) hidden @endif
                                data-hws-error="physicochemical_test_date"
                            >{{ $errors->first('physicochemical_test_date') }}</p>
                        </div>

                        <fieldset class="lml-hws__result-fieldset">
                            <legend class="lml-hws__field-label" id="lml-hws-physico-result-legend">
                                Physico - Chemical Result
                            </legend>
                            <div
                                class="lml-hws__radio-row"
                                role="radiogroup"
                                aria-labelledby="lml-hws-physico-result-legend"
                                aria-describedby="lml-hws-err-physico-result"
                            >
                                <label class="lml-hws__choice lml-focus-ring">
                                    <input
                                        type="radio"
                                        name="physicochemical_result"
                                        value="passed"
                                        data-hws-physico-result
                                        data-hws-toggle-radio
                                        @checked($physicoResult === 'passed')
                                    >
                                    <span>Passed</span>
                                </label>
                                <label class="lml-hws__choice lml-focus-ring">
                                    <input
                                        type="radio"
                                        name="physicochemical_result"
                                        value="failed"
                                        data-hws-physico-result
                                        data-hws-toggle-radio
                                        @checked($physicoResult === 'failed')
                                    >
                                    <span>Failed</span>
                                </label>
                            </div>
                            <p
                                id="lml-hws-err-physico-result"
                                class="lml-hws__error"
                                role="alert"
                                @if (! $errors->has('physicochemical_result')) hidden @endif
                                data-hws-error="physicochemical_result"
                            >{{ $errors->first('physicochemical_result') }}</p>
                        </fieldset>
                    </section>
                </div>

                <div class="lml-hws__actions lml-hws__actions--pair">
                    <button
                        type="button"
                        class="lml-hws__prev lml-focus-ring"
                        data-hws-previous
                    >
                        Previous
                    </button>
                    <button
                        type="submit"
                        class="lml-hws__next lml-focus-ring"
                        data-hws-next
                    >
                        Next
                    </button>
                </div>
            </form>
        </div>

        <div class="lml-hws__dialog-backdrop" data-hws-dialog hidden>
            <div
                class="lml-hws__dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="lml-hws-discard-title"
                aria-describedby="lml-hws-discard-message"
                tabindex="-1"
                data-hws-dialog-panel
            >
                <h2 id="lml-hws-discard-title" class="lml-hws__dialog-title">
                    Leave this page?
                </h2>
                <p id="lml-hws-discard-message" class="lml-hws__dialog-message" data-hws-dialog-message>
                    You have unsaved changes. Are you sure you want to leave this step?
                </p>
                <div class="lml-hws__dialog-actions">
                    <button
                        type="button"
                        class="lml-hws__dialog-btn lml-hws__dialog-btn--stay lml-focus-ring"
                        data-hws-dialog-stay
                    >
                        Stay
                    </button>
                    <button
                        type="button"
                        class="lml-hws__dialog-btn lml-hws__dialog-btn--leave lml-focus-ring"
                        data-hws-dialog-leave
                    >
                        Leave
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection
