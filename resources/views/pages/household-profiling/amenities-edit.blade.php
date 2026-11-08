{{--
    Household Profiling — Edit Household Amenities Details.
    Same visual structure as the read-only details page; editable fields only.
    Derived statuses remain read-only and server-owned.
--}}
@extends('layouts.dashboard')

@section('title', 'Edit Household Amenities Details - LMLinga')

@php
    use App\Support\DemoHouseholdWaterSupply;
    use App\Support\HouseholdAmenitiesPresentation;

    $household = $demoHousehold ?? null;
    $record = is_array($amenitiesRecord ?? null) ? $amenitiesRecord : [];

    $selectedWaterLevel = (string) old('water_supply_status', $record['water_supply_status'] ?? '');
    $solidWastePractices = old('solid_waste_practices', $record['solid_waste_practices'] ?? []);
    if (! is_array($solidWastePractices)) {
        $solidWastePractices = [];
    }

    $basicSafeStatus = DemoHouseholdWaterSupply::deriveBasicSafeWaterStatus($selectedWaterLevel);
    if (old('water_supply_status') === null && isset($record['basic_safe_water_status'])) {
        $basicSafeStatus = (string) $record['basic_safe_water_status'];
    }
    $basicSafeLabel = DemoHouseholdWaterSupply::basicSafeWaterStatusLabel($basicSafeStatus);
    $basicSafeModifier = HouseholdAmenitiesPresentation::basicSafeWaterModifier($basicSafeStatus);

    $toiletType = (string) old('toilet_type', $record['toilet_type'] ?? '');
    $sewageDisposal = (string) old('sewage_disposal_method', $record['sewage_disposal_method'] ?? '');
    $managementStatus = DemoHouseholdWaterSupply::deriveManagementStatus($toiletType, $sewageDisposal);
    if (old('toilet_type') === null && isset($record['management_status'])) {
        $managementStatus = (string) $record['management_status'];
    }
    $managementLabel = DemoHouseholdWaterSupply::managementStatusBadgeLabel($managementStatus);
    $managementModifier = HouseholdAmenitiesPresentation::managementStatusModifier($managementStatus);

    $toiletStatus = DemoHouseholdWaterSupply::deriveToiletStatus($toiletType) ?? '';
    if (old('toilet_type') === null && isset($record['toilet_status'])) {
        $toiletStatus = (string) $record['toilet_status'];
    }
    $toiletStatusLabel = HouseholdAmenitiesPresentation::toiletStatusLabel($toiletStatus);
    $toiletStatusModifier = HouseholdAmenitiesPresentation::toiletStatusModifier($toiletStatus);
    $toiletStatusIcon = HouseholdAmenitiesPresentation::toiletStatusIcon($toiletStatus);

    $validationStatus = DemoHouseholdWaterSupply::validationTestingStatus([
        'microbiological_test_date' => old('microbiological_test_date', $record['microbiological_test_date'] ?? null),
        'microbiological_result' => old('microbiological_result', $record['microbiological_result'] ?? null),
        'physicochemical_test_date' => old('physicochemical_test_date', $record['physicochemical_test_date'] ?? null),
        'physicochemical_result' => old('physicochemical_result', $record['physicochemical_result'] ?? null),
    ]);
    $validationLabel = DemoHouseholdWaterSupply::validationTestingStatusLabel($validationStatus);
    $validationModifier = HouseholdAmenitiesPresentation::validationStatusModifier($validationStatus);

    $solidWasteStatus = count($solidWastePractices) > 0 ? 'good_practice' : 'not_yet_determined';
    if (old('solid_waste_practices') === null && isset($record['solid_waste_status'])) {
        $solidWasteStatus = (string) $record['solid_waste_status'];
    }
    $solidWasteLabel = DemoHouseholdWaterSupply::solidWasteStatusLabel($solidWasteStatus);
    $solidWasteModifier = HouseholdAmenitiesPresentation::solidWasteStatusModifier($solidWasteStatus);

    $completeStatus = DemoHouseholdWaterSupply::deriveCompleteSanitationFacilityStatus([
        'management_status' => $managementStatus,
        'solid_waste_status' => $solidWasteStatus,
    ]);
    $completeLabel = DemoHouseholdWaterSupply::managementStatusBadgeLabel($completeStatus);

    $linked = is_array($linkedContext ?? null) ? $linkedContext : [];
    $socioeconomic = (string) ($socioeconomicStatus ?? DemoHouseholdWaterSupply::socioeconomicStatusLabel(
        $record !== [] ? $record : ($linked !== [] ? $linked : null),
        $household
    ));

    $houseHead = trim((string) ($record['house_head'] ?? $linked['house_head'] ?? $household['houseHead'] ?? ''));
    if ($houseHead === '') {
        $houseHead = 'Not available';
    }

    $waterLevels = [
        DemoHouseholdWaterSupply::WATER_LEVEL_I => [
            'label' => 'Level I',
            'description' => 'Point of Source',
            'icon' => 'bi-droplet',
        ],
        DemoHouseholdWaterSupply::WATER_LEVEL_II => [
            'label' => 'Level II',
            'description' => 'Communal Faucet',
            'icon' => 'bi-moisture',
        ],
        DemoHouseholdWaterSupply::WATER_LEVEL_III => [
            'label' => 'Level III',
            'description' => 'Individual Connection',
            'icon' => 'bi-house-door',
        ],
        DemoHouseholdWaterSupply::WATER_LEVEL_OTHERS => [
            'label' => 'Others',
            'description' => 'doubtful source',
            'icon' => 'bi-three-dots',
        ],
    ];
