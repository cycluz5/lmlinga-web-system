{{--
    Admin-only Create Health Worker account.
    Slim form posts to store: authentication account + role only.
    Does not prefill or redirect to demo health-worker profiles (e.g. hw-001).
--}}
@extends('layouts.dashboard')

@section('title', 'Create Account - LMLinga')

@php
    $roleOptions = [
        'BHW' => 'BHW',
        'BNS' => 'BNS',
        'BSPO' => 'BSPO',
        'Admin' => 'Admin',
    ];
    $statusOptions = [
        'Active' => 'Active',
        'Inactive' => 'Inactive',
    ];
@endphp

@section('content')
    <div
        class="lml-hw-wizard lml-hw-create"
        data-lml-hw-create
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
                    <i class="bi bi-person-plus"></i>
                </span>
                <div>
                    <h1 class="lml-hw-wizard__title" id="lml-hw-create-title">
                        Create Account
                    </h1>
                    <p class="lml-hw-wizard__subtitle">
                        Assign a Health Worker account and a temporary password. The worker must change this password on first login.
                    </p>
                </div>
            </header>

            <p
                class="lml-hw-wizard__alert"
                role="alert"
                aria-live="assertive"
                @if (! $errors->any()) hidden @endif
                data-hw-create-alert
            >@if ($errors->any()){{ $errors->first() }}@endif</p>

            <p
                class="lml-hw-wizard__toast"
                role="status"
                aria-live="polite"
                hidden
                data-hw-create-toast
            ></p>

            <form
                class="lml-hw-wizard__form lml-hw-create__form"
                method="post"
                action="{{ route('user-management.health-workers.store') }}"
                data-hw-create-form
                novalidate
            >
                @csrf
                <fieldset class="lml-hw-create__name-group">
                    <legend class="lml-form-label lml-form-label--required">Full Name</legend>
                    <div class="lml-hw-wizard__fields lml-hw-wizard__fields--2">
                        <x-lml.form-group label="First Name" name="hw_first_name" for="hw_first_name" :required="true" class="lml-hw-wizard__field">
                            <x-lml.text-input id="hw_first_name" name="first_name" :required="true" :value="old('first_name')" autocomplete="given-name" data-hw-create-field="first_name" />
                            <div class="lml-form-error" id="hw_first_name-error" hidden data-hw-create-error="first_name"></div>
                        </x-lml.form-group>
                        <x-lml.form-group label="Last Name" name="hw_last_name" for="hw_last_name" :required="true" class="lml-hw-wizard__field">
                            <x-lml.text-input id="hw_last_name" name="last_name" :required="true" :value="old('last_name')" autocomplete="family-name" data-hw-create-field="last_name" />
                            <div class="lml-form-error" id="hw_last_name-error" hidden data-hw-create-error="last_name"></div>
                        </x-lml.form-group>
                        <x-lml.form-group label="Middle Name" name="hw_middle_name" for="hw_middle_name" :required="false" class="lml-hw-wizard__field">
                            <x-lml.text-input id="hw_middle_name" name="middle_name" :value="old('middle_name')" autocomplete="additional-name" data-hw-create-field="middle_name" />
                            <div class="lml-form-error" id="hw_middle_name-error" hidden data-hw-create-error="middle_name"></div>
                        </x-lml.form-group>
                    </div>
                </fieldset>

                <div class="lml-hw-wizard__fields lml-hw-wizard__fields--2">
                    <x-lml.form-group label="Email" name="hw_email" for="hw_email" :required="true" class="lml-hw-wizard__field">
                        <x-lml.text-input type="email" id="hw_email" name="email" :required="true" :value="old('email')" autocomplete="email" placeholder="name@example.com" data-hw-create-field="email" />
                        <div class="lml-form-error" id="hw_email-error" hidden data-hw-create-error="email"></div>
                    </x-lml.form-group>
                    <x-lml.form-group label="Username" name="hw_username" for="hw_username" :required="true" class="lml-hw-wizard__field">
                        <x-lml.text-input id="hw_username" name="username" :required="true" :value="old('username')" autocomplete="username" placeholder="e.g. maria.santos" data-hw-create-field="username" />
                        <div class="lml-form-error" id="hw_username-error" hidden data-hw-create-error="username"></div>
                    </x-lml.form-group>
                    <x-lml.form-group label="Mobile Number" name="hw_mobile" for="hw_mobile" :required="true" class="lml-hw-wizard__field">
                        <x-lml.text-input type="tel" id="hw_mobile" name="mobile" :required="true" :value="old('mobile')" inputmode="tel" autocomplete="tel" placeholder="09XXXXXXXXX" data-hw-create-field="mobile" />
                        <div class="lml-form-error" id="hw_mobile-error" hidden data-hw-create-error="mobile"></div>
                    </x-lml.form-group>
                    <x-lml.form-group label="Role" name="hw_role" for="hw_role" :required="true" class="lml-hw-wizard__field">
                        <x-lml.select-input id="hw_role" name="role" placeholder="Select" :options="$roleOptions" :selected="old('role')" :required="true" data-hw-create-field="role" />
                        <div class="lml-form-error" id="hw_role-error" hidden data-hw-create-error="role"></div>
                    </x-lml.form-group>
                    <x-lml.form-group label="Account Status" name="hw_status" for="hw_status" :required="true" class="lml-hw-wizard__field">
                        <x-lml.select-input id="hw_status" name="status" :options="$statusOptions" :selected="old('status', 'Active')" :required="true" data-hw-create-field="status" />
                        <div class="lml-form-error" id="hw_status-error" hidden data-hw-create-error="status"></div>
                    </x-lml.form-group>
                    <x-lml.form-group
                        label="Temporary Password"
                        name="hw_password"
                        for="hw_password"
                        :required="true"
                        help="Use at least 8 characters with letters, numbers, and a symbol. The worker must replace this on first login."
                        class="lml-hw-wizard__field"
                    >
                        <x-lml.password-input
                            id="hw_password"
                            name="password"
                            :required="true"
                            placeholder="Assign or generate a temporary password"
                            autocomplete="new-password"
                            data-hw-create-field="password"
                            data-password-strength-input
                        />
                        <div
                            class="lml-password-strength"
                            data-password-strength
                            aria-live="polite"
                            hidden
                        >
                            <span class="lml-password-strength__label">Password strength:</span>
                            <span class="lml-password-strength__value" data-password-strength-value>Weak</span>
                        </div>
                        <p class="lml-password-strength__hint mb-0">
                            Use at least 8 characters with letters, numbers, and a symbol.
                        </p>
                        <div class="lml-form-error" id="hw_password-error" hidden data-hw-create-error="password"></div>
                    </x-lml.form-group>
                    <x-lml.form-group
                        label="Confirm Password"
                        name="hw_password_confirmation"
                        for="hw_password_confirmation"
                        :required="true"
                        class="lml-hw-wizard__field"
                    >
                        <x-lml.password-input
                            id="hw_password_confirmation"
                            name="password_confirmation"
                            :required="true"
                            placeholder="Confirm temporary password"
                            autocomplete="new-password"
                            data-hw-create-field="password_confirmation"
                        />
                        <div class="lml-form-error" id="hw_password_confirmation-error" hidden data-hw-create-error="password_confirmation"></div>
                    </x-lml.form-group>
                </div>

                <div class="lml-hw-create__generate">
                    <button
                        type="button"
                        class="lml-hw-wizard__btn lml-hw-wizard__btn--cancel lml-focus-ring"
                        data-hw-create-generate
                    >
                        Generate temporary password
                    </button>
                    <p class="lml-hw-create__generate-hint" role="status" data-hw-create-generate-status></p>
                </div>

                <div class="lml-hw-wizard__actions">
                    <a
                        href="{{ route('user-management.index') }}"
                        class="lml-hw-wizard__btn lml-hw-wizard__btn--cancel lml-focus-ring"
                    >
                        Cancel
                    </a>
                    <button
                        type="submit"
                        class="lml-hw-wizard__btn lml-hw-wizard__btn--save lml-focus-ring"
                        data-hw-create-save
                    >
                        Create Account
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
