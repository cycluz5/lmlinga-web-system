@php
    $canPersist = (bool) ($canPersist ?? false);
    $storeUrl = route('household-profiling.members.maternal-care.store', $routeParams);
@endphp

<section class="lml-mc__panel" aria-labelledby="lml-mc-register-title" data-mc-register>
    <header class="lml-mc__panel-head">
        <div class="lml-mc__panel-titles">
            <h2 id="lml-mc-register-title" class="lml-mc__panel-title">
                MATERNAL INFORMATION
            </h2>
            <p class="lml-mc__panel-subtitle">
                Register pregnancy and nutritional assessment details for this member.
            </p>
        </div>
    </header>

    <form
        method="post"
        action="{{ $storeUrl }}"
        class="lml-mc__form"
        data-mc-register-form
        data-mc-can-persist="{{ $canPersist ? 'true' : 'false' }}"
        novalidate
        @if ($canPersist)
            @include('offline.form-attrs', [
                'offlineOperation' => 'HEALTH_SERVICE_WRITE',
                'offlineHealthAction' => 'maternal_register',
                'offlineHouseholdNo' => $householdNo ?? ($routeParams['householdNo'] ?? null),
                'offlineMemberNo' => $memberId ?? ($routeParams['memberId'] ?? null),
            ])
        @endif
    >
        @csrf

        <fieldset class="lml-mc__fieldset">
            <legend class="lml-mc__legend">Pregnancy Information</legend>
            <div class="lml-mc__grid lml-mc__grid--3">
                <div class="lml-mc__field">
                    <label for="lml-mc-lmp" class="lml-mc__label">Last Menstrual Period (LMP)</label>
                    <input
                        type="date"
                        id="lml-mc-lmp"
                        name="lmp"
                        class="lml-mc__input lml-focus-ring"
                        data-mc-lmp
                        max="{{ now()->toDateString() }}"
                    >
                </div>
                <div class="lml-mc__field-stack">
                    <div class="lml-mc__field">
                        <label for="lml-mc-gravida" class="lml-mc__label">Gravida</label>
                        <input
                            type="number"
                            id="lml-mc-gravida"
                            name="gravida"
                            min="0"
                            step="1"
                            inputmode="numeric"
                            class="lml-mc__input lml-focus-ring"
                        >
                    </div>
                    <div class="lml-mc__field">
                        <label for="lml-mc-parity" class="lml-mc__label">Parity</label>
                        <input
                            type="number"
                            id="lml-mc-parity"
                            name="parity"
                            min="0"
                            step="1"
                            inputmode="numeric"
                            class="lml-mc__input lml-focus-ring"
                        >
                    </div>
                </div>
                <div class="lml-mc__field">
                    <label for="lml-mc-edd" class="lml-mc__label">EDD (Estimated Date of Delivery)</label>
                    <input
                        type="date"
                        id="lml-mc-edd"
                        name="edd"
                        class="lml-mc__input lml-focus-ring"
                        data-mc-edd
                    >
                </div>
            </div>
        </fieldset>

        <fieldset class="lml-mc__fieldset">
            <legend class="lml-mc__legend">Nutritional Assessment</legend>
            <div class="lml-mc__grid lml-mc__grid--4">
                <div class="lml-mc__field">
                    <label for="lml-mc-weight" class="lml-mc__label">Weight (kg)</label>
                    <input
                        type="number"
                        id="lml-mc-weight"
                        name="weight"
                        min="0"
                        step="0.1"
                        inputmode="decimal"
                        class="lml-mc__input lml-focus-ring"
                        data-mc-weight
                    >
                </div>
                <div class="lml-mc__field">
                    <label for="lml-mc-height" class="lml-mc__label">Height (cm)</label>
                    <input
                        type="number"
                        id="lml-mc-height"
                        name="height"
                        min="0"
                        step="0.1"
                        inputmode="decimal"
                        class="lml-mc__input lml-focus-ring"
                        data-mc-height
                    >
                </div>
                <div class="lml-mc__field">
                    <label id="lml-mc-bmi-label" class="lml-mc__label">BMI</label>
                    <div
                        id="lml-mc-bmi"
                        class="lml-mc__bmi-display"
                        data-mc-bmi
                        role="status"
                        aria-labelledby="lml-mc-bmi-label"
                    >
                        <span data-mc-bmi-value></span>
                        <span class="lml-mc__bmi-status" data-mc-bmi-status data-bmi-status=""></span>
                    </div>
                </div>
                <div class="lml-mc__field">
                    <label for="lml-mc-bp" class="lml-mc__label">Blood Pressure</label>
                    <input
                        type="text"
                        id="lml-mc-bp"
                        name="blood_pressure"
                        class="lml-mc__input lml-focus-ring"
                        autocomplete="off"
                        placeholder="e.g. 120/80"
                    >
                </div>
            </div>
        </fieldset>

        <div class="lml-mc__form-actions">
            <a
                href="{{ route('household-profiling.members.maternal-care.index', $routeParams) }}"
                class="lml-mc__btn lml-mc__btn--ghost lml-focus-ring"
            >
                Cancel
            </a>
            <button type="submit" class="lml-mc__btn lml-mc__btn--primary lml-focus-ring" data-mc-save>
                Save
            </button>
        </div>
    </form>
</section>
