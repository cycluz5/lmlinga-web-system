{{--
    Edit Health Worker — 5-step guided form.
    Prefills from database staff (numeric id). Persistence via PUT for DB workers.
--}}
@extends('layouts.dashboard')

@section('title', 'Edit Account Details - LMLinga')

@php
    $worker = $demoWorker ?? null;
    $civilStatusOptions = [
        'Single' => 'Single',
        'Married' => 'Married',
        'Widowed' => 'Widowed',
        'Separated' => 'Separated',
        'Annulled' => 'Annulled',
    ];
    $nationalityOptions = [
        'Filipino' => 'Filipino',
        'Other' => 'Other',
    ];
    $roleOptions = [
        'BHW' => 'BHW',
        'BNS' => 'BNS',
        'BSPO' => 'BSPO',
        'Admin' => 'Admin',
    ];
    $zoneOptions = [
        'Zone 1' => 'Zone 1',
        'Zone 2' => 'Zone 2',
        'Zone 3' => 'Zone 3',
        'Zone 4' => 'Zone 4',
        'Zone 5' => 'Zone 5',
    ];
    $statusOptions = [
        'Active' => 'Active',
        'Inactive' => 'Inactive',
    ];
    $steps = [
        1 => 'Personal Information',
        2 => 'Contact Information',
        3 => 'Residential Address',
        4 => 'Employment Information',
        5 => 'Account Information',
    ];
@endphp

