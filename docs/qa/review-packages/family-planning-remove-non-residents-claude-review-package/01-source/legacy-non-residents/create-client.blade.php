{{--
    Health Records → Family Planning → Add New Non Resident.
    UI preview — Save does not persist.
--}}
@extends('layouts.dashboard')

@section('title', 'Add New Non Resident — Family Planning - LMLinga')

@section('content')
    @php
        $listingUrl = route('health-records.family-planning.non-residents.index');
        $civilStatusOptions = $civilStatusOptions ?? [];
        $sexOptions = $sexOptions ?? [];
        $methodOptions = $methodOptions ?? [];
        $commodityOptions = $commodityOptions ?? [];
    @endphp

    <div
        class="lml-hr-fp-nr"
        data-lml-hr-fp-nr
        data-lml-hr-fp-nr-mode="create-client"
    >
        <div
            class="lml-hr-fp-nr__toast"
            data-hr-fp-nr-toast
            role="status"
            aria-live="polite"
            hidden
        ></div>

        <header class="lml-hr-fp-nr__page-head">
            <a
                href="{{ $listingUrl }}"
                class="lml-hr-fp-nr__page-back lml-focus-ring"
                aria-label="Back to Family Planning Non Residents listing"
            >
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Family Planning</span>
            </a>
        </header>

        <section class="lml-hr-fp-nr__form-panel" aria-labelledby="lml-hr-fp-nr-add-client-title">
            <div class="lml-hr-fp-nr__form-banner">
                <i class="bi bi-person-plus-fill lml-hr-fp-nr__form-banner-icon" aria-hidden="true"></i>
                <h3 id="lml-hr-fp-nr-add-client-title">Add New Non Resident</h3>
            </div>

            <form
                id="lml-hr-fp-nr-create-form"
                class="lml-hr-fp-nr__form"
                data-hr-fp-nr-create-form
                action="#"
                method="post"
                novalidate
            >
                @csrf

                <div class="lml-hr-fp-nr__section-box">
                <fieldset class="lml-hr-fp-nr__fieldset">
                    <legend class="lml-hr-fp-nr__section-title">
                        <i class="bi bi-person" aria-hidden="true"></i>
                        <span>PERSONAL INFORMATION</span>
                    </legend>

                    <div class="lml-hr-fp-nr__field-grid lml-hr-fp-nr__field-grid--3">
                        <div class="lml-hr-fp-nr__field">
                            <label for="lml-hr-fp-nr-first-name">First Name</label>
                            <input
                                id="lml-hr-fp-nr-first-name"
                                name="first_name"
                                type="text"
                                class="lml-hr-fp-nr__input lml-focus-ring"
                                placeholder="First Name"
                                autocomplete="given-name"
                            >
                        </div>
                        <div class="lml-hr-fp-nr__field">
                            <label for="lml-hr-fp-nr-middle-name">Middle Name</label>
                            <input
                                id="lml-hr-fp-nr-middle-name"
                                name="middle_name"
                                type="text"
                                class="lml-hr-fp-nr__input lml-focus-ring"
                                placeholder="Middle Name"
                                autocomplete="additional-name"
                            >
                        </div>
                        <div class="lml-hr-fp-nr__field">
                            <label for="lml-hr-fp-nr-last-name">Last Name</label>
                            <input
                                id="lml-hr-fp-nr-last-name"
                                name="last_name"
                                type="text"
                                class="lml-hr-fp-nr__input lml-focus-ring"
                                placeholder="Last Name"
                                autocomplete="family-name"
                            >
                        </div>
                    </div>

                    <div class="lml-hr-fp-nr__field-grid lml-hr-fp-nr__field-grid--3">
                        <div class="lml-hr-fp-nr__field">
                            <label for="lml-hr-fp-nr-birthday">Birthday</label>
                            <div class="lml-hr-fp-nr__input-with-icon">
                                <i class="bi bi-calendar3" aria-hidden="true"></i>
                                <input
                                    id="lml-hr-fp-nr-birthday"
                                    name="birthday"
                                    type="date"
                                    class="lml-hr-fp-nr__input lml-focus-ring"
                                    autocomplete="bday"
                                    data-hr-fp-nr-birthday
                                >
                            </div>
                        </div>
                        <div class="lml-hr-fp-nr__field">
                            <label for="lml-hr-fp-nr-sex">Sex</label>
                            <select
                                id="lml-hr-fp-nr-sex"
                                name="sex"
                                class="lml-hr-fp-nr__input lml-focus-ring"
                            >
                                <option value="">Select</option>
                                @foreach ($sexOptions as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="lml-hr-fp-nr__field">
                            <label for="lml-hr-fp-nr-civil-status">Civil Status</label>
                            <select
                                id="lml-hr-fp-nr-civil-status"
                                name="civil_status"
                                class="lml-hr-fp-nr__input lml-focus-ring"
                            >
                                <option value="">Select</option>
                                @foreach ($civilStatusOptions as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="lml-hr-fp-nr__field-grid lml-hr-fp-nr__field-grid--address">
                        <div class="lml-hr-fp-nr__field">
                            <label for="lml-hr-fp-nr-address">Address</label>
                            <input
                                id="lml-hr-fp-nr-address"
                                name="address_zone"
                                type="text"
                                class="lml-hr-fp-nr__input lml-focus-ring"
                                placeholder="Complete Address"
                                autocomplete="street-address"
                            >
                        </div>
                    </div>
                </fieldset>
                </div>

                <div class="lml-hr-fp-nr__section-box lml-hr-fp-nr__section-box--service">
                <fieldset class="lml-hr-fp-nr__fieldset">
                    <legend class="lml-hr-fp-nr__section-title lml-hr-fp-nr__section-title--service">
                        <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                        <span>Family Planning Service Record</span>
                    </legend>

                    <div class="lml-hr-fp-nr__form-split">
                        <div class="lml-hr-fp-nr__form-split-col">
                            <h4 class="lml-hr-fp-nr__subheading">Visit Information</h4>
                            <div class="lml-hr-fp-nr__field">
                                <label for="lml-hr-fp-nr-visit-date">Visit Date</label>
                                <div class="lml-hr-fp-nr__input-with-icon">
                                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                                    <input
                                        id="lml-hr-fp-nr-visit-date"
                                        name="visited_at"
                                        type="date"
                                        class="lml-hr-fp-nr__input lml-focus-ring"
                                    >
                                </div>
                            </div>
                            <div class="lml-hr-fp-nr__field">
                                <label for="lml-hr-fp-nr-method">Method</label>
                                <select
                                    id="lml-hr-fp-nr-method"
                                    name="method"
                                    class="lml-hr-fp-nr__input lml-focus-ring"
                                >
                                    <option value="">Select method</option>
                                    @foreach ($methodOptions as $option)
                                        <option value="{{ $option }}">{{ $option }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="lml-hr-fp-nr__field">
                                <label for="lml-hr-fp-nr-remarks">Remarks</label>
                                <textarea
                                    id="lml-hr-fp-nr-remarks"
                                    name="remarks"
                                    class="lml-hr-fp-nr__textarea lml-focus-ring"
                                    rows="2"
                                    placeholder="Enter remarks"
                                ></textarea>
                            </div>
                        </div>

                        <div class="lml-hr-fp-nr__form-split-col" data-hr-fp-nr-commodities>
                            <h4 class="lml-hr-fp-nr__subheading">Commodities Given</h4>
                            @include('pages.health-records.non-resident-family-planning.partials.commodity-rows', [
                                'commodityOptions' => $commodityOptions,
                                'commodities' => [['name' => '', 'quantity' => '']],
                                'idPrefix' => 'lml-hr-fp-nr-create',
                            ])
                        </div>
                    </div>
                </fieldset>
                </div>

                <div class="lml-hr-fp-nr__form-actions lml-hr-fp-nr__form-actions--centered">
                    <a
                        href="{{ $listingUrl }}"
                        class="lml-hr-fp-nr__btn lml-hr-fp-nr__btn--cancel lml-focus-ring"
                    >
                        Cancel
                    </a>
                    <button
                        type="submit"
                        class="lml-hr-fp-nr__btn lml-hr-fp-nr__btn--save lml-focus-ring"
                    >
                        Save
                    </button>
                </div>
            </form>
        </section>
    </div>
@endsection
