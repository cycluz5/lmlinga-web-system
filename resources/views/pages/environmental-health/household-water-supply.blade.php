{{--
    Environmental Sanitation & Occupational Health Program
    Step 1 — Household Water Supply Information
    Continues from Spot Mapping after a successful Plot Household.
--}}
@extends('layouts.dashboard')

@section('title', 'Household Water Supply Information - LMLinga')

@php
    $householdNo = $householdNo ?? '';
    $spotMapUrl = route('spot-mapping.index');
    $saved = is_array($savedRecord ?? null) ? $savedRecord : [];
    $selectedLevel = old('water_supply_status', $saved['water_supply_status'] ?? '');
    $basicSafeStatus = \App\Support\DemoHouseholdWaterSupply::deriveBasicSafeWaterStatus(
        is_string($selectedLevel) ? $selectedLevel : ''
    );
    $basicSafeLabel = \App\Support\DemoHouseholdWaterSupply::basicSafeWaterStatusLabel($basicSafeStatus);
    $basicSafeModifier = match ($basicSafeStatus) {
        \App\Support\DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITH => 'is-with',
        \App\Support\DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITHOUT => 'is-without',
        default => 'is-pending',
    };
    $specifyValue = old('specify_water_source', $saved['specify_water_source'] ?? '');
    $locationValue = old('water_source_location', $saved['water_source_location'] ?? '');
    $availabilityValue = old('water_availability', $saved['water_availability'] ?? '');
    $waterLevels = [
        'level_i' => [
            'label' => 'Level I',
            'description' => 'Point of Source',
            'icon' => 'bi-droplet-fill',
        ],
        'level_ii' => [
            'label' => 'Level II',
            'description' => 'Communal Faucet',
            'icon' => 'bi-moisture',
        ],
        'level_iii' => [
            'label' => 'Level III',
            'description' => 'Individual Connection',
            'icon' => 'bi-house-door-fill',
        ],
        'others' => [
            'label' => 'Others',
            'description' => 'For Doubtful Sources (e.g. Open Dug Well)',
            'icon' => 'bi-three-dots',
        ],
    ];
@endphp