@section('content')
    @if (! $worker)
        <div class="lml-hw-wizard">
            <div class="lml-hw-wizard__card">
                <h2 class="lml-hw-wizard__missing-title">Health worker not found</h2>
                <p class="lml-hw-wizard__missing-text">
                    The selected health worker could not be loaded.
                </p>
                <a href="{{ route('user-management.index') }}" class="lml-hw-wizard__back-link lml-focus-ring">
                    Back to Manage Health Workers
                </a>
            </div>
        </div>
    @else
        <div
            class="lml-hw-wizard"
            data-lml-hw-wizard
            data-worker-id="{{ $worker['id'] }}"
            data-return-url="{{ route('user-management.health-workers.view', ['id' => $worker['id']]) }}"
        >
            <div class="lml-hw-wizard__toolbar">
                <a
                    href="{{ route('user-management.index') }}"
                    class="lml-hw-wizard__back lml-focus-ring"
                >
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back to Manage Health Workers</span>
                </a>
            </div>

            <div class="lml-hw-wizard__card">
                <header class="lml-hw-wizard__header">
                    <span class="lml-hw-wizard__header-icon" aria-hidden="true">
                        <i class="bi bi-pencil-square"></i>
                    </span>
                    <div>
                        <h2 class="lml-hw-wizard__title" id="lml-hw-wizard-page-title">
                            Edit Account Details
                        </h2>
                        <p class="lml-hw-wizard__subtitle">
                            Update the selected health worker’s profile and account information.
                        </p>
                    </div>
                </header>

                <nav class="lml-hw-wizard__stepper" aria-label="Edit health worker progress">
                    <ol class="lml-hw-wizard__steps">
                        @foreach ($steps as $stepNumber => $stepLabel)
                            <li
                                class="lml-hw-wizard__step {{ $stepNumber === 1 ? 'is-current' : 'is-upcoming' }}"
                                data-hw-wizard-step-item="{{ $stepNumber }}"
                                @if ($stepNumber === 1) aria-current="step" @endif
                            >
                                <span class="lml-hw-wizard__step-marker" aria-hidden="true">
                                    <span class="lml-hw-wizard__step-num">{{ $stepNumber }}</span>
                                    <i class="bi bi-check-lg lml-hw-wizard__step-check"></i>
                                </span>
                                <span class="lml-hw-wizard__step-label">{{ $stepLabel }}</span>
                            </li>
                        @endforeach
                    </ol>
                    <p class="lml-hw-wizard__step-current-label" data-hw-wizard-current-label>
                        Step 1 of 5: Personal Information
                    </p>
                </nav>

                <p
                    class="lml-hw-wizard__alert"
                    role="alert"
                    aria-live="assertive"
                    @if (! $errors->any()) hidden @endif
                    data-hw-wizard-alert
                >@if ($errors->any()){{ $errors->first() }}@endif</p>

                <p
                    class="lml-hw-wizard__toast"
                    role="status"
                    aria-live="polite"
                    @if (! session('status')) hidden @endif
                    data-hw-wizard-toast
                >{{ session('status') }}</p>

                <form
                    class="lml-hw-wizard__form"
                    method="post"
                    action="{{ route('user-management.health-workers.update', ['id' => $worker['id']]) }}"
                    enctype="multipart/form-data"
                    data-hw-wizard-form
                    data-hw-mutable="{{ ($workerIsMutable ?? false) ? '1' : '0' }}"
                    @if (($workerIsMutable ?? false) && ! empty($offlineParentUserId) && ! empty($offlineFieldHash))
                        data-offline-operation="HEALTH_WORKER_UPDATE"
                        data-offline-parent-user-id="{{ $offlineParentUserId }}"
                        data-offline-field-hash="{{ $offlineFieldHash }}"
                    @endif
                    novalidate
                >
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="worker_id" value="{{ $worker['id'] }}" data-hw-field="id">
                    <input type="hidden" name="hw_remove_photo" value="0" data-hw-remove-photo>

                    {{-- Step 1 --}}
                    <section
                        class="lml-hw-wizard__panel"
                        data-hw-wizard-panel="1"
                        aria-labelledby="lml-hw-wizard-heading-1"
                    >
                        <h3
                            id="lml-hw-wizard-heading-1"
                            class="lml-hw-wizard__panel-title"
                            tabindex="-1"
                        >
                            <i class="bi bi-person-fill lml-hw-wizard__panel-icon" aria-hidden="true"></i>
                            <span class="lml-hw-wizard__panel-heading-text">Personal Information</span>
                        </h3>

                        <div class="lml-hw-wizard__personal">
                            <div class="lml-hw-wizard__profile">
                                <div class="lml-hw-wizard__avatar" aria-hidden="true">
                                    <img
                                        src="{{ $worker['photo'] ?? '' }}"
                                        alt=""
                                        class="lml-hw-wizard__avatar-img"
                                        @if (empty($worker['photo'])) hidden @endif
                                        data-hw-avatar-img
                                        data-um-avatar
                                    >
                                    <i
                                        class="bi bi-person"
                                        data-hw-avatar-icon
                                        data-um-avatar-fallback
                                        @if (! empty($worker['photo'])) hidden @endif
                                    ></i>
                                </div>
                                <p class="lml-hw-wizard__role-label" data-hw-role-label>
                                    {{ $worker['role'] }} (Role)
                                </p>

                                <fieldset class="lml-hw-wizard__sex" data-hw-field-group="sex">
                                    <legend class="lml-hw-wizard__sex-legend lml-form-label lml-form-label--required">
                                        Sex
                                    </legend>
                                    <div class="lml-hw-wizard__sex-options">
                                        <label class="lml-hw-wizard__sex-option">
                                            <input
                                                type="radio"
                                                name="sex"
                                                value="Male"
                                                data-hw-field="sex"
                                                @checked(($worker['sex'] ?? '') === 'Male')
                                            >
                                            <span>Male</span>
                                        </label>
                                        <label class="lml-hw-wizard__sex-option">
                                            <input
                                                type="radio"
                                                name="sex"
                                                value="Female"
                                                data-hw-field="sex"
                                                @checked(($worker['sex'] ?? '') === 'Female')
                                            >
                                            <span>Female</span>
                                        </label>
                                    </div>
                                    <div class="lml-form-error" id="hw_sex-error" hidden data-hw-error="sex"></div>
                                </fieldset>

                                <div class="lml-hw-wizard__photo-actions" data-hw-photo-actions>
                                    <button
                                        type="button"
                                        class="lml-hw-wizard__photo-btn lml-hw-wizard__photo-btn--change lml-focus-ring"
                                        data-hw-photo-change
                                    >
                                        <i class="bi bi-image" aria-hidden="true"></i>
                                        <span>Change photo</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="lml-hw-wizard__photo-btn lml-hw-wizard__photo-btn--remove lml-focus-ring"
                                        data-hw-photo-remove
                                    >
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                        <span>Remove</span>
                                    </button>
                                    <input
                                        type="file"
                                        name="hw_photo"
                                        id="hw_photo"
                                        class="visually-hidden"
                                        accept="image/jpeg,image/png,.jpg,.jpeg,.png"
                                        tabindex="-1"
                                        data-hw-photo-input
                                        aria-hidden="true"
                                    >
                                </div>
                                <div class="lml-form-error" id="hw_photo-error" hidden data-hw-error="photo"></div>
                            </div>

                            <div class="lml-hw-wizard__fields lml-hw-wizard__fields--2">
                                <x-lml.form-group label="First Name" name="hw_first_name" for="hw_first_name" :required="true" class="lml-hw-wizard__field">
                                    <x-lml.text-input id="hw_first_name" name="hw_first_name" :required="true" :value="$worker['first_name'] ?? ''" autocomplete="given-name" data-hw-field="first_name" />
                                    <div class="lml-form-error" id="hw_first_name-error" hidden data-hw-error="first_name"></div>
                                </x-lml.form-group>

                                <x-lml.form-group label="Last Name" name="hw_last_name" for="hw_last_name" :required="true" class="lml-hw-wizard__field">
                                    <x-lml.text-input id="hw_last_name" name="hw_last_name" :required="true" :value="$worker['last_name'] ?? ''" autocomplete="family-name" data-hw-field="last_name" />
                                    <div class="lml-form-error" id="hw_last_name-error" hidden data-hw-error="last_name"></div>
                                </x-lml.form-group>

                                <x-lml.form-group label="Middle Name" name="hw_middle_name" for="hw_middle_name" :required="true" class="lml-hw-wizard__field">
                                    <x-lml.text-input id="hw_middle_name" name="hw_middle_name" :required="true" :value="$worker['middle_name'] ?? ''" autocomplete="additional-name" data-hw-field="middle_name" />
                                    <div class="lml-form-error" id="hw_middle_name-error" hidden data-hw-error="middle_name"></div>
                                </x-lml.form-group>

                                <x-lml.form-group label="Suffix (Jr., Sr., III)" name="hw_suffix" for="hw_suffix" :required="false" :optional="true" class="lml-hw-wizard__field">
                                    <x-lml.text-input id="hw_suffix" name="hw_suffix" :required="false" :value="$worker['suffix'] ?? ''" data-hw-field="suffix" />
                                    <div class="lml-form-error" id="hw_suffix-error" hidden data-hw-error="suffix"></div>
                                </x-lml.form-group>

                                <x-lml.form-group label="Date of Birth" name="hw_dob" for="hw_dob" :required="true" class="lml-hw-wizard__field">
                                    <x-lml.text-input type="date" id="hw_dob" name="hw_dob" :required="true" :value="$worker['date_of_birth'] ?? ''" :max="now()->subDay()->toDateString()" data-hw-field="date_of_birth" data-hw-dob />
                                    <div class="lml-form-error" id="hw_dob-error" hidden data-hw-error="date_of_birth"></div>
                                </x-lml.form-group>

                                <x-lml.form-group label="Age" name="hw_age" for="hw_age" :required="true" help="(Auto-Computed)" class="lml-hw-wizard__field">
                                    <x-lml.text-input id="hw_age" name="hw_age" :required="true" :readonly="true" inputmode="numeric" data-hw-field="age" data-hw-age />
                                    <div class="lml-form-error" id="hw_age-error" hidden data-hw-error="age"></div>
                                </x-lml.form-group>

                                <x-lml.form-group label="Civil Status" name="hw_civil_status" for="hw_civil_status" :required="true" class="lml-hw-wizard__field">
                                    <x-lml.select-input id="hw_civil_status" name="hw_civil_status" :options="$civilStatusOptions" :selected="$worker['civil_status'] ?? null" :required="true" data-hw-field="civil_status" />
                                    <div class="lml-form-error" id="hw_civil_status-error" hidden data-hw-error="civil_status"></div>
                                </x-lml.form-group>

                                <x-lml.form-group label="Nationality" name="hw_nationality" for="hw_nationality" :required="true" class="lml-hw-wizard__field">
                                    <x-lml.select-input id="hw_nationality" name="hw_nationality" :options="$nationalityOptions" :selected="$worker['nationality'] ?? null" :required="true" data-hw-field="nationality" />
                                    <div class="lml-form-error" id="hw_nationality-error" hidden data-hw-error="nationality"></div>
                                </x-lml.form-group>
                            </div>
                        </div>
                    </section>

                    {{-- Step 2 --}}
                    <section
                        class="lml-hw-wizard__panel"
                        data-hw-wizard-panel="2"
                        aria-labelledby="lml-hw-wizard-heading-2"
                        hidden
                    >
                        <h3 id="lml-hw-wizard-heading-2" class="lml-hw-wizard__panel-title" tabindex="-1">
                            <i class="bi bi-telephone-fill lml-hw-wizard__panel-icon" aria-hidden="true"></i>
                            <span class="lml-hw-wizard__panel-heading-text">Contact Information</span>
                        </h3>
                        <div class="lml-hw-wizard__fields lml-hw-wizard__fields--2 lml-hw-wizard__fields--narrow">
                            <x-lml.form-group label="Mobile Number" name="hw_mobile" for="hw_mobile" :required="true" class="lml-hw-wizard__field">
                                <x-lml.text-input type="tel" id="hw_mobile" name="hw_mobile" :required="true" :value="$worker['mobile'] ?? ''" inputmode="tel" autocomplete="tel" data-hw-field="mobile" />
                                <div class="lml-form-error" id="hw_mobile-error" hidden data-hw-error="mobile"></div>
                            </x-lml.form-group>
                            <x-lml.form-group label="Email Address" name="hw_email" for="hw_email" :required="true" class="lml-hw-wizard__field">
                                <x-lml.text-input type="email" id="hw_email" name="hw_email" :required="true" :value="$worker['email'] ?? ''" autocomplete="email" data-hw-field="email" />
                                <div class="lml-form-error" id="hw_email-error" hidden data-hw-error="email"></div>
                            </x-lml.form-group>
                        </div>
                    </section>

                    {{-- Step 3 --}}
                    <section
                        class="lml-hw-wizard__panel"
                        data-hw-wizard-panel="3"
                        aria-labelledby="lml-hw-wizard-heading-3"
                        hidden
                    >
                        <h3 id="lml-hw-wizard-heading-3" class="lml-hw-wizard__panel-title" tabindex="-1">
                            <i class="bi bi-house-door-fill lml-hw-wizard__panel-icon" aria-hidden="true"></i>
                            <span class="lml-hw-wizard__panel-heading-text">Residential Address</span>
                        </h3>
                        <div class="lml-hw-wizard__fields lml-hw-wizard__fields--address">
                            <x-lml.form-group label="House No." name="hw_house_no" for="hw_house_no" :required="true" class="lml-hw-wizard__field">
                                <x-lml.text-input id="hw_house_no" name="hw_house_no" :required="true" :value="$worker['house_no'] ?? ''" data-hw-field="house_no" />
                                <div class="lml-form-error" id="hw_house_no-error" hidden data-hw-error="house_no"></div>
                            </x-lml.form-group>
                            <x-lml.form-group label="Street" name="hw_street" for="hw_street" :required="true" class="lml-hw-wizard__field">
                                <x-lml.text-input id="hw_street" name="hw_street" :required="true" :value="$worker['street'] ?? ''" data-hw-field="street" />
                                <div class="lml-form-error" id="hw_street-error" hidden data-hw-error="street"></div>
                            </x-lml.form-group>
                            <x-lml.form-group label="Purok / Zone" name="hw_purok_zone" for="hw_purok_zone" :required="true" class="lml-hw-wizard__field">
                                <x-lml.select-input id="hw_purok_zone" name="hw_purok_zone" :options="$zoneOptions" :selected="$worker['purok_zone'] ?? null" :required="true" data-hw-field="purok_zone" />
                                <div class="lml-form-error" id="hw_purok_zone-error" hidden data-hw-error="purok_zone"></div>
                            </x-lml.form-group>
                            <x-lml.form-group label="Barangay" name="hw_barangay" for="hw_barangay" :required="true" class="lml-hw-wizard__field">
                                <x-lml.text-input id="hw_barangay" name="hw_barangay" :required="true" :value="$worker['barangay'] ?? ''" data-hw-field="barangay" />
                                <div class="lml-form-error" id="hw_barangay-error" hidden data-hw-error="barangay"></div>
                            </x-lml.form-group>
                            <x-lml.form-group label="Municipality / City" name="hw_municipality" for="hw_municipality" :required="true" class="lml-hw-wizard__field">
                                <x-lml.text-input id="hw_municipality" name="hw_municipality" :required="true" :value="$worker['municipality'] ?? ''" data-hw-field="municipality" />
                                <div class="lml-form-error" id="hw_municipality-error" hidden data-hw-error="municipality"></div>
                            </x-lml.form-group>
                            <x-lml.form-group label="Province" name="hw_province" for="hw_province" :required="true" class="lml-hw-wizard__field">
                                <x-lml.text-input id="hw_province" name="hw_province" :required="true" :value="$worker['province'] ?? ''" data-hw-field="province" />
                                <div class="lml-form-error" id="hw_province-error" hidden data-hw-error="province"></div>
                            </x-lml.form-group>
                            <x-lml.form-group label="Zip Code" name="hw_zip" for="hw_zip" :required="true" class="lml-hw-wizard__field">
                                <x-lml.text-input id="hw_zip" name="hw_zip" :required="true" :value="$worker['zip_code'] ?? ''" inputmode="numeric" data-hw-field="zip_code" />
                                <div class="lml-form-error" id="hw_zip-error" hidden data-hw-error="zip_code"></div>
                            </x-lml.form-group>
                        </div>
                    </section>

                    {{-- Step 4 --}}
                    <section
                        class="lml-hw-wizard__panel"
                        data-hw-wizard-panel="4"
                        aria-labelledby="lml-hw-wizard-heading-4"
                        hidden
                    >
                        <h3 id="lml-hw-wizard-heading-4" class="lml-hw-wizard__panel-title" tabindex="-1">
                            <i class="bi bi-briefcase-fill lml-hw-wizard__panel-icon" aria-hidden="true"></i>
                            <span class="lml-hw-wizard__panel-heading-text">Employment Information</span>
                        </h3>
                        <div class="lml-hw-wizard__fields lml-hw-wizard__fields--employment">
                            <x-lml.form-group label="Role" name="hw_role" for="hw_role" :required="true" class="lml-hw-wizard__field lml-hw-wizard__field--full">
                                <x-lml.select-input id="hw_role" name="hw_role" :options="$roleOptions" :selected="$worker['role'] ?? null" :required="true" data-hw-field="role" data-hw-role />
                                <div class="lml-form-error" id="hw_role-error" hidden data-hw-error="role"></div>
                            </x-lml.form-group>
                            <x-lml.form-group label="Assigned Barangay" name="hw_assigned_barangay" for="hw_assigned_barangay" :required="true" class="lml-hw-wizard__field">
                                <x-lml.text-input id="hw_assigned_barangay" name="hw_assigned_barangay" :required="true" :value="$worker['assigned_barangay'] ?? ''" data-hw-field="assigned_barangay" />
                                <div class="lml-form-error" id="hw_assigned_barangay-error" hidden data-hw-error="assigned_barangay"></div>
                            </x-lml.form-group>
                            <x-lml.form-group label="Assigned Zones" name="hw_assigned_zone" for="hw_assigned_zone" :required="true" class="lml-hw-wizard__field">
                                @php
                                    $selectedAssignedZones = old('hw_assigned_zone', $worker['assigned_zones'] ?? null);
                                    if (! is_array($selectedAssignedZones)) {
                                        $scalarZone = is_string($selectedAssignedZones) && $selectedAssignedZones !== ''
                                            ? $selectedAssignedZones
                                            : (string) ($worker['assigned_zone'] ?? '');
                                        $selectedAssignedZones = $scalarZone !== '' ? [$scalarZone] : [];
                                    }
                                @endphp
                                <div class="lml-hw-wizard__zone-options" data-hw-field-group="assigned_zone">
                                    @foreach ($zoneOptions as $value => $label)
                                        <label class="lml-hw-wizard__zone-option">
                                            <input
                                                type="checkbox"
                                                name="hw_assigned_zone[]"
                                                value="{{ $value }}"
                                                class="lml-focus-ring"
                                                data-hw-field="assigned_zone"
                                                @checked(in_array($value, $selectedAssignedZones, true))
                                                @if ($loop->first) id="hw_assigned_zone" @endif
                                            >
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <div class="lml-form-error" id="hw_assigned_zone-error" hidden data-hw-error="assigned_zone"></div>
                            </x-lml.form-group>
                            <x-lml.form-group label="Date Appointed" name="hw_date_appointed" for="hw_date_appointed" :required="true" class="lml-hw-wizard__field">
                                <x-lml.text-input type="date" id="hw_date_appointed" name="hw_date_appointed" :required="true" :value="$worker['date_appointed'] ?? ''" :max="now()->toDateString()" data-hw-field="date_appointed" />
                                <div class="lml-form-error" id="hw_date_appointed-error" hidden data-hw-error="date_appointed"></div>
                            </x-lml.form-group>
                            <x-lml.form-group label="End of Appointment" name="hw_end_appointment" for="hw_end_appointment" :required="false" :optional="true" class="lml-hw-wizard__field">
                                <x-lml.text-input type="date" id="hw_end_appointment" name="hw_end_appointment" :required="false" :value="$worker['end_of_appointment'] ?? ''" data-hw-field="end_of_appointment" />
                                <div class="lml-form-error" id="hw_end_appointment-error" hidden data-hw-error="end_of_appointment"></div>
                            </x-lml.form-group>
                        </div>
                    </section>

                    {{-- Step 5 --}}
                    <section
                        class="lml-hw-wizard__panel"
                        data-hw-wizard-panel="5"
                        aria-labelledby="lml-hw-wizard-heading-5"
                        hidden
                    >
                        <h3 id="lml-hw-wizard-heading-5" class="lml-hw-wizard__panel-title" tabindex="-1">
                            <i class="bi bi-shield-lock-fill lml-hw-wizard__panel-icon" aria-hidden="true"></i>
                            <span class="lml-hw-wizard__panel-heading-text">Account Information</span>
                        </h3>
                        <div class="lml-hw-wizard__fields lml-hw-wizard__fields--account">
                            <x-lml.form-group label="Username" name="hw_username" for="hw_username" :required="true" class="lml-hw-wizard__field">
                                <x-lml.text-input id="hw_username" name="hw_username" :required="true" :value="$worker['username'] ?? ''" autocomplete="username" data-hw-field="username" />
                                <div class="lml-form-error" id="hw_username-error" hidden data-hw-error="username"></div>
                            </x-lml.form-group>
                            <x-lml.form-group label="Status" name="hw_status" for="hw_status" :required="true" class="lml-hw-wizard__field">
                                <x-lml.select-input id="hw_status" name="hw_status" :options="$statusOptions" :selected="$worker['status'] ?? null" :required="true" data-hw-field="status" />
                                <div class="lml-form-error" id="hw_status-error" hidden data-hw-error="status"></div>
                            </x-lml.form-group>
                            <x-lml.form-group
                                label="Password"
                                name="hw_password"
                                for="hw_password"
                                :required="false"
                                :optional="true"
                                help="Leave both fields blank to keep the current password."
                                class="lml-hw-wizard__field"
                            >
                                <x-lml.password-input
                                    id="hw_password"
                                    name="hw_password"
                                    :required="false"
                                    placeholder="Enter new password"
                                    autocomplete="new-password"
                                    data-hw-field="password"
                                    data-hw-describedby-base="hw_password-help"
                                />
                                <div class="lml-form-error" id="hw_password-error" hidden data-hw-error="password"></div>
                            </x-lml.form-group>
                            <x-lml.form-group
                                label="Confirm Password"
                                name="hw_password_confirmation"
                                for="hw_password_confirmation"
                                :required="false"
                                :optional="true"
                                class="lml-hw-wizard__field"
                            >
                                <x-lml.password-input
                                    id="hw_password_confirmation"
                                    name="hw_password_confirmation"
                                    :required="false"
                                    placeholder="Confirm new password"
                                    autocomplete="new-password"
                                    describedby="hw_password-help"
                                    data-hw-field="password_confirmation"
                                    data-hw-describedby-base="hw_password-help"
                                />
                                <div class="lml-form-error" id="hw_password_confirmation-error" hidden data-hw-error="password_confirmation"></div>
                            </x-lml.form-group>
                        </div>
                    </section>

                    <div class="lml-hw-wizard__actions">
                        <button
                            type="button"
                            class="lml-hw-wizard__btn lml-hw-wizard__btn--prev lml-focus-ring"
                            data-hw-wizard-prev
                            hidden
                        >
                            Previous
                        </button>
                        <a
                            href="{{ route('user-management.health-workers.view', ['id' => $worker['id']]) }}"
                            class="lml-hw-wizard__btn lml-hw-wizard__btn--cancel lml-focus-ring"
                            data-hw-wizard-cancel
                            data-um-nav="view-worker"
                        >
                            Cancel
                        </a>
                        <button
                            type="button"
                            class="lml-hw-wizard__btn lml-hw-wizard__btn--next lml-focus-ring"
                            data-hw-wizard-next
                        >
                            Next
                        </button>
                        <button
                            type="submit"
                            class="lml-hw-wizard__btn lml-hw-wizard__btn--save lml-focus-ring"
                            data-hw-wizard-save
                            hidden
                        >
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection
