{{--
    Household Profiling — Household Amenities Details (read-only).
    Visual layout mirrors the Figma amenities dashboard; data remains server-sourced.
--}}
@extends('layouts.dashboard')

@section('title', 'Household Amenities Details - LMLinga')

@php
    use App\Support\DemoHouseholdWaterSupply;
    use App\Support\DisplayDate;
    use App\Support\HouseholdAmenitiesPresentation;

    $household = $demoHousehold ?? null;
    $record = is_array($amenitiesRecord ?? null) ? $amenitiesRecord : [];
    $linked = is_array($linkedContext ?? null) ? $linkedContext : [];
    $solidWastePractices = is_array($record['solid_waste_practices'] ?? null) ? $record['solid_waste_practices'] : [];
    $validationStatus = $validationTestingStatus ?? DemoHouseholdWaterSupply::validationTestingStatus($record);
    $completeStatus = $completeSanitationStatus ?? DemoHouseholdWaterSupply::deriveCompleteSanitationFacilityStatus($record);

    $selectedWaterLevel = (string) ($record['water_supply_status'] ?? '');
    $basicSafeStatus = (string) ($record['basic_safe_water_status'] ?? DemoHouseholdWaterSupply::BASIC_SAFE_WATER_PENDING);
    $basicSafeLabel = DemoHouseholdWaterSupply::basicSafeWaterStatusLabel($basicSafeStatus);
    $basicSafeModifier = HouseholdAmenitiesPresentation::basicSafeWaterModifier($basicSafeStatus);

    $managementStatus = (string) ($record['management_status'] ?? DemoHouseholdWaterSupply::MANAGEMENT_STATUS_PENDING);
    $managementLabel = DemoHouseholdWaterSupply::managementStatusBadgeLabel($managementStatus);
    $managementModifier = HouseholdAmenitiesPresentation::managementStatusModifier($managementStatus);

    $toiletStatus = strtolower(trim((string) ($record['toilet_status'] ?? '')));
    $toiletStatusLabel = HouseholdAmenitiesPresentation::toiletStatusLabel($toiletStatus);
    $toiletStatusModifier = HouseholdAmenitiesPresentation::toiletStatusModifier($toiletStatus);
    $toiletStatusIcon = HouseholdAmenitiesPresentation::toiletStatusIcon($toiletStatus);

    $solidWasteStatus = (string) ($record['solid_waste_status'] ?? '');
    $solidWasteLabel = DemoHouseholdWaterSupply::solidWasteStatusLabel($solidWasteStatus);
    $solidWasteModifier = HouseholdAmenitiesPresentation::solidWasteStatusModifier($solidWasteStatus);

    $validationLabel = DemoHouseholdWaterSupply::validationTestingStatusLabel($validationStatus);
    $validationModifier = HouseholdAmenitiesPresentation::validationStatusModifier($validationStatus);

    $completeLabel = DemoHouseholdWaterSupply::managementStatusBadgeLabel($completeStatus);
    $socioeconomic = (string) ($socioeconomicStatus ?? DemoHouseholdWaterSupply::socioeconomicStatusLabel($record, $household));

    $houseHead = trim((string) ($record['house_head'] ?? $linked['house_head'] ?? $household['houseHead'] ?? ''));
    if ($houseHead === '') {
        $houseHead = 'Not available';
    }

    $waterSourceLocation = strtolower(trim((string) ($record['water_source_location'] ?? '')));
    $waterAvailability = strtolower(trim((string) ($record['water_availability'] ?? '')));
    $openDefecation = strtolower(trim((string) ($record['open_defecation_practiced'] ?? '')));
    $sharedToilet = strtolower(trim((string) ($record['shared_toilet'] ?? '')));
    $sewageMethod = strtolower(trim((string) ($record['sewage_disposal_method'] ?? '')));

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

    $microDate = $record['microbiological_test_date'] ?? null;
    $microResult = $record['microbiological_result'] ?? null;
    $physicoDate = $record['physicochemical_test_date'] ?? null;
    $physicoResult = $record['physicochemical_result'] ?? null;
    $microDateDisplay = is_string($microDate) ? DisplayDate::format($microDate) : '';
    $physicoDateDisplay = is_string($physicoDate) ? DisplayDate::format($physicoDate) : '';
@endphp

@section('content')
    <div class="lml-amenities" data-lml-amenities data-mode="show" data-household-no="{{ $householdNo }}">
        <a
            href="{{ route('household-profiling.view', ['householdNo' => $householdNo]) }}"
            class="lml-amenities__back lml-focus-ring"
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span>Back to View Household</span>
        </a>

        @if (session('status'))
            <p class="lml-amenities__toast" role="status" aria-live="polite">
                {{ session('status') }}
            </p>
        @endif

        @if ($errors->any())
            <p class="lml-amenities__error" role="alert">
                {{ $errors->first() }}
            </p>
        @endif

        @if (! is_array($household))
            <section class="lml-amenities__empty" aria-labelledby="lml-amenities-not-found-title">
                <h2 id="lml-amenities-not-found-title" class="lml-amenities__page-title">Household not found</h2>
                <p>The selected household record is not available.</p>
            </section>
        @else
            <header class="lml-amenities__page-head">
                <h2 id="lml-amenities-title" class="lml-amenities__page-title">
                    Household Amenities Details
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

            {{-- 1. Water Supply --}}
            <section class="lml-amenities__panel" aria-labelledby="lml-amenities-water-title">
                <div class="lml-amenities__panel-head">
                    <h3 id="lml-amenities-water-title" class="lml-amenities__panel-title">
                        <i class="bi bi-droplet" aria-hidden="true"></i>
                        <span>Household Water Supply Information</span>
                    </h3>
                    <p class="lml-amenities__status-badge {{ $basicSafeModifier }}" role="status">
                        {{ $basicSafeLabel }}
                    </p>
                </div>

                <div
                    class="lml-amenities__level-grid"
                    role="list"
                    aria-label="Water supply level or source"
                >
                    @foreach ($waterLevels as $value => $option)
                        @php $isSelected = $selectedWaterLevel === $value; @endphp
                        <div
                            class="lml-amenities__level-card{{ $isSelected ? ' is-selected' : '' }}"
                            role="listitem"
                            data-water-level="{{ $value }}"
                            @if ($isSelected) aria-current="true" @endif
                        >
                            <span class="lml-amenities__level-check" aria-hidden="true">
                                @if ($isSelected)
                                    <i class="bi bi-check-circle-fill"></i>
                                @else
                                    <i class="bi bi-circle"></i>
                                @endif
                            </span>
                            <span class="lml-amenities__level-icon" aria-hidden="true">
                                <i class="bi {{ $option['icon'] }}"></i>
                            </span>
                            <span class="lml-amenities__level-label">{{ $option['label'] }}</span>
                            <span class="lml-amenities__level-desc">{{ $option['description'] }}</span>
                            <span class="visually-hidden">
                                {{ $isSelected ? 'Selected' : 'Not selected' }}
                            </span>
                        </div>
                    @endforeach
                </div>

                <div class="lml-amenities__subgrid">
                    <div class="lml-amenities__subcard">
                        <p class="lml-amenities__subcard-label">
                            <i class="bi bi-geo-alt" aria-hidden="true"></i>
                            <span>Water Source Location</span>
                        </p>
                        <div class="lml-amenities__choice-row lml-amenities__choice-row--readonly" role="group" aria-label="Water Source Location">
                            @foreach (['yes' => 'Yes', 'no' => 'No'] as $value => $label)
                                @php $isOn = $waterSourceLocation === $value; @endphp
                                <span class="lml-amenities__choice{{ $isOn ? ' is-selected' : '' }}" @if ($isOn) aria-current="true" @endif>
                                    <span class="lml-amenities__choice-mark" aria-hidden="true">
                                        <i class="bi {{ $isOn ? 'bi-check-circle-fill' : 'bi-circle' }}"></i>
                                    </span>
                                    <span>{{ $label }}</span>
                                    <span class="visually-hidden">{{ $isOn ? 'Selected' : 'Not selected' }}</span>
                                </span>
                            @endforeach
                        </div>
                    </div>
                    <div class="lml-amenities__subcard">
                        <p class="lml-amenities__subcard-label">
                            <i class="bi bi-clock" aria-hidden="true"></i>
                            <span>Water Availability</span>
                        </p>
                        <div class="lml-amenities__choice-row lml-amenities__choice-row--readonly" role="group" aria-label="Water Availability">
                            @foreach (['yes' => 'Yes', 'no' => 'No'] as $value => $label)
                                @php $isOn = $waterAvailability === $value; @endphp
                                <span class="lml-amenities__choice{{ $isOn ? ' is-selected' : '' }}" @if ($isOn) aria-current="true" @endif>
                                    <span class="lml-amenities__choice-mark" aria-hidden="true">
                                        <i class="bi {{ $isOn ? 'bi-check-circle-fill' : 'bi-circle' }}"></i>
                                    </span>
                                    <span>{{ $label }}</span>
                                    <span class="visually-hidden">{{ $isOn ? 'Selected' : 'Not selected' }}</span>
                                </span>
                            @endforeach
                        </div>
                    </div>
                    @if ($selectedWaterLevel === DemoHouseholdWaterSupply::WATER_LEVEL_OTHERS)
                        <div class="lml-amenities__subcard lml-amenities__subcard--full">
                            <p class="lml-amenities__subcard-label">
                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                <span>Others Specification</span>
                            </p>
                            <p class="lml-amenities__subcard-value" data-field="specify_water_source">
                                {{ filled($record['specify_water_source'] ?? null) ? $record['specify_water_source'] : 'Not specified' }}
                            </p>
                        </div>
                    @endif
                </div>
            </section>

            {{-- 2. Validation --}}
            <section class="lml-amenities__panel" aria-labelledby="lml-amenities-validation-title">
                <div class="lml-amenities__panel-head">
                    <h3 id="lml-amenities-validation-title" class="lml-amenities__panel-title">
                        <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                        <span>Validation / Random Sampling / Testing</span>
                    </h3>
                    <p class="lml-amenities__status-badge {{ $validationModifier }}" role="status">
                        {{ $validationLabel }}
                    </p>
                </div>

                <div class="lml-amenities__test-grid">
                    <article class="lml-amenities__test-card" aria-labelledby="lml-amenities-micro-title">
                        <h4 id="lml-amenities-micro-title" class="lml-amenities__test-title">
                            <i class="bi bi-shield-check" aria-hidden="true"></i>
                            <span>Microbiological Validation</span>
                        </h4>
                        <dl class="lml-amenities__kv">
                            <div>
                                <dt>Date</dt>
                                <dd data-field="microbiological_test_date">{{ filled($microDateDisplay) ? $microDateDisplay : 'Not Conducted' }}</dd>
                            </div>
                            <div>
                                <dt>Result</dt>
                                <dd>
                                    @php
                                        $microLabel = DemoHouseholdWaterSupply::testResultLabel(is_string($microResult) ? $microResult : null);
                                        $microClass = match (strtolower(trim((string) $microResult))) {
                                            'passed' => 'is-passed',
                                            'failed' => 'is-failed',
                                            default => 'is-empty',
                                        };
                                    @endphp
                                    <span class="lml-amenities__result {{ $microClass }}" data-field="microbiological_result">
                                        {{ $microLabel }}
                                    </span>
                                </dd>
                            </div>
                        </dl>
                    </article>

                    <article class="lml-amenities__test-card" aria-labelledby="lml-amenities-physico-title">
                        <h4 id="lml-amenities-physico-title" class="lml-amenities__test-title">
                            <i class="bi bi-eyedropper" aria-hidden="true"></i>
                            <span>Physico-Chemical Test</span>
                        </h4>
                        <dl class="lml-amenities__kv">
                            <div>
                                <dt>Date</dt>
                                <dd data-field="physicochemical_test_date">{{ filled($physicoDateDisplay) ? $physicoDateDisplay : 'Not Conducted' }}</dd>
                            </div>
                            <div>
                                <dt>Result</dt>
                                <dd>
                                    @php
                                        $physicoLabel = DemoHouseholdWaterSupply::testResultLabel(is_string($physicoResult) ? $physicoResult : null);
                                        $physicoClass = match (strtolower(trim((string) $physicoResult))) {
                                            'passed' => 'is-passed',
                                            'failed' => 'is-failed',
                                            default => 'is-empty',
                                        };
                                    @endphp
                                    <span class="lml-amenities__result {{ $physicoClass }}" data-field="physicochemical_result">
                                        {{ $physicoLabel }}
                                    </span>
                                </dd>
                            </div>
                        </dl>
                    </article>
                </div>
            </section>

            {{-- 3. Sanitation --}}
            <section class="lml-amenities__panel" aria-labelledby="lml-amenities-sanitation-title">
                <div class="lml-amenities__panel-head">
                    <h3 id="lml-amenities-sanitation-title" class="lml-amenities__panel-title">
                        <i class="bi bi-badge-wc" aria-hidden="true"></i>
                        <span>Basic Sanitation Facility</span>
                    </h3>
                    <p class="lml-amenities__status-badge {{ $managementModifier }}" role="status">
                        {{ $managementLabel }}
                    </p>
                </div>

                <div class="lml-amenities__sanitation-top">
                    <div class="lml-amenities__subcard">
                        <p class="lml-amenities__subcard-label">
                            <i class="bi bi-badge-wc" aria-hidden="true"></i>
                            <span>Type of Toilet</span>
                        </p>
                        <p class="lml-amenities__subcard-value" data-field="toilet_type">
                            {{ DemoHouseholdWaterSupply::toiletTypeLabel($record['toilet_type'] ?? null) }}
                        </p>
                        <p class="lml-amenities__toilet-status {{ $toiletStatusModifier }}" role="status">
                            <i class="bi {{ $toiletStatusIcon }}" aria-hidden="true"></i>
                            <span>{{ $toiletStatusLabel }}</span>
                        </p>
                    </div>

                    <div class="lml-amenities__subcard">
                        <p class="lml-amenities__subcard-label">
                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                            <span>Open Defecation Place</span>
                        </p>
                        <p class="lml-amenities__subcard-hint">Is toilet within 20 meters from the household?</p>
                        <div class="lml-amenities__choice-row lml-amenities__choice-row--readonly" role="group" aria-label="Open Defecation Place">
                            @foreach (['yes' => 'Yes', 'no' => 'No'] as $value => $label)
                                @php $isOn = $openDefecation === $value; @endphp
                                <span class="lml-amenities__choice{{ $isOn ? ' is-selected' : '' }}" @if ($isOn) aria-current="true" @endif>
                                    <span class="lml-amenities__choice-mark" aria-hidden="true">
                                        <i class="bi {{ $isOn ? 'bi-check-circle-fill' : 'bi-circle' }}"></i>
                                    </span>
                                    <span>{{ $label }}</span>
                                    <span class="visually-hidden">{{ $isOn ? 'Selected' : 'Not selected' }}</span>
                                </span>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="lml-amenities__facility-card">
                    <p class="lml-amenities__subcard-label">
                        <i class="bi bi-building" aria-hidden="true"></i>
                        <span>Facility</span>
                    </p>
                    <div class="lml-amenities__facility-row">
                        <div class="lml-amenities__facility-shared">
                            <span class="lml-amenities__practice-icon{{ $sharedToilet === 'yes' ? ' is-on' : '' }}" aria-hidden="true">
                                <i class="bi {{ $sharedToilet === 'yes' ? 'bi-check-square-fill' : 'bi-square' }}"></i>
                            </span>
                            <span>Shared Toilet?</span>
                            <span class="visually-hidden">
                                {{ $sharedToilet === 'yes' ? 'Yes, selected' : ($sharedToilet === 'no' ? 'No' : 'Not yet determined') }}
                            </span>
                        </div>
                        <div class="lml-amenities__facility-disposal" role="group" aria-label="Excreta / Sewage Disposal Method">
                            <span class="lml-amenities__facility-disposal-label">Toilet Sewage Disposal Method</span>
                            <div class="lml-amenities__choice-row lml-amenities__choice-row--readonly">
                                @foreach ([
                                    DemoHouseholdWaterSupply::SEWAGE_ON_SITE => 'In-site Disposed',
                                    DemoHouseholdWaterSupply::SEWAGE_OFF_SITE => 'Off-site Disposed',
                                ] as $value => $label)
                                    @php $isOn = $sewageMethod === $value; @endphp
                                    <span class="lml-amenities__choice{{ $isOn ? ' is-selected' : '' }}" @if ($isOn) aria-current="true" @endif>
                                        <span class="lml-amenities__choice-mark" aria-hidden="true">
                                            <i class="bi {{ $isOn ? 'bi-check-circle-fill' : 'bi-circle' }}"></i>
                                        </span>
                                        <span>{{ $label }}</span>
                                        <span class="visually-hidden">{{ $isOn ? 'Selected' : 'Not selected' }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- 4. Solid Waste --}}
            <section class="lml-amenities__panel" aria-labelledby="lml-amenities-solid-title">
                <div class="lml-amenities__panel-head">
                    <h3 id="lml-amenities-solid-title" class="lml-amenities__panel-title">
                        <i class="bi bi-recycle" aria-hidden="true"></i>
                        <span>Solid Waste Management</span>
                    </h3>
                    <p class="lml-amenities__status-badge {{ $solidWasteModifier }}" role="status">
                        {{ $solidWasteLabel }}
                    </p>
                </div>

                <ul class="lml-amenities__practice-grid" aria-label="Solid waste management practices">
                    @foreach (DemoHouseholdWaterSupply::solidWastePracticeValues() as $practiceValue)
                        @php $isChecked = in_array($practiceValue, $solidWastePractices, true); @endphp
                        <li
                            class="lml-amenities__practice-item{{ $isChecked ? ' is-checked' : '' }}"
                            data-practice="{{ $practiceValue }}"
                        >
                            <span class="lml-amenities__practice-icon" aria-hidden="true">
                                @if ($isChecked)
                                    <i class="bi bi-check-square-fill"></i>
                                @else
                                    <i class="bi bi-square"></i>
                                @endif
                            </span>
                            <span class="lml-amenities__practice-label">
                                {{ DemoHouseholdWaterSupply::solidWastePracticeLabel($practiceValue) }}
                            </span>
                            <span class="visually-hidden">{{ $isChecked ? 'Selected' : 'Not selected' }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- 5. Summary --}}
            <section class="lml-amenities__summary-panel" aria-labelledby="lml-amenities-summary-title">
                <h3 id="lml-amenities-summary-title" class="lml-amenities__summary-title">
                    <i class="bi bi-list-check" aria-hidden="true"></i>
                    <span>SUMMARY</span>
                </h3>

                <div class="lml-amenities__summary-grid">
                    <article class="lml-amenities__summary-card">
                        <span class="lml-amenities__summary-icon" aria-hidden="true">
                            <i class="bi bi-droplet"></i>
                        </span>
                        <h4>Basic Safe Water Status</h4>
                        <p>{{ $basicSafeLabel }}</p>
                    </article>
                    <article class="lml-amenities__summary-card">
                        <span class="lml-amenities__summary-icon" aria-hidden="true">
                            <i class="bi bi-clipboard2-check"></i>
                        </span>
                        <h4>Validation / Testing Status</h4>
                        <p>{{ $validationLabel }}</p>
                    </article>
                    <article class="lml-amenities__summary-card">
                        <span class="lml-amenities__summary-icon" aria-hidden="true">
                            <i class="bi bi-badge-wc"></i>
                        </span>
                        <h4>Basic Sanitation Facility Status</h4>
                        <p>{{ $managementLabel }}</p>
                    </article>
                    <article class="lml-amenities__summary-card">
                        <span class="lml-amenities__summary-icon" aria-hidden="true">
                            <i class="bi bi-shield-check"></i>
                        </span>
                        <h4>Complete Sanitation Facility Status</h4>
                        <p>{{ $completeLabel }}</p>
                    </article>
                </div>
            </section>

            <div class="lml-amenities__actions">
                <a
                    href="{{ route('household-profiling.amenities.edit', ['householdNo' => $householdNo]) }}"
                    class="lml-amenities__btn lml-amenities__btn--secondary lml-focus-ring"
                    data-hh-nav="amenities-edit"
                >
                    Edit
                </a>
                <a
                    href="{{ route('household-profiling.view', ['householdNo' => $householdNo]) }}"
                    class="lml-amenities__btn lml-amenities__btn--primary lml-focus-ring"
                >
                    Close
                </a>
            </div>
        @endif
    </div>
@endsection