@endphp

@section('content')
    <div class="lml-amenities" data-lml-amenities data-mode="edit" data-household-no="{{ $householdNo }}">
        <a
            href="{{ route('household-profiling.amenities.show', ['householdNo' => $householdNo]) }}"
            class="lml-amenities__back lml-focus-ring"
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span>Back to Household Amenities Details</span>
        </a>

        @if (! is_array($household))
            <section class="lml-amenities__empty" aria-labelledby="lml-amenities-edit-not-found">
                <h2 id="lml-amenities-edit-not-found" class="lml-amenities__page-title">Household not found</h2>
                <p>The selected household record is not available.</p>
            </section>
        @else
            <header class="lml-amenities__page-head">
                <h2 id="lml-amenities-edit-title" class="lml-amenities__page-title">
                    Edit Household Amenities Details
                </h2>
            </header>

            <p class="lml-amenities__program-heading" role="note">
                ENVIRONMENTAL SANITATION AND OCCUPATIONAL HEALTH PROGRAM
            </p>

            @include('pages.household-profiling.partials.amenities-context', [
                'household' => $household,
                'householdNo' => $householdNo,
                'socioeconomic' => $socioeconomic,
                'houseHead' => $houseHead,
            ])

            @if ($errors->any())
                <p class="lml-amenities__error" role="alert">
                    Please review the highlighted fields below.
                </p>
            @endif

            <form
                method="post"
                action="{{ route('household-profiling.amenities.update', ['householdNo' => $householdNo]) }}"
                class="lml-amenities__form"
                novalidate
                aria-labelledby="lml-amenities-edit-title"
                @include('offline.form-attrs', [
                    'offlineOperation' => 'HOUSEHOLD_AMENITIES_UPDATE',
                    'offlineHouseholdPk' => $offlineHouseholdPk ?? null,
                    'offlineHouseholdNo' => $householdNo,
                    'offlineFieldHash' => $offlineFieldHash ?? null,
                ])
            >
                @csrf
                @method('PUT')
                <p class="lml-offline-form-pending" data-lml-offline-form-pending hidden>Waiting to sync</p>
                <input type="hidden" name="household_no" value="{{ $householdNo }}">

                {{-- 1. Water Supply --}}
                <section class="lml-amenities__panel" aria-labelledby="lml-amenities-water-edit-title">
                    <div class="lml-amenities__panel-head">
                        <h3 id="lml-amenities-water-edit-title" class="lml-amenities__panel-title">
                            <i class="bi bi-droplet" aria-hidden="true"></i>
                            <span>Household Water Supply Information</span>
                        </h3>
                        <p class="lml-amenities__status-badge {{ $basicSafeModifier }}" role="status">
                            {{ $basicSafeLabel }}
                        </p>
                    </div>

                    <div
                        class="lml-amenities__level-grid"
                        role="radiogroup"
                        aria-labelledby="lml-amenities-water-edit-title"
                        aria-describedby="err-water_supply_status"
                        aria-required="true"
                    >
                        @foreach ($waterLevels as $value => $option)
                            <label class="lml-amenities__level-card lml-amenities__level-card--editable lml-focus-ring{{ $selectedWaterLevel === $value ? ' is-selected' : '' }}">
                                <input
                                    type="radio"
                                    class="lml-amenities__level-input"
                                    name="water_supply_status"
                                    value="{{ $value }}"
                                    @checked($selectedWaterLevel === $value)
                                >
                                <span class="lml-amenities__level-check" aria-hidden="true">
                                    <i class="bi bi-check-circle-fill"></i>
                                </span>
                                <span class="lml-amenities__level-icon" aria-hidden="true">
                                    <i class="bi {{ $option['icon'] }}"></i>
                                </span>
                                <span class="lml-amenities__level-label">{{ $option['label'] }}</span>
                                <span class="lml-amenities__level-desc">{{ $option['description'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p id="err-water_supply_status" class="lml-amenities__field-error" role="alert" @if (! $errors->has('water_supply_status')) hidden @endif>
                        {{ $errors->first('water_supply_status') }}
                    </p>

                    <div class="lml-amenities__subgrid">
                        <fieldset class="lml-amenities__subcard lml-amenities__field-block">
                            <legend class="lml-amenities__subcard-label">
                                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                <span>Water Source Location</span>
                            </legend>
                            <div class="lml-amenities__choice-row" role="radiogroup">
                                @foreach (['yes' => 'Yes', 'no' => 'No'] as $value => $label)
                                    <label class="lml-amenities__choice lml-focus-ring">
                                        <input
                                            type="radio"
                                            name="water_source_location"
                                            value="{{ $value }}"
                                            @checked(old('water_source_location', $record['water_source_location'] ?? '') === $value)
                                        >
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="lml-amenities__field-error" role="alert" @if (! $errors->has('water_source_location')) hidden @endif>
                                {{ $errors->first('water_source_location') }}
                            </p>
                        </fieldset>

                        <fieldset class="lml-amenities__subcard lml-amenities__field-block">
                            <legend class="lml-amenities__subcard-label">
                                <i class="bi bi-clock" aria-hidden="true"></i>
                                <span>Water Availability</span>
                            </legend>
                            <div class="lml-amenities__choice-row" role="radiogroup">
                                @foreach (['yes' => 'Yes', 'no' => 'No'] as $value => $label)
                                    <label class="lml-amenities__choice lml-focus-ring">
                                        <input
                                            type="radio"
                                            name="water_availability"
                                            value="{{ $value }}"
                                            @checked(old('water_availability', $record['water_availability'] ?? '') === $value)
                                        >
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="lml-amenities__field-error" role="alert" @if (! $errors->has('water_availability')) hidden @endif>
                                {{ $errors->first('water_availability') }}
                            </p>
                        </fieldset>

                        <div class="lml-amenities__subcard lml-amenities__subcard--full lml-amenities__field-block">
                            <label class="lml-amenities__subcard-label" for="specify_water_source">
                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                <span>Others Specification</span>
                            </label>
                            <input
                                id="specify_water_source"
                                type="text"
                                name="specify_water_source"
                                value="{{ old('specify_water_source', $record['specify_water_source'] ?? '') }}"
                                class="form-control lml-form-control @error('specify_water_source') is-invalid @enderror"
                                maxlength="255"
                                aria-describedby="err-specify_water_source"
                                placeholder="Required when Others is selected"
                            >
                            <p id="err-specify_water_source" class="lml-amenities__field-error" role="alert" @if (! $errors->has('specify_water_source')) hidden @endif>
                                {{ $errors->first('specify_water_source') }}
                            </p>
                        </div>
                    </div>
                </section>

                {{-- 2. Validation --}}
                <section class="lml-amenities__panel" aria-labelledby="lml-amenities-validation-edit-title">
                    <div class="lml-amenities__panel-head">
                        <h3 id="lml-amenities-validation-edit-title" class="lml-amenities__panel-title">
                            <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                            <span>Validation / Random Sampling / Testing</span>
                        </h3>
                        <p class="lml-amenities__status-badge {{ $validationModifier }}" role="status">
                            {{ $validationLabel }}
                        </p>
                    </div>

                    <div class="lml-amenities__test-grid">
                        <article class="lml-amenities__test-card" aria-labelledby="lml-amenities-micro-edit-title">
                            <h4 id="lml-amenities-micro-edit-title" class="lml-amenities__test-title">
                                <i class="bi bi-shield-check" aria-hidden="true"></i>
                                <span>Microbiological Validation</span>
                            </h4>
                            <div class="lml-amenities__field-block">
                                <label class="lml-amenities__field-label" for="microbiological_test_date">Date</label>
                                <input
                                    id="microbiological_test_date"
                                    type="date"
                                    name="microbiological_test_date"
                                    value="{{ old('microbiological_test_date', $record['microbiological_test_date'] ?? '') }}"
                                    class="form-control lml-form-control @error('microbiological_test_date') is-invalid @enderror"
                                    max="{{ now()->toDateString() }}"
                                    aria-describedby="err-microbiological_test_date"
                                >
                                <p id="err-microbiological_test_date" class="lml-amenities__field-error" role="alert" @if (! $errors->has('microbiological_test_date')) hidden @endif>
                                    {{ $errors->first('microbiological_test_date') }}
                                </p>
                            </div>
                            <div class="lml-amenities__field-block">
                                <label class="lml-amenities__field-label" for="microbiological_result">Result</label>
                                <select
                                    id="microbiological_result"
                                    name="microbiological_result"
                                    class="form-select lml-form-control @error('microbiological_result') is-invalid @enderror"
                                    aria-describedby="err-microbiological_result"
                                >
                                    <option value="">Select result</option>
                                    <option value="passed" @selected(old('microbiological_result', $record['microbiological_result'] ?? '') === 'passed')>Passed</option>
                                    <option value="failed" @selected(old('microbiological_result', $record['microbiological_result'] ?? '') === 'failed')>Failed</option>
                                </select>
                                <p id="err-microbiological_result" class="lml-amenities__field-error" role="alert" @if (! $errors->has('microbiological_result')) hidden @endif>
                                    {{ $errors->first('microbiological_result') }}
                                </p>
                            </div>
                        </article>

                        <article class="lml-amenities__test-card" aria-labelledby="lml-amenities-physico-edit-title">
                            <h4 id="lml-amenities-physico-edit-title" class="lml-amenities__test-title">
                                <i class="bi bi-eyedropper" aria-hidden="true"></i>
                                <span>Physico-Chemical Test</span>
                            </h4>
                            <div class="lml-amenities__field-block">
                                <label class="lml-amenities__field-label" for="physicochemical_test_date">Date</label>
                                <input
                                    id="physicochemical_test_date"
                                    type="date"
                                    name="physicochemical_test_date"
                                    value="{{ old('physicochemical_test_date', $record['physicochemical_test_date'] ?? '') }}"
                                    class="form-control lml-form-control @error('physicochemical_test_date') is-invalid @enderror"
                                    max="{{ now()->toDateString() }}"
                                    aria-describedby="err-physicochemical_test_date"
                                >
                                <p id="err-physicochemical_test_date" class="lml-amenities__field-error" role="alert" @if (! $errors->has('physicochemical_test_date')) hidden @endif>
                                    {{ $errors->first('physicochemical_test_date') }}
                                </p>
                            </div>
                            <div class="lml-amenities__field-block">
                                <label class="lml-amenities__field-label" for="physicochemical_result">Result</label>
                                <select
                                    id="physicochemical_result"
                                    name="physicochemical_result"
                                    class="form-select lml-form-control @error('physicochemical_result') is-invalid @enderror"
                                    aria-describedby="err-physicochemical_result"
                                >
                                    <option value="">Select result</option>
                                    <option value="passed" @selected(old('physicochemical_result', $record['physicochemical_result'] ?? '') === 'passed')>Passed</option>
                                    <option value="failed" @selected(old('physicochemical_result', $record['physicochemical_result'] ?? '') === 'failed')>Failed</option>
                                </select>
                                <p id="err-physicochemical_result" class="lml-amenities__field-error" role="alert" @if (! $errors->has('physicochemical_result')) hidden @endif>
                                    {{ $errors->first('physicochemical_result') }}
                                </p>
                            </div>
                        </article>
                    </div>
                </section>

                {{-- 3. Sanitation --}}
                <section class="lml-amenities__panel" aria-labelledby="lml-amenities-sanitation-edit-title">
                    <div class="lml-amenities__panel-head">
                        <h3 id="lml-amenities-sanitation-edit-title" class="lml-amenities__panel-title">
                            <i class="bi bi-badge-wc" aria-hidden="true"></i>
                            <span>Basic Sanitation Facility</span>
                        </h3>
                        <p class="lml-amenities__status-badge {{ $managementModifier }}" role="status">
                            {{ $managementLabel }}
                        </p>
                    </div>

                    <div class="lml-amenities__sanitation-top">
                        <div class="lml-amenities__subcard lml-amenities__field-block">
                            <label class="lml-amenities__subcard-label" for="toilet_type">
                                <i class="bi bi-badge-wc" aria-hidden="true"></i>
                                <span>Type of Toilet</span>
                            </label>
                            <select
                                id="toilet_type"
                                name="toilet_type"
                                class="form-select lml-form-control @error('toilet_type') is-invalid @enderror"
                                aria-describedby="err-toilet_type"
                            >
                                <option value="">Select type of toilet</option>
                                @foreach (DemoHouseholdWaterSupply::toiletTypes() as $value)
                                    <option value="{{ $value }}" @selected($toiletType === $value)>
                                        {{ DemoHouseholdWaterSupply::toiletTypeLabel($value) }}
                                    </option>
                                @endforeach
                            </select>
                            <p id="err-toilet_type" class="lml-amenities__field-error" role="alert" @if (! $errors->has('toilet_type')) hidden @endif>
                                {{ $errors->first('toilet_type') }}
                            </p>
                            <p class="lml-amenities__toilet-status {{ $toiletStatusModifier }}" role="status">
                                <i class="bi {{ $toiletStatusIcon }}" aria-hidden="true"></i>
                                <span>{{ $toiletStatusLabel }}</span>
                            </p>
                        </div>

                        <fieldset class="lml-amenities__subcard lml-amenities__field-block">
                            <legend class="lml-amenities__subcard-label">
                                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                                <span>Open Defecation Place</span>
                            </legend>
                            <p class="lml-amenities__subcard-hint">Is toilet within 20 meters from the household?</p>
                            <div class="lml-amenities__choice-row">
                                @foreach (['yes' => 'Yes', 'no' => 'No'] as $value => $label)
                                    <label class="lml-amenities__choice lml-focus-ring">
                                        <input
                                            type="radio"
                                            name="open_defecation_practiced"
                                            value="{{ $value }}"
                                            @checked(old('open_defecation_practiced', $record['open_defecation_practiced'] ?? '') === $value)
                                        >
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="lml-amenities__field-error" role="alert" @if (! $errors->has('open_defecation_practiced')) hidden @endif>
                                {{ $errors->first('open_defecation_practiced') }}
                            </p>
                        </fieldset>
                    </div>

                    <div class="lml-amenities__facility-card">
                        <p class="lml-amenities__subcard-label">
                            <i class="bi bi-building" aria-hidden="true"></i>
                            <span>Facility</span>
                        </p>
                        <div class="lml-amenities__facility-row">
                            <fieldset class="lml-amenities__facility-shared lml-amenities__field-block">
                                <legend class="lml-amenities__facility-disposal-label">Shared Toilet?</legend>
                                <div class="lml-amenities__choice-row">
                                    @foreach (['yes' => 'Yes', 'no' => 'No'] as $value => $label)
                                        <label class="lml-amenities__choice lml-focus-ring">
                                            <input
                                                type="radio"
                                                name="shared_toilet"
                                                value="{{ $value }}"
                                                @checked(old('shared_toilet', $record['shared_toilet'] ?? '') === $value)
                                            >
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="lml-amenities__field-error" role="alert" @if (! $errors->has('shared_toilet')) hidden @endif>
                                    {{ $errors->first('shared_toilet') }}
                                </p>
                            </fieldset>

                            <fieldset class="lml-amenities__facility-disposal lml-amenities__field-block">
                                <legend class="lml-amenities__facility-disposal-label">Toilet Sewage Disposal Method</legend>
                                <div class="lml-amenities__choice-row">
                                    <label class="lml-amenities__choice lml-focus-ring">
                                        <input
                                            type="radio"
                                            name="sewage_disposal_method"
                                            value="on_site_safely_managed"
                                            data-amenities-sewage
                                            @checked($sewageDisposal === 'on_site_safely_managed')
                                        >
                                        <span>In-site Disposed</span>
                                    </label>
                                    <label class="lml-amenities__choice lml-focus-ring">
                                        <input
                                            type="radio"
                                            name="sewage_disposal_method"
                                            value="off_site_collected_and_treated"
                                            data-amenities-sewage
                                            @checked($sewageDisposal === 'off_site_collected_and_treated')
                                        >
                                        <span>Off-site Disposed</span>
                                    </label>
                                </div>
                                <p class="lml-amenities__field-error" role="alert" @if (! $errors->has('sewage_disposal_method')) hidden @endif>
                                    {{ $errors->first('sewage_disposal_method') }}
                                </p>
                            </fieldset>
                        </div>
                    </div>
                </section>

                {{-- 4. Solid Waste --}}
                <section class="lml-amenities__panel" aria-labelledby="lml-amenities-solid-edit-title">
                    <div class="lml-amenities__panel-head">
                        <h3 id="lml-amenities-solid-edit-title" class="lml-amenities__panel-title">
                            <i class="bi bi-recycle" aria-hidden="true"></i>
                            <span>Solid Waste Management</span>
                        </h3>
                        <p class="lml-amenities__status-badge {{ $solidWasteModifier }}" role="status">
                            {{ $solidWasteLabel }}
                        </p>
                    </div>

                    <fieldset class="lml-amenities__practice-edit-grid" aria-describedby="err-solid_waste_practices">
                        <legend class="visually-hidden">Solid waste management practices</legend>
                        @foreach (DemoHouseholdWaterSupply::solidWastePracticeValues() as $practiceValue)
                            <label class="lml-amenities__practice-choice lml-focus-ring{{ in_array($practiceValue, $solidWastePractices, true) ? ' is-checked' : '' }}">
                                <input
                                    type="checkbox"
                                    name="solid_waste_practices[]"
                                    value="{{ $practiceValue }}"
                                    @checked(in_array($practiceValue, $solidWastePractices, true))
                                >
                                <span class="lml-amenities__practice-icon" aria-hidden="true">
                                    <i class="bi bi-check-square-fill"></i>
                                </span>
                                <span class="lml-amenities__practice-label">
                                    {{ DemoHouseholdWaterSupply::solidWastePracticeLabel($practiceValue) }}
                                </span>
                            </label>
                        @endforeach
                    </fieldset>
                    <p id="err-solid_waste_practices" class="lml-amenities__field-error" role="alert" @if (! $errors->has('solid_waste_practices')) hidden @endif>
                        {{ $errors->first('solid_waste_practices') }}
                    </p>
                </section>

                {{-- 5. Summary (read-only) --}}
                <section class="lml-amenities__summary-panel" aria-labelledby="lml-amenities-summary-edit-title">
                    <h3 id="lml-amenities-summary-edit-title" class="lml-amenities__summary-title">
                        <i class="bi bi-list-check" aria-hidden="true"></i>
                        <span>SUMMARY</span>
                    </h3>
                    <p class="lml-amenities__summary-note">
                        Summary values are server-derived and cannot be edited directly.
                    </p>
                    <div class="lml-amenities__summary-grid">
                        <article class="lml-amenities__summary-card">
                            <span class="lml-amenities__summary-icon" aria-hidden="true"><i class="bi bi-droplet"></i></span>
                            <h4>Basic Safe Water Status</h4>
                            <p>{{ $basicSafeLabel }}</p>
                        </article>
                        <article class="lml-amenities__summary-card">
                            <span class="lml-amenities__summary-icon" aria-hidden="true"><i class="bi bi-clipboard2-check"></i></span>
                            <h4>Validation / Testing Status</h4>
                            <p>{{ $validationLabel }}</p>
                        </article>
                        <article class="lml-amenities__summary-card">
                            <span class="lml-amenities__summary-icon" aria-hidden="true"><i class="bi bi-badge-wc"></i></span>
                            <h4>Basic Sanitation Facility Status</h4>
                            <p>{{ $managementLabel }}</p>
                        </article>
                        <article class="lml-amenities__summary-card">
                            <span class="lml-amenities__summary-icon" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
                            <h4>Complete Sanitation Facility Status</h4>
                            <p>{{ $completeLabel }}</p>
                        </article>
                    </div>
                </section>

                <div class="lml-amenities__actions">
                    <a
                        href="{{ route('household-profiling.amenities.show', ['householdNo' => $householdNo]) }}"
                        class="lml-amenities__btn lml-amenities__btn--secondary lml-focus-ring"
                    >
                        Close
                    </a>
                    <button
                        type="submit"
                        class="lml-amenities__btn lml-amenities__btn--primary lml-focus-ring"
                    >
                        Save
                    </button>
                </div>
            </form>
        @endif
    </div>
@endsection

