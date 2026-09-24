{{--
    Health Records → Child Care → Add New Child (non-resident / unregistered).
    UI preview — Save does not persist.
--}}
@extends('layouts.dashboard')

@section('title', 'Add New Child — Child Care - LMLinga')

@section('content')
    @php
        $listingUrl = route('health-records.child-care.non-residents.index');
        $childCareUrl = route('health-records.child-care.index');
        $sexOptions = $sexOptions ?? [];
        $residentLookup = $residentLookup ?? [];
    @endphp

    <div
        class="lml-hr-cc-nr"
        data-lml-hr-cc-nr
        data-lml-hr-cc-nr-mode="create"
    >
        <script type="application/json" data-hr-cc-nr-residents>
            {!! json_encode($residentLookup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
        </script>

        <div
            class="lml-hr-cc-nr__toast"
            data-hr-cc-nr-toast
            role="status"
            aria-live="polite"
            hidden
        ></div>

        <header class="lml-hr-cc-nr__page-head">
            <a
                href="{{ $childCareUrl }}"
                class="lml-hr-cc-nr__page-back lml-focus-ring"
                aria-label="Back to Child Care"
            >
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
            </a>
            <p class="lml-hr-cc-nr__page-head-title">Child Care</p>
        </header>

        <section class="lml-hr-cc-nr__form-panel" aria-labelledby="lml-hr-cc-nr-add-title">
            <div class="lml-hr-cc-nr__form-banner">
                <i class="bi bi-person-plus-fill" aria-hidden="true"></i>
                <h2 id="lml-hr-cc-nr-add-title">Add New Child</h2>
            </div>

            <div
                class="lml-hr-cc-nr__duplicate"
                data-hr-cc-nr-duplicate
                role="alert"
                hidden
            >
                <p class="lml-hr-cc-nr__duplicate-title">
                    This child appears to already exist in Household Profiling.
                </p>
                <p class="lml-hr-cc-nr__duplicate-hint" data-hr-cc-nr-duplicate-hint></p>
                <a
                    href="#"
                    class="lml-hr-cc-nr__duplicate-link lml-focus-ring"
                    data-hr-cc-nr-duplicate-link
                    hidden
                >
                    Open existing household member
                </a>
            </div>

            <form
                id="lml-hr-cc-nr-create-form"
                class="lml-hr-cc-nr__form"
                data-hr-cc-nr-create-form
                action="#"
                method="post"
                novalidate
            >
                @csrf

                <fieldset class="lml-hr-cc-nr__fieldset">
                    <legend class="lml-hr-cc-nr__section-title">
                        <i class="bi bi-person" aria-hidden="true"></i>
                        <span>CHILD INFORMATION</span>
                    </legend>

                    <div class="lml-hr-cc-nr__field-grid lml-hr-cc-nr__field-grid--3">
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hr-cc-nr-first-name">First Name</label>
                            <input id="lml-hr-cc-nr-first-name" name="first_name" type="text" class="lml-hr-cc-nr__input lml-focus-ring" autocomplete="given-name" data-hr-cc-nr-first-name>
                        </div>
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hr-cc-nr-middle-name">Middle Name</label>
                            <input id="lml-hr-cc-nr-middle-name" name="middle_name" type="text" class="lml-hr-cc-nr__input lml-focus-ring" autocomplete="additional-name" data-hr-cc-nr-middle-name>
                        </div>
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hr-cc-nr-last-name">Last Name</label>
                            <input id="lml-hr-cc-nr-last-name" name="last_name" type="text" class="lml-hr-cc-nr__input lml-focus-ring" autocomplete="family-name" data-hr-cc-nr-last-name>
                        </div>
                    </div>

                    <div class="lml-hr-cc-nr__field-grid lml-hr-cc-nr__field-grid--3">
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hr-cc-nr-mother-name">Mother's Name</label>
                            <input
                                id="lml-hr-cc-nr-mother-name"
                                name="mother_name"
                                type="text"
                                class="lml-hr-cc-nr__input lml-focus-ring"
                                placeholder="Mother's First Name"
                                data-hr-cc-nr-mother-name
                            >
                        </div>
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hr-cc-nr-birthday">Birthday</label>
                            <input id="lml-hr-cc-nr-birthday" name="birthday" type="date" class="lml-hr-cc-nr__input lml-focus-ring" autocomplete="bday">
                        </div>
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hr-cc-nr-sex">Sex</label>
                            <select id="lml-hr-cc-nr-sex" name="sex" class="lml-hr-cc-nr__input lml-focus-ring">
                                <option value="">Select sex</option>
                                @foreach ($sexOptions as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="lml-hr-cc-nr__field-grid lml-hr-cc-nr__field-grid--3">
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hr-cc-nr-address">Address</label>
                            <input
                                id="lml-hr-cc-nr-address"
                                name="address"
                                type="text"
                                class="lml-hr-cc-nr__input lml-focus-ring"
                                placeholder="Zone"
                                autocomplete="address-line2"
                            >
                        </div>
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hr-cc-nr-barangay-field">Barangay</label>
                            <input
                                id="lml-hr-cc-nr-barangay-field"
                                name="barangay"
                                type="text"
                                class="lml-hr-cc-nr__input lml-focus-ring"
                                placeholder="Barangay"
                                autocomplete="address-level3"
                            >
                        </div>
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hr-cc-nr-municipality">Municipality</label>
                            <input id="lml-hr-cc-nr-municipality" name="municipality" type="text" class="lml-hr-cc-nr__input lml-focus-ring" autocomplete="address-level2">
                        </div>
                    </div>

                    <div class="lml-hr-cc-nr__field-grid lml-hr-cc-nr__field-grid--2">
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hr-cc-nr-school">School Name</label>
                            <input id="lml-hr-cc-nr-school" name="school_name" type="text" class="lml-hr-cc-nr__input lml-focus-ring">
                        </div>
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-hr-cc-nr-grade">Grade Level</label>
                            <input
                                id="lml-hr-cc-nr-grade"
                                name="grade_level"
                                type="text"
                                class="lml-hr-cc-nr__input lml-focus-ring"
                                placeholder="Grade Level"
                            >
                        </div>
                    </div>
                </fieldset>

                <div class="lml-hr-cc-nr__form-actions">
                    <a
                        href="{{ $listingUrl }}"
                        class="lml-hr-cc-nr__cancel-btn lml-focus-ring"
                        data-hr-cc-nr-cancel
                    >
                        Cancel
                    </a>
                    <button type="submit" class="lml-hr-cc-nr__save-btn lml-focus-ring" data-hr-cc-nr-save>
                        Save
                    </button>
                </div>
            </form>
        </section>
    </div>
@endsection