@section('content')
    <div
        class="lml-hws"
        data-lml-hws
        data-hws-step="1"
        data-household-no="{{ $householdNo }}"
        data-spot-mapping-url="{{ $spotMapUrl }}"
        data-hws-back-url="{{ $spotMapUrl }}"
    >
        <div class="lml-hws__body">
            <x-environmental-health.household-water-header
                :current-step="1"
                page-heading="Household Water Supply Information"
                page-heading-id="lml-hws-page-title"
                back-aria-label="Back to Spot Mapping"
            />

            @if ($householdNo === '')
                <div class="lml-hws__missing" role="alert">
                    <p class="lml-hws__missing-text">
                        @if ($errors->any())
                            {{ $errors->first() }}
                        @else
                            No household was linked from Spot Mapping. Plot a household first, then continue here.
                        @endif
                    </p>
                    <a href="{{ $spotMapUrl }}" class="lml-hws__missing-link lml-focus-ring">
                        Go to Spot Mapping
                    </a>
                </div>
            @else
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
                    method="post"
                    action="{{ route('environmental-health.household-water-supply.store') }}"
                    novalidate
                    aria-labelledby="lml-hws-page-title"
                    @include('offline.form-attrs', [
                        'offlineOperation' => 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
                        'offlineEhStep' => 1,
                        'offlineHouseholdPk' => $offlineHouseholdPk ?? null,
                        'offlineHouseholdNo' => $householdNo,
                        'offlineFieldHash' => $offlineFieldHash ?? null,
                    ])
                >
                    @csrf
                    <p class="lml-offline-form-pending" data-lml-offline-form-pending hidden>Waiting to sync</p>
                    <input type="hidden" name="household_no" value="{{ $householdNo }}" data-hws-household-no>

                    <section class="lml-hws__section" aria-labelledby="lml-hws-status-heading">
                        <div class="lml-hws__status-panel">
                            <div class="lml-hws__status-panel-header">
                                <h3 id="lml-hws-status-heading" class="lml-hws__section-title lml-hws__section-title--panel">
                                    <i class="bi bi-droplet-half" aria-hidden="true"></i>
                                    <span>Water Supply Status</span>
                                </h3>
                                <p
                                    class="lml-hws__safe-water-badge {{ $basicSafeModifier }}"
                                    data-hws-safe-water-badge
                                    role="status"
                                    aria-live="polite"
                                >
                                    <span data-hws-safe-water-badge-text>{{ $basicSafeLabel }}</span>
                                </p>
                            </div>

                            <div
                                class="lml-hws__level-grid"
                                role="radiogroup"
                                aria-labelledby="lml-hws-status-heading"
                                aria-required="true"
                                aria-describedby="lml-hws-err-status"
                                data-hws-level-group
                            >
                                @foreach ($waterLevels as $value => $option)
                                    <label class="lml-hws__level-card lml-focus-ring" data-hws-level-card>
                                        <input
                                            type="radio"
                                            class="lml-hws__level-input"
                                            name="water_supply_status"
                                            value="{{ $value }}"
                                            data-hws-level
                                            @checked($selectedLevel === $value)
                                        >
                                        <span class="lml-hws__level-radio" aria-hidden="true"></span>
                                        <span class="lml-hws__level-icon" aria-hidden="true">
                                            <i class="bi {{ $option['icon'] }}"></i>
                                        </span>
                                        <span class="lml-hws__level-label">{{ $option['label'] }}</span>
                                        <span class="lml-hws__level-desc">{{ $option['description'] }}</span>
                                    </label>
                                @endforeach
                            </div>

                            <p
                                id="lml-hws-err-status"
                                class="lml-hws__error"
                                role="alert"
                                hidden
                                data-hws-error="water_supply_status"
                            ></p>

                            <div class="lml-hws__specify" data-hws-specify @if($selectedLevel !== 'others') hidden @endif>
                                <label class="lml-hws__specify-label" for="lml-hws-specify-input">
                                    Specify Water Source
                                    <span class="lml-hws__required" aria-hidden="true">*</span>
                                </label>
                                <input
                                    id="lml-hws-specify-input"
                                    type="text"
                                    class="form-control lml-form-control lml-hws__specify-input"
                                    name="specify_water_source"
                                    value="{{ $specifyValue }}"
                                    placeholder="Specify Water Source (If Others is selected)"
                                    autocomplete="off"
                                    maxlength="255"
                                    data-hws-specify-input
                                    aria-describedby="lml-hws-err-specify"
                                >
                                <p
                                    id="lml-hws-err-specify"
                                    class="lml-hws__error"
                                    role="alert"
                                    hidden
                                    data-hws-error="specify_water_source"
                                ></p>
                            </div>
                        </div>
                    </section>

                    <div class="lml-hws__questions">
                        <fieldset class="lml-hws__question" data-hws-question="location">
                            <legend class="lml-hws__question-legend">
                                <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                                <span class="lml-hws__question-title">Water Source Location</span>
                            </legend>
                            <p class="lml-hws__question-text" id="lml-hws-location-q">
                                Is the water source located within the household premises?
                            </p>
                            <div
                                class="lml-hws__radio-row"
                                role="radiogroup"
                                aria-labelledby="lml-hws-location-q"
                                aria-required="true"
                                aria-describedby="lml-hws-err-location"
                            >
                                <label class="lml-hws__choice lml-focus-ring">
                                    <input
                                        type="radio"
                                        name="water_source_location"
                                        value="yes"
                                        data-hws-location
                                        @checked($locationValue === 'yes')
                                    >
                                    <span>Yes</span>
                                </label>
                                <label class="lml-hws__choice lml-focus-ring">
                                    <input
                                        type="radio"
                                        name="water_source_location"
                                        value="no"
                                        data-hws-location
                                        @checked($locationValue === 'no')
                                    >
                                    <span>No</span>
                                </label>
                            </div>
                            <p
                                id="lml-hws-err-location"
                                class="lml-hws__error"
                                role="alert"
                                hidden
                                data-hws-error="water_source_location"
                            ></p>
                        </fieldset>

                        <fieldset class="lml-hws__question" data-hws-question="availability">
                            <legend class="lml-hws__question-legend">
                                <i class="bi bi-clock-fill" aria-hidden="true"></i>
                                <span class="lml-hws__question-title">Water Availability</span>
                            </legend>
                            <p class="lml-hws__question-text" id="lml-hws-availability-q">
                                Is water available 24 hours a day?
                            </p>
                            <div
                                class="lml-hws__radio-row"
                                role="radiogroup"
                                aria-labelledby="lml-hws-availability-q"
                                aria-required="true"
                                aria-describedby="lml-hws-err-availability"
                            >
                                <label class="lml-hws__choice lml-focus-ring">
                                    <input
                                        type="radio"
                                        name="water_availability"
                                        value="yes"
                                        data-hws-availability
                                        @checked($availabilityValue === 'yes')
                                    >
                                    <span>Yes</span>
                                </label>
                                <label class="lml-hws__choice lml-focus-ring">
                                    <input
                                        type="radio"
                                        name="water_availability"
                                        value="no"
                                        data-hws-availability
                                        @checked($availabilityValue === 'no')
                                    >
                                    <span>No</span>
                                </label>
                            </div>
                            <p
                                id="lml-hws-err-availability"
                                class="lml-hws__error"
                                role="alert"
                                hidden
                                data-hws-error="water_availability"
                            ></p>
                        </fieldset>
                    </div>

                    <div class="lml-hws__actions">
                        <button
                            type="submit"
                            class="lml-hws__next lml-focus-ring"
                            data-hws-next
                            disabled
                            aria-disabled="true"
                        >
                            Next
                        </button>
                    </div>
                </form>
            @endif
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
                    You have unsaved changes. Are you sure you want to return to Spot Mapping?
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
