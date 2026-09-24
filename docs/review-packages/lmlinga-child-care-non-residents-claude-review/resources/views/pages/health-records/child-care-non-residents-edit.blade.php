{{--
    Health Records → Child Care → Non-Residents Edit Personal Information.
    UI preview — Save does not persist.
--}}
@extends('layouts.dashboard')

@section('title', 'Edit Personal Information — Child Care - LMLinga')

@section('content')
    @php
        $child = $child ?? null;
        $listingUrl = route('health-records.child-care.non-residents.index');
        $childCareUrl = route('health-records.child-care.index');
        $viewUrl = isset($child['key'])
            ? route('health-records.child-care.non-residents.show', ['childKey' => $child['key']])
            : $listingUrl;
        $sexOptions = $sexOptions ?? [];
        $motherName = trim((string) ($child['mother_name'] ?? ''));
        if ($motherName === '—') {
            $motherName = '';
        }
        $gradeLevel = trim((string) ($child['grade_level'] ?? ''));
        if ($gradeLevel === '' || strcasecmp($gradeLevel, 'N/A') === 0) {
            $gradeLevel = '';
        }
        $schoolName = trim((string) ($child['school_name'] ?? ''));
    @endphp

    <div
        class="lml-hr-cc-nr"
        data-lml-hr-cc-nr
        data-lml-hr-cc-nr-mode="edit-profile"
    >
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

        @if (! $child)
            <article class="lml-hr-cc-nr__profile">
                <h2 class="lml-hr-cc-nr__child-name">Record not found</h2>
                <p class="lml-hr-cc-nr__stub-note">No non-resident child record matches this identifier.</p>
                <a href="{{ $listingUrl }}" class="lml-hr-cc-nr__cancel-btn lml-focus-ring">Back to Non-Residents listing</a>
            </article>
        @else
            <section class="lml-hr-cc-nr__form-panel" aria-labelledby="lml-hr-cc-nr-edit-title">
                <div class="lml-hr-cc-nr__form-banner">
                    <i class="bi bi-person-gear" aria-hidden="true"></i>
                    <h2 id="lml-hr-cc-nr-edit-title">Edit Personal Information</h2>
                </div>

                <form
                    id="lml-hr-cc-nr-edit-form"
                    class="lml-hr-cc-nr__form"
                    data-hr-cc-nr-edit-form
                    action="#"
                    method="post"
                    novalidate
                    data-hr-cc-nr-return="{{ $viewUrl }}"
                    data-hr-cc-nr-preview-save="Preview only: this child's personal information was not saved to the database."
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
                                <input
                                    id="lml-hr-cc-nr-first-name"
                                    name="first_name"
                                    type="text"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    autocomplete="given-name"
                                    value="{{ $child['first_name'] }}"
                                >
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-middle-name">Middle Name</label>
                                <input
                                    id="lml-hr-cc-nr-middle-name"
                                    name="middle_name"
                                    type="text"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    autocomplete="additional-name"
                                    value="{{ $child['middle_name'] }}"
                                >
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-last-name">Last Name</label>
                                <input
                                    id="lml-hr-cc-nr-last-name"
                                    name="last_name"
                                    type="text"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    autocomplete="family-name"
                                    value="{{ $child['last_name'] }}"
                                >
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
                                    value="{{ $motherName }}"
                                >
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-birthday">Birthday</label>
                                <input
                                    id="lml-hr-cc-nr-birthday"
                                    name="birthday"
                                    type="date"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    autocomplete="bday"
                                    value="{{ $child['birthday'] }}"
                                >
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-sex">Sex</label>
                                <select id="lml-hr-cc-nr-sex" name="sex" class="lml-hr-cc-nr__input lml-focus-ring">
                                    <option value="">Select sex</option>
                                    @foreach ($sexOptions as $option)
                                        <option value="{{ $option }}" @selected(($child['sex'] ?? '') === $option)>{{ $option }}</option>
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
                                    value="{{ $child['address_zone'] }}"
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
                                    value="{{ $child['barangay'] }}"
                                >
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-municipality">Municipality</label>
                                <input
                                    id="lml-hr-cc-nr-municipality"
                                    name="municipality"
                                    type="text"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    autocomplete="address-level2"
                                    value="{{ $child['municipality'] }}"
                                >
                            </div>
                        </div>

                        <div class="lml-hr-cc-nr__field-grid lml-hr-cc-nr__field-grid--2">
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-school">School Name</label>
                                <input
                                    id="lml-hr-cc-nr-school"
                                    name="school_name"
                                    type="text"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    value="{{ $schoolName }}"
                                >
                            </div>
                            <div class="lml-hr-cc-nr__field">
                                <label for="lml-hr-cc-nr-grade">Grade Level</label>
                                <input
                                    id="lml-hr-cc-nr-grade"
                                    name="grade_level"
                                    type="text"
                                    class="lml-hr-cc-nr__input lml-focus-ring"
                                    placeholder="Grade Level"
                                    value="{{ $gradeLevel }}"
                                >
                            </div>
                        </div>
                    </fieldset>

                    <div class="lml-hr-cc-nr__form-actions">
                        <a
                            href="{{ $viewUrl }}"
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
        @endif
    </div>
@endsection
