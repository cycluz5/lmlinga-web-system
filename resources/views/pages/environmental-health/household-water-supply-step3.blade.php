{{--
    Environmental Sanitation & Occupational Health Program
    Part 2 — Basic Sanitation Facility / Household Toilet (required)
--}}
@extends('layouts.dashboard')

@section('title', 'Basic Sanitation Facility - LMLinga')

@php
    use App\Support\DemoHouseholdWaterSupply;

    $householdNo = $householdNo ?? '';
    $saved = is_array($savedRecord ?? null) ? $savedRecord : [];
    $spotMapUrl = route('spot-mapping.index');
    $step2Url = route('environmental-health.household-water-supply.step2', ['householdNo' => $householdNo]);
    $storeUrl = route('environmental-health.household-water-supply.step3.store', ['householdNo' => $householdNo]);

    $toiletType = old('toilet_type', $saved['toilet_type'] ?? '');
    $openDefecation = old('open_defecation_practiced', $saved['open_defecation_practiced'] ?? '');
    $sharedToilet = old('shared_toilet', $saved['shared_toilet'] ?? '');
    $sewageDisposal = old('sewage_disposal_method', $saved['sewage_disposal_method'] ?? '');

    $withoutToilet = DemoHouseholdWaterSupply::isWithoutToilet((string) $toiletType);

    if ($withoutToilet) {
        $sharedToilet = 'no';
        $sewageDisposal = '';
    }

    $managementStatus = DemoHouseholdWaterSupply::deriveManagementStatus(
        (string) $toiletType,
        (string) $sewageDisposal
    );

    $toiletOptions = [
        'pour_flush_with_septic_tank' => 'Pour/Flush Type with Septic Tank',
        'pour_flush_connected_to_septic_or_sewer' => 'Pour/Flush Connected to Septic Tank or Sewerage System',
        'ventilated_improved_pit_latrine' => 'Pour/ Ventilated Pit (VIP) Latrine',
        'water_sealed_without_septic_tank' => 'Water-Sealed Toilet without Septic Tank',
        'overhung_latrine' => 'Overhung Latrine (Antipolo Type)',
        'open_pit_latrine' => 'Open Pit Latrine',
        'without_toilet' => 'Without Toilet',
    ];

    $statusModifier = match ($managementStatus) {
        DemoHouseholdWaterSupply::MANAGEMENT_STATUS_SAFELY_MANAGED => 'is-sanitary',
        DemoHouseholdWaterSupply::MANAGEMENT_STATUS_NOT_SAFELY_MANAGED => 'is-unsanitary',
        default => 'is-pending',
    };

    $badgeModifier = match ($managementStatus) {
        DemoHouseholdWaterSupply::MANAGEMENT_STATUS_SAFELY_MANAGED => 'is-safely-managed',
        DemoHouseholdWaterSupply::MANAGEMENT_STATUS_NOT_SAFELY_MANAGED => 'is-not-safely-managed',
        default => 'is-pending',
    };

    $badgeLabel = DemoHouseholdWaterSupply::managementStatusBadgeLabel($managementStatus);
    $statusText = DemoHouseholdWaterSupply::managementStatusDisplayText($managementStatus);
@endphp

@section('content')
    <div
        class="lml-hws"
        data-lml-hws
        data-hws-step="3"
        data-household-no="{{ $householdNo }}"
        data-spot-mapping-url="{{ $spotMapUrl }}"
        data-hws-back-url="{{ $step2Url }}"
    >
        <div class="lml-hws__body">
            <x-environmental-health.household-water-header
                :current-step="3"
                :step-labels="['1', '1.2', '2', '3']"
                page-heading="Basic Sanitation Facility"
                page-heading-id="lml-hws-page-title"
                back-aria-label="Back to Validation / Random Sampling / Testing"
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
                data-hws-step3-form
                method="post"
                action="{{ $storeUrl }}"
                novalidate
                aria-labelledby="lml-hws-page-title"
                @include('offline.form-attrs', [
                    'offlineOperation' => 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
                    'offlineEhStep' => 3,
                    'offlineHouseholdPk' => $offlineHouseholdPk ?? null,
                    'offlineHouseholdNo' => $householdNo,
                    'offlineFieldHash' => $offlineFieldHash ?? null,
                ])
            >
                @csrf
                <p class="lml-offline-form-pending" data-lml-offline-form-pending hidden>Waiting to sync</p>
                <input type="hidden" name="household_no" value="{{ $householdNo }}" data-hws-household-no>

                <div class="lml-hws__toilet-top">
                    <div class="lml-hws__toilet-card lml-hws__toilet-card--type">
                        <div class="lml-hws__field lml-hws__toilet-field">
                            <label class="lml-hws__field-label" for="lml-hws-toilet-type" id="lml-hws-toilet-type-label">
                                <i class="bi bi-badge-wc" aria-hidden="true"></i>
                                <span>Type of Toilet</span>
                            </label>
                            <select
                                id="lml-hws-toilet-type"
                                name="toilet_type"
                                class="form-select lml-form-control lml-hws__select @error('toilet_type') is-invalid @enderror"
                                data-hws-toilet-type
                                aria-required="true"
                                aria-describedby="lml-hws-err-toilet-type"
                                aria-invalid="{{ $errors->has('toilet_type') ? 'true' : 'false' }}"
                            >
                                <option value="" @selected($toiletType === '')>Select type of toilet</option>
                                @foreach ($toiletOptions as $value => $label)
                                    <option value="{{ $value }}" @selected($toiletType === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p
                                id="lml-hws-err-toilet-type"
                                class="lml-hws__error"
                                role="alert"
                                @if (! $errors->has('toilet_type')) hidden @endif
                                data-hws-error="toilet_type"
                            >{{ $errors->first('toilet_type') }}</p>
                        </div>
                    </div>

                    <div
                        class="lml-hws__toilet-card lml-hws__toilet-card--status lml-hws__toilet-status {{ $statusModifier }}"
                        data-hws-toilet-status
                        aria-live="polite"
                        aria-atomic="true"
                    >
                        <div class="lml-hws__toilet-status-header">
                            <p class="lml-hws__toilet-status-label">
                                <i class="bi bi-pencil" aria-hidden="true" data-hws-status-label-icon></i>
                                <span>Status</span>
                            </p>
                            <p
                                class="lml-hws__management-badge {{ $badgeModifier }}"
                                data-hws-management-badge
                                role="status"
                            >
                                <span data-hws-management-badge-text>{{ $badgeLabel }}</span>
                            </p>
                        </div>
                        <div class="lml-hws__toilet-status-body">
                            <span class="lml-hws__toilet-status-icon" aria-hidden="true" data-hws-status-icon>
                                @if ($managementStatus === DemoHouseholdWaterSupply::MANAGEMENT_STATUS_SAFELY_MANAGED)
                                    <i class="bi bi-shield-fill-check"></i>
                                @elseif ($managementStatus === DemoHouseholdWaterSupply::MANAGEMENT_STATUS_NOT_SAFELY_MANAGED)
                                    <i class="bi bi-shield-fill-x"></i>
                                @else
                                    <i class="bi bi-shield"></i>
                                @endif
                            </span>
                            <p class="lml-hws__toilet-status-value" data-hws-status-text>
                                {{ $statusText }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="lml-hws__questions lml-hws__questions--sanitation">
                    <fieldset class="lml-hws__question" aria-describedby="lml-hws-err-open-defecation">
                        <legend class="lml-hws__question-legend" id="lml-hws-open-defecation-legend">
                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                            <span class="lml-hws__question-title">Open Defecation Place</span>
                        </legend>
                        <p class="lml-hws__question-text" id="lml-hws-open-defecation-text">
                            Is open defecation practiced by the household?
                        </p>
                        <div
                            class="lml-hws__radio-row @error('open_defecation_practiced') is-invalid @enderror"
                            role="radiogroup"
                            aria-labelledby="lml-hws-open-defecation-legend"
                            aria-describedby="lml-hws-open-defecation-text lml-hws-err-open-defecation"
                            aria-required="true"
                        >
                            <label class="lml-hws__choice lml-focus-ring">
                                <input
                                    type="radio"
                                    name="open_defecation_practiced"
                                    value="yes"
                                    data-hws-open-defecation
                                    @checked($openDefecation === 'yes')
                                >
                                <span>Yes</span>
                            </label>
                            <label class="lml-hws__choice lml-focus-ring">
                                <input
                                    type="radio"
                                    name="open_defecation_practiced"
                                    value="no"
                                    data-hws-open-defecation
                                    @checked($openDefecation === 'no')
                                >
                                <span>No</span>
                            </label>
                        </div>
                        <p
                            id="lml-hws-err-open-defecation"
                            class="lml-hws__error"
                            role="alert"
                            @if (! $errors->has('open_defecation_practiced')) hidden @endif
                            data-hws-error="open_defecation_practiced"
                        >{{ $errors->first('open_defecation_practiced') }}</p>
                    </fieldset>

                    <div class="lml-hws__question lml-hws__facility-block">
                        <h4 class="lml-hws__question-legend" id="lml-hws-facility-heading">
                            <i class="bi bi-building" aria-hidden="true"></i>
                            <span class="lml-hws__question-title">Facility</span>
                        </h4>

                        <fieldset
                            class="lml-hws__facility-fieldset"
                            data-hws-shared-toilet-fieldset
                            aria-describedby="lml-hws-err-shared-toilet lml-hws-shared-toilet-note"
                        >
                            <legend class="lml-hws__field-label" id="lml-hws-shared-toilet-legend">
                                Is the toilet facility shared?
                            </legend>
                            <div
                                class="lml-hws__radio-row @error('shared_toilet') is-invalid @enderror"
                                role="radiogroup"
                                aria-labelledby="lml-hws-shared-toilet-legend"
                                aria-describedby="lml-hws-err-shared-toilet lml-hws-shared-toilet-note"
                                aria-required="true"
                                data-hws-shared-toilet-group
                            >
                                <label class="lml-hws__choice lml-focus-ring">
                                    <input
                                        type="radio"
                                        name="shared_toilet"
                                        value="yes"
                                        data-hws-shared-toilet
                                        @checked($sharedToilet === 'yes')
                                        @disabled($withoutToilet)
                                    >
                                    <span>Yes</span>
                                </label>
                                <label class="lml-hws__choice lml-focus-ring">
                                    <input
                                        type="radio"
                                        name="shared_toilet"
                                        value="no"
                                        data-hws-shared-toilet
                                        @checked($sharedToilet === 'no' || $withoutToilet)
                                        @disabled($withoutToilet)
                                    >
                                    <span>No</span>
                                </label>
                            </div>
                            @if ($withoutToilet)
                                <input type="hidden" name="shared_toilet" value="no" data-hws-shared-toilet-hidden>
                            @endif
                            <p
                                id="lml-hws-shared-toilet-note"
                                class="lml-hws__na-note"
                                data-hws-shared-toilet-note
                                @if (! $withoutToilet) hidden @endif
                            >
                                Not applicable because the household has no toilet facility. Shared toilet is set to No.
                            </p>
                            <p
                                id="lml-hws-err-shared-toilet"
                                class="lml-hws__error"
                                role="alert"
                                @if (! $errors->has('shared_toilet')) hidden @endif
                                data-hws-error="shared_toilet"
                            >{{ $errors->first('shared_toilet') }}</p>
                        </fieldset>

                        <fieldset
                            class="lml-hws__facility-fieldset"
                            data-hws-sewage-fieldset
                            aria-describedby="lml-hws-err-sewage lml-hws-sewage-note"
                        >
                            <legend class="lml-hws__field-label" id="lml-hws-sewage-legend">
                                Excreta / Sewage Disposal Method
                            </legend>
                            <div
                                class="lml-hws__radio-col @error('sewage_disposal_method') is-invalid @enderror"
                                role="radiogroup"
                                aria-labelledby="lml-hws-sewage-legend"
                                aria-describedby="lml-hws-err-sewage lml-hws-sewage-note"
                                aria-required="false"
                                data-hws-sewage-group
                                @if ($withoutToilet) hidden @endif
                            >
                                <label class="lml-hws__choice lml-focus-ring">
                                    <input
                                        type="radio"
                                        name="sewage_disposal_method"
                                        value="on_site_safely_managed"
                                        data-hws-sewage
                                        @checked($sewageDisposal === 'on_site_safely_managed')
                                        @disabled($withoutToilet)
                                    >
                                    <span>In-site Disposed</span>
                                </label>
                                <label class="lml-hws__choice lml-focus-ring">
                                    <input
                                        type="radio"
                                        name="sewage_disposal_method"
                                        value="off_site_collected_and_treated"
                                        data-hws-sewage
                                        @checked($sewageDisposal === 'off_site_collected_and_treated')
                                        @disabled($withoutToilet)
                                    >
                                    <span>Off-site Disposed</span>
                                </label>
                            </div>
                            <p
                                id="lml-hws-sewage-note"
                                class="lml-hws__na-note"
                                data-hws-sewage-note
                                @if (! $withoutToilet) hidden @endif
                            >
                                Not applicable because the household has no toilet facility.
                            </p>
                            <p
                                id="lml-hws-err-sewage"
                                class="lml-hws__error"
                                role="alert"
                                @if (! $errors->has('sewage_disposal_method')) hidden @endif
                                data-hws-error="sewage_disposal_method"
                            >{{ $errors->first('sewage_disposal_method') }}</p>
                        </fieldset>
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
                        disabled
                        aria-disabled="true"
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
