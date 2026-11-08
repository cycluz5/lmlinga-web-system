{{--
    Environmental Sanitation & Occupational Health Program
    Part 3 — Solid Waste Management (required, multi-select)
--}}
@extends('layouts.dashboard')

@section('title', 'Solid Waste Management - LMLinga')

@php
    use App\Support\DemoHouseholdWaterSupply;

    $householdNo = $householdNo ?? '';
    $saved = is_array($savedRecord ?? null) ? $savedRecord : [];
    $spotMapUrl = route('spot-mapping.index');
    $step3Url = route('environmental-health.household-water-supply.step3', ['householdNo' => $householdNo]);
    $storeUrl = route('environmental-health.household-water-supply.step4.store', ['householdNo' => $householdNo]);

    $selectedPractices = old('solid_waste_practices', $saved['solid_waste_practices'] ?? []);
    if (! is_array($selectedPractices)) {
        $selectedPractices = [];
    }

    $practiceOptions = [
        DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION => 'Waste Segregation',
        DemoHouseholdWaterSupply::SOLID_WASTE_BACKYARD_COMPOSTING => 'Backyard Composting',
        DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE => 'Recycling / Reuse',
        DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION => 'Collected by Municipality / Municipal Collection and Disposal System',
    ];

    $usePersistedStatus = isset($saved['solid_waste_status']) && old('solid_waste_practices') === null;

    if ($usePersistedStatus) {
        $persistedStatus = (string) $saved['solid_waste_status'];
        if ($persistedStatus === 'good_practice') {
            $statusText = 'GOOD PRACTICE';
            $statusModifier = 'is-good';
            $hasSelection = true;
        } else {
            $statusText = 'NOT YET DETERMINED';
            $statusModifier = 'is-pending';
            $hasSelection = false;
        }
    } else {
        $hasSelection = count($selectedPractices) > 0;
        $statusText = $hasSelection ? 'GOOD PRACTICE' : 'NOT YET DETERMINED';
        $statusModifier = $hasSelection ? 'is-good' : 'is-pending';
    }
@endphp

@section('content')
    <div
        class="lml-hws"
        data-lml-hws
        data-hws-step="4"
        data-household-no="{{ $householdNo }}"
        data-spot-mapping-url="{{ $spotMapUrl }}"
        data-hws-back-url="{{ $step3Url }}"
    >
        <div class="lml-hws__body">
            <x-environmental-health.household-water-header
                :current-step="4"
                :step-labels="['1', '1.2', '2', '3']"
                page-heading="Solid Waste Management"
                page-heading-id="lml-hws-page-title"
                back-aria-label="Back to Basic Sanitation Facility"
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
                data-hws-step4-form
                method="post"
                action="{{ $storeUrl }}"
                novalidate
                aria-labelledby="lml-hws-page-title"
                @include('offline.form-attrs', [
                    'offlineOperation' => 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
                    'offlineEhStep' => 4,
                    'offlineHouseholdPk' => $offlineHouseholdPk ?? null,
                    'offlineHouseholdNo' => $householdNo,
                    'offlineFieldHash' => $offlineFieldHash ?? null,
                ])
            >
                @csrf
                <p class="lml-offline-form-pending" data-lml-offline-form-pending hidden>Waiting to sync</p>
                <input type="hidden" name="household_no" value="{{ $householdNo }}" data-hws-household-no>

                <div class="lml-hws__solid-top">
                    <fieldset
                        class="lml-hws__solid-card lml-hws__solid-card--left"
                        aria-describedby="lml-hws-err-solid-waste"
                    >
                        <legend class="lml-hws__question-legend" id="lml-hws-solid-waste-legend">
                            <i class="bi bi-trash" aria-hidden="true"></i>
                            <span class="lml-hws__question-title">Waste Management Practices</span>
                        </legend>

                        <div
                            class="lml-hws__check-col @error('solid_waste_practices') is-invalid @enderror"
                            data-hws-solid-practices-group
                            role="group"
                            aria-labelledby="lml-hws-solid-waste-legend"
                            aria-required="true"
                        >
                            @foreach ($practiceOptions as $value => $label)
                                <label class="lml-hws__choice lml-focus-ring">
                                    <input
                                        type="checkbox"
                                        name="solid_waste_practices[]"
                                        value="{{ $value }}"
                                        data-hws-solid-practice
                                        @checked(in_array($value, $selectedPractices, true))
                                    >
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>

                        <p
                            id="lml-hws-err-solid-waste"
                            class="lml-hws__error"
                            role="alert"
                            @if (! $errors->has('solid_waste_practices')) hidden @endif
                            data-hws-error="solid_waste_practices"
                        >{{ $errors->first('solid_waste_practices') }}</p>
                    </fieldset>

                    <div
                        class="lml-hws__solid-card lml-hws__solid-card--status lml-hws__solid-status {{ $statusModifier }}"
                        data-hws-solid-status
                        aria-live="polite"
                        aria-atomic="true"
                    >
                        <p class="lml-hws__toilet-status-label">
                            <i class="bi bi-pencil" aria-hidden="true"></i>
                            <span>Status</span>
                        </p>
                        <div class="lml-hws__toilet-status-body">
                            <span class="lml-hws__toilet-status-icon" aria-hidden="true" data-hws-solid-status-icon>
                                @if ($hasSelection)
                                    <i class="bi bi-shield-fill-check"></i>
                                @else
                                    <i class="bi bi-shield"></i>
                                @endif
                            </span>
                            <p class="lml-hws__toilet-status-value" data-hws-solid-status-text>
                                {{ $statusText }}
                            </p>
                        </div>
                    </div>
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
